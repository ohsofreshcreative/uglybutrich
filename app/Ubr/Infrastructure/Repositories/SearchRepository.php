<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

class SearchRepository
{
	public const ACTIVE_STATUSES = ['queued', 'running'];

	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::SEARCHES);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::SEARCHES, $this->encode($data) + [
			'status' => 'queued',
			'next_run_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	/**
	 * Bez zawężenia do organizacji — tylko dla procesów w tle (runner, CLI).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findForOrganization(int $id, int $organizationId): ?array
	{
		return $this->decode($this->db->row(
			"SELECT * FROM {$this->t()} WHERE id = %d AND organization_id = %d",
			$id,
			$organizationId
		));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$data = $this->encode($data);
		$data['updated_at'] = Db::now();
		$this->db->update(Tables::SEARCHES, $data, ['id' => $id]);
	}

	/**
	 * Atomowa blokada wyszukiwania na czas jednego kroku (chroni przed równoległym przetwarzaniem).
	 */
	public function acquireLock(int $id, int $seconds): bool
	{
		$now = Db::now();
		$until = gmdate('Y-m-d H:i:s', time() + $seconds);

		return $this->db->execute(
			"UPDATE {$this->t()} SET locked_until = %s WHERE id = %d AND (locked_until IS NULL OR locked_until < %s)",
			$until,
			$id,
			$now
		) === 1;
	}

	public function releaseLock(int $id): void
	{
		$this->db->execute("UPDATE {$this->t()} SET locked_until = NULL WHERE id = %d", $id);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listForOrganization(int $organizationId, int $limit = 20): array
	{
		global $wpdb;

		$rows = $this->db->rows(
			"SELECT s.id, s.label, s.criteria, s.providers, s.status, s.progress, s.results_count, s.with_domain_count, s.error, s.created_at, s.finished_at, u.display_name AS user_name
			FROM {$this->t()} s
			LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
			WHERE s.organization_id = %d
			ORDER BY s.id DESC
			LIMIT %d",
			$organizationId,
			$limit
		);

		return array_map(fn ($r) => $this->decode($r), $rows);
	}

	public function countActive(int $organizationId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->t()} WHERE organization_id = %d AND status IN ('queued','running')",
			$organizationId
		);
	}

	/**
	 * Zaległe zadania (dla WP-CLI `queue:work` i samonaprawy kolejki).
	 *
	 * @return list<int>
	 */
	public function dueIds(int $limit = 10): array
	{
		return array_map('intval', $this->db->column(
			"SELECT id FROM {$this->t()}
			WHERE status IN ('queued','running') AND (next_run_at IS NULL OR next_run_at <= %s) AND (locked_until IS NULL OR locked_until < %s)
			ORDER BY next_run_at ASC
			LIMIT %d",
			Db::now(),
			Db::now(),
			$limit
		));
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function encode(array $data): array
	{
		foreach (['criteria', 'state'] as $json) {
			if (array_key_exists($json, $data) && is_array($data[$json])) {
				$data[$json] = wp_json_encode($data[$json]);
			}
		}

		if (isset($data['providers']) && is_array($data['providers'])) {
			$data['providers'] = implode(',', $data['providers']);
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

		foreach (['id', 'organization_id', 'user_id', 'progress', 'results_count', 'with_domain_count', 'attempts', 'ticks', 'cancel_requested'] as $int) {
			if (array_key_exists($int, $row) && $row[$int] !== null) {
				$row[$int] = (int) $row[$int];
			}
		}

		foreach (['criteria', 'state'] as $json) {
			if (array_key_exists($json, $row)) {
				$row[$json] = json_decode((string) $row[$json], true) ?: [];
			}
		}

		if (array_key_exists('providers', $row)) {
			$row['providers'] = array_values(array_filter(explode(',', (string) $row['providers'])));
		}

		return $row;
	}
}
