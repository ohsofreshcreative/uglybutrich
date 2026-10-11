<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

class ResultRepository
{
	public function __construct(private Db $db)
	{
	}

	public function add(int $searchId, int $organizationId, int $companyId, string $provider, int $position, array $matchedTerms): bool
	{
		return $this->db->execute(
			"INSERT IGNORE INTO {$this->db->table(Tables::RESULTS)} (search_id, organization_id, company_id, provider, position, matched_terms, created_at)
			VALUES (%d, %d, %d, %s, %d, %s, %s)",
			$searchId,
			$organizationId,
			$companyId,
			$provider,
			$position,
			mb_substr(implode(', ', $matchedTerms), 0, 255),
			Db::now()
		) > 0;
	}

	/**
	 * @param array<string, mixed> $relevance wynik RelevanceScorer::score()
	 */
	public function setRelevance(int $searchId, int $companyId, array $relevance): void
	{
		$this->db->execute(
			"UPDATE {$this->db->table(Tables::RESULTS)} SET relevance_score = %d, relevance = %s, location_match = %s WHERE search_id = %d AND company_id = %d",
			(int) $relevance['score'],
			wp_json_encode($relevance),
			(string) $relevance['location_match'],
			$searchId,
			$companyId
		);
	}

	/**
	 * Wszystkie wystąpienia firmy w wynikach (wszystkie organizacje) — tylko do przeliczeń w tle, nie do API.
	 *
	 * @return list<array{search_id: int, provider: string, matched_terms: ?string, criteria: string}>
	 */
	public function occurrencesForCompany(int $companyId): array
	{
		return $this->db->rows(
			"SELECT r.search_id, r.provider, r.matched_terms, s.criteria
			FROM {$this->db->table(Tables::RESULTS)} r
			INNER JOIN {$this->db->table(Tables::SEARCHES)} s ON s.id = r.search_id
			WHERE r.company_id = %d",
			$companyId
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function withoutRelevance(int $limit = 500): array
	{
		return $this->db->rows(
			"SELECT DISTINCT company_id FROM {$this->db->table(Tables::RESULTS)} WHERE relevance_score IS NULL LIMIT %d",
			$limit
		);
	}

	/**
	 * Diagnostyka wyników wyszukiwania: rozkład stanów lokalizacji, statusów weryfikacji, braków danych.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(int $searchId): array
	{
		$r = $this->db->table(Tables::RESULTS);
		$c = $this->db->table(Tables::COMPANIES);

		$row = $this->db->row(
			"SELECT COUNT(*) AS total,
				SUM(c.domain IS NOT NULL) AS with_domain,
				SUM(r.relevance_score >= 60) AS relevant,
				SUM(r.relevance_score < 40) AS weak,
				ROUND(AVG(r.relevance_score)) AS avg_relevance,
				SUM(c.verification_status = 'pending') AS v_pending,
				SUM(c.verification_status = 'verified') AS v_verified,
				SUM(c.verification_status = 'uncertain') AS v_uncertain,
				SUM(c.verification_status = 'inactive') AS v_inactive,
				SUM(c.verification_status = 'suspended') AS v_suspended,
				SUM(c.domain_status = 'confirmed') AS d_confirmed,
				SUM(c.domain_status = 'mismatch') AS d_mismatch,
				SUM(c.domain_status = 'unavailable') AS d_unavailable
			FROM {$r} r INNER JOIN {$c} c ON c.id = r.company_id
			WHERE r.search_id = %d",
			$searchId
		) ?? [];

		$location = [];

		foreach ($this->db->rows("SELECT COALESCE(location_match, 'unscored') AS state, COUNT(*) AS n FROM {$r} WHERE search_id = %d GROUP BY state", $searchId) as $l) {
			$location[$l['state']] = (int) $l['n'];
		}

		return array_map(fn ($v) => $v === null ? 0 : (int) $v, $row) + ['location' => $location];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function bestRelevanceForCompany(int $companyId, int $organizationId): ?array
	{
		return $this->db->row(
			"SELECT r.search_id, r.relevance_score, r.relevance, s.label
			FROM {$this->db->table(Tables::RESULTS)} r
			INNER JOIN {$this->db->table(Tables::SEARCHES)} s ON s.id = r.search_id
			WHERE r.company_id = %d AND r.organization_id = %d
			ORDER BY COALESCE(r.relevance_score, -1) DESC, r.id DESC
			LIMIT 1",
			$companyId,
			$organizationId
		);
	}

	public function countForSearch(int $searchId): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->db->table(Tables::RESULTS)} WHERE search_id = %d", $searchId);
	}

	public function countWithDomainForSearch(int $searchId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->db->table(Tables::RESULTS)} r
			INNER JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = r.company_id
			WHERE r.search_id = %d AND c.domain IS NOT NULL",
			$searchId
		);
	}

	/**
	 * @return list<int>
	 */
	public function companyIdsForSearch(int $searchId): array
	{
		return array_map('intval', $this->db->column(
			"SELECT company_id FROM {$this->db->table(Tables::RESULTS)} WHERE search_id = %d ORDER BY id ASC",
			$searchId
		));
	}

	/**
	 * Wyniki wyszukiwania (zawężone do organizacji) + status zapisania w prospektach.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function paginate(int $searchId, int $organizationId, int $page, int $perPage, string $filter = 'all', string $sort = 'relevance', array $extra = []): array
	{
		$where = 'r.search_id = %d AND r.organization_id = %d';
		$args = [$searchId, $organizationId];

		// Filtry Website Analyzera (wartości liczbowe/whitelist).
		if (! empty($extra['min_opportunity'])) {
			$where .= ' AND c.opportunity_score >= %d';
			$args[] = max(0, min(100, (int) $extra['min_opportunity']));
		}

		if (in_array($extra['confidence'] ?? '', ['low', 'medium', 'high'], true)) {
			$where .= ' AND c.opportunity_confidence = %s';
			$args[] = $extra['confidence'];
		}

		if (! empty($extra['min_findings'])) {
			$where .= ' AND c.findings_count >= %d';
			$args[] = max(0, min(100, (int) $extra['min_findings']));
		}

		// Filtry Decision Maker Findera.
		$where .= ContactFilters::where($extra, $args);

		// Filtry Lead Intelligence (ocena organizacji; LEFT JOIN — firmy bez oceny nie znikają, gdy filtr nie jest ustawiony).
		$where .= LeadFilters::where($extra, $args);

		// Cold Calling: telefony, gotowość, opiekun, wielkość, ostatni kontakt (całe wyniki, nie bieżąca strona).
		$where .= CallFilters::where($extra, $args, 'r.organization_id', (int) ($extra['user_id'] ?? 0));

		$where .= match ($filter) {
			'with_domain' => ' AND c.domain IS NOT NULL',
			'relevant' => ' AND r.relevance_score >= 60',
			'verified' => " AND c.verification_status = 'verified'",
			'location_confirmed' => " AND r.location_match IN ('confirmed','declared','not_required')",
			'location_unknown' => " AND r.location_match IN ('filtered','unknown')",
			'problems' => " AND (c.verification_status IN ('inactive','suspended') OR c.domain_status = 'mismatch' OR r.location_match = 'mismatch')",
			'analyzed' => " AND c.analysis_status IN ('completed','partial')",
			'not_analyzed' => ' AND c.analysis_status IS NULL',
			'analysis_active' => " AND c.analysis_status IN ('queued','running')",
			'analysis_failed' => " AND c.analysis_status = 'failed'",
			'opportunity_high' => ' AND c.opportunity_score >= 60',
			'contacts_found' => ' AND c.top_contact_id IS NOT NULL',
			'contacts_none' => " AND c.contacts_status IN ('completed','partial') AND c.top_contact_id IS NULL",
			'contacts_active' => " AND c.contacts_status IN ('queued','running')",
			default => '',
		};

		// Prompt 10.4 — lekkie odświeżanie listy: tylko wskazane (widoczne) firmy i tylko zmienione od `changed_since`.
		$changesOnly = ! empty($extra['ids']);

		if ($changesOnly) {
			$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $extra['ids'])))), 0, 100);
			$where .= ' AND r.company_id IN (' . ($ids ? implode(',', $ids) : '0') . ')';

			if (! empty($extra['changed_since']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $extra['changed_since'])) {
				$where .= ' AND (c.updated_at >= %s OR vq.updated_at >= %s OR li.updated_at >= %s OR p.updated_at >= %s)';
				array_push($args, $extra['changed_since'], $extra['changed_since'], $extra['changed_since'], $extra['changed_since']);
			}
		}

		$from = "{$this->db->table(Tables::RESULTS)} r
			INNER JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = r.company_id
			LEFT JOIN {$this->db->table(Tables::PROSPECTS)} p ON p.company_id = r.company_id AND p.organization_id = r.organization_id
			LEFT JOIN {$this->db->table(Tables::VERIFICATION_QUEUE)} vq ON vq.company_id = r.company_id"
			. LeadFilters::join('r.company_id', 'r.organization_id', $this->db->table(Tables::LEAD_INTELLIGENCE));

		$total = $changesOnly ? 0 : (int) $this->db->value("SELECT COUNT(*) FROM {$from} WHERE {$where}", ...$args);

		$items = $this->db->rows(
			"SELECT c.*, r.provider, r.matched_terms, r.relevance_score, r.relevance, r.location_match, p.id AS prospect_id, vq.status AS vq_status, " . LeadFilters::select() . "
			FROM {$from}
			WHERE {$where}
			ORDER BY {$this->orderBy($sort)}
			LIMIT %d OFFSET %d",
			...array_merge($args, [$perPage, ($page - 1) * $perPage])
		);

		return ['items' => $items, 'total' => $total];
	}

	private function orderBy(string $sort): string
	{
		$call = CallFilters::order($sort, 'r.organization_id', 'COALESCE(li.lead_score, -1) DESC, r.id ASC');

		if ($call !== null) {
			return $call;
		}

		$lead = LeadFilters::order($sort, 'COALESCE(r.relevance_score, -1) DESC, r.id ASC');

		if ($lead !== null) {
			return $lead;
		}

		return match ($sort) {
			'name' => 'c.name ASC, r.id ASC',
			'position' => 'r.position ASC, r.id ASC',
			'opportunity' => 'COALESCE(c.opportunity_score, -1) DESC, COALESCE(r.relevance_score, -1) DESC, r.id ASC',
			'contact_priority' => ContactFilters::ORDER . ', COALESCE(r.relevance_score, -1) DESC, r.id ASC',
			// Domyślnie: trafność, potem firmy z domeną, potem kolejność źródła.
			default => 'COALESCE(r.relevance_score, -1) DESC, (c.domain IS NULL) ASC, r.position ASC, r.id ASC',
		};
	}

	public function countDistinctCompaniesForOrganization(int $organizationId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(DISTINCT company_id) FROM {$this->db->table(Tables::RESULTS)} WHERE organization_id = %d",
			$organizationId
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recentCompaniesForOrganization(int $organizationId, int $limit = 8): array
	{
		return $this->db->rows(
			"SELECT c.id, c.name, c.domain, c.city, c.region, c.industry, MAX(r.created_at) AS found_at, MAX(p.id) AS prospect_id
			FROM {$this->db->table(Tables::RESULTS)} r
			INNER JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = r.company_id
			LEFT JOIN {$this->db->table(Tables::PROSPECTS)} p ON p.company_id = r.company_id AND p.organization_id = r.organization_id
			WHERE r.organization_id = %d
			GROUP BY c.id, c.name, c.domain, c.city, c.region, c.industry
			ORDER BY found_at DESC, c.id DESC
			LIMIT %d",
			$organizationId,
			$limit
		);
	}

	/**
	 * Źródła (dostawcy), przez które organizacja znalazła firmę.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function findingsForCompany(int $companyId, int $organizationId): array
	{
		return $this->db->rows(
			"SELECT r.search_id, r.provider, r.matched_terms, r.created_at, s.label
			FROM {$this->db->table(Tables::RESULTS)} r
			INNER JOIN {$this->db->table(Tables::SEARCHES)} s ON s.id = r.search_id
			WHERE r.company_id = %d AND r.organization_id = %d
			ORDER BY r.id DESC
			LIMIT 20",
			$companyId,
			$organizationId
		);
	}
}
