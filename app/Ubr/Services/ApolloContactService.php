<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Contacts\ApolloMatcher;
use App\Ubr\Domain\Contacts\RoleCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Contacts\ApolloPeopleSource;
use App\Ubr\Infrastructure\Database\DatabaseException;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ContactRepository;
use App\Ubr\Support\UbrException;

/**
 * Apollo Contact Matching (Prompt 10.3).
 *
 * 1. „Szukaj decydentów w Apollo” — WYŁĄCZNIE ręcznie z karty firmy: domena firmy musi być ustalona i nie może należeć
 *    do innego podmiotu; najpierw cache (bez zapytania), zapytanie People API Search tylko przy włączonej funkcji
 *    (UBR_APOLLO_PEOPLE_ENABLED) i świadomej zgodzie użytkownika; blokada równoległych zapytań, 24 h przerwy na firmę,
 *    dzienny limit organizacji. Nigdy przy otwarciu karty, renderowaniu listy, filtrach ani synchronizacji.
 * 2. Kandydaci (`ubr_apollo_people`) — tylko osoby z identyfikatorem Apollo, bez duplikatów; osoby spoza firmy
 *    (inna domena / organizacja Apollo) zostają oznaczone i nie są dopasowywane.
 * 3. Dopasowanie (`ubr_apollo_matches`) — PRYWATNE dla organizacji, ApolloMatcher. EXACT (ten sam identyfikator Apollo
 *    albo pełne imię i nazwisko + firma + zgodny obszar stanowiska) zatwierdzane automatycznie; STRONG / POSSIBLE
 *    tylko decyzją użytkownika; NO_MATCH / CONFLICT nie mogą być zatwierdzone. Decyzji użytkownika ponowne wyszukanie
 *    nie nadpisuje. Identyfikator Apollo przypisany do kontaktu = dopasowanie APPROVED (kontakt wspólny bez zmian).
 * 4. Historia każdej zmiany (`ubr_apollo_match_history`).
 * Identyfikator osoby Apollo nie jest wysyłany do przeglądarki — interfejs posługuje się wewnętrznym id kandydata.
 */
class ApolloContactService
{
	public const COOLDOWN = DAY_IN_SECONDS;

	public const STATUSES = ['SUGGESTED', 'APPROVED', 'REJECTED', 'DETACHED', 'OBSOLETE'];

	public const STATUS_LABELS = [
		'SUGGESTED' => 'Propozycja',
		'APPROVED' => 'Zatwierdzone',
		'REJECTED' => 'Odrzucone',
		'DETACHED' => 'Odłączone',
		'OBSOLETE' => 'Nieaktualne',
	];

	public const AVAILABILITY_LABELS = [
		'AVAILABLE' => 'Apollo sygnalizuje numer bezpośredni — pobranie płatne',
		'UNKNOWN' => 'Dostępność telefonu nieznana',
		'UNAVAILABLE' => 'Apollo nie sygnalizuje numeru bezpośredniego',
		'LOCKED' => 'Numer zablokowany — wymaga odblokowania',
	];

	public static function dailyLimit(): int
	{
		return max(0, (int) (defined('UBR_APOLLO_PEOPLE_DAILY_LIMIT') ? constant('UBR_APOLLO_PEOPLE_DAILY_LIMIT') : 20));
	}

	/**
	 * Sygnał `has_direct_phone` z People API Search (pole NIEUDOKUMENTOWANE — obserwowane wartości: „Yes”,
	 * „Maybe: please request direct dial via people/bulk_match”). LOCKED nie jest ustawiany: API nie podaje tego wprost.
	 */
	public static function availability(mixed $signal): string
	{
		if (is_bool($signal)) {
			return $signal ? 'AVAILABLE' : 'UNAVAILABLE';
		}

		$s = strtolower(trim((string) $signal));

		return match (true) {
			in_array($s, ['yes', 'true'], true) => 'AVAILABLE',
			in_array($s, ['no', 'false'], true) => 'UNAVAILABLE',
			default => 'UNKNOWN',
		};
	}

	public function __construct(
		private Db $db,
		private CompanyRepository $companies,
		private ContactRepository $contacts,
		private ApolloPeopleSource $source,
	) {
	}

