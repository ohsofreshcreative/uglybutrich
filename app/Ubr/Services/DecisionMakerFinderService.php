<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Contacts\ContactConfidence;
use App\Ubr\Domain\Contacts\ContactMerger;
use App\Ubr\Domain\Contacts\ContactPriorityCalculator;
use App\Ubr\Domain\Contacts\ContactRanking;
use App\Ubr\Domain\Contacts\ContactVerifier;
use App\Ubr\Domain\Contacts\PeopleExtractor;
use App\Ubr\Domain\Contacts\PersonName;
use App\Ubr\Domain\Verification\RegistryUnavailable;
use App\Ubr\Infrastructure\Contacts\ApolloPeopleSource;
use App\Ubr\Infrastructure\Contacts\ContactPageCrawler;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Registry\KrsClient;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ContactRepository;
use App\Ubr\Infrastructure\Repositories\ContactRunRepository;
use App\Ubr\Infrastructure\Http\UrlGuard;

/**
 * Decision Maker Finder — jedno wyszukiwanie decydentów firmy (wykonywane w tle przez ContactResearchRunner).
 *
 * Przebieg: firma i domena → źródła (KRS: skład zarządu; oficjalna strona: zarząd/zespół/kontakt; Apollo People — jeśli
 * włączone) → kandydaci → deduplikacja → weryfikacja (firma, KRS, aktualność) → confidence → Contact Priority Score →
 * ranking Top 3 → zapis (kontakty, źródła, historia) → podsumowanie przy firmie.
 *
 * Status wyszukiwania: COMPLETED (źródła odpowiedziały — także gdy nie znaleziono osób), PARTIAL (część źródeł
 * zawiodła), FAILED (żadne źródło nie odpowiedziało). Brak osób NIE jest błędem technicznym.
 */
class DecisionMakerFinderService
{
	// v2: e-mail działu przypisywany wg działu osoby; szersze wzorce podstron („poznaj nas”, „o spółce”).
	public const RESEARCH_VERSION = 'dmf-2026-10-v2';

	public function __construct(
		private CompanyRepository $companies,
		private ContactRepository $contacts,
		private ContactRunRepository $runs,
		private KrsClient $krs,
		private ContactPageCrawler $crawler,
		private PeopleExtractor $extractor,
		private ApolloPeopleSource $apollo,
		private ContactMerger $merger,
		private ContactVerifier $verifier,
		private ContactConfidence $confidence,
		private ContactPriorityCalculator $calculator,
		private ContactRanking $ranking,
	) {
	}

