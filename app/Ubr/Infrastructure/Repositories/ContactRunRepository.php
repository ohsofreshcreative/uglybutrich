<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Wyszukiwania kontaktów (historia + kolejka). Każde wyszukiwanie ma własny rekord — historia nie jest nadpisywana.
 */
class ContactRunRepository
{
	public const ACTIVE = ['queued', 'running'];

	private const JSON = ['sources', 'pages', 'snapshot', 'changes', 'errors', 'company_channels'];

	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::CONTACT_RUNS);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::CONTACT_RUNS, $this->encode($data) + ['status' => 'queued', 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$data = $this->encode($data);
		$data['updated_at'] = Db::now();
		$this->db->update(Tables::CONTACT_RUNS, $data, ['id' => $id]);
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
	 * @return list<array<string, mixed>>
	 */
	public function historyForCompany(int $companyId, int $limit = 20): array
	{
		return array_map(fn ($r) => $this->decode($r), $this->db->rows(
			"SELECT id, company_id, organization_id, status, trigger_type, research_version, started_at, finished_at, duration_ms, candidates_found, top_count, sources, snapshot, changes, errors, created_at
			FROM {$this->t()} WHERE company_id = %d ORDER BY id DESC LIMIT %d",
			$companyId,
			$limit
		));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function claim(int $lockSeconds): ?array
	{
		$now = Db::now();

		for ($i = 0; $i < 3; $i++) {
			$row = $this->db->row(
				"SELECT id FROM {$this->t()} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s)) ORDER BY next_attempt_at ASC, id ASC LIMIT 1",
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

	public function countDue(): int
	{
		$now = Db::now();

		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s))", $now, $now);
	}

	public function countActiveForOrganization(int $organizationId): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE organization_id = %d AND status IN ('queued','running')", $organizationId);
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