	/**
	 * „Szukaj decydentów w Apollo”. Bez `$confirm` i bez danych w cache — nic nie jest wysyłane (zwraca informację o zgodzie).
	 *
	 * @return array<string, mixed>
	 */
	public function search(TenantContext $ctx, int $companyId, bool $confirm): array
	{
		if (! $ctx->can('contacts.run')) {
			throw UbrException::forbidden();
		}

		$company = $this->visible($ctx, $companyId);
		$domain = self::domainOf($company);

		if ($domain === null) {
			throw UbrException::invalid('Firma nie ma ustalonej domeny — wyszukiwanie w Apollo wymaga domeny firmy.');
		}

		if (($company['domain_status'] ?? '') === 'mismatch') {
			throw UbrException::invalid('Domena należy do innego podmiotu — osoby z Apollo nie byłyby osobami tej firmy.');
		}

		$cachedAvailable = is_array(get_transient(ApolloPeopleSource::cacheKey($domain, ApolloPeopleSource::MATCHING_PER_PAGE)));
		$meta = ['domain' => $domain, 'executed' => false, 'cached' => false, 'state' => 'ok', 'message' => ''];

		if (! $cachedAvailable) {
			if (! ApolloPeopleSource::enabled()) {
				return $this->candidates($ctx, $companyId) + ['search' => ['state' => 'disabled', 'executed' => false, 'cached' => false, 'domain' => $domain, 'message' => ApolloProvider::apiKey() === null
					? 'Apollo nie jest skonfigurowane (brak klucza API). Nic nie zostało wysłane.'
					: 'Wyszukiwanie osób w Apollo jest wyłączone (UBR_APOLLO_PEOPLE_ENABLED w wp-config.php — wymaga decyzji właściciela). Nic nie zostało wysłane.']];
			}

			if (! $confirm) {
				return $this->candidates($ctx, $companyId) + ['search' => ['state' => 'confirm_required', 'executed' => false, 'cached' => false, 'domain' => $domain, 'message' => self::searchCostNote()]];
			}

			if (get_transient('ubr_apollo_search_done_' . $companyId)) {
				throw UbrException::limit('Osoby tej firmy były wyszukiwane w Apollo w ciągu ostatnich 24 godzin.');
			}

			$day = 'ubr_apollo_search_day_' . $ctx->organizationId . '_' . gmdate('Ymd');
			$used = (int) get_transient($day);

			if ($used >= self::dailyLimit()) {
				throw UbrException::limit(sprintf('Osiągnięto dzienny limit wyszukiwań osób w Apollo (%d firm).', self::dailyLimit()));
			}

			// Blokada równoległych zapytań o tę samą firmę (podwójne kliknięcie, dwie karty) — atomowo przez UNIQUE option_name.
			$lock = 'ubr_lock_apollo_people_' . $companyId;
			$since = (int) get_option($lock, 0);

			if ($since && $since < time() - 120) {
				delete_option($lock);
			}

			if (! add_option($lock, time(), '', false)) {
				throw UbrException::limit('Wyszukiwanie osób tej firmy w Apollo już trwa.');
			}

			try {
				set_transient($day, $used + 1, DAY_IN_SECONDS);
				$res = $this->source->people($domain, $ctx->organizationId, true);
			} finally {
				delete_option($lock);
			}

			if ($res['executed']) {
				set_transient('ubr_apollo_search_done_' . $companyId, 1, self::COOLDOWN);
			}

			if ($res['error']) {
				return $this->candidates($ctx, $companyId) + ['search' => ['state' => 'error', 'executed' => $res['executed'], 'cached' => false, 'domain' => $domain, 'message' => $res['error']]];
			}

			$meta['executed'] = $res['executed'];
		} else {
			$res = $this->source->people($domain, $ctx->organizationId, false);
			$meta['cached'] = true;
		}

		$apolloOrg = $this->apolloOrgId($companyId);
		$stored = $this->store($companyId, $domain, $apolloOrg, (array) ($res['data']['people'] ?? []), $ctx->organizationId);
		$this->rematch($ctx->organizationId, $companyId);
		$meta['found'] = $stored['found'];
		$meta['outside_company'] = $stored['outside'];
		$meta['message'] = ($meta['cached'] ? 'Wyniki z pamięci podręcznej (bez nowego zapytania do Apollo). ' : 'Wykonano jedno zapytanie People API Search. ')
			. sprintf('Osoby z Apollo: %d (spoza firmy: %d).', $stored['found'], $stored['outside']);

		return $this->candidates($ctx, $companyId) + ['search' => $meta];
	}

	public static function searchCostNote(): string
	{
		return 'Wg dokumentacji Apollo (10.2026) People API Search nie zużywa kredytów i nie zwraca e-maili ani numerów; wymaga klucza master. Koszt na Twoim koncie nie jest potwierdzony — potwierdź, że chcesz wysłać jedno zapytanie.';
	}

