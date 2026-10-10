<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Contacts\ContactConfidence;
use App\Ubr\Domain\Contacts\ContactPriorityCalculator;
use App\Ubr\Domain\Contacts\ContactRanking;
use App\Ubr\Domain\Contacts\ContactSources;
use App\Ubr\Domain\Contacts\ContactVerifier;
use App\Ubr\Domain\Contacts\PersonName;
use App\Ubr\Domain\Contacts\RoleCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Contacts\ApolloPeopleSource;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ContactRepository;
use App\Ubr\Infrastructure\Repositories\ContactRunRepository;
use App\Ubr\Support\UbrException;
use App\Ubr\Infrastructure\Http\UrlGuard;

/**
 * Przypadki użycia Decision Maker Findera dla organizacji: zlecenie (pojedyncze / zbiorcze), wyniki z rankingiem
 * uwzględniającym prywatne decyzje organizacji, przegląd ręczny, kontakty dodane ręcznie, historia.
 *
 * Dostęp: tylko firmy widoczne dla organizacji (jej wyniki Discovery / prospekty). Dane z automatycznego researchu
 * są wspólne; decyzje, notatki i kontakty ręczne — prywatne dla organizacji (ContactResearchHistory + reviews).
 */
class ContactResearchService
{
	public const STATUS_LABELS = [
		'' => 'Nie szukano',
		'queued' => 'W kolejce',
		'running' => 'Wyszukiwanie',
		'completed' => 'Gotowe',
		'partial' => 'Częściowe',
		'failed' => 'Błąd',
	];

	public function __construct(
		private CompanyRepository $companies,
		private ContactRepository $contacts,
		private ContactRunRepository $runs,
		private ContactLimits $limits,
		private ContactResearchRunner $runner,
		private ContactRanking $ranking,
	) {
	}

