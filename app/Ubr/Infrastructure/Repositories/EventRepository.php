<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Dziennik zdarzeń zadań Discovery (postęp, błędy, ponowienia) oraz zdarzenia użycia (pod przyszłe limity/billing).
 */
class EventRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function log(int $searchId, string $level, string $message, array $context = []): void
	{
		$this->db->insert(Tables::EVENTS, [
			'search_id' => $searchId,
			'level' => $level,
			'message' => mb_substr($message, 0, 500),
			'context' => $context ? wp_json_encode($context) : null,
			'created_at' => Db::now(),
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function forSearch(int $searchId, int $limit = 50): array
	{
		return $this->db->rows(
			"SELECT level, message, created_at FROM {$this->db->table(Tables::EVENTS)} WHERE search_id = %d ORDER BY id DESC LIMIT %d",
			$searchId,
			$limit
		);
	}

	public function recordUsage(int $organizationId, ?int $userId, string $metric, int $quantity = 1, ?int $referenceId = null): void
	{
		$this->db->insert(Tables::USAGE, [
			'organization_id' => $organizationId,
			'user_id' => $userId,
			'metric' => $metric,
			'quantity' => $quantity,
			'reference_id' => $referenceId,
			'created_at' => Db::now(),
		]);
	}

	public function usageSince(int $organizationId, string $metric, string $sinceUtc): int
	{
		return (int) $this->db->value(
			"SELECT COALESCE(SUM(quantity), 0) FROM {$this->db->table(Tables::USAGE)} WHERE organization_id = %d AND metric = %s AND created_at >= %s",
			$organizationId,
			$metric,
			$sinceUtc
		);
	}
}