	/**
	 * @param array<string, mixed> $run
	 * @return array{status: string, retry: bool}
	 */
	public function run(array $run): array
	{
		$started = microtime(true);
		$runId = (int) $run['id'];
		$company = $this->companies->find((int) $run['company_id']);

		if (! $company) {
			$this->runs->update($runId, ['status' => 'failed', 'finished_at' => Db::now(), 'locked_until' => null, 'errors' => [['stage' => 'company', 'message' => 'Firma nie istnieje.']]]);

			return ['status' => 'failed', 'retry' => false];
		}

		$limits = ContactLimits::limits(isset($run['organization_id']) ? (int) $run['organization_id'] : null);
		$claim = ($run['trigger_type'] ?? '') === 'claim';
		// Prompt 10.4.3 — etap analizy (statusy po przejęciu firmy): szukamy decydenta → szukamy telefonu → gotowe.
		$this->runs->update($runId, ['stage' => 'people']);
		$sources = [];
		$errors = [];
		$raw = [];
		$channels = [];
		$pages = [];
		$transient = false;

		// A) KRS — skład zarządu (zanonimizowany w API) do potwierdzania osób.
		$board = null;

		if (! empty($company['krs'])) {
			try {
				$board = $this->krs->board((string) $company['krs']);
				$sources['krs'] = $board
					? ['state' => 'ok', 'message' => sprintf('Zarząd: %d os., prokurenci: %d (stan z %s). Dane osobowe w API KRS są zanonimizowane — KRS potwierdza osoby znalezione w innych źródłach.', count($board['members']), count($board['proxies']), $board['state_date'] ?? '—'), 'url' => $board['source_url']]
					: ['state' => 'not_found', 'message' => 'Brak odpisu KRS dla numeru firmy.'];
			} catch (RegistryUnavailable $e) {
				$transient = true;
				$sources['krs'] = ['state' => 'error', 'message' => $e->getMessage()];
				$errors[] = ['stage' => 'krs', 'message' => $e->getMessage()];
			}
		} else {
			$sources['krs'] = ['state' => 'not_applicable', 'message' => 'Firma bez numeru KRS (np. jednoosobowa działalność) — brak danych o zarządzie w KRS. CEIDG zostanie podłączone po dodaniu klucza.'];
		}

		// B) Oficjalna strona firmy (nie przy domenie niezgodnej).
		$domain = (string) ($company['domain'] ?? '');

		if ($domain === '' || empty($company['website_url'])) {
			$sources['website'] = ['state' => 'not_applicable', 'message' => 'Firma nie ma strony WWW w danych.'];
		} elseif (($company['domain_status'] ?? '') === 'mismatch') {
			$sources['website'] = ['state' => 'skipped', 'message' => 'Domena niezgodna z firmą (weryfikacja) — osoby z tej strony nie są przypisywane do firmy.'];
		} else {
			$crawl = $this->crawler->crawl((string) $company['website_url'], $limits);
			$pages = $crawl['pages'];

			foreach ($crawl['docs'] as $doc) {
				$found = $this->extractor->extract($doc['html'], $doc['url'], $doc['title'], $doc['kind'], (string) ($company['registry_name'] ?: $company['name']), $domain);
				$raw = array_merge($raw, $found['candidates']);

				foreach ($found['channels'] as $ch) {
					$channels[$ch['value']] ??= $ch;
				}
			}

			// Telefony firmy z tych samych stron (bez dodatkowych zapytań) — numery FIRMY, nie przypisywane osobom.
			app(CompanyPhoneService::class)->fromDocuments((int) $company['id'], array_map(fn ($d) => ['html' => $d['html'], 'url' => $d['url'], 'kind' => in_array($d['kind'], ['home', 'contact'], true) ? $d['kind'] : 'other'], $crawl['docs']));

			$checked = count(array_filter($pages, fn ($p) => $p['status'] !== null));

			if ($crawl['docs'] === []) {
				$transient = $transient || ! $crawl['blocked'];
				$sources['website'] = ['state' => 'error', 'message' => $crawl['errors'][0]['message'] ?? 'Nie udało się pobrać strony.'];
			} else {
				$sources['website'] = ['state' => $crawl['errors'] ? 'partial' : 'ok', 'message' => sprintf('Sprawdzono stron: %d (zapytań: %d).', $checked, $crawl['requests'])];
			}

			$errors = array_merge($errors, $crawl['errors']);
		}

		// C) Apollo People Search (przygotowane, domyślnie wyłączone).
		$apolloOrg = app(\App\Ubr\Infrastructure\Database\Db::class)->value("SELECT external_id FROM " . app(\App\Ubr\Infrastructure\Database\Db::class)->table(\App\Ubr\Infrastructure\Database\Tables::COMPANY_EXTERNAL_IDS) . " WHERE company_id = %d AND provider = 'apollo' LIMIT 1", (int) $company['id']);
		if ($claim && ! ApolloPeopleSource::claimSearchAllowed()) {
			// Przejęcie firmy: wyłącznie zapisane wyniki Apollo (bez nowych zapytań, bez kosztów).
			$apollo = $this->apollo->saved((int) $company['id']);
			$sources['apollo'] = ['state' => $apollo['candidates'] ? 'ok' : 'skipped', 'message' => $apollo['candidates'] ? sprintf('Zapisane wyniki Apollo: %d osób.', count($apollo['candidates'])) : 'Bez nowych zapytań Apollo przy przejęciu — brak zapisanych osób z pełnym nazwiskiem.'];
		} else {
			$apollo = $this->apollo->search($domain, isset($run['organization_id']) ? (int) $run['organization_id'] : null, $apolloOrg ? (string) $apolloOrg : null);
			$apolloStatus = ApolloPeopleSource::status();
			$sources['apollo'] = $apollo['error']
				? ['state' => 'error', 'message' => $apollo['error']]
				: ['state' => $apollo['state'] === 'enabled' ? 'ok' : $apollo['state'], 'message' => $apollo['state'] === 'enabled' ? sprintf('Kandydaci: %d.', count($apollo['candidates'])) : $apolloStatus['message']];
		}

		if ($apollo['error']) {
			$errors[] = ['stage' => 'apollo', 'message' => $apollo['error']];
		}

		$raw = array_merge($raw, $apollo['candidates']);

		// Osoby wyłączone z researchu (sprzeciw / prośba o usunięcie) — pomijane.
		$raw = array_values(array_filter($raw, fn ($c) => ! $this->contacts->isSuppressed((int) $company['id'], $c['name']->key())));

		// D) Deduplikacja → weryfikacja → confidence → Contact Priority Score.
		$hasGeneral = $channels !== [];
		$assessed = [];

		foreach ($this->merger->merge($raw) as $c) {
			$c = $this->verifier->verify($c, $company, $board);

			if ($c['krs_match'] === 'matched') {
				$c['sources'][] = [
					'source_type' => 'KRS',
					'source_url' => $board['source_url'],
					'source_title' => 'KRS — odpis aktualny (stan z ' . ($board['state_date'] ?? '—') . ')',
					'observed_role' => $c['registry_role'],
					'excerpt' => 'Skład organu: ' . self::maskFor($board, $c) . ' — ' . $c['registry_role'] . ' (imię i nazwisko zanonimizowane w API; zgodne inicjały i długość).',
					'page_kind' => 'registry',
				];
				usort($c['sources'], fn ($a, $b) => ContactMerger::rankSource($b['source_type']) <=> ContactMerger::rankSource($a['source_type']));
			}

			$c = self::assignDepartmentEmail($c, array_values($channels));
			$conf = $this->confidence->assess($c, $board, $hasGeneral);
			$score = $this->calculator->score($c, $conf, $company, $hasGeneral);
			$c['confidence'] = $conf;
			$c['confidence_level'] = $conf['level'];
			$c['contact_priority_score'] = $score['score'];
			$c['score_status'] = $score['status'];
			$c['score_breakdown'] = $score['breakdown'];
			$assessed[] = $c;
		}

		// E) Ranking (globalny — bez prywatnych decyzji organizacji).
		$inactive = in_array($company['verification_status'] ?? '', ['inactive', 'suspended'], true);
		$ranked = $this->ranking->rank($assessed, ContactPriorityCalculator::sizeBand($company), $inactive);

		// F) Status wyszukiwania.
		$applicable = array_filter($sources, fn ($s) => ! in_array($s['state'], ['not_applicable', 'skipped', 'disabled', 'not_configured'], true));
		$failed = array_filter($applicable, fn ($s) => $s['state'] === 'error');
		$status = match (true) {
			$applicable === [] => 'completed',
			count($failed) === count($applicable) => 'failed',
			$failed !== [] || array_filter($applicable, fn ($s) => $s['state'] === 'partial') => 'partial',
			default => 'completed',
		};

		if ($status === 'failed' && $transient && (int) $run['attempts'] < $limits['max_attempts']) {
			$this->runs->update($runId, ['status' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 300), 'locked_until' => null, 'sources' => $sources, 'errors' => $errors]);

			return ['status' => 'queued', 'retry' => true];
		}

