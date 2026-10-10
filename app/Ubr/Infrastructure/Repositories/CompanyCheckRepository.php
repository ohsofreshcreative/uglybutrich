<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Historia weryfikacji firm (przedsiębiorstwo i domena). Dane z rejestrów publicznych — wspólne dla organizacji;
 * informacja o tym, kto zlecił sprawdzenie, jest prywatna i nie jest zwracana innym organizacjom.
 */
class CompanyCheckRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	public function add(int $companyId, string $kind, string $status, ?string $confidence, ?string $source, array $summary, ?int $organizationId, ?int $userId, string $trigger, int $durationMs): int
	{
		return $this->db->insert(Tables::COMPANY_CHECKS, [
			'company_id' => $companyId,
			'kind' => $kind,
			'status' => $status,
			'confidence' => $confidence,
			'source' => $source ? mb_substr($source, 0, 100) : null,
			'summary' => wp_json_encode($summary),
			'requested_by_org' => $organizationId ?: null,
			'requested_by_user' => $userId ?: null,
			'trigger_type' => $trigger,
			'duration_ms' => $durationMs,
			'created_at' => Db::now(),
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function forCompany(int $companyId, int $organizationId, int $limit = 10): array
	{
		$rows = $this->db->rows(
			"SELECT id, kind, status, confidence, source, summary, trigger_type, requested_by_org, created_at
			FROM {$this->db->table(Tables::COMPANY_CHECKS)}
			WHERE company_id = %d
			ORDER BY id DESC
			LIMIT %d",
			$companyId,
			$limit
		);

		return array_map(function ($r) use ($organizationId) {
			$summary = json_decode((string) $r['summary'], true) ?: [];

			return [
				'id' => (int) $r['id'],
				'kind' => $r['kind'],
				'status' => $r['status'],
				'confidence' => $r['confidence'],
				'source' => $r['source'],
				'reasons' => array_slice((array) ($summary['reasons'] ?? []), 0, 8),
				'discrepancies' => array_slice((array) ($summary['discrepancies'] ?? []), 0, 8),
				'trigger' => $r['trigger_type'],
				'by_my_organization' => (int) $r['requested_by_org'] === $organizationId,
				'created_at' => gmdate('c', strtotime($r['created_at'] . ' UTC')),
			];
		}, $rows);
	}
}
