<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Analysis\HtmlDocument;
use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Domain\Contacts\RoleCatalog;
use App\Ubr\Domain\Phones\PersonPhoneExtractor;
use App\Ubr\Domain\Phones\PhoneNumber;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Contacts\ContactPageCrawler;
use App\Ubr\Infrastructure\Contacts\RobotsPolicy;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Http\PageFetcher;
use App\Ubr\Infrastructure\Http\UrlGuard;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ContactPhoneLinkRepository;
use App\Ubr\Infrastructure\Repositories\ContactRepository;
use App\Ubr\Support\Redactor;
use App\Ubr\Support\UbrException;
use Throwable;

/**
 * Direct Decision Maker Phones (Prompt 10.2): bezpośrednie służbowe numery decydentów z oficjalnej strony firmy.
 *
 * - Pobrania: TYLKO strony, z których pochodzą zapisane numery firmy (maks. 3), oraz maks. 2 podstrony
 *   „zarząd / zespół / media” z nich podlinkowane — przez PageFetcher (SSRF, limit rozmiaru), robots.txt,
 *   blokada 10 min na firmę. Bez ponownego wyszukiwania firm, bez Website Analyzera, bez Apollo.
 * - Dowód powiązania numer ↔ osoba z PersonPhoneExtractor (VERIFIED / LIKELY / UNCERTAIN / REJECTED).
 * - Dopasowanie do istniejących kontaktów WYŁĄCZNIE po firmie + kluczu imienia i nazwiska (bez „podobnych” nazwisk).
 *   Nowa osoba trafia do bazy tylko, gdy ma rolę decydenta (CallReadiness::DECISION_ROLES) z oficjalnej strony —
 *   przez tę samą ścieżkę co Decision Maker Finder (źródło, weryfikacja, Contact Priority Score bez telefonu).
 * - Właściciel każdego numeru firmy (PERSON_DIRECT / DEPARTMENT / COMPANY_GENERAL / BRANCH / UNCERTAIN) przy numerze.
 * - Decyzje organizacji (potwierdź / odrzuć / przypisz / usuń) — prywatne, nigdy nie nadpisywane ekstrakcją;
 *   usunięcie powiązania nie usuwa numeru firmy.
 * Znaleziony numer nie zmienia Lead Potential, Opportunity ani Contact Priority Score.
 */
class DecisionPhoneService
{
	public const COOLDOWN = 600;

	public const MAX_SOURCE_PAGES = 3;

	public const MAX_TEAM_PAGES = 2;

	public const SOURCE_LABELS = ['WEBSITE' => 'Strona firmowa', 'MANUAL' => 'Ręcznie potwierdzony', 'APOLLO' => 'Apollo'];