		// G) Zapis kontaktów, źródeł, historii.
		$changes = [];
		$keep = [];
		$snapshot = [];

		if ($status !== 'failed') {
			foreach (array_merge($ranked['top'], $ranked['others'], $ranked['hidden']) as $c) {
				$id = $this->persist((int) $company['id'], $runId, $c, $changes);
				$keep[] = $id;

				if ($c['rank_position'] ?? null) {
					$snapshot[] = ['id' => $id, 'name' => $c['full_name'], 'title' => $c['job_title'], 'score' => $c['contact_priority_score'], 'position' => $c['rank_position'], 'status' => $c['verification_status']];
				}
			}

			// Osoby niewidoczne w pełnym wyszukiwaniu (wszystkie źródła odpowiedziały) → nieaktywne; przy częściowym — bez zmian.
			if ($status === 'completed') {
				foreach ($this->contacts->forCompany((int) $company['id'], null) as $old) {
					if ((int) $old['org_key'] === 0 && ! in_array((int) $old['id'], $keep, true)) {
						$changes[] = ['type' => 'removed', 'name' => $old['full_name'], 'message' => 'Nie znaleziono ponownie w źródłach: ' . $old['full_name'] . ' (' . $old['job_title'] . ').'];
					}
				}

				$this->contacts->deactivateMissing((int) $company['id'], $keep, $runId);
			}
		}