	/**
	 * Zapis kandydatów (idempotentny: firma + identyfikator osoby Apollo = jeden wiersz). Bez e-maili i numerów.
	 *
	 * @param list<mixed> $people
	 * @return array{found: int, outside: int}
	 */
	public function store(int $companyId, string $domain, ?string $apolloOrg, array $people, ?int $organizationId = null): array
	{
		$now = Db::now();
		$seen = [];
		$outside = 0;

		foreach ($people as $p) {
			$pid = is_array($p) ? (string) ($p['id'] ?? '') : '';

			if (! preg_match('/^[A-Za-z0-9]{6,64}$/', $pid) || isset($seen[$pid]) || trim((string) ($p['first_name'] ?? '')) === '') {
				continue;
			}

			$seen[$pid] = true;
			$last = trim((string) ($p['last_name'] ?? ''));
			$obf = trim((string) ($p['last_name_obfuscated'] ?? ''));
			$full = $last !== '' && ! str_contains($last, '*') ? $last : null;
			$masked = $obf !== '' ? $obf : ($last !== '' && str_contains($last, '*') ? $last : null);
			$title = mb_substr(trim((string) ($p['title'] ?? '')), 0, 200);
			$role = $title !== '' ? (RoleCatalog::match($title)['role'] ?? null) : null;
			$belongs = ApolloPeopleSource::belongsToCompany($p, $domain, $apolloOrg);
			$org = is_array($p['organization'] ?? null) ? $p['organization'] : [];
			$signal = $p['has_direct_phone'] ?? null;
			$outside += $belongs ? 0 : 1;

			$this->db->execute(
				"INSERT INTO {$this->db->table(Tables::APOLLO_PEOPLE)} (company_id, apollo_person_id, apollo_org_id, first_name, last_name, last_name_obfuscated, title, normalized_role, org_domain, belongs_to_company, phone_signal, phone_availability, has_email_signal, apollo_refreshed_at, found_at, last_seen_at, last_search_org, created_at, updated_at)
				VALUES (%d, %s, NULLIF(%s, ''), %s, NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), %d, NULLIF(%s, ''), %s, NULLIF(%s, ''), NULLIF(%s, ''), %s, %s, NULLIF(%d, 0), %s, %s)
				ON DUPLICATE KEY UPDATE apollo_org_id = COALESCE(VALUES(apollo_org_id), apollo_org_id), first_name = VALUES(first_name),
					last_name = COALESCE(VALUES(last_name), last_name), last_name_obfuscated = COALESCE(VALUES(last_name_obfuscated), last_name_obfuscated),
					title = VALUES(title), normalized_role = VALUES(normalized_role), org_domain = COALESCE(VALUES(org_domain), org_domain),
					belongs_to_company = VALUES(belongs_to_company), phone_signal = VALUES(phone_signal), phone_availability = VALUES(phone_availability),
					has_email_signal = VALUES(has_email_signal), apollo_refreshed_at = VALUES(apollo_refreshed_at), last_seen_at = VALUES(last_seen_at),
					last_search_org = VALUES(last_search_org), updated_at = VALUES(updated_at)",
				$companyId, $pid, mb_substr((string) ($p['organization_id'] ?? $org['id'] ?? ''), 0, 64), mb_substr(trim((string) $p['first_name']), 0, 100),
				mb_substr((string) $full, 0, 120), mb_substr((string) $masked, 0, 120), $title, (string) $role, mb_substr((string) ($org['primary_domain'] ?? ''), 0, 190),
				$belongs ? 1 : 0, mb_substr(is_bool($signal) ? ($signal ? 'true' : 'false') : (string) $signal, 0, 120), self::availability($signal),
				isset($p['has_email']) ? (string) (int) (bool) $p['has_email'] : '', mb_substr((string) ($p['last_refreshed_at'] ?? ''), 0, 40),
				$now, $now, (int) $organizationId, $now, $now
			);
		}

