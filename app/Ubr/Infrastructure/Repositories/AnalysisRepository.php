<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Analizy stron (historia + kolejka). Wiersz nigdy nie jest nadpisywany przez kolejną analizę — każda ma własny rekord.
 */
class AnalysisRepository
{
	public const ACTIVE = ['queued', 'running'];

	private const JSON = ['results', 'findings', 'errors', 'tools', 'screenshots'];

	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::ANALYSES);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::ANALYSES, $this->encode($data) + ['status' => 'queued', 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$data = $this->encode($data);
		$data['updated_at'] = Db::now();
		$this->db->update(Tables::ANALYSES, $data, ['id' => $id]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function latestForCompany(int $companyId, bool $finishedOnly = false): ?array
	{
		$where = $finishedOnly ? " AND status IN ('completed','partial')" : '';

		return $this->decode($this->db->row("SELECT * FROM {$this->t()} WHERE company_id = %d{$where} ORDER BY id DESC LIMIT 1", $companyId));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function activeForCompany(int $companyId): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t()} WHERE company_id = %d AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", $companyId));
	}

	/**
	 * Historia (bez wyników szczegółowych — lekkie wiersze do listy).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function historyForCompany(int $companyId, int $limit = 20): array
	{
		return $this->db->rows(
			"SELECT id, company_id, organization_id, status, trigger_type, algorithm_version, started_at, finished_at, duration_ms, opportunity_score, opportunity_level, confidence, coverage, findings_count, created_at
			FROM {$this->t()} WHERE company_id = %d ORDER BY id DESC LIMIT %d",
			$companyId,
			$limit
		);
	}

	/**
	 * Pobiera i blokuje kolejną analizę do wykonania.
	 *
	 * @return array<string, mixed>|null
	 */
	public function claim(int $lockSeconds): ?array
	{
		$now = Db::now();

		for ($i = 0; $i < 3; $i++) {
			$row = $this->db->row(
				"SELECT id FROM {$this->t()} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s)) ORDER BY (trigger_type = 'auto') ASC, next_attempt_at ASC, id ASC LIMIT 1",
				$now,
				$now
			);

			if (! $row) {
				return null;
			}

			$claimed = $this->db->execute(
				"UPDATE {$this->t()} SET status = 'running', locked_until = %s, attempts = attempts + 1, started_at = COALESCE(started_at, %s), updated_at = %s
				WHERE id = %d AND (status = 'queued' OR (status = 'running' AND locked_until < %s))",
				gmdate('Y-m-d H:i:s', time() + $lockSeconds),
				$now,
				$now,
				(int) $row['id'],
				$now
			);

			if ($claimed > 0) {
				return $this->find((int) $row['id']);
			}
		}

		return null;
	}

	/**
	 * Pozycje „running” z wygasłą blokadą i wyczerpanym limitem prób (worker zabity: timeout, OOM, restart PHP) —
	 * oznacza je jako 'failed' (warunkowo, bez wyścigu) i zwraca. Bez tego claim() podejmował je w nieskończoność.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function failExhausted(int $maxAttempts): array
	{
		$now = Db::now();
		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->t()} WHERE status = 'running' AND locked_until < %s AND attempts >= %d ORDER BY id ASC LIMIT 20", $now, $maxAttempts) as $row) {
			if ($this->db->execute("UPDATE {$this->t()} SET status = 'failed', locked_until = NULL, updated_at = %s WHERE id = %d AND status = 'running' AND locked_until < %s", $now, (int) $row['id'], $now) > 0) {
				$out[] = $row;
			}
		}

		return $out;
	}

	public function countDue(): int
	{
		$now = Db::now();

		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s))", $now, $now);
	}

	public function countRunning(): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE status = 'running' AND locked_until >= %s", Db::now());
	}

	/** Prompt 10.4.3 — liczba analiz automatycznych (Discover) organizacji od podanej chwili (osobny limit dzienny). */
	public function countAutoForOrganizationSince(int $organizationId, string $sinceUtc): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->t()} WHERE organization_id = %d AND trigger_type = 'auto' AND created_at >= %s",
			$organizationId,
			$sinceUtc
		);
	}

	public function countForOrganizationSince(int $organizationId, string $sinceUtc): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->t()} WHERE organization_id = %d AND trigger_type IN ('manual','bulk') AND created_at >= %s",
			$organizationId,
			$sinceUtc
		);
	}

	/**
	 * @param list<int> $companyIds
	 * @return array<string, int>
	 */
	public function statusCounts(array $companyIds): array
	{
		if ($companyIds === []) {
			return [];
		}

		$out = [];

		foreach ($this->db->rows("SELECT status, COUNT(*) AS n FROM {$this->t()} WHERE company_id IN (" . Db::placeholders($companyIds) . ") AND status IN ('queued','running') GROUP BY status", $companyIds) as $r) {
			$out[$r['status']] = (int) $r['n'];
		}

		return $out;
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
			$row[$key] = isset($row[$key]) && $row[$key] !== null ? (json_decode((string) $row[$key], true) ?: []) : [];
		}

		return $row;
	}
}