		$visible = count($ranked['top']) + count($ranked['others']);
		$this->runs->update($runId, [
			'status' => $status,
			'finished_at' => Db::now(),
			'locked_until' => null,
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
			'candidates_found' => count($assessed),
			'top_count' => count($ranked['top']),
			'sources' => $sources + ['board' => $board ? ['state_date' => $board['state_date'], 'organ' => $board['organ'], 'representation' => $board['representation'], 'members' => array_map(fn ($m) => ['initials' => PersonName::initials($m['first_mask'], $m['last_mask']), 'function' => $m['function'], 'suspended' => $m['suspended']], $board['members']), 'proxies' => count($board['proxies'])] : null],
			'pages' => array_slice($pages, 0, 15),
			'snapshot' => ['top' => $snapshot, 'top1_note' => $ranked['top1_note'], 'visible' => $visible, 'hidden' => count($ranked['hidden'])],
			'changes' => array_slice($changes, 0, 30),
			'errors' => $errors,
			'company_channels' => array_values($channels),
		]);

		if ($status !== 'failed') {
			$this->runs->update($runId, ['stage' => 'phones']);

			// Prompt 10.2: telefony osób z tych samych stron (bez dodatkowych zapytań) — powiązania z dowodem.
			// Prompt 10.4.3: przed powiadomieniem o zakończeniu (aktualizacja ClickUp ma już numer bezpośredni).
			if (! empty($crawl['docs'])) {
				$this->syncCompanyQuiet((int) $company['id'], $runId, $status, $ranked, array_values($channels));
				app(DecisionPhoneService::class)->fromDocuments((int) $company['id'], array_map(fn ($d) => ['html' => $d['html'], 'url' => $d['url']], $crawl['docs']));
			}

			$this->syncCompany((int) $company['id'], $runId, $status, $ranked, array_values($channels));
			$this->runs->update($runId, ['stage' => 'done']);
		} else {
			$this->runs->update($runId, ['stage' => 'failed']);
			$this->companies->update((int) $company['id'], ['contacts_status' => 'failed', 'last_contact_run_id' => $runId]);
		}