		return ['found' => count($seen), 'outside' => $outside];
	}

	/**
	 * Ponowne dopasowanie kandydatów firmy do kontaktów widocznych dla organizacji (bez zapytań do Apollo).
	 * Decyzje użytkownika (APPROVED / REJECTED / DETACHED) pozostają bez zmian.
	 *
	 * @return array<string, int> liczba propozycji wg poziomu
	 */
	public function rematch(int $organizationId, int $companyId, ?int $onlyCandidate = null): array
	{
		$candidates = $this->db->rows("SELECT * FROM {$this->db->table(Tables::APOLLO_PEOPLE)} WHERE company_id = %d" . ($onlyCandidate ? ' AND id = ' . (int) $onlyCandidate : '') . ' ORDER BY id', $companyId);
		$counts = array_fill_keys(ApolloMatcher::LEVELS, 0);

		foreach ($candidates as $cand) {
			[$contacts, $approvedByPerson] = $this->matchContext($organizationId, $companyId);
			$existing = [];

			foreach ($this->db->rows("SELECT * FROM {$this->mt()} WHERE organization_id = %d AND candidate_id = %d", $organizationId, (int) $cand['id']) as $row) {
				$existing[(int) $row['contact_id']] = $row;
			}

			$results = ApolloMatcher::match($cand, $contacts, $approvedByPerson);
			$produced = [];

			foreach ($results as $m) {
				$cid = (int) $m['contact_id'];
				$produced[$cid] = true;
				$counts[$m['level']]++;
				$row = $existing[$cid] ?? null;
				$evidence = wp_json_encode($m['evidence'], JSON_UNESCAPED_UNICODE);

				if ($row && in_array($row['match_status'], ['APPROVED', 'REJECTED', 'DETACHED'], true)) {
					// Decyzja użytkownika — tylko aktualny poziom i dowody (widoczne ostrzeżenie przy konflikcie), status bez zmian.
					if ($row['match_level'] !== $m['level']) {
						$this->db->update(Tables::APOLLO_MATCHES, ['match_level' => $m['level'], 'evidence' => $evidence, 'last_verified_at' => Db::now(), 'updated_at' => Db::now()], ['id' => (int) $row['id']]);
						$this->history((int) $row['id'], $organizationId, 'level_change', $row['match_status'], $row['match_status'], $m['level'], $cid, null, 'Poziom ' . $row['match_level'] . ' → ' . $m['level'] . ' po ponownym dopasowaniu.');
					}

					continue;
				}

				$autoApprove = $m['level'] === ApolloMatcher::EXACT && $cid > 0;
				$status = $autoApprove ? 'APPROVED' : 'SUGGESTED';

				if ($row) {
					if ($row['match_level'] !== $m['level'] || $row['match_status'] !== $status || $row['evidence'] !== $evidence) {
						$this->db->update(Tables::APOLLO_MATCHES, ['match_level' => $m['level'], 'match_status' => $status, 'evidence' => $evidence, 'last_verified_at' => Db::now(), 'updated_at' => Db::now()] + ($autoApprove ? ['decided_at' => Db::now(), 'decided_by' => null] : []), ['id' => (int) $row['id']]);
						$this->history((int) $row['id'], $organizationId, $autoApprove ? 'auto_approve' : 'rematch', $row['match_status'], $status, $m['level'], $cid, null, $autoApprove ? 'Dopasowanie pewne — zatwierdzone automatycznie.' : null);
					}

					continue;
				}

				$now = Db::now();

				try {
					$id = $this->db->insert(Tables::APOLLO_MATCHES, [
						'organization_id' => $organizationId, 'company_id' => $companyId, 'candidate_id' => (int) $cand['id'], 'apollo_person_id' => (string) $cand['apollo_person_id'],
						'contact_id' => $cid, 'match_level' => $m['level'], 'match_status' => $status, 'match_source' => 'PEOPLE_SEARCH', 'evidence' => $evidence,
						'matched_at' => $now, 'decided_at' => $autoApprove ? $now : null, 'last_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
					]);
				} catch (DatabaseException $e) {
					// Równoległe dopasowanie tej samej pary (UNIQUE) — wiersz już istnieje, bez duplikatu.
					if (! $e->duplicate) {
						throw $e;
					}

					continue;
				}

				if ($id) {
					$this->history($id, $organizationId, $autoApprove ? 'auto_approve' : 'suggest', null, $status, $m['level'], $cid, null, $autoApprove ? 'Dopasowanie pewne — zatwierdzone automatycznie.' : null);
				}
			}

			// Propozycje, których dopasowanie już nie zwraca (np. dodano kontakt) — oznaczone jako nieaktualne, nie usuwane.
			foreach ($existing as $cid => $row) {
				if (! isset($produced[$cid]) && $row['match_status'] === 'SUGGESTED') {
					$this->db->update(Tables::APOLLO_MATCHES, ['match_status' => 'OBSOLETE', 'updated_at' => Db::now()], ['id' => (int) $row['id']]);
					$this->history((int) $row['id'], $organizationId, 'obsolete', 'SUGGESTED', 'OBSOLETE', $row['match_level'], $cid, null, 'Propozycja nieaktualna po ponownym dopasowaniu.');
				}
			}
		}

		return $counts;
	}

	/**
	 * Kandydaci Apollo firmy z dopasowaniami organizacji (tylko odczyt — bez zapytań do Apollo).
	 *
	 * @return array<string, mixed>
	 */
	public function candidates(TenantContext $ctx, int $companyId): array
	{
		if (! $ctx->can('contacts.view')) {
			throw UbrException::forbidden();
		}

		$company = $this->visible($ctx, $companyId);
		$people = $this->db->rows("SELECT * FROM {$this->db->table(Tables::APOLLO_PEOPLE)} WHERE company_id = %d ORDER BY belongs_to_company DESC, id", $companyId);
		$matches = $this->db->rows("SELECT * FROM {$this->mt()} WHERE organization_id = %d AND company_id = %d AND match_status <> 'OBSOLETE' ORDER BY id", $ctx->organizationId, $companyId);
		$contacts = [];

		foreach ($this->contacts->forCompany($companyId, $ctx->organizationId) as $c) {
			$contacts[(int) $c['id']] = $c;
		}

		$byCand = [];

		foreach ($matches as $m) {
			$byCand[(int) $m['candidate_id']][] = $m;
		}

		$rankLevel = array_flip(ApolloMatcher::LEVELS);
		$rankStatus = ['APPROVED' => 0, 'SUGGESTED' => 1, 'DETACHED' => 2, 'REJECTED' => 3];
		$out = [];

		foreach ($people as $p) {
			$list = [];

			foreach ($byCand[(int) $p['id']] ?? [] as $m) {
				$c = $contacts[(int) $m['contact_id']] ?? null;

				if ((int) $m['contact_id'] > 0 && ! $c) {
					continue; // kontakt nieaktywny / niewidoczny dla organizacji
				}

				$list[] = $this->presentMatch($m, $c);
			}

			usort($list, fn ($a, $b) => [$rankStatus[$a['status']] ?? 9, $rankLevel[$a['level']] ?? 9] <=> [$rankStatus[$b['status']] ?? 9, $rankLevel[$b['level']] ?? 9]);
			$masked = $p['last_name'] === null || $p['last_name'] === '';
			$availability = (string) $p['phone_availability'];
			$out[] = [
				'id' => (int) $p['id'],
				'first_name' => $p['first_name'],
				'display_name' => trim($p['first_name'] . ' ' . ($masked ? (string) $p['last_name_obfuscated'] : (string) $p['last_name'])),
				'name_masked' => $masked,
				'title' => $p['title'],
				'role' => $p['normalized_role'],
				'role_label' => $p['normalized_role'] ? RoleCatalog::label((string) $p['normalized_role']) : null,
				'belongs_to_company' => (bool) $p['belongs_to_company'],
				'source' => 'Apollo People API Search',
				'phone_availability' => $availability,
				'phone_availability_label' => self::AVAILABILITY_LABELS[$availability] ?? self::AVAILABILITY_LABELS['UNKNOWN'],
				'found_at' => $p['found_at'],
				'last_seen_at' => $p['last_seen_at'],
				'matches' => $list,
				'top' => $list[0] ?? null,
				'approved' => array_values(array_filter($list, fn ($m) => $m['status'] === 'APPROVED'))[0] ?? null,
			];
		}

		return [
			'company' => ['id' => $companyId, 'name' => $company['name'], 'domain' => self::domainOf($company)],
			'candidates' => $out,
			'contacts' => array_values(array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['full_name'], 'title' => $c['job_title']], $contacts)),
			'enabled' => ApolloPeopleSource::enabled(),
			'configured' => ApolloProvider::apiKey() !== null,
			'can_search' => $ctx->can('contacts.run'),
			'can_decide' => $ctx->can('contacts.manage'),
			'cost_note' => self::searchCostNote(),
			'labels' => ['levels' => ApolloMatcher::LABELS, 'statuses' => self::STATUS_LABELS],
		];
	}

	/**
	 * Decyzja użytkownika o dopasowaniu: approve / reject / detach / recheck; link — ręczne połączenie kandydata
	 * z wybranym kontaktem (tylko gdy imię i nazwisko nie przeczą danym Apollo).
	 *
	 * @return array<string, mixed> aktualna lista kandydatów firmy
	 */
	public function decide(TenantContext $ctx, string $action, int $matchId, int $candidateId = 0, int $contactId = 0, string $note = ''): array
	{
		if (! $ctx->can('contacts.manage')) {
			throw UbrException::forbidden();
		}

		$note = mb_substr(trim(wp_strip_all_tags($note)), 0, 300);

		if ($action === 'link') {
			$cand = $this->db->row("SELECT * FROM {$this->db->table(Tables::APOLLO_PEOPLE)} WHERE id = %d", $candidateId);

			if (! $cand) {
				throw UbrException::notFound('Nie znaleziono kandydata Apollo.');
			}

			$this->visible($ctx, (int) $cand['company_id']);
			$this->link($ctx, $cand, $contactId, $note);

			return $this->candidates($ctx, (int) $cand['company_id']);
		}

		$m = $this->db->row("SELECT * FROM {$this->mt()} WHERE id = %d AND organization_id = %d", $matchId, $ctx->organizationId);

		if (! $m) {
			throw UbrException::notFound('Nie znaleziono dopasowania.');
		}

		$this->visible($ctx, (int) $m['company_id']);
		$id = (int) $m['id'];
		$cid = (int) $m['contact_id'];

		switch ($action) {
			case 'approve':
				if ($cid === 0 || in_array($m['match_level'], [ApolloMatcher::NO_MATCH, ApolloMatcher::CONFLICT], true)) {
					throw UbrException::invalid('Tego dopasowania nie można zatwierdzić (brak kontaktu albo konflikt danych).');
				}

				if ($m['match_status'] === 'APPROVED') {
					break; // idempotentnie
				}

				$this->assertAssignable($ctx->organizationId, (string) $m['apollo_person_id'], $cid, $id);
				$this->setStatus($m, 'APPROVED', $ctx->userId, 'approve', $note);
				break;

			case 'reject':
				if ($m['match_status'] !== 'REJECTED') {
					$this->setStatus($m, 'REJECTED', $ctx->userId, 'reject', $note);
				}

				break;

			case 'detach':
				if ($m['match_status'] !== 'APPROVED') {
					throw UbrException::invalid('Odłączyć można tylko zatwierdzone dopasowanie.');
				}

				$this->setStatus($m, 'DETACHED', $ctx->userId, 'detach', $note);
				break;

			case 'recheck':
				$this->rematch($ctx->organizationId, (int) $m['company_id'], (int) $m['candidate_id']);
				$this->db->update(Tables::APOLLO_MATCHES, ['last_verified_at' => Db::now()], ['id' => $id]);
				$this->history($id, $ctx->organizationId, 'recheck', $m['match_status'], (string) $this->db->value("SELECT match_status FROM {$this->mt()} WHERE id = %d", $id), (string) $this->db->value("SELECT match_level FROM {$this->mt()} WHERE id = %d", $id), $cid, $ctx->userId, 'Ponowne sprawdzenie dopasowania (bez zapytania do Apollo).');
				break;

			default:
				throw UbrException::invalid('Nieznana akcja.');
		}

		ActionCenterService::bumpOrganization($ctx->organizationId);

		return $this->candidates($ctx, (int) $m['company_id']);
	}

	/**
	 * @param array<string, mixed> $cand
	 */
	private function link(TenantContext $ctx, array $cand, int $contactId, string $note): void
	{
		$contact = $this->contacts->find($contactId);

		if (! $contact || (int) $contact['company_id'] !== (int) $cand['company_id'] || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true) || empty($contact['active'])) {
			throw UbrException::notFound('Nie znaleziono kontaktu tej firmy.');
		}

		[$contacts, $approvedByPerson] = $this->matchContext($ctx->organizationId, (int) $cand['company_id']);
		$mine = array_values(array_filter($contacts, fn ($c) => (int) $c['id'] === $contactId));
		$result = array_values(array_filter(ApolloMatcher::match($cand, $mine, $approvedByPerson), fn ($m) => (int) $m['contact_id'] === $contactId))[0] ?? null;

		if (! $result) {
			throw UbrException::invalid('Imię lub nazwisko kontaktu nie zgadza się z danymi Apollo — połączenie wymagałoby zgadywania tożsamości.');
		}

		if ($result['level'] === ApolloMatcher::CONFLICT) {
			throw new UbrException('Konflikt: ' . end($result['evidence']), 'ubr_conflict', 409);
		}

		$existing = $this->db->row("SELECT * FROM {$this->mt()} WHERE organization_id = %d AND candidate_id = %d AND contact_id = %d", $ctx->organizationId, (int) $cand['id'], $contactId);
		$this->assertAssignable($ctx->organizationId, (string) $cand['apollo_person_id'], $contactId, (int) ($existing['id'] ?? 0));
		$evidence = wp_json_encode(array_merge($result['evidence'], ['Połączone ręcznie przez użytkownika.']), JSON_UNESCAPED_UNICODE);
		$now = Db::now();

		if ($existing) {
			if ($existing['match_status'] === 'APPROVED') {
				return;
			}

			$this->db->update(Tables::APOLLO_MATCHES, ['match_level' => $result['level'], 'match_source' => 'MANUAL', 'evidence' => $evidence, 'updated_at' => $now], ['id' => (int) $existing['id']]);
			$existing['match_level'] = $result['level'];
			$this->setStatus($existing, 'APPROVED', $ctx->userId, 'manual_link', $note);

			return;
		}

		$id = $this->db->insert(Tables::APOLLO_MATCHES, [
			'organization_id' => $ctx->organizationId, 'company_id' => (int) $cand['company_id'], 'candidate_id' => (int) $cand['id'], 'apollo_person_id' => (string) $cand['apollo_person_id'],
			'contact_id' => $contactId, 'match_level' => $result['level'], 'match_status' => 'APPROVED', 'match_source' => 'MANUAL', 'evidence' => $evidence,
			'matched_at' => $now, 'decided_by' => $ctx->userId, 'decided_at' => $now, 'last_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
		]);
		$this->history($id, $ctx->organizationId, 'manual_link', null, 'APPROVED', $result['level'], $contactId, $ctx->userId, $note ?: null);
		ActionCenterService::bumpOrganization($ctx->organizationId);
	}

	/**
	 * Identyfikator Apollo nie może trafić do dwóch kontaktów, a kontakt nie może mieć dwóch identyfikatorów (409).
	 */
	private function assertAssignable(int $organizationId, string $apolloPersonId, int $contactId, int $exceptMatchId): void
	{
		$other = $this->db->row("SELECT id, contact_id FROM {$this->mt()} WHERE organization_id = %d AND apollo_person_id = %s AND match_status = 'APPROVED' AND contact_id <> %d AND id <> %d LIMIT 1", $organizationId, $apolloPersonId, $contactId, $exceptMatchId);

		if ($other) {
			throw new UbrException('Ten identyfikator Apollo jest już przypisany do innego kontaktu — najpierw odłącz tamto dopasowanie.', 'ubr_conflict', 409);
		}

		$second = $this->db->value("SELECT id FROM {$this->mt()} WHERE organization_id = %d AND contact_id = %d AND match_status = 'APPROVED' AND apollo_person_id <> %s AND id <> %d LIMIT 1", $organizationId, $contactId, $apolloPersonId, $exceptMatchId);
		$contact = $this->contacts->find($contactId);
		$own = ($contact['external_provider'] ?? '') === 'apollo' ? (string) ($contact['external_id'] ?? '') : '';

		if ($second || ($own !== '' && $own !== $apolloPersonId)) {
			throw new UbrException('Ten kontakt ma już inny identyfikator Apollo — najpierw odłącz poprzednie dopasowanie.', 'ubr_conflict', 409);
		}
	}

	/**
	 * @param array<string, mixed> $m
	 */
	private function setStatus(array $m, string $status, int $userId, string $change, string $note): void
	{
		$now = Db::now();
		$this->db->update(Tables::APOLLO_MATCHES, ['match_status' => $status, 'decided_by' => $userId, 'decided_at' => $now, 'updated_at' => $now], ['id' => (int) $m['id']]);
		$this->history((int) $m['id'], (int) $m['organization_id'], $change, $m['match_status'], $status, $m['match_level'], (int) $m['contact_id'], $userId, $note ?: null);
	}

	private function history(int $matchId, int $organizationId, string $change, ?string $from, string $to, ?string $level, ?int $contactId, ?int $userId, ?string $note): void
	{
		$this->db->insert(Tables::APOLLO_MATCH_HISTORY, [
			'match_id' => $matchId, 'organization_id' => $organizationId, 'change_type' => $change, 'from_status' => $from, 'to_status' => $to,
			'match_level' => $level, 'contact_id' => $contactId, 'user_id' => $userId, 'note' => $note !== null ? mb_substr($note, 0, 300) : null, 'created_at' => Db::now(),
		]);
	}

	/**
	 * Historia dopasowań kandydata (dla organizacji).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function historyFor(TenantContext $ctx, int $matchId): array
	{
		if (! $ctx->can('contacts.view')) {
			throw UbrException::forbidden();
		}

		return $this->db->rows("SELECT change_type, from_status, to_status, match_level, contact_id, user_id, note, created_at FROM {$this->db->table(Tables::APOLLO_MATCH_HISTORY)} WHERE match_id = %d AND organization_id = %d ORDER BY id", $matchId, $ctx->organizationId);
	}

	/**
	 * Identyfikator osoby Apollo dla kontaktu w organizacji: zatwierdzone dopasowanie, a bez niego identyfikator
	 * zapisany przy kontakcie (np. osoba dodana z Apollo przez Decision Maker Findera). Odłączone / odrzucone — brak.
	 */
	public function apolloIdFor(int $organizationId, int $contactId): ?string
	{
		$approved = $this->db->value("SELECT apollo_person_id FROM {$this->mt()} WHERE organization_id = %d AND contact_id = %d AND match_status = 'APPROVED' ORDER BY id DESC LIMIT 1", $organizationId, $contactId);

		if ($approved) {
			return (string) $approved;
		}

		$contact = $this->contacts->find($contactId);

		if (($contact['external_provider'] ?? '') !== 'apollo' || empty($contact['external_id'])) {
			return null;
		}

		// Identyfikator przy kontakcie odrzucony / odłączony w organizacji — nie używamy go.
		$revoked = $this->db->value("SELECT 1 FROM {$this->mt()} WHERE organization_id = %d AND contact_id = %d AND apollo_person_id = %s AND match_status IN ('REJECTED', 'DETACHED') LIMIT 1", $organizationId, $contactId, (string) $contact['external_id']);

		return $revoked ? null : (string) $contact['external_id'];
	}

	/**
	 * Zatwierdzone dopasowania kontaktów (lista / karta firmy) — jednym zapytaniem, bez wywołań Apollo.
	 *
	 * @param list<int> $contactIds
	 * @return array<int, array<string, mixed>> contact_id → availability, availability_label, checked_at, level
	 */
	public function approvedFor(int $organizationId, array $contactIds): array
	{
		$contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));

		if ($contactIds === []) {
			return [];
		}

		$rows = $this->db->rows(
			"SELECT m.contact_id, m.match_level, m.id AS match_id, p.phone_availability, p.last_seen_at FROM {$this->mt()} m
			JOIN {$this->db->table(Tables::APOLLO_PEOPLE)} p ON p.id = m.candidate_id
			WHERE m.organization_id = %d AND m.match_status = 'APPROVED' AND m.contact_id IN (" . Db::placeholders($contactIds) . ') ORDER BY m.id',
			...array_merge([$organizationId], $contactIds)
		);
		$out = [];

		foreach ($rows as $r) {
			$a = (string) $r['phone_availability'];
			$out[(int) $r['contact_id']] = [
				'match_id' => (int) $r['match_id'], 'level' => $r['match_level'], 'availability' => $a,
				'availability_label' => self::AVAILABILITY_LABELS[$a] ?? self::AVAILABILITY_LABELS['UNKNOWN'], 'checked_at' => $r['last_seen_at'],
			];
		}

		return $out;
	}

	/**
	 * Retencja: kandydaci niewidziani w Apollo od `$days` dni i bez zatwierdzonego dopasowania (CLI, nie automatycznie).
	 */
	public function purgeStale(int $days): int
	{
		$people = $this->db->table(Tables::APOLLO_PEOPLE);

		return $this->db->execute(
			"DELETE p FROM {$people} p WHERE p.last_seen_at < %s AND NOT EXISTS (SELECT 1 FROM {$this->mt()} m WHERE m.candidate_id = p.id AND m.match_status = 'APPROVED')",
			gmdate('Y-m-d H:i:s', time() - max(30, $days) * DAY_IN_SECONDS)
		);
	}

	/**
	 * @param array<string, mixed> $m
	 * @param array<string, mixed>|null $c
	 * @return array<string, mixed>
	 */
	private function presentMatch(array $m, ?array $c): array
	{
		$evidence = json_decode((string) $m['evidence'], true);

		return [
			'id' => (int) $m['id'],
			'contact_id' => (int) $m['contact_id'],
			'contact_name' => $c['full_name'] ?? null,
			'contact_title' => $c['job_title'] ?? null,
			'level' => $m['match_level'],
			'level_label' => ApolloMatcher::LABELS[$m['match_level']] ?? $m['match_level'],
			'status' => $m['match_status'],
			'status_label' => self::STATUS_LABELS[$m['match_status']] ?? $m['match_status'],
			'source' => $m['match_source'],
			'evidence' => is_array($evidence) ? array_values(array_map('strval', $evidence)) : [],
			'decided_at' => $m['decided_at'],
			'decided_by_system' => $m['match_status'] === 'APPROVED' && $m['decided_by'] === null,
			'last_verified_at' => $m['last_verified_at'],
			'can_approve' => (int) $m['contact_id'] > 0 && $m['match_status'] !== 'APPROVED' && ! in_array($m['match_level'], [ApolloMatcher::NO_MATCH, ApolloMatcher::CONFLICT], true),
			'can_reject' => $m['match_status'] !== 'REJECTED' && (int) $m['contact_id'] > 0,
			'can_detach' => $m['match_status'] === 'APPROVED',
		];
	}

	/**
	 * Kontakty firmy widoczne dla organizacji z identyfikatorami zatwierdzonymi w organizacji + mapa identyfikator → kontakt.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
	 */
	private function matchContext(int $organizationId, int $companyId): array
	{
		$approved = $this->db->rows("SELECT contact_id, apollo_person_id FROM {$this->mt()} WHERE organization_id = %d AND match_status = 'APPROVED' AND contact_id > 0", $organizationId);
		$byContact = [];
		$byPerson = [];

		foreach ($approved as $a) {
			$byContact[(int) $a['contact_id']] = (string) $a['apollo_person_id'];
			$byPerson[(string) $a['apollo_person_id']] = (int) $a['contact_id'];
		}

		$contacts = [];

		foreach ($this->contacts->forCompany($companyId, $organizationId) as $c) {
			$c['approved_apollo_id'] = $byContact[(int) $c['id']] ?? null;
			$contacts[] = $c;
		}

		return [$contacts, $byPerson];
	}

	private function apolloOrgId(int $companyId): ?string
	{
		$v = $this->db->value("SELECT external_id FROM {$this->db->table(Tables::COMPANY_EXTERNAL_IDS)} WHERE company_id = %d AND provider = 'apollo' LIMIT 1", $companyId);

		return $v ? (string) $v : null;
	}

	/**
	 * @param array<string, mixed> $company
	 */
	public static function domainOf(array $company): ?string
	{
		$d = strtolower(trim((string) ($company['domain'] ?? '')));

		if ($d === '' && ! empty($company['website_url'])) {
			$d = strtolower((string) parse_url((string) $company['website_url'], PHP_URL_HOST));
		}

		$d = (string) preg_replace('/^www\./', '', $d);

		return preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d) ? $d : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function visible(TenantContext $ctx, int $companyId): array
	{
		$company = $this->companies->find($companyId);

		if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		return $company;
	}

	private function mt(): string
	{
		return $this->db->table(Tables::APOLLO_MATCHES);
	}
}
