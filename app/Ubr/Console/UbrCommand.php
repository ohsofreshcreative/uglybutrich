<?php

namespace App\Ubr\Console;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Migrator;
use App\Ubr\Infrastructure\Discovery\ProviderRegistry;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Queue\JobDispatcher;
use App\Ubr\Infrastructure\Repositories\EventRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use App\Ubr\Services\Bootstrapper;
use App\Ubr\Services\DiscoveryRunner;
use App\Ubr\Services\DiscoveryService;
use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\QueueMonitor;
use App\Ubr\Support\UbrException;
use WP_CLI;

/**
 * Ugly but Rich — komendy WP-CLI.
 *
 * ## EXAMPLES
 *
 *     wp ubr install
 *     wp ubr status
 *     wp ubr discover --industry=steel_structures --region=mazowieckie --org=ohsofresh --sync
 *     wp ubr queue-work
 *     wp ubr doctor
 */
class UbrCommand
{
	/**
	 * Uruchamia migracje i inicjalizację (idempotentnie).
	 */
	public function install(): void
	{
		$result = $this->runMigrations();
		WP_CLI::log('Zastosowane migracje: ' . ($result['migrated'] ? implode(', ', $result['migrated']) : 'brak (aktualne)'));
		WP_CLI::log(sprintf('Organizacja domyślna: #%d (%s)', $result['organization_id'], $result['created'] ? 'utworzona' : 'już istniała'));
		$this->status();
	}

	/**
	 * Migracje schematu podczas wdrożenia (zalecane na produkcji z `UBR_AUTO_MIGRATE=false`). Kod wyjścia ≠ 0 przy błędzie.
	 *
	 * ## OPTIONS
	 *
	 * [--status]
	 * : Tylko pokaż wersję schematu, oczekujące migracje i ostatni błąd (bez zmian w bazie).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ubr migrate --status
	 *     wp ubr migrate
	 */
	public function migrate(array $args, array $assoc): void
	{
		$migrator = app(Migrator::class);

		if (isset($assoc['status'])) {
			WP_CLI::log(sprintf('Schemat: v%d (najnowsza v%d) — %s', $migrator->currentVersion(), $migrator->latestVersion(), $migrator->needsMigration() ? 'OCZEKUJĄ migracje' : 'aktualny'));
			WP_CLI::log('Automatyczne migracje przy żądaniach WWW: ' . (defined('UBR_AUTO_MIGRATE') && ! constant('UBR_AUTO_MIGRATE') ? 'wyłączone (UBR_AUTO_MIGRATE=false)' : 'włączone'));
			$error = $migrator->lastError();
			WP_CLI::log('Ostatni błąd migracji: ' . ($error ? sprintf('%s (v%s): %s', gmdate('Y-m-d H:i', $error['at']), $error['version'] ?? '?', $error['message']) : 'brak'));

			return;
		}

		$result = $this->runMigrations();
		WP_CLI::success('Zastosowane migracje: ' . ($result['migrated'] ? implode(', ', $result['migrated']) : 'brak (schemat aktualny)') . sprintf(' — schemat v%d.', $migrator->currentVersion()));
	}

	/**
	 * @return array{migrated: list<int>, organization_id: int, created: bool}
	 */
	private function runMigrations(): array
	{
		try {
			$result = app(Bootstrapper::class)->run();
		} catch (\Throwable $e) {
			WP_CLI::error('Migracja nie powiodła się: ' . $e->getMessage() . ' — schemat v' . app(Migrator::class)->currentVersion() . '. Szczegóły w logu PHP; przywróć kopię bazy lub popraw przyczynę i uruchom ponownie.');
		}

		if (app(Migrator::class)->needsMigration()) {
			WP_CLI::error('Migracje nie zostały zastosowane — trwa migracja w innym procesie (blokada). Spróbuj ponownie za chwilę.');
		}

		return $result;
	}

	/**
	 * Stan aplikacji: wersja schematu, tabele, kolejka, dostawcy.
	 */
	public function status(): void
	{
		$migrator = app(Migrator::class);
		WP_CLI::log(sprintf('Schemat: v%d (najnowsza v%d)', $migrator->currentVersion(), $migrator->latestVersion()));

		foreach ($migrator->tableStatus() as $table => $exists) {
			WP_CLI::log(sprintf('  %-32s %s', $table, $exists ? 'OK' : 'BRAK'));
		}

		WP_CLI::log('Kolejka: ' . app(JobDispatcher::class)->driver() . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? ' (DISABLE_WP_CRON=true — użyj systemowego crona lub `wp ubr queue-work`)' : ''));

