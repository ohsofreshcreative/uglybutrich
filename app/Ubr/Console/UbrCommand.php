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
		$result = app(Bootstrapper::class)->run();
		WP_CLI::log('Zastosowane migracje: ' . ($result['migrated'] ? implode(', ', $result['migrated']) : 'brak (aktualne)'));
		WP_CLI::log(sprintf('Organizacja domyślna: #%d (%s)', $result['organization_id'], $result['created'] ? 'utworzona' : 'już istniała'));
		$this->status();
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

			if (! isset($assoc['loop'])) {
				WP_CLI::success(sprintf('Przetworzono zadań: %d', count($ids)));
				break;
			}

			sleep(10);
		} while (true);
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