	/**
	 * @param list<int> $companyIds
	 * @return array<string, mixed>
	 */
	public function request(TenantContext $ctx, array $companyIds, bool $force = false, string $trigger = 'manual'): array
	{
		$this->assert($ctx, 'contacts.run');
		$companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));

		if ($companyIds === []) {
			throw UbrException::invalid('Nie wybrano firm.');
		}

		$limits = ContactLimits::limits($ctx->organizationId);

		if (count($companyIds) > $limits['bulk_max']) {
			throw UbrException::invalid(sprintf('Jednorazowo można zlecić wyszukiwanie dla maks. %d firm.', $limits['bulk_max']));
		}

		$queued = [];
		$skipped = [];
		$toCreate = [];

		foreach ($companyIds as $id) {
			$company = $this->companies->find($id);

			if (! $company || ! $this->companies->isVisibleTo($id, $ctx->organizationId)) {
				if (count($companyIds) === 1) {
					throw UbrException::notFound('Nie znaleziono firmy.');
				}

				$skipped[] = ['company_id' => $id, 'reason' => 'not_found'];
				continue;
			}

			$reason = $this->skipReason($company, $force, $limits);

			if ($reason) {
				$skipped[] = ['company_id' => $id, 'name' => $company['name'], 'reason' => $reason['code'], 'message' => $reason['message']];
				continue;
			}

			$toCreate[] = $company;
		}

		if ($toCreate) {
			$this->limits->assertCanStart($ctx->organizationId, count($toCreate));
		}

		foreach ($toCreate as $company) {
			$runId = $this->runs->create([
				'company_id' => (int) $company['id'],
				'organization_id' => $ctx->organizationId,
				'user_id' => $ctx->userId,
				'trigger_type' => count($companyIds) > 1 ? 'bulk' : $trigger,
				'research_version' => DecisionMakerFinderService::RESEARCH_VERSION,
			]);
			$this->companies->update((int) $company['id'], ['contacts_status' => 'queued']);
			$queued[] = ['company_id' => (int) $company['id'], 'run_id' => $runId];
		}

		if ($queued) {
			$this->runner->kick();
		}

		$labels = ['not_found' => 'firma niedostępna', 'no_sources' => 'brak źródeł (bez KRS i strony WWW)', 'in_progress' => 'wyszukiwanie już trwa', 'fresh' => 'aktualne wyniki (< 30 dni)', 'cooldown' => 'wyszukiwano niedawno (< 24 h)'];
		$summary = [];

		foreach ($skipped as $s) {
			$summary[$s['reason']] = ($summary[$s['reason']] ?? 0) + 1;
		}

		return [
			'queued' => $queued,
			'skipped' => $skipped,
			'message' => trim(sprintf('Zlecono wyszukiwanie decydentów: %d.', count($queued)) . ($summary ? ' Pominięto: ' . implode(', ', array_map(fn ($k, $n) => ($labels[$k] ?? $k) . ' (' . $n . ')', array_keys($summary), $summary)) . '.' : '')),
			'usage' => $this->limits->usage($ctx->organizationId),
		];
	}

	/**
	 * @param array<string, mixed> $company
	 * @param array<string, int> $limits
	 * @return array{code: string, message: string}|null
	 */
	private function skipReason(array $company, bool $force, array $limits): ?array
	{
		$hasSite = ! empty($company['domain']) && ! empty($company['website_url']) && ($company['domain_status'] ?? '') !== 'mismatch';

		if (! $hasSite && empty($company['krs']) && ! ApolloPeopleSource::enabled()) {
			return ['code' => 'no_sources', 'message' => 'Brak źródeł: firma nie ma numeru KRS ani zgodnej strony WWW.'];
		}

		if ($this->runs->activeForCompany((int) $company['id'])) {
			return ['code' => 'in_progress', 'message' => 'Wyszukiwanie decydentów tej firmy już trwa.'];
		}

		$latest = $this->runs->latestForCompany((int) $company['id'], true);

		// Wynik starszej wersji researchu nie blokuje ponownego wyszukiwania.
		if ($latest && $latest['finished_at'] && ($latest['research_version'] ?? '') === DecisionMakerFinderService::RESEARCH_VERSION) {
			$age = time() - strtotime($latest['finished_at'] . ' UTC');

			if (! $force && $age < $limits['fresh_days'] * DAY_IN_SECONDS) {
				return ['code' => 'fresh', 'message' => 'Wyniki są aktualne — użyj „Znajdź ponownie”.'];
			}

			if ($force && $age < $limits['manual_cooldown_hours'] * HOUR_IN_SECONDS) {
				return ['code' => 'cooldown', 'message' => 'Ponowne wyszukiwanie możliwe po ' . $limits['manual_cooldown_hours'] . ' h od poprzedniego.'];
			}
		}

		return null;
	}

	/**
	 * Widok „Decision Makers” firmy: podsumowanie, Top 3 (z prywatnymi decyzjami organizacji), pozostali kandydaci,
	 * ukryci (nieaktualni / odrzuceni), skład zarządu z KRS (inicjały), ogólne kanały firmy, historia wyszukiwań.
	 * Odczyt NIE uruchamia wyszukiwania.
	 *
	 * @return array<string, mixed>
	 */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		$this->assert($ctx, 'contacts.view');
		$company = $this->visibleCompany($ctx, $companyId);
		$view = $this->rankedView($ctx, $company);
		$latest = $this->runs->latestForCompany($companyId, true);
		$active = $this->runs->activeForCompany($companyId);
		$limits = ContactLimits::limits($ctx->organizationId);

		return [
			'company' => ['id' => (int) $company['id'], 'name' => $company['name'], 'domain' => $company['domain'] ?: null, 'website_url' => $company['website_url'] ?: null, 'krs' => $company['krs'] ?: null, 'verification_status' => $company['verification_status'] ?? null, 'domain_status' => $company['domain_status'] ?? null, 'size_band' => ContactPriorityCalculator::sizeBand($company)],
			'status' => $active['status'] ?? ($company['contacts_status'] ?? null),
			'status_label' => self::STATUS_LABELS[(string) ($active['status'] ?? ($company['contacts_status'] ?? ''))] ?? 'Nie szukano',
			'active' => $active ? ['id' => (int) $active['id'], 'status' => $active['status']] : null,
			'researched_at' => ! empty($company['contacts_researched_at']) ? gmdate('c', strtotime($company['contacts_researched_at'] . ' UTC')) : null,
			'count' => count($view['top']) + count($view['others']),
			'top' => array_map(fn ($c) => $this->presentContact($c, $view['sources'], $view['history'], $view['reviews']), $view['top']),
			'top1_note' => $view['top1_note'],
			'missing_message' => 'Nie znaleziono kolejnego wiarygodnego kontaktu.',
			'others' => array_map(fn ($c) => $this->presentContact($c, $view['sources'], $view['history'], $view['reviews']), $view['others']),
			'hidden' => array_map(fn ($c) => $this->presentContact($c, $view['sources'], $view['history'], $view['reviews']), $view['hidden']),
			'board' => $latest['sources']['board'] ?? null,
			'sources' => array_map(fn ($s) => array_intersect_key((array) $s, array_flip(['state', 'message', 'url'])), array_diff_key((array) ($latest['sources'] ?? []), ['board' => 1])),
			'company_channels' => array_map(fn ($ch) => $ch + ['label' => $ch['type'] === 'COMPANY_GENERAL_EMAIL' ? 'Ogólny e-mail firmy (COMPANY_GENERAL_EMAIL)' : 'E-mail działu: ' . (['marketing' => 'marketing', 'management' => 'zarząd / sekretariat', 'sales' => 'sprzedaż'][$ch['department']] ?? $ch['department'])], (array) (json_decode((string) ($company['company_channels'] ?? ''), true) ?: [])),
			'history' => array_map(fn ($r) => $this->presentRun($r, $ctx), $this->runs->historyForCompany($companyId)),
			'can_run' => $ctx->can('contacts.run'),
			'can_manage' => $ctx->can('contacts.manage'),
			'limits' => ['cooldown_hours' => $limits['manual_cooldown_hours'], 'fresh_days' => $limits['fresh_days']],
			'apollo' => ApolloPeopleSource::status(),
			'notice' => 'Dane zawodowe z publicznych, oficjalnych źródeł (KRS, strona firmy). Opublikowany adres e-mail nie oznacza zgody na marketing — przed kontaktem oceń obowiązki wynikające z RODO i przepisów o komunikacji elektronicznej.',
		];
	}

	/**
	 * Ranking widziany przez organizację: kontakty globalne + jej ręczne, z jej decyzjami (zatwierdź / odrzuć / nieaktualny /
	 * wyklucz / preferowany). Nie zmienia danych globalnych.
	 *
	 * @param array<string, mixed> $company
	 * @return array<string, mixed>
	 */
	private function rankedView(TenantContext $ctx, array $company): array
	{
		$companyId = (int) $company['id'];
		$rows = $this->contacts->forCompany($companyId, $ctx->organizationId);
		$ids = array_map(fn ($r) => (int) $r['id'], $rows);
		$sources = $this->contacts->sources($ids);
		$reviews = $this->contacts->reviews($ctx->organizationId, $companyId);
		$overrides = [];

		foreach ($reviews as $cid => $r) {
			$overrides[$cid] = ['status' => $r['status'], 'preferred' => (bool) $r['preferred']];
		}

		$items = array_map(function ($r) use ($sources) {
			$conf = (array) $r['confidence_breakdown'];

			return $r + [
				'confidence' => $conf,
				'sources' => array_map(fn ($s) => ['source_type' => $s['source_type']], $sources[(int) $r['id']] ?? [['source_type' => $r['source_type'] ?: 'COMPANY_WEBSITE']]),
				'email' => $r['public_business_email'],
				'role_email' => $r['flags']['role_email'] ?? null,
				'marketing_focus' => (bool) ($r['flags']['marketing_focus'] ?? false),
			];
		}, $rows);

		$inactive = in_array($company['verification_status'] ?? '', ['inactive', 'suspended'], true);
		$ranked = $this->ranking->rank($items, ContactPriorityCalculator::sizeBand($company), $inactive, $overrides);

		return $ranked + ['sources' => $sources, 'history' => $this->contacts->history($ids), 'reviews' => $reviews];
	}

	/**
	 * Dla list (Discover / Prospects): Top 1 przeliczony z decyzjami organizacji (tylko firmy, w których je podjęto).
	 *
	 * @param list<array<string, mixed>> $items wiersze po CompanyPresenter::row
	 * @return list<array<string, mixed>>
	 */
	public function applyOrganizationView(TenantContext $ctx, array $items): array
	{
		$ids = array_map(fn ($i) => (int) $i['id'], $items);
		$withReviews = $this->contacts->companiesWithReviews($ctx->organizationId, $ids);

		if ($withReviews === []) {
			return $items;
		}

		foreach ($items as &$item) {
			if (! in_array((int) $item['id'], $withReviews, true)) {
				continue;
			}

			$company = $this->companies->find((int) $item['id']);

			if (! $company) {
				continue;
			}

			$view = $this->rankedView($ctx, $company);
			$top1 = null;

			foreach ($view['top'] as $c) {
				if ((int) $c['rank_position'] === 1) {
					$top1 = $c;
				}
			}

			$item['contacts']['count'] = count($view['top']) + count($view['others']);
			$item['contacts']['top'] = $top1 ? ['name' => $top1['full_name'], 'title' => $top1['job_title'], 'score' => (int) $top1['contact_priority_score'], 'confidence' => $top1['confidence_level'], 'confidence_label' => ContactConfidence::label($top1['confidence_level'])] : null;
			$item['contacts']['private_view'] = true;
		}

		unset($item);

		return $items;
	}

	/**
	 * @param array<string, mixed> $data action: approve|reject|outdated|exclude|reset|prefer|unprefer|note
	 * @return array<string, mixed>
	 */
	public function review(TenantContext $ctx, int $contactId, array $data): array
	{
		$this->assert($ctx, 'contacts.manage');
		$contact = $this->contacts->find($contactId);

		// Kontakt musi należeć do firmy widocznej dla organizacji i być globalny albo jej własny (IDOR).
		if (! $contact || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true) || ! $this->companies->isVisibleTo((int) $contact['company_id'], $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono kontaktu.');
		}

		$action = sanitize_key((string) ($data['action'] ?? ''));
		$update = match ($action) {
			'approve' => ['status' => 'approved'],
			'reject' => ['status' => 'rejected'],
			'outdated' => ['status' => 'outdated'],
			'exclude' => ['status' => 'excluded'],
			'reset' => ['status' => null],
			'prefer' => ['preferred' => 1],
			'unprefer' => ['preferred' => 0],
			'note' => ['note' => mb_substr(sanitize_textarea_field((string) ($data['note'] ?? '')), 0, 2000) ?: null],
			default => throw UbrException::invalid('Nieznana akcja.'),
		};

		$this->contacts->saveReview($ctx->organizationId, $contactId, (int) $contact['company_id'], $ctx->userId, $update);

		do_action('ubr/company/changed', (int) $contact['company_id'], 'contacts_reviewed', $ctx->organizationId);

		return $this->forCompany($ctx, (int) $contact['company_id']);
	}

	/**
	 * Kontakt dodany ręcznie — prywatny dla organizacji, oznaczony USER_PROVIDED, nigdy „automatycznie zweryfikowany”.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function addManual(TenantContext $ctx, int $companyId, array $input): array
	{
		$this->assert($ctx, 'contacts.manage');
		$company = $this->visibleCompany($ctx, $companyId);
		$name = PersonName::parse(sanitize_text_field((string) ($input['full_name'] ?? '')));

		if (! $name) {
			throw UbrException::invalid('Podaj imię i nazwisko (np. „Jan Kowalski”).');
		}

		$title = mb_substr(sanitize_text_field((string) ($input['job_title'] ?? '')), 0, 200);
		$role = RoleCatalog::match($title);

		if (! $role) {
			throw UbrException::invalid('Podaj stanowisko związane z decyzją (np. Prezes Zarządu, Dyrektor Marketingu).');
		}

		$sourceUrl = esc_url_raw((string) ($input['source_url'] ?? ''));
		$sourceUrl = $sourceUrl !== '' && preg_match('#^https?://#i', $sourceUrl) ? mb_substr($sourceUrl, 0, 500) : null;
		$email = sanitize_email((string) ($input['email'] ?? '')) ?: null;

		// Ogólny adres firmy (biuro@, info@, sekretariat@ …) nie jest adresem osobistym decydenta — nie zapisujemy go jako
		// e-maila osoby (liczyłby się w Contact Priority i trafiał do eksportu jako „służbowy” adres tej osoby).
		if ($email !== null && \App\Ubr\Domain\Contacts\PeopleExtractor::isGenericEmail($email)) {
			throw UbrException::invalid('To ogólny adres firmy lub działu (np. biuro@, info@, sekretariat@) — nie zapisujemy go jako e-maila osoby. Zostaw pole puste; ogólne kanały firmy są pokazywane osobno.');
		}
		$key = $name->key();

		if ($this->contacts->isSuppressed($companyId, $key)) {
			throw UbrException::invalid('Ta osoba została wyłączona z researchu (sprzeciw / prośba o usunięcie danych).');
		}

		foreach ([0, $ctx->organizationId] as $orgKey) {
			if ($existing = $this->contacts->findByKey($companyId, $orgKey, $key)) {
				throw new UbrException('Ta osoba jest już na liście kontaktów tej firmy (' . $existing['full_name'] . ') — użyj przeglądu ręcznego zamiast dodawania drugi raz.', 'ubr_conflict', 409);
			}
		}

		$contact = [
			'person_key' => $key, 'first_name' => $name->first, 'last_name' => $name->last, 'full_name' => $name->full(), 'job_title' => $title,
			'normalized_role' => $role['role'], 'marketing_focus' => $role['marketing_focus'], 'email' => $email, 'role_email' => null, 'phone' => null, 'profile' => null,
			'former' => false, 'other_entity' => null, 'obfuscated' => false, 'conflict' => false, 'observed_roles' => [$role['role']],
			'sources' => [['source_type' => ContactSources::USER, 'source_url' => $sourceUrl, 'source_title' => 'Dodane ręcznie', 'observed_role' => $title, 'excerpt' => $sourceUrl ? 'Źródło wskazane przez użytkownika.' : 'Bez źródła — dane od użytkownika.', 'page_kind' => 'user']],
		];
		$contact = app(ContactVerifier::class)->verify($contact, $company, null);
		$conf = app(ContactConfidence::class)->assess($contact, null);
		$score = app(ContactPriorityCalculator::class)->score($contact, $conf, $company);

		$id = $this->contacts->insert([
			'company_id' => $companyId, 'org_key' => $ctx->organizationId, 'person_key' => $key, 'first_name' => $name->first, 'last_name' => $name->last, 'full_name' => $name->full(),
			'job_title' => $title, 'normalized_role' => $role['role'], 'department' => $role['department'], 'seniority' => $role['seniority'],
			'decision_authority' => (int) $score['breakdown']['decision_authority']['points'], 'contact_priority_score' => $score['score'], 'score_status' => $score['status'], 'score_breakdown' => $score['breakdown'],
			'confidence_level' => $conf['level'], 'confidence_breakdown' => $conf, 'verification_status' => $contact['verification_status'], 'verification_reason' => $contact['verification_reason'],
			'company_match_status' => $contact['company_match_status'], 'employment_status' => 'current', 'public_business_email' => $email,
			'source_type' => ContactSources::USER, 'source_url' => $sourceUrl, 'source_observed_at' => \App\Ubr\Infrastructure\Database\Db::now(), 'needs_review' => 1,
			'flags' => ['user_provided' => true, 'marketing_focus' => $role['marketing_focus']], 'active' => 1, 'research_version' => DecisionMakerFinderService::RESEARCH_VERSION, 'created_by' => $ctx->userId,
		]);
		$this->contacts->addSource($id, null, $contact['sources'][0] + ['confidence' => $conf['level']]);

		do_action('ubr/company/changed', $companyId, 'contacts_reviewed', $ctx->organizationId);

		return $this->forCompany($ctx, $companyId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function environment(TenantContext $ctx): array
	{
		$this->assert($ctx, 'contacts.view');

		return [
			'sources' => [
				['key' => 'krs', 'label' => 'KRS (API Ministerstwa Sprawiedliwości)', 'state' => 'enabled', 'note' => 'Bezpłatne. Skład zarządu i prokurenci — dane osobowe w API są zanonimizowane (inicjały), więc KRS POTWIERDZA osoby znalezione na stronie firmy, nie dostarcza nazwisk.'],
				['key' => 'website', 'label' => 'Oficjalna strona firmy', 'state' => 'enabled', 'note' => 'Wybrane podstrony (zarząd, zespół, o nas, kontakt, media), robots.txt, ochrona SSRF.'],
				['key' => 'apollo'] + ApolloPeopleSource::status() + ['label' => 'Apollo People API Search'],
				['key' => 'ceidg_gus', 'label' => 'CEIDG / GUS', 'state' => 'not_configured', 'note' => 'Przygotowane w module weryfikacji — klucze API zostaną dodane później.'],
				['key' => 'linkedin', 'label' => 'LinkedIn', 'state' => 'not_used', 'note' => 'Nie jest pobierany (regulamin). Zapisujemy tylko link do profilu, jeśli firma opublikowała go przy osobie.'],
			],
			'limits' => ContactLimits::limits($ctx->organizationId),
			'usage' => $this->limits->usage($ctx->organizationId),
			'version' => ['research' => DecisionMakerFinderService::RESEARCH_VERSION, 'score' => ContactPriorityCalculator::VERSION],
		];
	}

	/**
	 * @param array<string, mixed> $c
	 * @param array<int, list<array<string, mixed>>> $sources
	 * @param array<int, list<array<string, mixed>>> $history
	 * @param array<int, array<string, mixed>> $reviews
	 * @return array<string, mixed>
	 */
	private function presentContact(array $c, array $sources, array $history, array $reviews): array
	{
		$id = (int) $c['id'];
		$review = $reviews[$id] ?? null;
		$conf = (array) $c['confidence_breakdown'];

		return [
			'id' => $id,
			'full_name' => $c['full_name'],
			'job_title' => $c['job_title'],
			'registry_role' => $c['registry_role'] ? mb_convert_case(mb_strtolower((string) $c['registry_role']), MB_CASE_TITLE) : null,
			'role' => $c['normalized_role'],
			'role_label' => RoleCatalog::label((string) $c['normalized_role']),
			'department' => $c['department'],
			'seniority' => $c['seniority'],
			'score' => $c['contact_priority_score'] !== null ? (int) $c['contact_priority_score'] : null,
			'score_status' => $c['score_status'],
			'breakdown' => array_map(fn ($b) => $b, (array) $c['score_breakdown']),
			'confidence' => [
				'level' => $c['confidence_level'],
				'label' => ContactConfidence::label($c['confidence_level']),
				'score' => $conf['score'] ?? null,
				'identity' => $conf['identity'] ?? null,
				'role_currency' => $conf['role_currency'] ?? null,
				'company_match' => $conf['company_match'] ?? null,
				'contact_data' => $conf['contact_data'] ?? null,
				'notes' => $conf['notes'] ?? [],
			],
			'verification' => ['status' => $c['verification_status'], 'label' => ContactVerifier::LABELS[$c['verification_status']] ?? $c['verification_status'], 'reason' => $c['verification_reason']],
			'company_match' => $c['company_match_status'],
			'company_match_uncertain' => $c['company_match_status'] === ContactVerifier::MATCH_UNCERTAIN,
			'employment' => $c['employment_status'],
			'email' => $c['public_business_email'] ?: null,
			'email_note' => $c['public_business_email'] ? null : 'Brak publicznego adresu e-mail',
			'role_email' => ! empty($c['flags']['role_email']) ? ['value' => $c['flags']['role_email'], 'label' => 'E-mail działu (nie osobisty)'] : null,
			'phone' => $c['public_business_phone'] ?: null,
			'profile_url' => UrlGuard::httpUrl($c['professional_profile_url'] ?? null),
			'sources' => array_map(fn ($s) => [
				'type' => $s['source_type'],
				'label' => ContactSources::label((string) $s['source_type']),
				'url' => UrlGuard::httpUrl($s['source_url'] ?? null),
				'title' => $s['source_title'],
				'observed_role' => $s['observed_role'],
				'excerpt' => $s['evidence_excerpt'],
				'retrieved_at' => gmdate('c', strtotime($s['retrieved_at'] . ' UTC')),
			], array_slice($sources[$id] ?? [], 0, 6)),
			'rank_position' => $c['rank_position'] ?? null,
			'rank_reason' => $c['rank_reason'] ?? null,
			'needs_review' => (bool) $c['needs_review'],
			'user_provided' => (int) $c['org_key'] !== 0,
			'review' => $review ? ['status' => $review['status'], 'preferred' => (bool) $review['preferred'], 'note' => $review['note']] : ['status' => null, 'preferred' => false, 'note' => null],
			'last_verified_at' => ! empty($c['last_verified_at']) ? gmdate('c', strtotime($c['last_verified_at'] . ' UTC')) : null,
			'history' => array_map(fn ($h) => ['score' => $h['contact_priority_score'] !== null ? (int) $h['contact_priority_score'] : null, 'position' => $h['rank_position'] !== null ? (int) $h['rank_position'] : null, 'status' => $h['verification_status'], 'title' => $h['job_title'], 'at' => gmdate('c', strtotime($h['created_at'] . ' UTC'))], $history[$id] ?? []),
		];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function presentRun(array $r, TenantContext $ctx): array
	{
		return [
			'id' => (int) $r['id'],
			'status' => $r['status'],
			'status_label' => self::STATUS_LABELS[(string) $r['status']] ?? $r['status'],
			'trigger' => $r['trigger_type'],
			'version' => $r['research_version'],
			'created_at' => gmdate('c', strtotime($r['created_at'] . ' UTC')),
			'finished_at' => $r['finished_at'] ? gmdate('c', strtotime($r['finished_at'] . ' UTC')) : null,
			'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
			'candidates' => (int) $r['candidates_found'],
			'top_count' => (int) $r['top_count'],
			'sources' => array_map(fn ($s) => ['state' => $s['state'] ?? null, 'message' => $s['message'] ?? null], array_diff_key((array) $r['sources'], ['board' => 1])),
			'top' => array_map(fn ($t) => ['name' => $t['name'], 'title' => $t['title'], 'score' => $t['score'], 'position' => $t['position']], (array) ($r['snapshot']['top'] ?? [])),
			'changes' => array_map(fn ($c) => $c['message'], (array) $r['changes']),
			'errors' => array_map(fn ($e) => $e['message'] ?? '', (array) $r['errors']),
			// Kto zlecił — widoczne tylko dla organizacji zlecającej.
			'by_my_organization' => (int) ($r['organization_id'] ?? 0) === $ctx->organizationId,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function visibleCompany(TenantContext $ctx, int $companyId): array
	{
		$company = $this->companies->find($companyId);

		if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		return $company;
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}

	/**
	 * Podsumowanie przy firmie na listach (z kolumn firmy — globalne).
	 *
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function summary(array $r): array
	{
		$status = $r['contacts_status'] ?? null;

		return [
			'status' => $status,
			'label' => self::STATUS_LABELS[(string) $status] ?? 'Nie szukano',
			'count' => isset($r['contacts_count']) && $r['contacts_count'] !== null ? (int) $r['contacts_count'] : null,
			'researched_at' => ! empty($r['contacts_researched_at']) ? gmdate('c', strtotime($r['contacts_researched_at'] . ' UTC')) : null,
			'top' => ! empty($r['top_contact_name']) ? [
				'name' => $r['top_contact_name'],
				'title' => $r['top_contact_title'],
				'score' => $r['top_contact_score'] !== null ? (int) $r['top_contact_score'] : null,
				'confidence' => $r['top_contact_confidence'],
				'confidence_label' => ContactConfidence::label($r['top_contact_confidence'] ?? null),
			] : null,
		];
	}
}
