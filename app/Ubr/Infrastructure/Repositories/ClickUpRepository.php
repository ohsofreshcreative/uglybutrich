<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\DatabaseException;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Dane integracji ClickUp: połączenia (per użytkownik + organizacja), eksporty (deduplikacja), kolejka / historia
 * operacji, reguły. Zapytania zawsze zawężone do organizacji (i użytkownika dla połączeń).
 */
class ClickUpRepository
{
	private const JSON = ['authorized_teams', 'destination', 'settings', 'conditions', 'result'];

	public function __construct(private Db $db)
	{
	}

	private function t(string $table): string
	{
		return $this->db->table($table);
	}

	/* ------------------------------ połączenia ------------------------------ */

	/**
	 * @return array<string, mixed>|null
	 */
	public function connection(int $organizationId, int $userId): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t(Tables::CLICKUP_CONNECTIONS)} WHERE organization_id = %d AND user_id = %d", $organizationId, $userId));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function connectionById(int $id): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t(Tables::CLICKUP_CONNECTIONS)} WHERE id = %d", $id));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function saveConnection(int $organizationId, int $userId, array $data): int
	{
		$existing = $this->connection($organizationId, $userId);
		$now = Db::now();
		$data = $this->encode($data);

		if ($existing) {
			$this->db->update(Tables::CLICKUP_CONNECTIONS, $data + ['updated_at' => $now], ['id' => (int) $existing['id']]);

			return (int) $existing['id'];
		}

		return $this->db->insert(Tables::CLICKUP_CONNECTIONS, $data + ['organization_id' => $organizationId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
	}

	public function updateConnection(int $id, array $data): void
	{
		$this->db->update(Tables::CLICKUP_CONNECTIONS, $this->encode($data) + ['updated_at' => Db::now()], ['id' => $id]);
	}

	public function deleteConnection(int $organizationId, int $userId): void
	{
		$this->db->delete(Tables::CLICKUP_CONNECTIONS, ['organization_id' => $organizationId, 'user_id' => $userId]);
	}

	/* ------------------------------ eksporty ------------------------------ */

	/**
	 * @return array<string, mixed>|null
	 */
	public function export(int $organizationId, int $exportId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t(Tables::CLICKUP_EXPORTS)} WHERE id = %d AND organization_id = %d", $exportId, $organizationId);
	}

	/**
	 * Aktualny eksport firmy do listy w organizacji (najwyższy wariant).
	 *
	 * @return array<string, mixed>|null
	 */
	public function exportFor(int $organizationId, int $companyId, string $listId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t(Tables::CLICKUP_EXPORTS)} WHERE organization_id = %d AND company_id = %d AND list_id = %s ORDER BY variant DESC LIMIT 1", $organizationId, $companyId, $listId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function exportsForCompany(int $organizationId, int $companyId): array
	{
		return $this->db->rows("SELECT * FROM {$this->t(Tables::CLICKUP_EXPORTS)} WHERE organization_id = %d AND company_id = %d ORDER BY id DESC LIMIT 20", $organizationId, $companyId);
	}

	/**
	 * Rezerwacja eksportu (unikalny klucz org + firma + lista + wariant = idempotencja przy równoległych kliknięciach).
	 *
	 * @param array<string, mixed> $data
	 * @return int|null null = już istnieje (wyścig) — nie tworzymy drugiego
	 */
	public function reserveExport(array $data): ?int
	{
		$now = Db::now();

		try {
			return $this->db->insert(Tables::CLICKUP_EXPORTS, $data + ['export_status' => 'queued', 'created_at' => $now, 'updated_at' => $now]);
		} catch (DatabaseException $e) {
			if ($e->duplicate) {
				return null;
			}

			throw $e;
		}
	}

	public function updateExport(int $id, array $data): void
	{
		$this->db->update(Tables::CLICKUP_EXPORTS, $data + ['updated_at' => Db::now()], ['id' => $id]);
	}

	public function countNewTasksSince(int $organizationId, string $sinceUtc, ?int $ruleId = null): int
	{
		$rule = $ruleId ? ' AND rule_id = ' . (int) $ruleId : '';

		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t(Tables::CLICKUP_EXPORTS)} WHERE organization_id = %d AND created_at >= %s AND export_status IN ('queued','running','success','unknown'){$rule}", $organizationId, $sinceUtc);
	}

	/* ------------------------------ kolejka / historia ------------------------------ */

	/**
	 * @param array<string, mixed> $data
	 */
	public function createJob(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::CLICKUP_JOBS, $this->encode($data) + ['status' => 'queued', 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
	}

	public function updateJob(int $id, array $data): void
	{
		$this->db->update(Tables::CLICKUP_JOBS, $this->encode($data) + ['updated_at' => Db::now()], ['id' => $id]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function job(int $id): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t(Tables::CLICKUP_JOBS)} WHERE id = %d", $id));
	}

	public function hasActiveJob(int $organizationId, int $companyId, string $operation, ?int $exportId = null, ?int $ruleId = null): bool
	{
		$extra = ($exportId ? ' AND export_id = ' . (int) $exportId : '') . ($ruleId ? ' AND rule_id = ' . (int) $ruleId : '');

		return (bool) $this->db->value("SELECT 1 FROM {$this->t(Tables::CLICKUP_JOBS)} WHERE organization_id = %d AND company_id = %d AND operation = %s AND status IN ('queued','running'){$extra} LIMIT 1", $organizationId, $companyId, $operation);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function claim(int $lockSeconds): ?array
	{
		$now = Db::now();
		$t = $this->t(Tables::CLICKUP_JOBS);

		for ($i = 0; $i < 3; $i++) {
			$row = $this->db->row("SELECT id FROM {$t} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s)) ORDER BY next_attempt_at ASC, id ASC LIMIT 1", $now, $now);

			if (! $row) {
				return null;
			}

			if ($this->db->execute("UPDATE {$t} SET status = 'running', locked_until = %s, attempts = attempts + 1, started_at = COALESCE(started_at, %s), updated_at = %s WHERE id = %d AND (status = 'queued' OR (status = 'running' AND locked_until < %s))", gmdate('Y-m-d H:i:s', time() + $lockSeconds), $now, $now, (int) $row['id'], $now) > 0) {
				return $this->job((int) $row['id']);
			}
		}

		return null;
	}

	public function countDue(): int
	{
		$now = Db::now();

		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t(Tables::CLICKUP_JOBS)} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s))", $now, $now);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function jobsForCompany(int $organizationId, int $companyId, int $limit = 30): array
	{
		return array_map(fn ($r) => $this->decode($r), $this->db->rows("SELECT * FROM {$this->t(Tables::CLICKUP_JOBS)} WHERE organization_id = %d AND company_id = %d AND operation IN ('create','update') ORDER BY id DESC LIMIT %d", $organizationId, $companyId, $limit));
	}

	/**
	 * @return array<string, int>
	 */
	public function batchCounts(int $organizationId, string $batchId): array
	{
		$out = [];

		foreach ($this->db->rows("SELECT status, COUNT(*) AS n FROM {$this->t(Tables::CLICKUP_JOBS)} WHERE organization_id = %d AND batch_id = %s GROUP BY status", $organizationId, $batchId) as $r) {
			$out[$r['status']] = (int) $r['n'];
		}

		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recentJobs(int $organizationId, int $limit = 30): array
	{
		return array_map(fn ($r) => $this->decode($r), $this->db->rows("SELECT j.*, c.name AS company_name FROM {$this->t(Tables::CLICKUP_JOBS)} j LEFT JOIN {$this->t(Tables::COMPANIES)} c ON c.id = j.company_id WHERE j.organization_id = %d ORDER BY j.id DESC LIMIT %d", $organizationId, $limit));
	}

	/* ------------------------------ reguły ------------------------------ */

	/**
	 * @return array<string, mixed>|null
	 */
	public function rule(int $organizationId, int $ruleId): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t(Tables::CLICKUP_RULES)} WHERE id = %d AND organization_id = %d", $ruleId, $organizationId));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rules(int $organizationId): array
	{
		return array_map(fn ($r) => $this->decode($r), $this->db->rows("SELECT * FROM {$this->t(Tables::CLICKUP_RULES)} WHERE organization_id = %d ORDER BY id DESC", $organizationId));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function enabledRules(?int $organizationId = null): array
	{
		$where = $organizationId ? ' AND organization_id = ' . (int) $organizationId : '';

		return array_map(fn ($r) => $this->decode($r), $this->db->rows("SELECT * FROM {$this->t(Tables::CLICKUP_RULES)} WHERE enabled = 1{$where}"));
	}

	public function createRule(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::CLICKUP_RULES, $this->encode($data) + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function updateRule(int $id, array $data): void
	{
		$this->db->update(Tables::CLICKUP_RULES, $this->encode($data) + ['updated_at' => Db::now()], ['id' => $id]);
	}

	public function deleteRule(int $organizationId, int $id): void
	{
		$this->db->delete(Tables::CLICKUP_RULES, ['id' => $id, 'organization_id' => $organizationId]);
	}

	public function incrementRule(int $id, string $column): void
	{
		if (in_array($column, ['exported_count', 'error_count'], true)) {
			$this->db->execute("UPDATE {$this->t(Tables::CLICKUP_RULES)} SET {$column} = {$column} + 1, last_run_at = %s WHERE id = %d", Db::now(), $id);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function encode(array $data): array
	{
		foreach (self::JSON as $key) {
			if (array_key_exists($key, $data) && is_array($data[$key])) {
				$data[$key] = wp_json_encode($data[$key]);
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, mixed>|null
	 */
	private function decode(?array $row): ?array
	{
		if (! $row) {
			return null;
		}

		foreach (self::JSON as $key) {
			if (array_key_exists($key, $row)) {
				$row[$key] = $row[$key] !== null ? (json_decode((string) $row[$key], true) ?: []) : [];
			}
		}

		return $row;
	}
}