	/** Adresy e-mail z fragmentów stron NIE są zapisywane ani pokazywane (bez pozyskiwania e-maili osób). */
	public static function redact(?string $text): string
	{
		return trim((string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[e-mail]', (string) $text));
	}

	public static function dailyLimit(): int
	{
		return max(1, (int) (defined('UBR_DM_PHONES_DAILY_LIMIT') ? constant('UBR_DM_PHONES_DAILY_LIMIT') : 80));
	}

	public function __construct(
		private Db $db,
		private CompanyRepository $companies,
		private ContactRepository $contacts,
		private ContactPhoneLinkRepository $links,
		private PageFetcher $fetcher,
		private PersonPhoneExtractor $extractor,
	) {
	}

	/**
	 * Wyszukanie telefonów decydentów dla jednej firmy (ręcznie / walidacja). $dryRun — analiza bez zapisu.
	 *
	 * @return array<string, mixed>
	 */
	public function scan(TenantContext $ctx, int $companyId, bool $dryRun = false): array
	{
		if (! $ctx->can('contacts.run')) {
			throw UbrException::forbidden();
		}

		$company = $this->visible($ctx, $companyId);

		if (empty($company['website_url'])) {
			throw UbrException::invalid('Firma nie ma strony WWW.');
		}

		if (($company['domain_status'] ?? '') === 'mismatch') {
			throw UbrException::invalid('Domena należy do innego podmiotu — osoby i numery z tej strony nie są przypisywane do firmy.');
		}

		$lock = 'ubr_dm_phones_' . $companyId;

		if (get_transient($lock)) {
			throw UbrException::invalid('Telefony decydentów tej firmy były sprawdzane przed chwilą — spróbuj ponownie za kilka minut.');
		}

		// Limit dzienny organizacji (ręczne sprawdzenia; maks. 5 stron na firmę).
		$day = 'ubr_dm_phones_day_' . $ctx->organizationId . '_' . gmdate('Ymd');
		$used = (int) get_transient($day);

		if ($used >= self::dailyLimit()) {
			throw UbrException::invalid(sprintf('Osiągnięto dzienny limit sprawdzeń telefonów decydentów (%d firm).', self::dailyLimit()));
		}

		set_transient($day, $used + 1, DAY_IN_SECONDS);
		set_transient($lock, 1, self::COOLDOWN);

		$robots = app(RobotsPolicy::class);
		$requests = 0;
		$rules = $robots->rulesFor((string) $company['website_url'], $requests);
		$pages = [];
		$docs = [];
		$seen = [];

		foreach ($this->sourceUrls($companyId, (string) $company['website_url']) as $url) {
			$this->fetchInto($url, 'source', $rules, $robots, $pages, $docs, $seen);
		}

		// Podstrony „zarząd / zespół / media” podlinkowane ze sprawdzonych stron (maks. 2).
		$team = [];

		foreach ($docs as $d) {
			foreach (app(ContactPageCrawler::class)->candidates(new HtmlDocument($d['html'], $d['url']), $d['url'], array_keys($seen)) as $cand) {
				if (in_array($cand['kind'], ['management', 'team', 'press'], true) && ! isset($seen[self::norm($cand['url'])])) {
					$team[self::norm($cand['url'])] ??= $cand;
				}
			}
		}

		uasort($team, fn ($a, $b) => [$b['score'], strlen($a['url'])] <=> [$a['score'], strlen($b['url'])]);

		foreach (array_slice($team, 0, self::MAX_TEAM_PAGES) as $cand) {
			$this->fetchInto($cand['url'], $cand['kind'], $rules, $robots, $pages, $docs, $seen);
		}

		$report = $this->process($company, $docs, true, $dryRun);
		$report['pages'] = $pages;
		$report['dry_run'] = $dryRun;
		$report['message'] = $docs === []
			? 'Nie udało się pobrać żadnej strony firmy.'
			: sprintf('Sprawdzono stron: %d. Osoby z numerem: %d (potwierdzone: %d, prawdopodobne: %d, do weryfikacji: %d, odrzucone: %d).', count($docs), $report['summary']['people_with_phone'], $report['summary']['links']['VERIFIED'], $report['summary']['links']['LIKELY'], $report['summary']['links']['UNCERTAIN'], $report['summary']['links']['REJECTED']);

		if (! $dryRun) {
			ActionCenterService::bumpGlobal();
		}

		return $report;
	}

	/**
	 * Strony już pobrane przez Decision Maker Findera — bez nowych zapytań, bez tworzenia osób (robi to wyszukiwanie).
	 *
	 * @param list<array{html: string, url: string}> $docs
	 */
	public function fromDocuments(int $companyId, array $docs): void
	{
		try {
			$company = $this->companies->find($companyId);

			if ($company && ($company['domain_status'] ?? '') !== 'mismatch') {
				$this->process($company, $docs, false, false);
				ActionCenterService::bumpGlobal();
			}
		} catch (Throwable $e) {
			Redactor::log('Decision phones #' . $companyId . ': ' . $e->getMessage());
		}
	}

	/**
	 * @param array<string, mixed> $company
	 * @param list<array{html: string, url: string}> $docs
	 * @return array<string, mixed>
	 */
	public function process(array $company, array $docs, bool $createContacts, bool $dryRun): array
	{
		$companyId = (int) $company['id'];
		$phones = $this->db->rows("SELECT id, e164, phone_type, is_primary, branch_label FROM {$this->db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d AND org_key = 0", $companyId);
		$byNumber = array_column($phones, null, 'e164');
		$general = array_values(array_map(fn ($p) => (string) $p['e164'], array_filter($phones, fn ($p) => (int) $p['is_primary'] === 1 || $p['phone_type'] === 'MAIN')));
		$branch = array_values(array_map(fn ($p) => (string) $p['e164'], array_filter($phones, fn ($p) => $p['phone_type'] === 'BRANCH')));

		$people = [];
		$numbers = [];
		$classRank = ['PERSON_DIRECT' => 5, 'BRANCH' => 4, 'COMPANY_GENERAL' => 3, 'DEPARTMENT' => 2, 'UNCERTAIN' => 1];
		$statusRank = ['REJECTED' => 4, 'VERIFIED' => 3, 'LIKELY' => 2, 'UNCERTAIN' => 1];

		foreach ($docs as $d) {
			$found = $this->extractor->extract((string) $d['html'], (string) $d['url'], $general, $branch);

			foreach ($found['people'] as $p) {
				$key = $p['key'];

				if (! isset($people[$key])) {
					$people[$key] = $p + ['phones_by' => []];
				} else {
					$people[$key]['mentions'] += $p['mentions'];
				}

				if ($people[$key]['role'] === null && $p['role'] !== null) {
					[$people[$key]['role'], $people[$key]['title'], $people[$key]['source_url'], $people[$key]['excerpt']] = [$p['role'], $p['title'], $p['source_url'], $p['excerpt']];
				}

				foreach ($p['phones'] as $ph) {
					$old = $people[$key]['phones_by'][$ph['e164']] ?? null;

					if ($old === null || $statusRank[$ph['status']] > $statusRank[$old['status']]) {
						$people[$key]['phones_by'][$ph['e164']] = $ph + ['source_url' => $d['url']];
					}
				}
			}

			foreach ($found['numbers'] as $e164 => $n) {
				if (! isset($numbers[$e164]) || $classRank[$n['class']] > $classRank[$numbers[$e164]['class']]) {
					$numbers[$e164] = $n;
				}
			}
		}

		$existing = [];

		// Ta sama firma + ten sam klucz imienia i nazwiska (bez diakrytyków). Bez „podobnych” nazwisk.
		foreach ($this->contacts->forCompany($companyId, null) as $c) {
			if ((int) $c['org_key'] === 0) {
				$existing[(string) $c['person_key']] ??= $c;
				$parsed = \App\Ubr\Domain\Contacts\PersonName::parse((string) $c['full_name']);

				if ($parsed) {
					$existing[$parsed->key()] ??= $c;
				}
			}
		}

		$summary = ['people' => count($people), 'possible_duplicates' => 0, 'people_with_phone' => 0, 'decision_people' => 0, 'matched' => 0, 'created' => 0, 'would_create' => 0, 'links' => ['VERIFIED' => 0, 'LIKELY' => 0, 'UNCERTAIN' => 0, 'REJECTED' => 0], 'numbers' => array_fill_keys(PersonPhoneExtractor::OWNER_CLASSES, 0)];
		$out = [];
		$dmf = $createContacts && ! $dryRun ? app(DecisionMakerFinderService::class) : null;

		// Najpierw osoby częściej wymienione na stronach — wariant z literówką nie wyprzedza poprawnej pisowni.
		uasort($people, fn ($a, $b) => $b['mentions'] <=> $a['mentions']);

		foreach ($people as $key => $p) {
			$roleKey = $p['role']['role'] ?? null;
			$decision = $roleKey !== null && in_array($roleKey, CallReadiness::DECISION_ROLES, true);
			$contact = $existing[$key] ?? null;
			$created = false;
			$duplicateOf = $contact === null ? self::similarPerson($p, $existing) : null;

			// Możliwa literówka / wariant nazwiska istniejącej osoby (to samo imię, podobne nazwisko): NIE łączymy
			// automatycznie i NIE tworzymy duplikatu — do ręcznej weryfikacji.
			if ($duplicateOf !== null) {
				$summary['possible_duplicates'] = ($summary['possible_duplicates'] ?? 0) + 1;
				$out[] = ['name' => $p['name']->full(), 'title' => $p['title'], 'role' => $roleKey, 'role_label' => $roleKey ? RoleCatalog::label($roleKey) : null, 'decision' => $decision, 'contact_id' => null, 'created' => false, 'would_create' => false, 'possible_duplicate_of' => $duplicateOf['full_name'], 'source_url' => UrlGuard::httpUrl($p['source_url']), 'phones' => []];
				continue;
			}

			if ($contact === null && $decision && $createContacts) {
				if ($dmf) {
					$res = $dmf->persistWebsiteCandidate($company, ['name' => $p['name'], 'title' => $p['title'], 'role' => $p['role'], 'source_type' => 'COMPANY_WEBSITE', 'source_url' => $p['source_url'], 'source_title' => '', 'page_kind' => 'team', 'excerpt' => self::redact($p['excerpt'])]);
					$contact = $this->contacts->find($res['id']);
					$created = $res['created'];
					$summary['created'] += $created ? 1 : 0;
					$existing[$key] = $contact;
				} else {
					$summary['would_create']++;
				}
			} elseif ($contact !== null) {
				$summary['matched']++;
			}

			$summary['decision_people'] += $decision ? 1 : 0;
			$summary['people_with_phone'] += $p['phones_by'] !== [] ? 1 : 0;
			$phoneOut = [];

			foreach ($p['phones_by'] as $e164 => $ph) {
				$status = $ph['status'];
				$evidence = $ph['evidence'];
				$note = null;

				// Ta sama osoba, inne stanowisko w bazie (np. „prezes” vs „dyrektor sprzedaży”) — bez pełnego potwierdzenia.
				$known = (string) ($contact['normalized_role'] ?? 'other');

				if ($contact && $status === PersonPhoneExtractor::VERIFIED && $roleKey && $known !== 'other' && $known !== $roleKey
					&& (RoleCatalog::ROLES[$known]['department'] ?? null) !== (RoleCatalog::ROLES[$roleKey]['department'] ?? null)) {
					$status = PersonPhoneExtractor::LIKELY;
					$note = 'Stanowisko na stronie („' . $p['title'] . '”) różni się od zapisanego (' . $contact['job_title'] . ').';
				}

				$summary['links'][$status]++;

				if ($contact && ! $dryRun) {
					$this->links->upsertAuto([
						'contact_id' => (int) $contact['id'],
						'company_id' => $companyId,
						'org_key' => (int) $contact['org_key'],
						'e164' => $e164,
						'raw_number' => $ph['raw'],
						'company_phone_id' => isset($byNumber[$e164]) ? (int) $byNumber[$e164]['id'] : 0,
						'link_status' => $status,
						'evidence' => $evidence,
						'source_url' => UrlGuard::httpUrl($ph['source_url']) ? $ph['source_url'] : '',
						'evidence_excerpt' => self::redact(($note ? $note . ' ' : '') . $ph['excerpt']),
						'observed_name' => $p['name']->full(),
						'observed_title' => (string) $p['title'],
					]);
				}

				$phoneOut[] = ['number' => PhoneNumber::format($e164), 'e164' => $e164, 'status' => $status, 'status_label' => PersonPhoneExtractor::STATUS_LABELS[$status], 'evidence' => $evidence, 'evidence_label' => PersonPhoneExtractor::EVIDENCE_LABELS[$evidence] ?? $evidence, 'source_url' => UrlGuard::httpUrl($ph['source_url']), 'excerpt' => self::redact($ph['excerpt']), 'note' => $note];
			}

			if ($phoneOut !== [] || $decision) {
				$out[] = [
					'name' => $p['name']->full(),
					'title' => $p['title'],
					'role' => $roleKey,
					'role_label' => $roleKey ? RoleCatalog::label($roleKey) : null,
					'decision' => $decision,
					'contact_id' => $contact ? (int) $contact['id'] : null,
					'created' => $created,
					'would_create' => $contact === null && $decision && $dryRun && $createContacts,
					'source_url' => UrlGuard::httpUrl($p['source_url']),
					'phones' => $phoneOut,
				];
			}
		}

		// Właściciel numerów firmy (tylko numery zapisane przy firmie; wspólne org_key 0).
		$classified = [];

		foreach ($numbers as $e164 => $n) {
			$summary['numbers'][$n['class']]++;
			$n['note'] = $n['note'] !== null ? self::redact($n['note']) : null;
			$classified[] = ['number' => PhoneNumber::format($e164), 'e164' => $e164, 'class' => $n['class'], 'class_label' => PersonPhoneExtractor::OWNER_LABELS[$n['class']], 'note' => $n['note'], 'stored' => isset($byNumber[$e164])];

			if (! $dryRun && isset($byNumber[$e164])) {
				$this->db->execute(
					"UPDATE {$this->db->table(Tables::COMPANY_PHONES)} SET owner_class = %s, owner_note = NULLIF(%s, ''), owner_checked_at = %s WHERE id = %d",
					$n['class'], mb_substr((string) $n['note'], 0, 200), Db::now(), (int) $byNumber[$e164]['id']
				);
			}
		}

		return ['company_id' => $companyId, 'summary' => $summary, 'people' => $out, 'numbers' => $classified];
	}

	/**
	 * Decyzja organizacji: confirm | reject | assign (istniejący numer firmy) — prywatna, ma pierwszeństwo przed automatem.
	 *
	 * @param array<string, mixed> $input action, e164 | company_phone_id
	 * @return array<string, mixed>
	 */
	public function decide(TenantContext $ctx, int $contactId, array $input): array
	{
		[$contact] = $this->contactFor($ctx, $contactId);
		$action = (string) ($input['action'] ?? '');
		$companyId = (int) $contact['company_id'];
		$current = $this->links->effectiveFor($ctx->organizationId, [$contactId])[$contactId] ?? [];
		$e164 = null;
		$extra = [];

		if ($action === 'assign') {
			$phone = null;

			if (! empty($input['company_phone_id'])) {
				$phone = $this->db->row("SELECT * FROM {$this->db->table(Tables::COMPANY_PHONES)} WHERE id = %d AND company_id = %d AND org_key IN (0, %d)", (int) $input['company_phone_id'], $companyId, $ctx->organizationId);
			} elseif (! empty($input['e164'])) {
				$n = PhoneNumber::parse((string) $input['e164']);
				$phone = $n ? $this->db->row("SELECT * FROM {$this->db->table(Tables::COMPANY_PHONES)} WHERE e164 = %s AND company_id = %d AND org_key IN (0, %d) ORDER BY org_key ASC LIMIT 1", $n['e164'], $companyId, $ctx->organizationId) : null;
			}

			if (! $phone) {
				throw UbrException::invalid('Można przypisać tylko istniejący numer tej firmy (z karty „Telefony firmy”).');
			}

			$e164 = (string) $phone['e164'];
			$extra = ['raw_number' => $phone['raw_number'], 'company_phone_id' => (int) $phone['id'], 'source_url' => $phone['source_url'], 'evidence_excerpt' => 'Numer firmy przypisany ręcznie do osoby' . ($phone['context'] ? ': ' . $phone['context'] : '.'), 'observed_name' => $contact['full_name'], 'observed_title' => $contact['job_title']];
			$this->links->decide($ctx->organizationId, $contactId, $companyId, $e164, PersonPhoneExtractor::VERIFIED, 'MANUAL_ASSIGN', $ctx->userId, $extra);
		} elseif (in_array($action, ['confirm', 'reject'], true)) {
			$n = PhoneNumber::parse((string) ($input['e164'] ?? ''));
			$link = $n ? (array_values(array_filter($current, fn ($l) => $l['e164'] === $n['e164']))[0] ?? null) : null;

			if (! $link) {
				throw UbrException::invalid('Nie znaleziono powiązania tego numeru z osobą.');
			}

			$e164 = (string) $link['e164'];
			$src = $link['auto'] ?? $link;
			$extra = ['raw_number' => $src['raw_number'], 'company_phone_id' => (int) ($src['company_phone_id'] ?? 0), 'source_url' => $src['source_url'], 'evidence_excerpt' => $src['evidence_excerpt'], 'observed_name' => $src['observed_name'], 'observed_title' => $src['observed_title']];
			$this->links->decide($ctx->organizationId, $contactId, $companyId, $e164, $action === 'confirm' ? PersonPhoneExtractor::VERIFIED : PersonPhoneExtractor::REJECTED, $action === 'confirm' ? 'MANUAL_CONFIRM' : 'MANUAL_REJECT', $ctx->userId, $extra);
		} else {
			throw UbrException::invalid('Nieznana operacja.');
		}

		ActionCenterService::bumpOrganization($ctx->organizationId);

		return $this->linksPayload($ctx, $contactId) + ['message' => ['assign' => 'Numer przypisany do osoby (decyzja Twojej organizacji).', 'confirm' => 'Powiązanie potwierdzone.', 'reject' => 'Powiązanie odrzucone — numer nie będzie pokazywany jako telefon tej osoby.'][$action]];
	}

	/**
	 * Usunięcie błędnego powiązania: decyzja organizacji → usunięta (wraca dowód automatyczny); powiązanie automatyczne →
	 * odrzucone dla organizacji. Numer firmy pozostaje bez zmian.
	 *
	 * @return array<string, mixed>
	 */
	public function remove(TenantContext $ctx, int $contactId, int $linkId): array
	{
		[$contact] = $this->contactFor($ctx, $contactId);
		$link = $this->links->find($linkId);

		if (! $link || (int) $link['contact_id'] !== $contactId || ! in_array((int) $link['org_key'], [0, $ctx->organizationId], true)) {
			throw UbrException::notFound('Nie znaleziono powiązania.');
		}

		if ((int) $link['org_key'] === $ctx->organizationId && str_starts_with((string) $link['evidence'], 'MANUAL') && (int) $contact['org_key'] === 0) {
			$this->links->deleteDecision($linkId, $ctx->organizationId); // wraca dowód automatyczny (jeśli był)
		} else {
			$this->links->decide($ctx->organizationId, $contactId, (int) $contact['company_id'], (string) $link['e164'], PersonPhoneExtractor::REJECTED, 'MANUAL_REJECT', $ctx->userId, ['raw_number' => $link['raw_number'], 'company_phone_id' => (int) ($link['company_phone_id'] ?? 0), 'source_url' => $link['source_url'], 'evidence_excerpt' => $link['evidence_excerpt']]);
		}

		ActionCenterService::bumpOrganization($ctx->organizationId);

		return $this->linksPayload($ctx, $contactId) + ['message' => 'Powiązanie usunięte. Numer firmy pozostał w karcie „Telefony firmy”.'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function linksPayload(TenantContext $ctx, int $contactId): array
	{
		return ['contact_id' => $contactId, 'phone_links' => $this->present($this->links->effectiveFor($ctx->organizationId, [$contactId])[$contactId] ?? [], $ctx->can('contacts.manage'))];
	}

	/**
	 * Powiązania kontaktów dla organizacji (karta firmy, listy).
	 *
	 * @param list<int> $contactIds
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function forContacts(TenantContext $ctx, array $contactIds): array
	{
		$out = [];

		foreach ($this->links->effectiveFor($ctx->organizationId, $contactIds) as $cid => $list) {
			$out[$cid] = $this->present($list, $ctx->can('contacts.manage'));
		}

		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $list
	 * @return list<array<string, mixed>>
	 */
	public function present(array $list, bool $canManage): array
	{
		return array_map(function ($l) use ($canManage) {
			$status = (string) $l['link_status'];
			$source = (string) $l['source_type'];
			$auto = $l['auto'] ?? null;

			return [
				'id' => (int) $l['id'],
				'number' => PhoneNumber::format((string) $l['e164']),
				'e164' => (string) $l['e164'],
				'tel' => 'tel:' . $l['e164'],
				'status' => $status,
				'status_label' => PersonPhoneExtractor::STATUS_LABELS[$status] ?? $status,
				'direct' => in_array($status, [PersonPhoneExtractor::VERIFIED, PersonPhoneExtractor::LIKELY], true),
				'evidence' => $l['evidence'],
				'evidence_label' => PersonPhoneExtractor::EVIDENCE_LABELS[(string) $l['evidence']] ?? (string) $l['evidence'],
				'source' => $source,
				'source_label' => self::SOURCE_LABELS[$source] ?? $source,
				'origin_label' => $auto ? (self::SOURCE_LABELS[(string) $auto['source_type']] ?? null) : null,
				'source_url' => UrlGuard::httpUrl($l['source_url'] ?? ($auto['source_url'] ?? null)),
				'excerpt' => isset($l['evidence_excerpt']) || isset($auto['evidence_excerpt']) ? self::redact($l['evidence_excerpt'] ?? $auto['evidence_excerpt']) : null,
				'observed_title' => $l['observed_title'] ?? null,
				'found_at' => ! empty($l['found_at']) ? gmdate('c', strtotime($l['found_at'] . ' UTC')) : null,
				'verified_at' => ! empty($l['verified_at']) ? gmdate('c', strtotime($l['verified_at'] . ' UTC')) : null,
				'decided_by_organization' => ! empty($l['overridden']),
				'can_confirm' => $canManage && $status !== PersonPhoneExtractor::VERIFIED,
				'can_reject' => $canManage && $status !== PersonPhoneExtractor::REJECTED,
				'can_remove' => $canManage,
			];
		}, $list);
	}

	/**
	 * Ta sama osoba z literówką w nazwisku: identyczne imię, nazwisko różne o maks. 2 znaki (min. 5 liter), inny klucz.
	 *
	 * @param array<string, mixed> $p
	 * @param array<string, array<string, mixed>> $existing
	 * @return array<string, mixed>|null
	 */
	public static function similarPerson(array $p, array $existing): ?array
	{
		$first = \App\Ubr\Domain\Contacts\PersonName::fold($p['name']->first);
		$last = \App\Ubr\Domain\Contacts\PersonName::fold($p['name']->last);

		foreach ($existing as $key => $c) {
			[$cf, $cl] = array_pad(explode(' ', (string) $key, 2), 2, '');

			if ($cf === $first && $cl !== $last && strlen($last) >= 5 && strlen($cl) >= 5 && levenshtein($cl, $last) <= 2) {
				return $c;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function sourceUrls(int $companyId, string $websiteUrl): array
	{
		$rows = $this->db->rows(
			"SELECT source_url, SUM(phone_type IN ('OTHER','SALES','SUPPORT','UNKNOWN')) AS weight, COUNT(*) AS n FROM {$this->db->table(Tables::COMPANY_PHONES)}
			WHERE company_id = %d AND org_key = 0 AND source_url IS NOT NULL AND source_url <> '' GROUP BY source_url ORDER BY weight DESC, n DESC, source_url ASC LIMIT %d",
			$companyId,
			self::MAX_SOURCE_PAGES
		);
		$urls = array_values(array_filter(array_map(fn ($r) => (string) $r['source_url'], $rows), fn ($u) => UrlGuard::httpUrl($u) !== null && self::sameSite($u, $websiteUrl)));

		return $urls !== [] ? $urls : [$websiteUrl];
	}

	/**
	 * @param array<string, mixed> $rules
	 * @param list<array<string, mixed>> $pages
	 * @param list<array{html: string, url: string}> $docs
	 * @param array<string, bool> $seen
	 */
	private function fetchInto(string $url, string $kind, array $rules, RobotsPolicy $robots, array &$pages, array &$docs, array &$seen): void
	{
		$seen[self::norm($url)] = true;

		if (! $robots->allowed($rules, (string) (parse_url($url, PHP_URL_PATH) ?: '/'))) {
			$pages[] = ['url' => $url, 'kind' => $kind, 'status' => null, 'error' => 'robots.txt — pominięto'];

			return;
		}

		$res = $this->fetcher->fetch($url, 10);
		$pages[] = ['url' => $url, 'kind' => $kind, 'status' => $res['status'], 'error' => $res['ok'] ? null : ($res['blocked'] ? 'adres zablokowany (ochrona SSRF)' : mb_substr((string) $res['error'], 0, 120))];

		if ($res['ok']) {
			$final = (string) ($res['final_url'] ?: $url);
			$seen[self::norm($final)] = true;
			$docs[] = ['html' => (string) $res['body'], 'url' => $final];
		}
	}

	/**
	 * @return array{0: array<string, mixed>}
	 */
	private function contactFor(TenantContext $ctx, int $contactId): array
	{
		if (! $ctx->can('contacts.manage')) {
			throw UbrException::forbidden();
		}

		$contact = $this->contacts->find($contactId);

		if (! $contact || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true) || ! $this->companies->isVisibleTo((int) $contact['company_id'], $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono kontaktu.');
		}

		return [$contact];
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

	private static function norm(string $url): string
	{
		return rtrim(strtolower((string) strtok($url, '#')), '/');
	}

	private static function sameSite(string $url, string $website): bool
	{
		$a = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
		$b = preg_replace('/^www\./', '', strtolower((string) parse_url($website, PHP_URL_HOST))) ?? '';

		return $a !== '' && ($a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a));
	}
}
