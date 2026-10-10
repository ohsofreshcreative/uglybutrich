<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prywatna lista prospektów organizacji. KAŻDE zapytanie jest zawężone do organization_id.
 */
class ProspectRepository
{
	public const SORTABLE = [
		'name' => 'c.name',
		'domain' => 'c.domain',
		'city' => 'c.city',
		'region' => 'c.region',
		'industry' => 'c.industry',
		'created_at' => 'p.created_at',
		'relevance' => 'relevance_score',
		'verification' => 'c.verification_status',
		'opportunity' => 'opportunity_score',
		'contact_priority' => 'top_contact_score',
		'analyzed_at' => 'c.analyzed_at',
		'pipeline_status' => 'p.status',
		'priority' => 'p.priority',
		'next_action_at' => 'p.next_action_at',
	];

	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array{id: int, created: bool}
	 */
	public function add(int $organizationId, int $companyId, int $userId, ?int $searchId): array
	{
		$now = Db::now();
		$inserted = $this->db->execute(
			"INSERT IGNORE INTO {$this->db->table(Tables::PROSPECTS)} (organization_id, company_id, status, added_by, source_search_id, created_at, updated_at)
			VALUES (%d, %d, 'new', %d, %d, %s, %s)",
			$organizationId,
			$companyId,
			$userId,
			(int) $searchId,
			$now,
			$now
		) > 0;

		$id = (int) $this->idForCompany($organizationId, $companyId);

		// Ponowne dodanie zarchiwizowanego prospektu = przywrócenie (historia i notatki zostają).
		if (! $inserted) {
			$restored = $this->db->execute("UPDATE {$this->db->table(Tables::PROSPECTS)} SET archived_at = NULL, archived_by = NULL, updated_at = %s WHERE id = %d AND organization_id = %d AND archived_at IS NOT NULL", $now, $id, $organizationId) > 0;

			return ['id' => $id, 'created' => false, 'restored' => $restored];
		}

		return ['id' => $id, 'created' => true, 'restored' => false];
	}

	public function idForCompany(int $organizationId, int $companyId, bool $includeArchived = true): ?int
	{
		$id = $this->db->value(
			"SELECT id FROM {$this->db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d" . ($includeArchived ? '' : ' AND archived_at IS NULL'),
			$organizationId,
			$companyId
		);

		return $id ? (int) $id : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $organizationId, int $prospectId): ?array
	{
		return $this->db->row(
			"SELECT * FROM {$this->db->table(Tables::PROSPECTS)} WHERE id = %d AND organization_id = %d",
			$prospectId,
			$organizationId
		);
	}

	/**
	 * „Usunięcie” z Prospects = archiwizacja (globalna firma, analizy, notatki i historia zostają).
	 */
	public function remove(int $organizationId, int $prospectId, ?int $userId = null): int
	{
		return $this->db->execute("UPDATE {$this->db->table(Tables::PROSPECTS)} SET archived_at = %s, archived_by = %d, updated_at = %s WHERE id = %d AND organization_id = %d AND archived_at IS NULL", Db::now(), (int) $userId, Db::now(), $prospectId, $organizationId);
	}