		foreach (app(ProviderRegistry::class)->describe() as $p) {
			WP_CLI::log(sprintf('Źródło %-14s %s', $p['key'], $p['available'] ? 'aktywne' : 'nieaktywne — ' . $p['description']));
		}
	}

	/**
	 * Organizacje.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list | create | add-member
	 *
	 * [<args>...]
	 * : create <nazwa> | add-member <slug> <login|email|ID>
	 *
	 * [--owner=<user>]
	 * : Właściciel przy tworzeniu.
	 *
	 * [--role=<role>]
	 * : owner|admin|member (domyślnie member)
	 *
	 * @subcommand org
	 */
	public function org(array $args, array $assoc): void
	{
		$action = array_shift($args);
		$orgs = app(OrganizationRepository::class);

		if ($action === 'list') {
			global $wpdb;
			$rows = $wpdb->get_results("SELECT o.id, o.name, o.slug, o.plan, (SELECT COUNT(*) FROM {$wpdb->prefix}ubr_organization_members m WHERE m.organization_id = o.id) AS members FROM {$wpdb->prefix}ubr_organizations o ORDER BY o.id", ARRAY_A);
			WP_CLI\Utils\format_items('table', $rows, ['id', 'name', 'slug', 'plan', 'members']);

			return;
		}

		if ($action === 'create') {
			$owner = isset($assoc['owner']) ? $this->user($assoc['owner']) : 0;
			$id = app(OrganizationService::class)->create((string) ($args[0] ?? ''), $owner);
			WP_CLI::success("Utworzono organizację #{$id}.");

			return;
		}

		if ($action === 'add-member') {
			$org = $orgs->findBySlug((string) ($args[0] ?? '')) ?? WP_CLI::error('Nie ma organizacji o tym slugu.');
			$userId = $this->user((string) ($args[1] ?? ''));
			$role = Role::tryFrom($assoc['role'] ?? 'member') ?? WP_CLI::error('Nieprawidłowa rola.');
			app(MemberRepository::class)->add($org['id'], $userId, $role);
			WP_CLI::success("Użytkownik #{$userId} należy do {$org['slug']} ({$role->value}).");

			return;
		}

		WP_CLI::error('Nieznana akcja. Użyj: list | create | add-member');
	}

	/**
	 * Uruchamia Discovery jako wskazany członek organizacji.
	 *
	 * ## OPTIONS
	 *
	 * --org=<slug>
	 * : Organizacja.
	 *
	 * [--user=<user>]
	 * : Członek organizacji (domyślnie pierwszy właściciel).
	 *
	 * [--industry=<key>]
	 * : Klucz branży (np. steel_structures, machinery, automation).
	 *
	 * [--phrase=<text>]
	 * : Fraza opisująca działalność.
	 *
	 * [--region=<key>]
	 * : Województwo (np. mazowieckie, slaskie).
	 *
	 * [--city=<city>]
	 * : Miejscowość.
	 *
	 * [--size=<key>]
	 * : any|1_10|11_50|51_200|201_500|501_1000|1001_plus
	 *
	 * [--limit=<n>]
	 * : Maks. liczba wyników (25, 50, 100, 250, 500).
	 *
	 * [--providers=<list>]
	 * : np. apollo,wikidata,osm (domyślnie wszystkie skonfigurowane)
	 *
	 * [--sync]
	 * : Wykonaj całe zadanie w tym procesie (bez czekania na cron).
	 */
	public function discover(array $args, array $assoc): void
	{
		$ctx = $this->context($assoc);
		$input = array_intersect_key($assoc, array_flip(['industry', 'phrase', 'region', 'city', 'size', 'limit']));

		if (! empty($assoc['providers'])) {
			$input['providers'] = explode(',', (string) $assoc['providers']);
		}

		if (isset($assoc['sync'])) {
			// Tryb --sync: wszystkie kroki w tym procesie, bez workerów HTTP.
			add_filter('ubr/queue/async', '__return_false');
		}

		try {
			// CLI omija limit częstotliwości dla użytkownika, ale nie limity organizacji.
			delete_transient('ubr_last_search_' . $ctx->userId);
			$search = app(DiscoveryService::class)->start($ctx, $input);
		} catch (UbrException $e) {
			WP_CLI::error($e->getMessage());
		}

		WP_CLI::log("Wyszukiwanie #{$search['id']}: {$search['label']}");

		if (isset($assoc['sync'])) {
			$this->runToCompletion($search['id']);
		}
	}

	/**
	 * Przetwarza zaległe zadania Discovery (alternatywa dla workera w tle, np. z systemowego crona).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maks. liczba zadań w jednym przebiegu.
	 *
	 * [--loop]
	 * : Działaj w pętli (co 10 s) aż do Ctrl+C — przydatne lokalnie.
	 *
	 * [--all]
	 * : Oprócz Discovery przetwórz po jednym kroku pozostałych kolejek: weryfikacja, analiza stron, decydenci,
	 * ClickUp, Lead Intelligence. Zalecane z systemowego crona, gdy loopback HTTP jest zablokowany (hosting).
	 *
	 * @subcommand queue-work
	 */
	public function queueWork(array $args, array $assoc): void
	{
		// W tym procesie wykonujemy kroki sami — bez zlecania workerów HTTP.
		add_filter('ubr/queue/async', '__return_false');

		do {
			$recovered = app(QueueMonitor::class)->recover();

			if ($recovered['failed']) {
				WP_CLI::warning('Oznaczono jako zablokowane: #' . implode(', #', $recovered['failed']));
			}

			$ids = app(SearchRepository::class)->dueIds((int) ($assoc['limit'] ?? 5));

			foreach ($ids as $id) {
				$this->runToCompletion($id);
			}

			if (isset($assoc['all'])) {
				$this->workOtherQueues();
			}

			if (! isset($assoc['loop'])) {
				WP_CLI::success(sprintf('Przetworzono zadań: %d', count($ids)));
				break;
			}

			sleep(10);
		} while (true);
	}

	/**
	 * Jeden krok każdej kolejki w tle (bez łańcuchowania przez loopback). Błąd jednej kolejki nie zatrzymuje pozostałych.
	 */
	private function workOtherQueues(): void
	{
		$steps = [
			'weryfikacja' => fn () => app(\App\Ubr\Services\VerificationRunner::class)->tick(20, false),
			'analiza stron' => fn () => app(\App\Ubr\Services\WebsiteAnalysisRunner::class)->tick(false),
			'decydenci' => fn () => app(\App\Ubr\Services\ContactResearchRunner::class)->tick(false),
			'ClickUp' => fn () => app(\App\Ubr\Services\ClickUpRunner::class)->tick(false),
			'Lead Intelligence' => fn () => app(\App\Ubr\Services\LeadIntelligenceRunner::class)->tick(\App\Ubr\Services\LeadIntelligenceRunner::BATCH, false),
			'telefony (Apollo)' => fn () => app(\App\Ubr\Services\ApolloPhoneRunner::class)->tick(false),
			'wiek stron' => fn () => app(\App\Ubr\Services\WebsiteAgeRunner::class)->tick(20, false),
			// Prompt 10.5 — wysyłka sekwencji (zalecane wywołanie przez cron serwera, nie tylko WP-Cron).
			'sekwencje e-mail' => fn () => app(\App\Ubr\Services\Sequences\SequenceRunner::class)->tick(20, false),
		];

		foreach ($steps as $label => $step) {
			try {
				$r = $step();
				WP_CLI::log(sprintf('  %s: przetworzono %s', $label, (string) ($r['processed'] ?? 0)));
			} catch (\Throwable $e) {
				WP_CLI::warning(sprintf('  %s: błąd — %s', $label, mb_substr($e->getMessage(), 0, 200)));
			}
		}
	}

	/**
	 * Diagnostyka Discovery: konfiguracja, kolejka, loopback HTTP, zadania i dostępność źródeł danych.
	 *
	 * ## OPTIONS
	 *
	 * [--providers]
	 * : Sprawdź też łączność ze źródłami (Wikidata API, Overpass, Apollo auth/health — bez zużycia kredytów).
	 */
	public function doctor(array $args, array $assoc): void
	{
		global $wpdb;

		WP_CLI::log('— Środowisko');
		WP_CLI::log(sprintf('  PHP %s, WordPress %s, baza %s, max_execution_time=%s', PHP_VERSION, get_bloginfo('version'), $wpdb->db_server_info(), ini_get('max_execution_time')));
		WP_CLI::log('  home_url: ' . home_url('/') . ' | admin-ajax: ' . admin_url('admin-ajax.php'));

		WP_CLI::log('— Kolejka');
		WP_CLI::log('  DISABLE_WP_CRON: ' . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'TAK' : 'nie') . ' | ALTERNATE_WP_CRON: ' . ((defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON) ? 'TAK' : 'nie'));
		$next = wp_next_scheduled(JobDispatcher::HEALTHCHECK_HOOK);
		WP_CLI::log('  Healthcheck (co minutę): ' . ($next ? 'zaplanowany, następny za ' . max(0, $next - time()) . ' s' : 'NIE zaplanowany'));
		$lock = get_transient('doing_cron');
		WP_CLI::log('  Blokada WP-Cron (doing_cron): ' . ($lock ? 'aktywna od ' . (int) (time() - (float) $lock) . ' s' : 'brak'));

		$ping = app(AsyncWorker::class)->ping();
		WP_CLI::log(sprintf('  Loopback HTTP do admin-ajax: %s (HTTP %s, %d ms)%s', $ping['ok'] ? 'OK' : 'BŁĄD', $ping['http_code'] ?? '-', $ping['ms'], $ping['error'] ? ' — ' . $ping['error'] : ''));

		$health = app(QueueMonitor::class)->health();
		WP_CLI::log('  Ostatni sygnał workera: ' . ($health['last_worker_heartbeat'] ? (time() - $health['last_worker_heartbeat']) . ' s temu' : 'nigdy'));

		WP_CLI::log('— Zadania aktywne');
		$rows = $wpdb->get_results("SELECT id, status, progress, results_count, next_run_at, locked_until, updated_at FROM {$wpdb->prefix}ubr_discovery_searches WHERE status IN ('queued','running') ORDER BY id", ARRAY_A);
		$rows ? WP_CLI\Utils\format_items('table', $rows, ['id', 'status', 'progress', 'results_count', 'next_run_at', 'locked_until', 'updated_at']) : WP_CLI::log('  brak');

		if (isset($assoc['providers'])) {
			WP_CLI::log('— Źródła danych');
			$http = app(\App\Ubr\Infrastructure\Http\ProviderHttpClient::class);
			$checks = [
				'Wikidata API' => ['GET', 'https://www.wikidata.org/w/api.php?action=query&meta=siteinfo&format=json'],
				'Overpass status' => ['GET', 'https://overpass-api.de/api/status'],
			];

			foreach ($checks as $label => [$method, $url]) {
				$r = $http->send('doctor', $method, $url, ['timeout' => 15], 0);
				WP_CLI::log(sprintf('  %-16s HTTP %s, %d ms%s', $label, $r['status'] ?? '-', $r['ms'], $r['error'] ? ' — ' . $r['error'] : ''));
			}

			$apollo = app(\App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider::class);

			if ($apollo->isAvailable()) {
				$t = $apollo->testConnection();
				WP_CLI::log(sprintf('  Apollo (auth/health) %s — %s', $t['ok'] ? 'OK' : 'BŁĄD', $t['message']));
			} else {
				WP_CLI::log('  Apollo: nieskonfigurowane (brak UBR_APOLLO_API_KEY)');
			}
		}
	}

	/**
	 * Pokazuje stan wyszukiwania i dziennik zdarzeń.
	 *
	 * <id>
	 * : ID wyszukiwania.
	 *
	 * [--results]
	 * : Wypisz znalezione firmy.
	 */
	public function search(array $args, array $assoc): void
	{
		$id = (int) ($args[0] ?? 0);
		$search = app(SearchRepository::class)->find($id) ?? WP_CLI::error('Nie ma takiego wyszukiwania.');
		WP_CLI::log(sprintf('#%d [%s] %d%% — %s | wyniki: %d, z domeną: %d', $id, $search['status'], $search['progress'], $search['label'], $search['results_count'], $search['with_domain_count']));

		foreach (array_reverse(app(EventRepository::class)->forSearch($id, 100)) as $e) {
			WP_CLI::log(sprintf('  %s %-7s %s', $e['created_at'], $e['level'], $e['message']));
		}

		if (isset($assoc['results'])) {
			$data = app(ResultRepository::class)->paginate($id, $search['organization_id'], 1, 500);
			WP_CLI\Utils\format_items('table', $data['items'], ['id', 'name', 'domain', 'relevance_score', 'location_match', 'verification_status', 'domain_status', 'city', 'region', 'provider']);
		}
	}

	/**
	 * Weryfikacja firm w rejestrach publicznych (KRS, biała lista VAT, CEIDG/GUS jeśli skonfigurowane) i domen.
	 *
	 * Rejestry są bezpłatne — komenda nie wykonuje żadnych płatnych zapytań (Apollo nie jest używane).
	 *
	 * ## OPTIONS
	 *
	 * [<company_id>...]
	 * : ID firm do weryfikacji (od razu, synchronicznie).
	 *
	 * [--search=<id>]
	 * : Zleć weryfikację wszystkich firm z wyszukiwania.
	 *
	 * [--all]
	 * : Zleć weryfikację wszystkich firm, które jej nie mają (status pending).
	 *
	 * [--work]
	 * : Przetwórz kolejkę weryfikacji teraz (w tym procesie).
	 *
	 * [--limit=<n>]
	 * : Maks. liczba firm przy --all / --work.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ubr verify 12 15
	 *     wp ubr verify --all --work --limit=20
	 *     wp ubr verify --search=8 --work
	 */
	public function verify(array $args, array $assoc): void
	{
		$queue = app(\App\Ubr\Infrastructure\Repositories\VerificationQueueRepository::class);
		$verifier = app(\App\Ubr\Services\CompanyVerifier::class);
		$limit = max(1, (int) ($assoc['limit'] ?? 50));

		foreach ($args as $id) {
			$r = $verifier->verify((int) $id, ['trigger' => 'cli']);
			WP_CLI::log(sprintf('#%d: firma %s, domena %s%s', (int) $id, $r['status'], $r['domain_status'], $r['error'] ? ' (rejestr: ' . $r['error'] . ')' : ''));
		}

		if (isset($assoc['search'])) {
			$search = app(SearchRepository::class)->find((int) $assoc['search']) ?? WP_CLI::error('Nie ma takiego wyszukiwania.');
			$n = app(\App\Ubr\Services\VerificationService::class)->enqueueForSearch((int) $search['id'], (int) $search['organization_id']);
			WP_CLI::log("Dodano do kolejki: {$n}");
		}

		if (isset($assoc['all'])) {
			$db = app(\App\Ubr\Infrastructure\Database\Db::class);
			$ids = $db->column("SELECT id FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::COMPANIES)} WHERE verification_status = 'pending' ORDER BY id ASC LIMIT %d", $limit);

			foreach ($ids as $id) {
				$queue->enqueue((int) $id, null, null, null, 7);
			}

			WP_CLI::log('Dodano do kolejki: ' . count($ids));
		}

		if (isset($assoc['work'])) {
			$runner = app(\App\Ubr\Services\VerificationRunner::class);
			$done = 0;

			while ($done < $limit) {
				$stats = $runner->tick(60, false);
				$done += $stats['processed'];
				WP_CLI::log(sprintf('  krok: przetworzono %d (zakończone %d, ponowienia %d, błędy %d), zaległe %d', $stats['processed'], $stats['verified'], $stats['retried'], $stats['failed'], $stats['remaining_due']));

				if ($stats['processed'] === 0 || $stats['remaining_due'] === 0) {
					break;
				}
			}
		}

		WP_CLI::log('Kolejka: ' . wp_json_encode($queue->stats()));
	}

	/**
	 * Website Analyzer: analiza stron firm (HTML + render w Chromium + Opportunity Score). Bez płatnych API.
	 *
	 * ## OPTIONS
	 *
	 * [<company_id>...]
	 * : ID firm do analizy.
	 *
	 * [--work]
	 * : Wykonaj analizy z kolejki w tym procesie (zamiast workera w tle).
	 *
	 * [--check]
	 * : Diagnostyka środowiska (Node.js, Playwright, Chrome).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ubr analyze --check
	 *     wp ubr analyze 12 15 --work
	 */
	public function analyze(array $args, array $assoc): void
	{
		if (isset($assoc['check'])) {
			foreach (app(\App\Ubr\Infrastructure\Analysis\SiteCapture::class)->check() as $k => $v) {
				WP_CLI::log(sprintf('%-16s %s', $k, is_bool($v) ? ($v ? 'tak' : 'nie') : (string) $v));
			}

			return;
		}

		$repo = app(\App\Ubr\Infrastructure\Repositories\AnalysisRepository::class);
		$companies = app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class);

		foreach ($args as $id) {
			$c = $companies->find((int) $id) ?? WP_CLI::error("Brak firmy #{$id}");

			if (empty($c['website_url'])) {
				WP_CLI::warning("#{$id}: brak strony WWW — pominięto.");
				continue;
			}

			$aid = $repo->create(['company_id' => (int) $id, 'url' => $c['website_url'], 'algorithm_version' => \App\Ubr\Services\WebsiteAnalyzerService::ALGORITHM_VERSION, 'trigger_type' => 'cli']);
			WP_CLI::log("#{$id} {$c['name']}: analiza #{$aid} w kolejce");
		}

		if (isset($assoc['work'])) {
			$runner = app(\App\Ubr\Services\WebsiteAnalysisRunner::class);

			do {
				$r = $runner->tick(false);
				WP_CLI::log(sprintf('  krok: %s, zaległe %d', $r['status'] ?? '—', $r['remaining_due']));
			} while ($r['processed'] > 0 && $r['remaining_due'] > 0);
		}
	}

	/**
	 * Decision Maker Finder: wyszukiwanie decydentów (KRS + oficjalna strona firmy), wykluczenia, retencja. Bez płatnych API.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : research | show | suppress | purge
	 *
	 * [<args>...]
	 * : research: ID firm; show: ID firmy; suppress: ID firmy i „Imię Nazwisko”.
	 *
	 * [--work]
	 * : research: wykonaj wyszukiwania z kolejki w tym procesie.
	 *
	 * [--reason=<reason>]
	 * : suppress: powód wykluczenia (np. sprzeciw osoby).
	 *
	 * [--days=<days>]
	 * : purge: usuń nieaktywne kontakty automatyczne starsze niż N dni (domyślnie z UBR_CONTACT_RETENTION_DAYS / 365).
	 *
	 * ## EXAMPLES
	 *
	 *     wp ubr contacts research 15 26 --work
	 *     wp ubr contacts show 15
	 *     wp ubr contacts suppress 15 "Jan Kowalski" --reason="sprzeciw"
	 *     wp ubr contacts purge --days=365
	 */
	public function contacts(array $args, array $assoc): void
	{
		$action = array_shift($args);
		$repo = app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class);

		if ($action === 'suppress') {
			$name = \App\Ubr\Domain\Contacts\PersonName::parse((string) ($args[1] ?? ''));

			if (! $name || empty($args[0])) {
				WP_CLI::error('Podaj ID firmy i imię oraz nazwisko.');
			}

			$repo->suppress((int) $args[0], $name->key(), $assoc['reason'] ?? null, get_current_user_id() ?: null);
			WP_CLI::success('Osoba wyłączona z dalszego researchu dla firmy #' . (int) $args[0] . ' (dane kontaktowe wyczyszczone).');

			return;
		}

		if ($action === 'purge') {
			$days = (int) ($assoc['days'] ?? \App\Ubr\Services\ContactLimits::limits()['retention_days']);
			WP_CLI::success('Usunięto nieaktywnych kontaktów: ' . $repo->purgeInactiveOlderThan(max(30, $days)));
			// Prompt 10.3: kandydaci z Apollo niewidziani od tylu dni i bez zatwierdzonego dopasowania.
			WP_CLI::success('Usunięto nieaktualnych kandydatów Apollo: ' . app(\App\Ubr\Services\ApolloContactService::class)->purgeStale(max(30, $days)));

			return;
		}

		if ($action === 'show') {
			foreach ($repo->forCompany((int) ($args[0] ?? 0), null) as $c) {
				WP_CLI::log(sprintf('%s %s | %s | %s | CPS %s (%s) | %s | %s', $c['rank_position'] ? 'TOP ' . $c['rank_position'] : '     ', $c['full_name'], $c['job_title'], $c['registry_role'] ?: '—', $c['contact_priority_score'], $c['score_status'], $c['confidence_level'], $c['verification_status']));
			}

			return;
		}

		if ($action !== 'research') {
			WP_CLI::error('Akcja: research | show | suppress | purge');
		}

		$runs = app(\App\Ubr\Infrastructure\Repositories\ContactRunRepository::class);

		foreach ($args as $id) {
			$c = app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->find((int) $id);

			if (! $c) {
				WP_CLI::warning("#{$id}: brak firmy");
				continue;
			}

			$rid = $runs->create(['company_id' => (int) $id, 'trigger_type' => 'cli', 'research_version' => \App\Ubr\Services\DecisionMakerFinderService::RESEARCH_VERSION]);
			WP_CLI::log("#{$id} {$c['name']}: wyszukiwanie #{$rid} w kolejce");
		}

		if (isset($assoc['work'])) {
			$runner = app(\App\Ubr\Services\ContactResearchRunner::class);

			do {
				$r = $runner->tick(false);
				WP_CLI::log(sprintf('  krok: %s, zaległe %d', $r['status'] ?? '—', $r['remaining_due']));
			} while ($r['processed'] > 0 && $r['remaining_due'] > 0);
		}
	}

	/**
	 * Przelicza Relevance Score wyników (bez zapytań zewnętrznych).
	 *
	 * ## OPTIONS
	 *
	 * [--company=<id>]
	 * : Tylko jedna firma.
	 */
	public function relevance(array $args, array $assoc): void
	{
		$service = app(\App\Ubr\Services\RelevanceService::class);

		if (isset($assoc['company'])) {
			WP_CLI::success('Przeliczono wyników: ' . $service->recomputeForCompany((int) $assoc['company']));

			return;
		}

		$db = app(\App\Ubr\Infrastructure\Database\Db::class);
		$count = 0;

		foreach ($db->column("SELECT DISTINCT company_id FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::RESULTS)}") as $id) {
			$count += $service->recomputeForCompany((int) $id);
		}

		WP_CLI::success("Przeliczono wyników: {$count}");
	}

	/**
	 * Klucz szyfrowania tokenów integracji: `wp ubr secrets key` generuje nową wartość UBR_ENCRYPTION_KEY
	 * (wyświetlana tylko w terminalu, nigdzie nie zapisywana), `wp ubr secrets status` pokazuje źródło klucza.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : key | status
	 */
	public function secrets(array $args, array $assoc): void
	{
		$action = $args[0] ?? 'status';

		if ($action === 'key') {
			WP_CLI::log("Dodaj do wp-config.php (poza repozytorium), PRZED linią „That's all, stop editing”:");
			WP_CLI::log("define('UBR_ENCRYPTION_KEY', '" . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . "');");
			WP_CLI::warning('Zmiana klucza unieważnia zapisane tokeny — użytkownicy będą musieli ponownie połączyć ClickUp.');

			return;
		}

		$box = \App\Ubr\Infrastructure\Security\SecretBox::class;
		WP_CLI::log('Szyfrowanie dostępne: ' . ($box::available() ? 'tak' : 'NIE (brak libsodium)'));
		WP_CLI::log('Źródło klucza: ' . ($box::source() ?? 'brak'));
		WP_CLI::log('Identyfikator klucza: ' . ($box::keyId() ?? '—'));
	}

	/**
	 * Prompt 10.2: telefony decydentów z oficjalnych stron firm z numerami „dział / osoba” (dowód powiązania).
	 * Domyślnie lista firm; `--dry-run` — analiza bez zapisu; `--execute` — zapis powiązań i właścicieli numerów.
	 * Pobierane są tylko strony źródłowe zapisanych numerów (maks. 3) i maks. 2 podstrony „zarząd / zespół”.
	 *
	 * ## OPTIONS
	 *
	 * --org=<slug>
	 * : Organizacja.
	 *
	 * [--user=<id>]
	 * : Użytkownik (członek organizacji).
	 *
	 * [--limit=<n>]
	 * : Maks. firm (domyślnie 25).
	 *
	 * [--company=<id>]
	 * : Jedna firma.
	 *
	 * [--dry-run]
	 * : Analiza bez zapisu.
	 *
	 * [--execute]
	 * : Zapis powiązań.
	 *
	 * @subcommand decision-phones
	 */
	public function decisionPhones(array $args, array $assoc): void
	{
		$ctx = $this->context($assoc);
		$db = app(\App\Ubr\Infrastructure\Database\Db::class);
		$limit = max(1, min(200, (int) ($assoc['limit'] ?? 25)));
		$cp = $db->table(\App\Ubr\Infrastructure\Database\Tables::COMPANY_PHONES);
		$rows = isset($assoc['company'])
			? $db->rows("SELECT id, name, domain FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::COMPANIES)} WHERE id = %d", (int) $assoc['company'])
			: $db->rows(
				"SELECT c.id, c.name, c.domain FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::COMPANIES)} c
				WHERE EXISTS (SELECT 1 FROM {$cp} x WHERE x.company_id = c.id AND x.org_key = 0 AND x.phone_type IN ('OTHER','SALES','SUPPORT'))
					AND COALESCE(c.domain_status, '') <> 'mismatch'
					AND (EXISTS (SELECT 1 FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::RESULTS)} r WHERE r.company_id = c.id AND r.organization_id = %d)
						OR EXISTS (SELECT 1 FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::PROSPECTS)} p WHERE p.company_id = c.id AND p.organization_id = %d AND p.archived_at IS NULL))
				ORDER BY c.id ASC LIMIT %d",
				$ctx->organizationId,
				$ctx->organizationId,
				$limit
			);

		WP_CLI::log(sprintf('Firm: %d.', count($rows)));

		if (! isset($assoc['execute']) && ! isset($assoc['dry-run'])) {
			foreach ($rows as $r) {
				WP_CLI::log(sprintf('  #%d %s (%s)', $r['id'], $r['name'], $r['domain']));
			}

			WP_CLI::success('Lista: nic nie pobrano. --dry-run = analiza bez zapisu, --execute = zapis powiązań (bezpłatne, bez Apollo).');

			return;
		}

		$service = app(\App\Ubr\Services\DecisionPhoneService::class);

		foreach ($rows as $i => $r) {
			try {
				$res = $service->scan($ctx, (int) $r['id'], ! isset($assoc['execute']));
				WP_CLI::log(sprintf('  #%d %s: %s', $r['id'], $r['name'], $res['message']));

				foreach ($res['people'] as $p) {
					foreach ($p['phones'] as $ph) {
						WP_CLI::log(sprintf('      %s (%s) — %s: %s [%s]', $p['name'], $p['title'] ?? 'bez stanowiska', $ph['number'], $ph['status_label'], $ph['evidence_label']));
					}
				}
			} catch (\Throwable $e) {
				WP_CLI::warning(sprintf('  #%d %s: %s', $r['id'], $r['name'], mb_substr($e->getMessage(), 0, 160)));
			}

			if ($i < count($rows) - 1) {
				sleep(1);
			}
		}

		WP_CLI::success('Gotowe.');
	}

	/**
	 * Uzupełnienie numerów firm ze stron WWW (bezpłatne: strona główna + Kontakt, ochrona SSRF, timeout 10 s, przerwa 1 s).
	 * Dla firm organizacji z domeną, których numerów jeszcze nie sprawdzano (np. przeanalizowanych przed etapem 10 — analizy
	 * nie przechowują HTML). Domyślnie tylko lista kandydatów, bez pobierania; pobieranie wyłącznie z `--execute`.
	 * Nie uruchamia analizy strony ani Apollo.
	 *
	 * ## OPTIONS
	 *
	 * --org=<slug>
	 * : Organizacja.
	 *
	 * [--user=<id>]
	 * : Użytkownik (członek organizacji).
	 *
	 * [--limit=<n>]
	 * : Maks. liczba firm (domyślnie 25, maks. 200).
	 *
	 * [--execute]
	 * : Pobierz strony i zapisz numery.
	 *
	 * @subcommand phones-backfill
	 */
	public function phonesBackfill(array $args, array $assoc): void
	{
		$ctx = $this->context($assoc);
		$db = app(\App\Ubr\Infrastructure\Database\Db::class);
		$limit = max(1, min(200, (int) ($assoc['limit'] ?? 25)));
		$c = $db->table(\App\Ubr\Infrastructure\Database\Tables::COMPANIES);
		$rows = $db->rows(
			"SELECT c.id, c.name, c.domain FROM {$c} c
			WHERE c.website_url IS NOT NULL AND c.website_url <> '' AND c.phones_checked_at IS NULL AND COALESCE(c.domain_status, '') <> 'mismatch'
				AND (EXISTS (SELECT 1 FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::RESULTS)} r WHERE r.company_id = c.id AND r.organization_id = %d)
					OR EXISTS (SELECT 1 FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::PROSPECTS)} p WHERE p.company_id = c.id AND p.organization_id = %d AND p.archived_at IS NULL))
			ORDER BY c.id ASC LIMIT %d",
			$ctx->organizationId,
			$ctx->organizationId,
			$limit
		);

		WP_CLI::log(sprintf('Firm do sprawdzenia: %d (limit %d).', count($rows), $limit));

		if (! isset($assoc['execute'])) {
			foreach ($rows as $r) {
				WP_CLI::log(sprintf('  #%d %s (%s)', $r['id'], $r['name'], $r['domain']));
			}

			WP_CLI::success('Tryb próbny: nic nie pobrano. Dodaj --execute, aby pobrać strony (2 zapytania HTTP na firmę, bezpłatne).');

			return;
		}

		$service = app(\App\Ubr\Services\CompanyPhoneService::class);
		$found = 0;

		foreach ($rows as $i => $r) {
			try {
				$res = $service->refresh($ctx, (int) $r['id']);
				$main = $res['main']['number'] ?? null;
				$found += $main ? 1 : 0;
				WP_CLI::log(sprintf('  #%d %s: %s', $r['id'], $r['name'], $main ? 'numer ' . $main . ' (' . count($res['phones']) . ' numerów)' : 'brak numeru na stronie'));
			} catch (\Throwable $e) {
				WP_CLI::warning(sprintf('  #%d %s: %s', $r['id'], $r['name'], mb_substr($e->getMessage(), 0, 160)));
			}

			if ($i < count($rows) - 1) {
				sleep(1);
			}
		}

		WP_CLI::success(sprintf('Sprawdzono %d firm, numer główny znaleziono dla %d.', count($rows), $found));
	}

	/**
	 * Bezpieczny test „Odblokuj telefon” (Apollo) do RĘCZNEGO zatwierdzenia. Domyślnie tryb próbny: tylko sprawdzenie
	 * warunków (uprawnienia, flaga, identyfikator Apollo, limit) — BEZ zapytania do Apollo i bez zużycia kredytów.
	 * Zapytanie płatne wyłącznie z `--execute --yes` (świadoma decyzja właściciela; koszt nieznany).
	 *
	 * ## OPTIONS
	 *
	 * --org=<slug>
	 * : Organizacja.
	 *
	 * --contact=<id>
	 * : ID osoby (musi pochodzić z Apollo).
	 *
	 * [--user=<id>]
	 * : Użytkownik (właściciel / administrator organizacji).
	 *
	 * [--execute]
	 * : Wyślij zapytanie do Apollo (PŁATNE).
	 *
	 * [--yes]
	 * : Potwierdzenie nieznanego kosztu (wymagane z --execute).
	 *
	 * [--poll]
	 * : Odbierz wyniki oczekujących zleceń (0 kredytów).
	 *
	 * @subcommand apollo-phone-test
	 */
	public function apolloPhoneTest(array $args, array $assoc): void
	{
		$ctx = $this->context($assoc);
		$service = app(\App\Ubr\Services\ApolloPhoneService::class);

		if (isset($assoc['poll'])) {
			$r = app(\App\Ubr\Services\ApolloPhoneRunner::class)->tick(false);
			WP_CLI::success(sprintf('Odpytano zleceń: %d, oczekujące: %d.', $r['processed'], $r['pending']));

			return;
		}

		try {
			$check = $service->preflight($ctx, (int) ($assoc['contact'] ?? 0));
		} catch (UbrException $e) {
			WP_CLI::error($e->getMessage());
		}

		WP_CLI::log('Stan numeru: ' . $check['phone']['status_label']);
		WP_CLI::log('Koszt: ' . $check['cost']['message']);

		foreach ($check['reasons'] as $reason) {
			WP_CLI::warning($reason['message']);
		}

		if (! isset($assoc['execute'])) {
			WP_CLI::success($check['can_unlock'] ? 'Tryb próbny: warunki spełnione. Nie wysłano zapytania (dodaj --execute --yes, aby zlecić PŁATNE odblokowanie).' : 'Tryb próbny: odblokowanie niemożliwe (powody powyżej). Nie wysłano zapytania.');

			return;
		}

		if (! isset($assoc['yes'])) {
			WP_CLI::error('Zapytanie płatne wymaga --yes (potwierdzenie, że koszt jest nieznany i może zużyć kredyty Apollo).');
		}

		if (! $check['can_unlock']) {
			WP_CLI::error('Odblokowanie niemożliwe.');
		}

		$r = $service->reveal($ctx, (int) $assoc['contact'], (string) $check['confirm_token'], true);
		WP_CLI::success(sprintf('Zlecenie #%d: %s %s', $r['reveal_id'], $r['status'], $r['message']));
	}

	/**
	 * Uruchamia testy automatyczne (tests/run.php). Dane testowe są usuwane po zakończeniu.
	 */
	public function test(): void
	{
		require get_theme_file_path('tests/run.php');
	}

	private function runToCompletion(int $id): void
	{
		$runner = app(DiscoveryRunner::class);
		$repo = app(SearchRepository::class);

		for ($i = 0; $i < 200; $i++) {
			$search = $repo->find($id);

			if (! in_array($search['status'], SearchRepository::ACTIVE_STATUSES, true)) {
				break;
			}

			$wait = $search['next_run_at'] ? strtotime($search['next_run_at'] . ' UTC') - time() : 0;

			if ($wait > 0) {
				WP_CLI::log("  oczekiwanie {$wait}s (ponowienie)…");
				sleep(min($wait, 300));
			}

			$status = $runner->tick($id, 60);
			$search = $repo->find($id);
			WP_CLI::log(sprintf('  krok %d: %s, %d%%, wyniki %d (z domeną %d)', $i + 1, $status, $search['progress'], $search['results_count'], $search['with_domain_count']));
		}

		$this->search([(string) $id], []);
	}

	private function context(array $assoc): TenantContext
	{
		$org = app(OrganizationRepository::class)->findBySlug((string) ($assoc['org'] ?? '')) ?? WP_CLI::error('Podaj --org=<slug> istniejącej organizacji.');
		$members = app(MemberRepository::class);

		if (isset($assoc['user'])) {
			$userId = $this->user((string) $assoc['user']);
		} else {
			$owners = array_values(array_filter($members->list($org['id']), fn ($m) => $m['role'] === 'owner'));
			$userId = (int) ($owners[0]['user_id'] ?? 0);
		}

		$role = $members->role($org['id'], $userId) ?? WP_CLI::error('Użytkownik nie należy do tej organizacji.');

		return new TenantContext($userId, $org['id'], $role, $org);
	}

	private function user(string $value): int
	{
		$user = is_numeric($value) ? get_user_by('id', (int) $value) : (get_user_by('login', $value) ?: get_user_by('email', $value));

		return $user ? $user->ID : WP_CLI::error("Nie znaleziono użytkownika: {$value}");
	}
}
