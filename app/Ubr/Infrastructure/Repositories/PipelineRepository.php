<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prywatne dane sprzedażowe organizacji: historia zmian, notatki, aktywności, follow-upy, tablica, statystyki.
 * KAŻDE zapytanie jest zawężone do organization_id (ochrona IDOR po identyfikatorach).
 */
class PipelineRepository
{
	public function __construct(private Db $db)
	{
	}

	private function t(string $table): string
	{
		return $this->db->table($table);
	}

	/* ---------------------------------- prospekt ---------------------------------- */

	/**
	 * @return array<string, mixed>|null
	 */
	public function prospect(int $organizationId, int $prospectId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t(Tables::PROSPECTS)} WHERE id = %d AND organization_id = %d", $prospectId, $organizationId);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function prospectForCompany(int $organizationId, int $companyId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t(Tables::PROSPECTS)} WHERE company_id = %d AND organization_id = %d", $companyId, $organizationId);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateProspect(int $organizationId, int $prospectId, array $data): void
	{
		$data['updated_at'] = Db::now();
		$this->db->update(Tables::PROSPECTS, $data, ['id' => $prospectId, 'organization_id' => $organizationId]);
	}

	public function event(int $organizationId, int $prospectId, ?int $userId, string $type, ?string $old, ?string $new, array $meta = []): void
	{
		$this->db->insert(Tables::PROSPECT_EVENTS, [
			'organization_id' => $organizationId,
			'prospect_id' => $prospectId,
			'user_id' => $userId,
			'type' => $type,
			'old_value' => $old !== null ? mb_substr($old, 0, 255) : null,
			'new_value' => $new !== null ? mb_substr($new, 0, 255) : null,
			'meta' => $meta ? wp_json_encode($meta) : null,
			'created_at' => Db::now(),
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function events(int $organizationId, int $prospectId, int $limit = 50): array
	{
		return $this->db->rows("SELECT * FROM {$this->t(Tables::PROSPECT_EVENTS)} WHERE organization_id = %d AND prospect_id = %d ORDER BY id DESC LIMIT %d", $organizationId, $prospectId, $limit);
	}

	/* ---------------------------------- notatki ---------------------------------- */

	public function addNote(int $organizationId, int $prospectId, int $authorId, string $body): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::PROSPECT_NOTES, ['organization_id' => $organizationId, 'prospect_id' => $prospectId, 'author_id' => $authorId, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function note(int $organizationId, int $noteId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t(Tables::PROSPECT_NOTES)} WHERE id = %d AND organization_id = %d AND deleted_at IS NULL", $noteId, $organizationId);
	}

	public function updateNote(int $organizationId, int $noteId, string $body): void
	{
		$this->db->update(Tables::PROSPECT_NOTES, ['body' => $body, 'updated_at' => Db::now()], ['id' => $noteId, 'organization_id' => $organizationId]);
	}

	public function deleteNote(int $organizationId, int $noteId, int $userId): void
	{
		// Miękkie usunięcie: treść znika z widoku, zostaje ślad w historii (kto i kiedy).
		$this->db->update(Tables::PROSPECT_NOTES, ['deleted_at' => Db::now(), 'deleted_by' => $userId, 'updated_at' => Db::now()], ['id' => $noteId, 'organization_id' => $organizationId]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function notes(int $organizationId, int $prospectId): array
	{
		return $this->db->rows("SELECT * FROM {$this->t(Tables::PROSPECT_NOTES)} WHERE organization_id = %d AND prospect_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 200", $organizationId, $prospectId);
	}

	/* ---------------------------------- aktywności ---------------------------------- */

	/**
	 * @param array<string, mixed> $data
	 */
	public function addActivity(array $data): int
	{
		return $this->db->insert(Tables::PROSPECT_ACTIVITIES, $data + ['created_at' => Db::now()]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function activities(int $organizationId, int $prospectId): array
	{
		return $this->db->rows("SELECT * FROM {$this->t(Tables::PROSPECT_ACTIVITIES)} WHERE organization_id = %d AND prospect_id = %d ORDER BY occurred_at DESC, id DESC LIMIT 200", $organizationId, $prospectId);
	}

	/* ---------------------------------- follow-upy ---------------------------------- */

	/**
	 * @param array<string, mixed> $data
	 */
	public function addFollowup(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::PROSPECT_FOLLOWUPS, $data + ['status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function followup(int $organizationId, int $id): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t(Tables::PROSPECT_FOLLOWUPS)} WHERE id = %d AND organization_id = %d", $id, $organizationId);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateFollowup(int $organizationId, int $id, array $data): void
	{
		$this->db->update(Tables::PROSPECT_FOLLOWUPS, $data + ['updated_at' => Db::now()], ['id' => $id, 'organization_id' => $organizationId]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function followups(int $organizationId, int $prospectId): array
	{
		return $this->db->rows("SELECT * FROM {$this->t(Tables::PROSPECT_FOLLOWUPS)} WHERE organization_id = %d AND prospect_id = %d ORDER BY (status = 'open') DESC, due_at ASC, id DESC LIMIT 200", $organizationId, $prospectId);
	}

	/**
	 * Najbliższy otwarty termin → `next_action_at` prospektu (zdenormalizowane do list i tablicy).
	 */
	public function refreshNextAction(int $organizationId, int $prospectId): void
	{
		$next = $this->db->value("SELECT MIN(due_at) FROM {$this->t(Tables::PROSPECT_FOLLOWUPS)} WHERE organization_id = %d AND prospect_id = %d AND status = 'open'", $organizationId, $prospectId);
		$this->db->update(Tables::PROSPECTS, ['next_action_at' => $next ?: null], ['id' => $prospectId, 'organization_id' => $organizationId]);
	}

	/**
	 * Działania organizacji: zaległe / dziś / nadchodzące (otwarte), opcjonalnie tylko przypisane użytkownikowi.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function agenda(int $organizationId, string $bucket, ?int $userId = null, int $limit = 20): array
	{
		[, $todayEnd] = self::todayBounds();
		$where = match ($bucket) {
			'overdue' => 'f.due_at < %s',
			'today' => 'f.due_at BETWEEN %s AND %s',
			default => 'f.due_at > %s',
		};
		// Zaległe = termin minął (spójnie z oznaczeniem „zaległe” na kartach); dziś = od teraz do końca dnia.
		$now = Db::now();
		$args = match ($bucket) {
			'overdue' => [$now],
			'today' => [$now, $todayEnd],
			default => [$todayEnd],
		};
		$user = $userId ? ' AND f.assigned_user_id = ' . (int) $userId : '';

		return $this->db->rows(
			"SELECT f.*, p.company_id, c.name AS company_name, c.domain
			FROM {$this->t(Tables::PROSPECT_FOLLOWUPS)} f
			INNER JOIN {$this->t(Tables::PROSPECTS)} p ON p.id = f.prospect_id AND p.organization_id = f.organization_id AND p.archived_at IS NULL
			INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = p.company_id
			WHERE f.organization_id = %d AND f.status = 'open' AND {$where}{$user}
			ORDER BY f.due_at ASC, f.id ASC LIMIT %d",
			$organizationId,
			...array_merge($args, [$limit])
		);
	}

	/**
	 * @return array{overdue: int, today: int, upcoming: int}
	 */
	public function agendaCounts(int $organizationId): array
	{
		[, $todayEnd] = self::todayBounds();
		$row = $this->db->row(
			"SELECT SUM(f.due_at < %s) AS overdue, SUM(f.due_at BETWEEN %s AND %s) AS today, SUM(f.due_at > %s) AS upcoming
			FROM {$this->t(Tables::PROSPECT_FOLLOWUPS)} f
			INNER JOIN {$this->t(Tables::PROSPECTS)} p ON p.id = f.prospect_id AND p.organization_id = f.organization_id AND p.archived_at IS NULL
			WHERE f.organization_id = %d AND f.status = 'open'",
			Db::now(),
			Db::now(),
			$todayEnd,
			$todayEnd,
			$organizationId
		) ?? [];

		return ['overdue' => (int) ($row['overdue'] ?? 0), 'today' => (int) ($row['today'] ?? 0), 'upcoming' => (int) ($row['upcoming'] ?? 0)];
	}

	/**
	 * Granice „dziś” w strefie czasowej witryny, wyrażone w UTC (daty w bazie są w UTC).
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function todayBounds(): array
	{
		$start = new \DateTimeImmutable('today', wp_timezone());
		$utc = new \DateTimeZone('UTC');

		return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $start->modify('+1 day -1 second')->setTimezone($utc)->format('Y-m-d H:i:s')];
	}

	/* ---------------------------------- tablica i statystyki ---------------------------------- */

	/**
	 * Karty jednej kolumny (statusu) — stronicowane (lazy loading na tablicy).
	 *
	 * @param array<string, mixed> $filters
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function column(int $organizationId, string $status, array $filters, int $offset, int $limit): array
	{
		[$where, $args] = $this->boardWhere($organizationId, $filters);
		$where .= ' AND p.status = %s';
		$args[] = $status;
		$from = $this->boardFrom($organizationId);
		array_unshift($args, $organizationId);
		$total = (int) $this->db->value("SELECT COUNT(*) FROM {$from} WHERE {$where}", ...$args);
		$items = $this->db->rows(
			"SELECT p.id AS prospect_id, p.status AS pipeline_status, p.priority, p.owner_user_id, p.qualification, p.next_action_at, p.last_activity_at, p.selected_contact_id, p.created_at AS saved_at,
				c.id, c.name, c.domain, c.website_url, c.opportunity_score, c.opportunity_level, c.top_contact_name, c.top_contact_title, c.top_contact_score, c.top_contact_confidence, rel.relevance_score
			FROM {$from} WHERE {$where}
			ORDER BY FIELD(p.priority, 'urgent', 'high', 'medium', 'low'), (p.next_action_at IS NULL), p.next_action_at ASC, p.id DESC
			LIMIT %d OFFSET %d",
			...array_merge($args, [$limit, $offset])
		);

		return ['items' => $items, 'total' => $total];
	}

	/**
	 * Liczba prospektów w każdym statusie (z filtrami tablicy) — jedno zapytanie GROUP BY.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<string, int>
	 */
	public function statusCounts(int $organizationId, array $filters = []): array
	{
		[$where, $args] = $this->boardWhere($organizationId, $filters);
		$from = $this->boardFrom($organizationId);
		array_unshift($args, $organizationId);
		$out = [];

		foreach ($this->db->rows("SELECT p.status, COUNT(*) AS n FROM {$from} WHERE {$where} GROUP BY p.status", ...$args) as $r) {
			$out[(string) $r['status']] = (int) $r['n'];
		}

		return $out;
	}

	public function countExported(int $organizationId): int
	{
		return (int) $this->db->value("SELECT COUNT(DISTINCT company_id) FROM {$this->t(Tables::CLICKUP_EXPORTS)} WHERE organization_id = %d AND export_status = 'success'", $organizationId);
	}

	private function boardFrom(int $organizationId): string
	{
		return "{$this->t(Tables::PROSPECTS)} p
			INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = p.company_id
			LEFT JOIN (SELECT company_id, MAX(relevance_score) AS relevance_score FROM {$this->t(Tables::RESULTS)} WHERE organization_id = %d GROUP BY company_id) rel ON rel.company_id = p.company_id";
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function boardWhere(int $organizationId, array $filters): array
	{
		$where = 'p.organization_id = %d';
		$args = [$organizationId];
		$where .= ! empty($filters['archived']) ? ' AND p.archived_at IS NOT NULL' : ' AND p.archived_at IS NULL';

		if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
			$like = '%' . $this->db->wpdb()->esc_like($search) . '%';
			$where .= ' AND (c.name LIKE %s OR c.domain LIKE %s)';
			array_push($args, $like, $like);
		}

		if (($owner = (string) ($filters['owner'] ?? '')) !== '') {
			if ($owner === 'none') {
				$where .= ' AND p.owner_user_id IS NULL';
			} elseif (ctype_digit($owner)) {
				$where .= ' AND p.owner_user_id = %d';
				$args[] = (int) $owner;
			}
		}

		if (isset(\App\Ubr\Domain\Pipeline\PipelineCatalog::PRIORITIES[(string) ($filters['priority'] ?? '')])) {
			$where .= ' AND p.priority = %s';
			$args[] = $filters['priority'];
		}

		if (isset(\App\Ubr\Domain\Pipeline\PipelineCatalog::QUALIFICATIONS[(string) ($filters['qualification'] ?? '')])) {
			$where .= ' AND p.qualification = %s';
			$args[] = $filters['qualification'];
		} elseif (($filters['qualification'] ?? '') === 'none') {
			$where .= ' AND p.qualification IS NULL';
		}

		$due = (string) ($filters['overdue'] ?? '');

		if ($due === '1' || $due === 'yes') {
			$where .= ' AND p.next_action_at IS NOT NULL AND p.next_action_at < %s';
			$args[] = Db::now();
		} elseif ($due === 'planned') {
			$where .= ' AND p.next_action_at IS NOT NULL AND p.next_action_at >= %s';
			$args[] = Db::now();
		} elseif ($due === 'none') {
			$where .= ' AND p.next_action_at IS NULL';
		}

		return [$where, $args];
	}
}