	public function count(int $organizationId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND archived_at IS NULL",
			$organizationId
		);
	}

	/**
	 * @param array{search?: string, region?: string, has_domain?: string, industry?: string, verification?: string, domain_status?: string, min_relevance?: int|string} $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function paginate(int $organizationId, array $filters, string $sort, string $direction, int $page, int $perPage): array
	{
		$where = ['p.organization_id = %d', ($filters['archived'] ?? '') === '1' ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL'];
		$args = [$organizationId];

		// Filtry pipeline (whitelist).
		if (\App\Ubr\Domain\Pipeline\PipelineCatalog::isStatus((string) ($filters['pipeline_status'] ?? ''))) {
			$where[] = 'p.status = %s';
			$args[] = $filters['pipeline_status'];
		}

		if (isset(\App\Ubr\Domain\Pipeline\PipelineCatalog::PRIORITIES[(string) ($filters['priority'] ?? '')])) {
			$where[] = 'p.priority = %s';
			$args[] = $filters['priority'];
		}

		if (($owner = (string) ($filters['owner'] ?? '')) === 'none') {
			$where[] = 'p.owner_user_id IS NULL';
		} elseif (ctype_digit($owner)) {
			$where[] = 'p.owner_user_id = %d';
			$args[] = (int) $owner;
		}

		if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
			$like = '%' . $this->db->wpdb()->esc_like($search) . '%';
			$where[] = '(c.name LIKE %s OR c.domain LIKE %s OR c.city LIKE %s OR c.nip LIKE %s)';
			array_push($args, $like, $like, $like, $like);
		}

		if (($region = trim((string) ($filters['region'] ?? ''))) !== '') {
			$where[] = 'c.region = %s';
			$args[] = $region;
		}

		if (($industry = trim((string) ($filters['industry'] ?? ''))) !== '') {
			$where[] = 'c.industry LIKE %s';
			$args[] = '%' . $this->db->wpdb()->esc_like($industry) . '%';
		}

		if (in_array($filters['verification'] ?? '', ['pending', 'verified', 'uncertain', 'inactive', 'suspended'], true)) {
			$where[] = 'c.verification_status = %s';
			$args[] = $filters['verification'];
		}

		if (in_array($filters['domain_status'] ?? '', ['unchecked', 'confirmed', 'uncertain', 'mismatch', 'unavailable'], true)) {
			$where[] = 'c.domain_status = %s';
			$args[] = $filters['domain_status'];
		}

		if (($min = (int) ($filters['min_relevance'] ?? 0)) > 0) {
			$where[] = 'rel.relevance_score >= %d';
			$args[] = min(100, $min);
		}

		if (($minOpp = (int) ($filters['min_opportunity'] ?? 0)) > 0) {
			$where[] = 'c.opportunity_score >= %d';
			$args[] = min(100, $minOpp);
		}

		$where[] = match ($filters['analysis'] ?? '') {
			'analyzed' => "c.analysis_status IN ('completed','partial')",
			'not_analyzed' => 'c.analysis_status IS NULL',
			'failed' => "c.analysis_status = 'failed'",
			'review' => "(c.opportunity_confidence = 'low' OR c.analysis_status = 'partial' OR c.verification_status = 'uncertain')",
			default => '1=1',
		};

		// Filtry Decision Maker Findera (ma / nie ma decydenta, pewność, min. Contact Priority).
		$contactArgs = [];
		$contactWhere = ContactFilters::where($filters, $contactArgs);

		if ($contactWhere !== '') {
			$where[] = '1=1' . $contactWhere;
			array_push($args, ...$contactArgs);
		}

		if (($filters['has_domain'] ?? '') === 'yes') {
			$where[] = 'c.domain IS NOT NULL';
		} elseif (($filters['has_domain'] ?? '') === 'no') {
			$where[] = 'c.domain IS NULL';
		}

		$orderBy = self::SORTABLE[$sort] ?? 'p.created_at';
		$dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
		$whereSql = implode(' AND ', $where);
		// Trafność prospektu = najwyższa trafność firmy w wyszukiwaniach TEJ organizacji.
		$from = "{$this->db->table(Tables::PROSPECTS)} p
			INNER JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = p.company_id
			LEFT JOIN (
				SELECT company_id, MAX(relevance_score) AS relevance_score FROM {$this->db->table(Tables::RESULTS)} WHERE organization_id = %d GROUP BY company_id
			) rel ON rel.company_id = p.company_id";
		array_unshift($args, $organizationId);

		$total = (int) $this->db->value("SELECT COUNT(*) FROM {$from} WHERE {$whereSql}", ...$args);
		$items = $this->db->rows(
			"SELECT c.*, p.id AS prospect_id, p.status, p.status AS pipeline_status, p.priority, p.owner_user_id, p.qualification, p.next_action_at, p.last_activity_at, p.archived_at, p.created_at AS saved_at, rel.relevance_score,
				(SELECT e.task_url FROM {$this->db->table(Tables::CLICKUP_EXPORTS)} e WHERE e.organization_id = p.organization_id AND e.company_id = p.company_id AND e.export_status = 'success' ORDER BY e.id DESC LIMIT 1) AS clickup_task_url
			FROM {$from}
			WHERE {$whereSql}
			ORDER BY " . match ($orderBy) {
				'relevance_score' => 'COALESCE(rel.relevance_score, -1)',
				'opportunity_score' => 'COALESCE(c.opportunity_score, -1)',
				'top_contact_score' => 'COALESCE(c.top_contact_score, -1)',
				'p.priority' => "FIELD(p.priority, 'low', 'medium', 'high', 'urgent')",
				'p.next_action_at' => 'COALESCE(p.next_action_at, \'9999-12-31\')',
				default => $orderBy,
			} . " {$dir}, p.id DESC
			LIMIT %d OFFSET %d",
			...array_merge($args, [$perPage, ($page - 1) * $perPage])
		);

		return ['items' => $items, 'total' => $total];
	}

	/**
	 * @return list<string>
	 */
	public function regions(int $organizationId): array
	{
		return $this->db->column(
			"SELECT DISTINCT c.region FROM {$this->db->table(Tables::PROSPECTS)} p
			INNER JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = p.company_id
			WHERE p.organization_id = %d AND c.region IS NOT NULL AND c.region <> ''
			ORDER BY c.region",
			$organizationId
		);
	}
}