		return ['status' => $status, 'retry' => false];
	}

	/**
	 * @param array<string, mixed> $c
	 * @param list<array<string, mixed>> $changes
	 */
	private function persist(int $companyId, ?int $runId, array $c, array &$changes): int
	{
		$now = Db::now();
		$data = [
			'first_name' => $c['first_name'],
			'last_name' => $c['last_name'],
			'full_name' => mb_substr((string) $c['full_name'], 0, 200),
			'job_title' => mb_substr((string) $c['job_title'], 0, 200),
			'registry_role' => $c['registry_role'] ? mb_substr((string) $c['registry_role'], 0, 120) : null,
			'normalized_role' => $c['normalized_role'],
			'department' => \App\Ubr\Domain\Contacts\RoleCatalog::ROLES[$c['normalized_role']]['department'] ?? null,
			'seniority' => \App\Ubr\Domain\Contacts\RoleCatalog::ROLES[$c['normalized_role']]['seniority'] ?? null,
			'decision_authority' => (int) ($c['score_breakdown']['decision_authority']['points'] ?? 0),
			'contact_priority_score' => $c['contact_priority_score'],
			'score_status' => $c['score_status'],
			'score_breakdown' => $c['score_breakdown'],
			'confidence_level' => $c['confidence_level'],
			'confidence_breakdown' => $c['confidence'],
			'verification_status' => $c['verification_status'],
			'verification_reason' => $c['verification_reason'],
			'company_match_status' => $c['company_match_status'],
			'employment_status' => $c['employment_status'],
			'public_business_email' => $c['email'],
			'public_business_phone' => $c['phone'],
			'professional_profile_url' => UrlGuard::httpUrl($c['profile'] ?? null) ? mb_substr((string) $c['profile'], 0, 300) : null,
			'source_type' => $c['sources'][0]['source_type'] ?? null,
			'source_url' => UrlGuard::httpUrl($c['sources'][0]['source_url'] ?? null) ? mb_substr((string) $c['sources'][0]['source_url'], 0, 500) : null,
			'source_observed_at' => $now,
			'last_verified_at' => $now,
			// Identyfikator osoby u źródła (np. Apollo) — dowód powiązania i warunek odblokowania telefonu.
			'external_provider' => ! empty($c['external_id']) ? (string) ($c['external_provider'] ?? 'apollo') : null,
			'external_id' => ! empty($c['external_id']) ? mb_substr((string) $c['external_id'], 0, 64) : null,
			'rank_position' => $c['rank_position'] ?? null,
			'rank_reason' => $c['rank_reason'] ?? null,
			'needs_review' => $c['needs_review'] ? 1 : 0,
			'flags' => array_filter(['marketing_focus' => $c['marketing_focus'], 'krs_match' => $c['krs_match'], 'conflict' => $c['conflict'], 'observed_roles' => $c['observed_roles'], 'role_email' => $c['role_email'], 'other_entity' => $c['other_entity']]),
			'active' => 1,
			'research_version' => self::RESEARCH_VERSION,
			'last_run_id' => $runId,
		];

		$existing = $this->contacts->findByKey($companyId, 0, (string) $c['person_key']);

		if ($existing) {
			$id = (int) $existing['id'];

			if ((string) $existing['job_title'] !== (string) $data['job_title']) {
				$changes[] = ['type' => 'title', 'name' => $c['full_name'], 'message' => sprintf('Zmiana stanowiska: %s — „%s” → „%s”.', $c['full_name'], $existing['job_title'], $data['job_title'])];
			}

			if ((int) $existing['rank_position'] !== (int) $data['rank_position']) {
				$changes[] = ['type' => 'rank', 'name' => $c['full_name'], 'message' => sprintf('Zmiana pozycji: %s — %s → %s.', $c['full_name'], $existing['rank_position'] ? 'Top ' . $existing['rank_position'] : 'poza Top 3', $data['rank_position'] ? 'Top ' . $data['rank_position'] : 'poza Top 3')];
			}

			if ((string) $existing['verification_status'] !== (string) $data['verification_status']) {
				$changes[] = ['type' => 'status', 'name' => $c['full_name'], 'message' => sprintf('Zmiana statusu: %s — %s → %s.', $c['full_name'], ContactVerifier::LABELS[$existing['verification_status']] ?? $existing['verification_status'], ContactVerifier::LABELS[$data['verification_status']])];
			}

			$this->contacts->update($id, $data);
		} else {
			$id = $this->contacts->insert($data + ['company_id' => $companyId, 'org_key' => 0, 'person_key' => $c['person_key'], 'first_run_id' => $runId]);
			$changes[] = ['type' => 'new', 'name' => $c['full_name'], 'message' => sprintf('Nowa osoba: %s (%s).', $c['full_name'], $c['job_title'])];
		}

		foreach ($c['sources'] as $s) {
			$this->contacts->addSource($id, $runId, $s + ['confidence' => $c['confidence_level'], 'retrieved_at' => $now]);
		}

		$this->contacts->addHistory($id, $runId, $data);

		return $id;
	}

	/**
	 * Prompt 10.2: osoba znaleziona na oficjalnej stronie firmy przy wyszukiwaniu telefonów decydentów — ta sama ścieżka
	 * co w pełnym wyszukiwaniu (deduplikacja po kluczu osoby, weryfikacja, confidence, Contact Priority Score), bez
	 * rankingu (pozycja ustalana przy widoku / kolejnym wyszukiwaniu). Telefon NIE jest przekazywany do oceny — znaleziony
	 * numer nie zmienia Contact Priority Score (numer zapisuje się wyłącznie jako powiązanie z dowodem).
	 *
	 * @param array<string, mixed> $company
	 * @param array<string, mixed> $candidate kandydat w formacie PeopleExtractor (name, title, role, source_*, excerpt)
	 * @return array{id: int, created: bool}
	 */
	public function persistWebsiteCandidate(array $company, array $candidate): array
	{
		$candidate = ['email' => null, 'role_email' => null, 'phone' => null, 'profile' => null, 'former' => false, 'other_entity' => null] + $candidate;
		$c = $this->merger->merge([$candidate])[0];
		$existing = $this->contacts->findByKey((int) $company['id'], 0, (string) $c['person_key']);

		if ($existing) {
			return ['id' => (int) $existing['id'], 'created' => false];
		}

		$c = $this->verifier->verify($c, $company, null);
		$conf = $this->confidence->assess($c, null, false);
		$score = $this->calculator->score($c, $conf, $company, false);
		$c['confidence'] = $conf;
		$c['confidence_level'] = $conf['level'];
		$c['contact_priority_score'] = $score['score'];
		$c['score_status'] = $score['status'];
		$c['score_breakdown'] = $score['breakdown'];
		$c['rank_position'] = null;
		$c['rank_reason'] = null;
		$changes = [];

		return ['id' => $this->persist((int) $company['id'], null, $c, $changes), 'created' => true];
	}

	/**
	 * Podsumowanie przy firmie (globalne — listy Discover / Prospects).
	 *
	 * @param array<string, mixed> $ranked
	 * @param list<array<string, string>> $channels
	 */
	/** Zapis wyników bez zdarzenia `contacts_done` (telefony osób wymagają zapisanych kontaktów). */
	private function syncCompanyQuiet(int $companyId, int $runId, string $status, array $ranked, array $channels): void
	{
		$this->syncCompany($companyId, $runId, $status, $ranked, $channels, false);
	}

	private function syncCompany(int $companyId, int $runId, string $status, array $ranked, array $channels, bool $notify = true): void
	{
		$top1 = null;

		foreach ($ranked['top'] as $c) {
			if ((int) $c['rank_position'] === 1) {
				$top1 = $c;
			}
		}

		$existing = $top1 ? $this->contacts->findByKey($companyId, 0, (string) $top1['person_key']) : null;

		$this->companies->update($companyId, [
			'contacts_status' => $status,
			'contacts_researched_at' => Db::now(),
			'contacts_count' => count($ranked['top']) + count($ranked['others']),
			'last_contact_run_id' => $runId,
			'top_contact_id' => $existing ? (int) $existing['id'] : null,
			'top_contact_name' => $top1['full_name'] ?? null,
			'top_contact_title' => $top1 ? mb_substr((string) $top1['job_title'], 0, 200) : null,
			'top_contact_role' => $top1['normalized_role'] ?? null,
			'top_contact_score' => $top1['contact_priority_score'] ?? null,
			'top_contact_confidence' => $top1['confidence_level'] ?? null,
			'company_channels' => wp_json_encode($channels),
		]);

		// Prompt 10.4.3 — ogólny e-mail firmy z kanałów, gdy weryfikacja go nie znalazła.
		$general = array_values(array_filter($channels, fn ($ch) => ($ch['type'] ?? '') === 'COMPANY_GENERAL_EMAIL'))[0]['value'] ?? null;
		$company = $general ? $this->companies->find($companyId) : null;

		if ($company && empty($company['general_email']) && is_email((string) $general)) {
			$this->companies->update($companyId, ['general_email' => (string) $general, 'general_email_source' => mb_substr((string) (array_values(array_filter($channels, fn ($ch) => ($ch['type'] ?? '') === 'COMPANY_GENERAL_EMAIL'))[0]['source_url'] ?? ''), 0, 500) ?: null, 'general_email_at' => Db::now()]);
		}

		if ($notify) {
			do_action('ubr/company/changed', $companyId, 'contacts_done', null);
		}
	}

	/**
	 * Adres działu (np. marketing@, zarzad@) przypisujemy osobie z TEGO działu, nie osobie stojącej najbliżej w kodzie
	 * strony. To nadal adres działu („nie osobisty”) — nie udajemy, że to bezpośredni kontakt.
	 *
	 * @param array<string, mixed> $c
	 * @param list<array<string, string>> $channels
	 * @return array<string, mixed>
	 */
	public static function assignDepartmentEmail(array $c, array $channels): array
	{
		$dept = match (\App\Ubr\Domain\Contacts\RoleCatalog::ROLES[$c['normalized_role']]['department'] ?? 'other') {
			'management' => 'management',
			'marketing' => 'marketing',
			'sales' => 'sales',
			default => null,
		};

		if (! empty($c['marketing_focus']) && $dept === null) {
			$dept = 'marketing';
		}

		$byValue = array_column($channels, null, 'value');
		$current = $c['role_email'] ?? null;

		// Adres z bloku osoby zostaje tylko, jeśli dotyczy jej działu.
		if ($current && isset($byValue[$current]) && $byValue[$current]['department'] !== $dept) {
			$current = null;
		}

		if (! $current && $dept) {
			foreach ($channels as $ch) {
				if ($ch['type'] === 'DEPARTMENT_EMAIL' && $ch['department'] === $dept) {
					$current = $ch['value'];
					break;
				}
			}
		}

		$c['role_email'] = $current;

		return $c;
	}

	/**
	 * @param array<string, mixed> $board
	 * @param array<string, mixed> $c
	 */
	private static function maskFor(array $board, array $c): string
	{
		foreach (array_merge($board['members'], $board['proxies']) as $m) {
			if ($m['function'] === $c['registry_role'] && (new PersonName((string) $c['first_name'], (string) $c['last_name']))->matchesMask((string) $m['first_mask'], (string) $m['last_mask'])) {
				return $m['first_mask'] . ' ' . $m['last_mask'];
			}
		}

		return '—';
	}
}
