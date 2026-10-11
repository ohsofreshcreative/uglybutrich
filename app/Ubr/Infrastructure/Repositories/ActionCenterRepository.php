<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Domain\ActionCenter\ActionPriorityEngine;
use App\Ubr\Domain\ActionCenter\QueueCatalog;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Read model Action Center (Prompt 6): agregaty i listy w SQL (bez pobierania wszystkich firm do PHP, bez N+1).
 * KAŻDE zapytanie jest zawężone do organization_id. Widok bazowy = oceny Lead Intelligence organizacji (pula: wyniki
 * wyszukiwań + aktywni prospekci) + firma + prospekt; typ działania liczony wyrażeniem ActionPriorityEngine::sqlType().
 */
class ActionCenterRepository
{
	public function __construct(private Db $db)
	{
	}

	private function t(string $table): string
	{
		return $this->db->table($table);
	}

	/**
	 * Widok bazowy (alias x w zapytaniach zewnętrznych). Owner: all | mine | unassigned.
	 *
	 * @param list<mixed> $args
	 */
	private function base(int $organizationId, string $owner, int $userId, array &$args): string
	{
		$args[] = $userId; // viewer_id (SELECT) — przed organization_id (WHERE), kolejność placeholderów
		$args[] = $organizationId;
		$ownerSql = '';

		if ($owner === 'mine') {
			$ownerSql = ' AND p.owner_user_id = %d';
			$args[] = $userId;
		} elseif ($owner === 'unassigned') {
			$ownerSql = ' AND (p.id IS NULL OR p.owner_user_id IS NULL)';
		}

		return "(SELECT li.organization_id, li.company_id, li.id AS li_id, li.lead_score, li.score_status, li.confidence, li.coverage, li.redesign,
				li.recommendation, li.rank_score, li.has_blocker, li.stale,
				c.name, c.domain, c.website_url, c.city, c.region, c.industry, c.verification_status, c.domain_status, c.analysis_status, c.analyzed_at, c.contacts_status, c.contacts_count,
				c.top_contact_name, c.top_contact_title, c.top_contact_confidence, COALESCE(mr.review_status, 'not_reviewed') AS manual_review,
				p.id AS prospect_id, p.status AS prospect_status, p.priority AS p_priority, p.owner_user_id, p.next_action_at,
				COALESCE(p.do_not_contact, 0) AS p_dnc, %d AS viewer_id, c.main_phone_e164,
				" . CallFilters::readiness('li.organization_id') . " AS call_readiness,
				" . ActionPriorityEngine::sqlType() . " AS action_type
			FROM {$this->t(Tables::LEAD_INTELLIGENCE)} li
			INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = li.company_id
			LEFT JOIN {$this->t(Tables::PROSPECTS)} p ON p.organization_id = li.organization_id AND p.company_id = li.company_id
			LEFT JOIN {$this->t(Tables::MANUAL_REVIEWS)} mr ON mr.organization_id = li.organization_id AND mr.company_id = li.company_id
			WHERE li.organization_id = %d
				AND ((p.id IS NOT NULL AND p.archived_at IS NULL)
					OR (p.id IS NULL AND EXISTS (SELECT 1 FROM {$this->t(Tables::RESULTS)} r WHERE r.organization_id = li.organization_id AND r.company_id = li.company_id))){$ownerSql}
		) x";
	}

	private function snoozeJoin(): string
	{
		return " LEFT JOIN {$this->t(Tables::ACTION_SNOOZES)} s ON s.organization_id = x.organization_id AND s.company_id = x.company_id AND s.action_type = x.action_type AND s.snoozed_until > %s";
	}

	/**
	 * Liczniki wszystkich kolejek firm + działań (jedno zapytanie, SUM po warunkach).
	 *
	 * @return array<string, int>
	 */
	public function counts(int $organizationId, string $owner = 'all', int $userId = 0): array
	{
		$args = [];
		$from = $this->base($organizationId, $owner, $userId, $args);
		$args[] = Db::now();
		$sums = ['COUNT(*) AS evaluated', 'SUM(x.action_type IS NOT NULL AND s.id IS NULL AND (x.owner_user_id IS NULL OR x.owner_user_id = x.viewer_id)) AS actionable', 'SUM(s.id IS NOT NULL) AS snoozed'];

		foreach (array_keys(QueueCatalog::QUEUES) as $key) {
			if ($key !== 'followups') {
				$sums[] = 'SUM(' . QueueCatalog::where($key) . ") AS {$key}";
			}
		}

		foreach (array_keys(ActionPriorityEngine::TYPES) as $type) {
			if (in_array($type, ActionPriorityEngine::SNOOZABLE, true)) {
				$sums[] = "SUM(x.action_type = '{$type}' AND s.id IS NULL AND (x.owner_user_id IS NULL OR x.owner_user_id = x.viewer_id)) AS action_{$type}";
			}
		}

		$row = $this->db->row('SELECT ' . implode(', ', $sums) . " FROM {$from}" . $this->snoozeJoin(), ...$args) ?? [];

		return array_map('intval', $row);
	}

	/**
	 * Działania z rekomendacji (kategorie 3–8) w kolejności silnika, bez odłożonych. Firmy z follow-upem są wykluczone
	 * już w sqlType() (deduplikacja).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recommendedActions(int $organizationId, string $owner, int $userId, int $limit, int $offset = 0): array
	{
		$args = [];
		$from = $this->base($organizationId, $owner, $userId, $args);
		$args[] = Db::now();
		$args[] = $limit;
		$args[] = $offset;

		return $this->db->rows(
			'SELECT x.*, ' . $this->reasonSelect() . " FROM {$from}" . $this->snoozeJoin() . '
			LEFT JOIN ' . $this->t(Tables::LEAD_INTELLIGENCE) . ' lr ON lr.id = x.li_id
			WHERE x.action_type IS NOT NULL AND s.id IS NULL
				AND (x.owner_user_id IS NULL OR x.owner_user_id = x.viewer_id) -- firmy innego opiekuna nigdy w moich priorytetach (Prompt 10)
			ORDER BY ' . ActionPriorityEngine::sqlOrder() . '
			LIMIT %d OFFSET %d',
			...$args
		);
	}

	/**
	 * Strona kolejki firm z filtrami i sortowaniem.
	 *
	 * @param array{scope?: string, min_lead?: int, confidence?: string, search?: string, sort?: string} $f
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function queue(int $organizationId, string $key, string $owner, int $userId, array $f, int $limit, int $offset): array
	{
		$args = [];
		$from = $this->base($organizationId, $owner, $userId, $args);
		$args[] = Db::now();
		$where = QueueCatalog::where($key);

		$scope = $f['scope'] ?? 'all';
		if ($scope === 'prospects') {
			$where .= ' AND x.prospect_id IS NOT NULL';
		} elseif ($scope === 'not_prospects') {
			$where .= ' AND x.prospect_id IS NULL';
		}

		if (($f['min_lead'] ?? 0) > 0) {
			$where .= ' AND x.lead_score >= %d';
			$args[] = max(0, min(100, (int) $f['min_lead']));
		}

		if (in_array($f['confidence'] ?? '', LeadFilters::CONFIDENCE, true)) {
			$where .= ' AND x.confidence = %s';
			$args[] = $f['confidence'];
		}

		if (($industry = trim((string) ($f['industry'] ?? ''))) !== '') {
			$where .= ' AND x.industry LIKE %s';
			$args[] = '%' . $this->db->wpdb()->esc_like($industry) . '%';
		}

		if (($region = trim((string) ($f['region'] ?? ''))) !== '') {
			$where .= ' AND x.region = %s';
			$args[] = $region;
		}

		if (in_array($f['redesign'] ?? '', LeadFilters::REDESIGN, true)) {
			$where .= ' AND x.redesign = %s';
			$args[] = $f['redesign'];
		}

		if (in_array($f['recommendation'] ?? '', LeadFilters::ACTIONS, true)) {
			$where .= ' AND x.recommendation = %s';
			$args[] = $f['recommendation'];
		}

		// Prompt 10.2 — „Gotowe do telefonu”: bezpośrednio do decydenta (potwierdzony numer) / przez centralę firmy.
		if (in_array($f['call_kind'] ?? '', ['direct', 'company'], true)) {
			$where .= ' AND x.call_readiness = %s';
			$args[] = $f['call_kind'] === 'direct' ? 'READY_DIRECT' : 'CALL_COMPANY';
		}

		if (in_array($f['manual_review'] ?? '', ['not_reviewed', 'good', 'average', 'poor', 'very_poor'], true)) {
			$where .= ' AND x.manual_review = %s';
			$args[] = $f['manual_review'];
		}

		if (($search = trim((string) ($f['search'] ?? ''))) !== '') {
			$like = '%' . $this->db->wpdb()->esc_like($search) . '%';
			$where .= ' AND (x.name LIKE %s OR x.domain LIKE %s)';
			array_push($args, $like, $like);
		}

		$order = match ($f['sort'] ?? 'priority') {
			// Smart Lead Ranking (Prompt 5) — ta sama kolejność co LeadFilters::RANK_ORDER, bez nowego scoringu.
			'rank' => 'x.has_blocker ASC, (x.rank_score IS NULL) ASC, x.rank_score DESC, COALESCE(x.lead_score, -1) DESC, x.coverage DESC, x.company_id ASC',
			'lead' => 'COALESCE(x.lead_score, -1) DESC, x.coverage DESC, x.company_id ASC',
			'coverage' => 'x.coverage DESC, COALESCE(x.lead_score, -1) DESC, x.company_id ASC',
			'name' => 'x.name ASC, x.company_id ASC',
			// Domyślnie: kolejność silnika (kategoria nie ma znaczenia w obrębie jednej kolejki — liczą się remisy).
			default => '(x.action_type IS NULL) ASC, FIELD(x.action_type, ' . "'" . implode("','", array_keys(ActionPriorityEngine::TYPES)) . "'" . ') ASC, ' . "CASE x.p_priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END ASC, (x.rank_score IS NULL) ASC, x.rank_score DESC, COALESCE(x.lead_score, -1) DESC, x.coverage DESC, (x.prospect_id IS NULL) ASC, x.company_id ASC",
		};

		$total = (int) $this->db->value("SELECT COUNT(*) FROM {$from}" . $this->snoozeJoin() . " WHERE {$where}", ...$args);
		$items = $this->db->rows(
			'SELECT x.*, s.snoozed_until, cc.company_channels, JSON_EXTRACT(lr.result, \'$.warnings\') AS li_warnings, ' . $this->reasonSelect() . " FROM {$from}" . $this->snoozeJoin() . '
			LEFT JOIN ' . $this->t(Tables::LEAD_INTELLIGENCE) . ' lr ON lr.id = x.li_id
			LEFT JOIN ' . $this->t(Tables::COMPANIES) . " cc ON cc.id = x.company_id
			WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d",
			...array_merge($args, [$limit, $offset])
		);

		return ['items' => $items, 'total' => $total];
	}

	/** Uzasadnienia z zapisanej oceny Lead Intelligence (tylko dla wierszy strony — bez dodatkowych zapytań). */
	private function reasonSelect(): string
	{
		return "JSON_UNQUOTE(JSON_EXTRACT(lr.result, '$.recommendation.reason')) AS li_reason, JSON_UNQUOTE(JSON_EXTRACT(lr.result, '$.blockers[0].label')) AS blocker_label";
	}

	/**
	 * Województwa występujące w puli organizacji (opcje filtra).
	 *
	 * @return list<string>
	 */
	public function regions(int $organizationId): array
	{
		return array_values(array_filter(array_map('strval', $this->db->column(
			"SELECT DISTINCT c.region FROM {$this->t(Tables::LEAD_INTELLIGENCE)} li INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = li.company_id
			WHERE li.organization_id = %d AND c.region IS NOT NULL AND c.region <> '' ORDER BY c.region ASC LIMIT 50",
			$organizationId
		))));
	}

	/* ---------------------------------- follow-upy ---------------------------------- */

	/**
	 * @return array{0: string, 1: string, 2: string} [teraz, koniec dziś, koniec +7 dni] w UTC (granice dnia w strefie WP)
	 */
	public static function bounds(): array
	{
		[, $todayEnd] = PipelineRepository::todayBounds();
		$start = new \DateTimeImmutable('today', wp_timezone());
		$weekEnd = $start->modify('+8 days -1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

		return [Db::now(), $todayEnd, $weekEnd];
	}

	/**
	 * @param list<mixed> $args
	 */
	private function followupBase(int $organizationId, string $owner, int $userId, array &$args): string
	{
		$args[] = $organizationId;
		$ownerSql = '';

		if ($owner === 'mine') {
			$ownerSql = ' AND f.assigned_user_id = %d';
			$args[] = $userId;
		} elseif ($owner === 'unassigned') {
			$ownerSql = ' AND f.assigned_user_id IS NULL';
		}

		return "{$this->t(Tables::PROSPECT_FOLLOWUPS)} f
			INNER JOIN {$this->t(Tables::PROSPECTS)} p ON p.id = f.prospect_id AND p.organization_id = f.organization_id AND p.archived_at IS NULL
			INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = p.company_id
			LEFT JOIN {$this->t(Tables::LEAD_INTELLIGENCE)} li ON li.organization_id = f.organization_id AND li.company_id = p.company_id
			WHERE f.organization_id = %d AND f.status = 'open'{$ownerSql}";
	}

	/**
	 * @return array{overdue: int, today: int, upcoming: int, later: int}
	 */
	public function followupCounts(int $organizationId, string $owner = 'all', int $userId = 0): array
	{
		[$now, $todayEnd, $weekEnd] = self::bounds();
		$args = [$now, $now, $todayEnd, $todayEnd, $weekEnd, $weekEnd];
		$from = $this->followupBase($organizationId, $owner, $userId, $args);
		$row = $this->db->row("SELECT SUM(f.due_at < %s) AS overdue, SUM(f.due_at >= %s AND f.due_at <= %s) AS today, SUM(f.due_at > %s AND f.due_at <= %s) AS upcoming, SUM(f.due_at > %s) AS later FROM {$from}", ...$args) ?? [];

		return ['overdue' => (int) ($row['overdue'] ?? 0), 'today' => (int) ($row['today'] ?? 0), 'upcoming' => (int) ($row['upcoming'] ?? 0), 'later' => (int) ($row['later'] ?? 0)];
	}

	/**
	 * Follow-upy w przedziale: overdue | today | upcoming (7 dni) | open (wszystkie otwarte) | due (zaległe + dziś).
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function followups(int $organizationId, string $bucket, string $owner, int $userId, int $limit, int $offset = 0): array
	{
		[$now, $todayEnd, $weekEnd] = self::bounds();
		[$cond, $condArgs] = match ($bucket) {
			'overdue' => ['f.due_at < %s', [$now]],
			'today' => ['f.due_at >= %s AND f.due_at <= %s', [$now, $todayEnd]],
			'upcoming' => ['f.due_at > %s AND f.due_at <= %s', [$todayEnd, $weekEnd]],
			'due' => ['f.due_at <= %s', [$todayEnd]],
			default => ['1 = 1', []],
		};
		$args = [];
		$from = $this->followupBase($organizationId, $owner, $userId, $args);
		$args = array_merge($args, $condArgs);
		$total = (int) $this->db->value("SELECT COUNT(*) FROM {$from} AND {$cond}", ...$args);
		$items = $this->db->rows(
			"SELECT f.id, f.title, f.due_at, f.assigned_user_id, f.prospect_id, p.company_id, p.owner_user_id, p.status AS prospect_status, p.priority AS p_priority,
				c.name, c.domain, c.website_url, li.lead_score, li.confidence, li.coverage, li.has_blocker
			FROM {$from} AND {$cond}
			ORDER BY f.due_at ASC, f.id ASC LIMIT %d OFFSET %d",
			...array_merge($args, [$limit, $offset])
		);

		return ['items' => $items, 'total' => $total];
	}

	/* ---------------------------------- pokrycie i aktywność ---------------------------------- */

	/**
	 * Prospecting Coverage: niezależne liczniki puli organizacji (bez współczynników konwersji).
	 *
	 * @return array<string, int>
	 */
	public function coverage(int $organizationId): array
	{
		$in = "'" . implode("','", ActionPriorityEngine::ANALYZED) . "'";
		$row = $this->db->row(
			"SELECT COUNT(*) AS pool,
				SUM(c.verification_status = 'verified') AS verified,
				SUM(c.domain_status = 'confirmed') AS domain_confirmed,
				SUM(c.analyzed_at IS NOT NULL OR COALESCE(c.analysis_status, '') IN ({$in})) AS analyzed,
				SUM(c.contacts_status IS NOT NULL AND c.contacts_status NOT IN ('queued', 'running')) AS contacts_researched,
				SUM(COALESCE(c.contacts_count, 0) > 0) AS with_contacts,
				SUM(li.id IS NOT NULL) AS evaluated,
				SUM(li.stale = 1) AS stale
			FROM (SELECT company_id FROM {$this->t(Tables::RESULTS)} WHERE organization_id = %d
				UNION SELECT company_id FROM {$this->t(Tables::PROSPECTS)} WHERE organization_id = %d AND archived_at IS NULL) v
			INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = v.company_id
			LEFT JOIN {$this->t(Tables::LEAD_INTELLIGENCE)} li ON li.organization_id = %d AND li.company_id = v.company_id",
			$organizationId,
			$organizationId,
			$organizationId
		) ?? [];
		$q = (int) $this->db->value("SELECT COUNT(*) FROM {$this->t(Tables::PROSPECTS)} WHERE organization_id = %d AND archived_at IS NULL AND qualification = 'good_candidate'", $organizationId);

		return array_map('intval', $row) + ['qualified' => $q];
	}

	/**
	 * Ostatnia aktywność organizacji: zdarzenia prospektów (status, notatki, follow-upy) + zarejestrowane aktywności.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recentActivity(int $organizationId, int $limit = 10): array
	{
		$e = $this->t(Tables::PROSPECT_EVENTS);
		$a = $this->t(Tables::PROSPECT_ACTIVITIES);
		$p = $this->t(Tables::PROSPECTS);
		$c = $this->t(Tables::COMPANIES);

		return $this->db->rows(
			"SELECT * FROM (
				(SELECT 'event' AS kind, e.id, e.type, e.old_value, e.new_value, NULL AS description, e.user_id, e.created_at AS at, p.company_id, c.name AS company_name
					FROM {$e} e INNER JOIN {$p} p ON p.id = e.prospect_id AND p.organization_id = e.organization_id INNER JOIN {$c} c ON c.id = p.company_id
					WHERE e.organization_id = %d ORDER BY e.created_at DESC, e.id DESC LIMIT %d)
				UNION ALL
				(SELECT 'activity' AS kind, a.id, a.type, NULL, NULL, a.description, a.user_id, a.occurred_at AS at, p.company_id, c.name
					FROM {$a} a INNER JOIN {$p} p ON p.id = a.prospect_id AND p.organization_id = a.organization_id INNER JOIN {$c} c ON c.id = p.company_id
					WHERE a.organization_id = %d ORDER BY a.occurred_at DESC, a.id DESC LIMIT %d)
			) u ORDER BY u.at DESC, u.kind ASC, u.id DESC LIMIT %d",
			$organizationId,
			$limit,
			$organizationId,
			$limit,
			$limit
		);
	}

	/* ---------------------------------- odłożenie działania ---------------------------------- */

	public function snooze(int $organizationId, int $companyId, string $type, string $untilUtc, int $userId, ?string $reason): void
	{
		$now = Db::now();
		$this->db->execute(
			"INSERT INTO {$this->t(Tables::ACTION_SNOOZES)} (organization_id, company_id, action_type, snoozed_until, reason, user_id, created_at, updated_at)
			VALUES (%d, %d, %s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE snoozed_until = VALUES(snoozed_until), reason = VALUES(reason), user_id = VALUES(user_id), updated_at = VALUES(updated_at)",
			$organizationId,
			$companyId,
			$type,
			$untilUtc,
			(string) $reason,
			$userId,
			$now,
			$now
		);
	}

	public function unsnooze(int $organizationId, int $companyId, string $type): int
	{
		return $this->db->delete(Tables::ACTION_SNOOZES, ['organization_id' => $organizationId, 'company_id' => $companyId, 'action_type' => $type]);
	}

	/**
	 * Czy firma należy do puli organizacji (wyniki wyszukiwań lub aktywny prospekt) — ochrona IDOR.
	 */
	public function visible(int $organizationId, int $companyId): bool
	{
		return (bool) $this->db->value(
			"SELECT 1 FROM {$this->t(Tables::RESULTS)} WHERE organization_id = %d AND company_id = %d
			UNION SELECT 1 FROM {$this->t(Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d AND archived_at IS NULL LIMIT 1",
			$organizationId,
			$companyId,
			$organizationId,
			$companyId
		);
	}
}
