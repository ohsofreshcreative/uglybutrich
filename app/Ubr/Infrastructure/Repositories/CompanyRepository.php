<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Globalna baza firm (wspólna dla wszystkich organizacji — bez danych prywatnych organizacji).
 */
class CompanyRepository
{
	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::COMPANIES);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id);
	}

	public function idByExternalId(string $provider, string $externalId): ?int
	{
		$id = $this->db->value(
			"SELECT company_id FROM {$this->db->table(Tables::COMPANY_EXTERNAL_IDS)} WHERE provider = %s AND external_id = %s",
			$provider,
			$externalId
		);

		return $id ? (int) $id : null;
	}

	public function idByColumn(string $column, string $value): ?int
	{
		if (! in_array($column, ['domain', 'nip', 'krs'], true)) {
			throw new \InvalidArgumentException('Unsupported lookup column');
		}

		$id = $this->db->value("SELECT id FROM {$this->t()} WHERE {$column} = %s", $value);

		return $id ? (int) $id : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findByNormalizedName(string $normalizedName, int $limit = 10): array
	{
		return $this->db->rows(
			"SELECT * FROM {$this->t()} WHERE normalized_name = %s ORDER BY id ASC LIMIT %d",
			$normalizedName,
			$limit
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(array $data): int
	{
		$now = Db::now();
		$data += ['first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now];

		return $this->db->insert(Tables::COMPANIES, $data);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		if ($data === []) {
			return;
		}

		$data['updated_at'] = Db::now();
		$this->db->update(Tables::COMPANIES, $data, ['id' => $id]);
	}

	public function touch(int $id): void
	{
		$this->db->update(Tables::COMPANIES, ['last_seen_at' => Db::now()], ['id' => $id]);
	}

	public function addExternalId(int $companyId, string $provider, string $externalId, ?string $url): void
	{
		$now = Db::now();
		$this->db->execute(
			"INSERT INTO {$this->db->table(Tables::COMPANY_EXTERNAL_IDS)} (company_id, provider, external_id, url, created_at, updated_at)
			VALUES (%d, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at), url = VALUES(url)",
			$companyId,
			$provider,
			$externalId,
			(string) $url,
			$now,
			$now
		);
	}

	/**
	 * Historia pochodzenia: każda unikalna wartość pola z danego źródła jest zapisana raz,
	 * kolejne potwierdzenia aktualizują `retrieved_at`.
	 */
	public function addEvidence(int $companyId, string $provider, ?string $externalId, string $field, ?string $value, string $confidence, ?string $sourceUrl, ?int $searchId): void
	{
		$now = Db::now();
		$value = $value === null ? null : mb_substr($value, 0, 2000);

		$this->db->execute(
			"INSERT INTO {$this->db->table(Tables::COMPANY_EVIDENCE)}
				(company_id, provider, external_id, field, value, value_hash, confidence, source_url, search_id, first_retrieved_at, retrieved_at)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE retrieved_at = VALUES(retrieved_at), confidence = VALUES(confidence), search_id = VALUES(search_id)",
			$companyId,
			$provider,
			(string) $externalId,
			$field,
			(string) $value,
			sha1($field . '|' . (string) $value),
			$confidence,
			(string) $sourceUrl,
			(int) $searchId,
			$now,
			$now
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function evidence(int $companyId): array
	{
		return $this->db->rows(
			"SELECT provider, external_id, field, value, confidence, source_url, first_retrieved_at, retrieved_at
			FROM {$this->db->table(Tables::COMPANY_EVIDENCE)}
			WHERE company_id = %d
			ORDER BY field ASC, retrieved_at DESC",
			$companyId
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function externalIds(int $companyId): array
	{
		return $this->db->rows(
			"SELECT provider, external_id, url FROM {$this->db->table(Tables::COMPANY_EXTERNAL_IDS)} WHERE company_id = %d",
			$companyId
		);
	}

	/**
	 * Firma jest widoczna dla organizacji, jeśli pojawiła się w jej wynikach discovery lub prospektach.
	 */
	public function isVisibleTo(int $companyId, int $organizationId): bool
	{
		return (bool) $this->db->value(
			"SELECT 1 FROM {$this->db->table(Tables::RESULTS)} WHERE company_id = %d AND organization_id = %d
			UNION SELECT 1 FROM {$this->db->table(Tables::PROSPECTS)} WHERE company_id = %d AND organization_id = %d
			LIMIT 1",
			$companyId,
			$organizationId,
			$companyId,
			$organizationId
		);
	}

	public function countAll(): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()}");
	}
}
