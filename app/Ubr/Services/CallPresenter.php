<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Domain\Calling\CompanySize;
use App\Ubr\Domain\Contacts\RoleCatalog;
use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\CallFilters;
use App\Ubr\Infrastructure\Repositories\CompanyPhoneRepository;
use App\Ubr\Infrastructure\Repositories\LeadFilters;
use App\Ubr\Infrastructure\Repositories\PhoneRevealRepository;

/**
 * Dane Cold Calling dla wierszy list (Discover / Prospects): gotowość, wielkość, decydent z telefonem, numer firmy,
 * opiekun / CRM, ostatni kontakt i dozwolone szybkie akcje. Wszystko ładowane zbiorczo dla strony (bez N+1).
 *
 * Widoczność: numery pobrane (Apollo) i ręczne — tylko organizacji, która je ma; numery ze strony firmy — wspólne.
 * Firmy innego opiekuna: dane widoczne, akcje kontaktowe ograniczone. Zakaz kontaktu (DNC) blokuje akcje kontaktowe.
 */
class CallPresenter
{
	public function __construct(
		private Db $db,
		private CompanyPhoneRepository $companyPhones,
		private PhoneRevealRepository $reveals,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $items wiersze po CompanyPresenter::row (ta sama kolejność co $raw)
	 * @param list<array<string, mixed>> $raw wiersze z repozytorium (kolumny Cold Calling dociągane tu, dla strony)
	 * @return list<array<string, mixed>>
	 */
	public function attach(TenantContext $ctx, array $items, array $raw): array
	{
		if ($items === []) {
			return $items;
		}

		$org = $ctx->organizationId;
		$byId = [];

		foreach ($raw as $r) {
			$byId[(int) $r['id']] = $r;
		}

		$companyIds = array_keys($byId);

		// Gotowość, osoba z numerem i pola prospektu — jedno zapytanie dla firm z bieżącej strony.
		foreach ($this->callRows($companyIds, $org) as $id => $extra) {
			$byId[$id] = $extra + $byId[$id];
		}
		$contactIds = [];

		foreach ($byId as $r) {
			$cid = (int) ($r['phone_contact_id'] ?? 0) ?: (int) ($r['top_contact_id'] ?? 0);

			if ($cid) {
				$contactIds[] = $cid;
			}
		}

		$contacts = $this->contacts($contactIds, $org);
		$revealed = $this->reveals->phonesFor($org, array_keys($contacts));
		$latest = $this->reveals->latestFor($org, array_keys($contacts));
		$links = app(DecisionPhoneService::class)->forContacts($ctx, array_keys($contacts));
		// Prompt 10.3 — zatwierdzone dopasowania Apollo (jedno zapytanie do bazy, bez wywołań Apollo przy renderowaniu).
		$apollo = app(ApolloContactService::class)->approvedFor($org, array_keys($contacts));
		$phones = $this->companyPhones->forCompanies($companyIds, $org);
		$owners = $this->owners(array_map(fn ($r) => (int) ($r['p_owner_user_id'] ?? 0), $byId));

		foreach ($items as &$item) {
			$r = $byId[(int) $item['id']] ?? null;

			if ($r === null) {
				continue;
			}

			$item['call'] = $this->row($ctx, $item, $r, $contacts, $revealed, $latest, $phones[(int) $r['id']] ?? [], $owners, $links, $apollo);
		}

		unset($item);

		return $items;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $r
	 * @param array<int, array<string, mixed>> $contacts
	 * @param array<int, list<array<string, mixed>>> $revealed
	 * @param array<int, array<string, mixed>> $latest
	 * @param list<array<string, mixed>> $companyRows
	 * @param array<int, string> $owners
	 * @param array<int, list<array<string, mixed>>> $links powiązania numer ↔ osoba (Prompt 10.2)
	 * @return array<string, mixed>
	 */
	private function row(TenantContext $ctx, array $item, array $r, array $contacts, array $revealed, array $latest, array $companyRows, array $owners, array $links = [], array $apollo = []): array
	{
		$readiness = (string) ($r['call_readiness'] ?? CallReadiness::RESEARCH);
		$ownership = $this->ownership($ctx, $r, $owners);
		$main = CompanyPhoneService::mainFor($companyRows);

		// Decydent: osoba z bezpośrednim numerem, a bez niego — najwyżej oceniona osoba (bez widoku prywatnego organizacji).
		$contactId = (int) ($r['phone_contact_id'] ?? 0) ?: (empty($item['contacts']['private_view']) ? (int) ($r['top_contact_id'] ?? 0) : 0);
		$contact = $contactId ? ($contacts[$contactId] ?? null) : null;
		$dm = null;

		if ($contact) {
			$hasApollo = isset($apollo[$contactId]) || (($contact['external_provider'] ?? '') === 'apollo' && ! empty($contact['external_id']));
			$phone = ApolloPhoneService::state($links[$contactId] ?? [], $revealed[$contactId] ?? [], $latest[$contactId] ?? null, $hasApollo);
			$phone['apollo'] = $apollo[$contactId] ?? null;
			$phone['direct_label'] = self::directLabel($phone);
			$phone['to_verify'] = count(array_filter($links[$contactId] ?? [], fn ($l) => $l['status'] === 'UNCERTAIN'));
			$phone['reveal_possible'] = $phone['reveal_possible'] && ApolloPhoneService::enabled() && $ctx->can('contacts.reveal') && ! $ownership['dnc'];
			$dm = [
				'contact_id' => $contactId,
				'name' => $contact['full_name'],
				'title' => $contact['job_title'],
				'role' => $contact['normalized_role'],
				'role_label' => $contact['normalized_role'] ? RoleCatalog::label((string) $contact['normalized_role']) : null,
				'decision_role' => in_array($contact['normalized_role'], CallReadiness::DECISION_ROLES, true),
				'source' => $contact['external_provider'] === 'apollo' ? 'Apollo' : 'strona firmy',
				'phone' => $phone,
			];
		} elseif (! empty($item['contacts']['top'])) {
			$dm = ['contact_id' => null, 'name' => $item['contacts']['top']['name'], 'title' => $item['contacts']['top']['title'], 'role' => null, 'role_label' => null, 'decision_role' => null, 'source' => null, 'phone' => null];
		}

		$hasPhone = $main !== null || ! empty($dm['phone']['numbers']);
		$canContact = ! $ownership['dnc'] && $readiness !== CallReadiness::BLOCKED && $ownership['state'] !== 'ASSIGNED_OTHER';

		return [
			'readiness' => CallReadiness::present($readiness),
			'size' => CompanySize::present($r),
			'decision_maker' => $dm,
			'company_phone' => $main ? CompanyPhoneService::present($main) : null,
			'company_phones_count' => count($companyRows),
			'ownership' => $ownership,
			'actions' => [
				'can_claim' => $ownership['state'] === 'UNASSIGNED' && ! $ownership['dnc'] && $ctx->can('prospects.manage'),
				'can_transfer' => $ownership['state'] === 'ASSIGNED_OTHER' && $ctx->can('prospects.transfer'),
				'can_call' => $hasPhone && $canContact,
				'can_record_outcome' => $canContact && $ctx->can('pipeline.manage'),
				'restricted_reason' => match (true) {
					$ownership['dnc'] => 'Zakaz kontaktu' . ($ownership['dnc_reason'] ? ': ' . $ownership['dnc_reason'] : '') . '.',
					$readiness === CallReadiness::BLOCKED => 'Aktywna blokada firmy — kontakt wstrzymany.',
					$ownership['state'] === 'ASSIGNED_OTHER' => 'Firmą opiekuje się ' . ($ownership['owner_name'] ?? 'inny handlowiec') . '.',
					default => null,
				},
			],
		];
	}

	/**
	 * Prompt 10.3 — opis numeru bezpośredniego decydenta: potwierdzony / sygnał Apollo / brak numeru / dostępność nieznana.
	 * Sygnał Apollo bez numeru NIE jest numerem (nie zmienia gotowości READY_DIRECT).
	 *
	 * @param array<string, mixed> $phone
	 */
	public static function directLabel(array $phone): string
	{
		$verified = array_filter((array) ($phone['numbers'] ?? []), fn ($n) => ! empty($n['verified']));
		$availability = $phone['apollo']['availability'] ?? null;

		return match (true) {
			$verified !== [] => 'Numer bezpośredni potwierdzony',
			$availability === 'AVAILABLE' => 'Apollo: numer bezpośredni dostępny (płatny, nie pobrano)',
			$availability === 'UNAVAILABLE' => 'Brak numeru bezpośredniego',
			$phone['apollo'] ?? null => 'Dostępność telefonu nieznana',
			($phone['status'] ?? '') === 'UNAVAILABLE' => 'Brak numeru bezpośredniego',
			default => 'Dostępność telefonu nieznana',
		};
	}

	/**
	 * Statusy nie wykluczają się: state (UNASSIGNED / MINE / ASSIGNED_OTHER) + flagi in_progress i dnc.
	 *
	 * @param array<string, mixed> $r
	 * @param array<int, string> $owners
	 * @return array<string, mixed>
	 */
	private function ownership(TenantContext $ctx, array $r, array $owners): array
	{
		$active = ! empty($r['prospect_id']) && empty($r['p_archived_at']);
		$owner = $active ? (int) ($r['p_owner_user_id'] ?? 0) : 0;
		$status = $active ? ($r['p_status'] ?? null) : null;
		$state = match (true) {
			$owner === 0 => 'UNASSIGNED',
			$owner === $ctx->userId => 'MINE',
			default => 'ASSIGNED_OTHER',
		};
		$dnc = $active && ! empty($r['p_dnc']);
		$flags = [$state];

		if ($status && in_array($status, PipelineCatalog::IN_PROGRESS_STATUSES, true)) {
			$flags[] = 'IN_PROGRESS';
		}

		if ($dnc) {
			$flags[] = 'DO_NOT_CONTACT';
		}

		return [
			'state' => $state,
			'flags' => $flags,
			'label' => ['UNASSIGNED' => 'Wolna', 'MINE' => 'Moja', 'ASSIGNED_OTHER' => 'Ma opiekuna'][$state],
			'in_prospects' => $active,
			'prospect_id' => $active ? (int) $r['prospect_id'] : null,
			'owner_user_id' => $owner ?: null,
			'owner_name' => $owner ? ($owners[$owner] ?? 'Użytkownik #' . $owner) : null,
			'in_progress' => in_array('IN_PROGRESS', $flags, true),
			'dnc' => $dnc,
			'dnc_reason' => $dnc ? ($r['p_dnc_reason'] ?? null) : null,
			'pipeline_status' => $status,
			'pipeline_label' => $status ? (PipelineCatalog::STATUSES[$status]['label'] ?? $status) : null,
			'last_contact_at' => $active && ! empty($r['p_last_contact_at']) ? gmdate('c', strtotime($r['p_last_contact_at'] . ' UTC')) : null,
			'last_contact_outcome' => $active ? ($r['p_last_contact_outcome'] ?? null) : null,
			'last_contact_outcome_label' => $active ? PipelineCatalog::outcomeLabel($r['p_last_contact_outcome'] ?? null) : null,
		];
	}

	/**
	 * @param list<int> $companyIds
	 * @return array<int, array<string, mixed>>
	 */
	private function callRows(array $companyIds, int $org): array
	{
		if ($companyIds === []) {
			return [];
		}

		$o = (string) (int) $org;
		$out = [];
		$rows = $this->db->rows(
			'SELECT c.id, ' . CallFilters::select($o) . " FROM {$this->db->table(Tables::COMPANIES)} c
			LEFT JOIN {$this->db->table(Tables::PROSPECTS)} p ON p.company_id = c.id AND p.organization_id = {$o}"
			. LeadFilters::join('c.id', $o, $this->db->table(Tables::LEAD_INTELLIGENCE)) . '
			WHERE c.id IN (' . Db::placeholders($companyIds) . ')',
			...$companyIds
		);

		foreach ($rows as $row) {
			$out[(int) $row['id']] = $row;
		}

		return $out;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private function contacts(array $ids, int $org): array
	{
		$ids = array_values(array_unique(array_filter($ids)));

		if ($ids === []) {
			return [];
		}

		$out = [];
		$rows = $this->db->rows(
			"SELECT id, company_id, org_key, full_name, job_title, normalized_role, phone_e164, external_provider, external_id FROM {$this->db->table(Tables::CONTACTS)}
			WHERE id IN (" . Db::placeholders($ids) . ') AND org_key IN (0, %d) AND active = 1',
			...array_merge($ids, [$org])
		);

		foreach ($rows as $row) {
			$out[(int) $row['id']] = $row;
		}

		return $out;
	}

	/**
	 * @param array<int, int> $userIds
	 * @return array<int, string>
	 */
	private function owners(array $userIds): array
	{
		$out = [];

		foreach (array_unique(array_filter($userIds)) as $uid) {
			$user = get_userdata($uid);
			$out[$uid] = $user ? (string) ($user->display_name ?: $user->user_login) : 'Użytkownik #' . $uid;
		}

		return $out;
	}
}
