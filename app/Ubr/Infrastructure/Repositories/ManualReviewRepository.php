<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Ręczna ocena strony (M009) — prywatna dla organizacji: każde zapytanie zawężone do organization_id.
 */
class ManualReviewRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function current(int $organizationId, int $companyId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->db->table(Tables::MANUAL_REVIEWS)} WHERE organization_id = %d AND company_id = %d", $organizationId, $companyId);
	}

	/**
	 * Zapis bieżącej oceny + wpis w historii (poprzedni status zachowany). Zwraca poprzedni status.
	 */
	public function save(int $organizationId, int $companyId, string $status, ?string $note, int $userId): ?string
	{
		$now = Db::now();
		$previous = $this->current($organizationId, $companyId);
		$this->db->execute(
			"INSERT INTO {$this->db->table(Tables::MANUAL_REVIEWS)} (organization_id, company_id, review_status, review_note, reviewer_id, reviewed_at, created_at, updated_at)
			VALUES (%d, %d, %s, NULLIF(%s, ''), %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE review_status = VALUES(review_status), review_note = VALUES(review_note), reviewer_id = VALUES(reviewer_id), reviewed_at = VALUES(reviewed_at), updated_at = VALUES(updated_at)",
			$organizationId, $companyId, $status, (string) $note, $userId, $now, $now, $now
		);
		$this->db->insert(Tables::MANUAL_REVIEW_HISTORY, [
			'organization_id' => $organizationId,
			'company_id' => $companyId,
			'review_status' => $status,
			'previous_status' => $previous['review_status'] ?? null,
			'review_note' => ($note !== null && $note !== '') ? $note : null,
			'reviewer_id' => $userId,
			'reviewed_at' => $now,
		]);

		return $previous['review_status'] ?? null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function history(int $organizationId, int $companyId, int $limit = 20): array
	{
		return $this->db->rows("SELECT * FROM {$this->db->table(Tables::MANUAL_REVIEW_HISTORY)} WHERE organization_id = %d AND company_id = %d ORDER BY id DESC LIMIT %d", $organizationId, $companyId, $limit);
	}
}
