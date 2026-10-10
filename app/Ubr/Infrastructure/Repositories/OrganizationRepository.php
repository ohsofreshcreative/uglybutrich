<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

class OrganizationRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array
	{
		return $this->hydrate($this->db->row(
			"SELECT * FROM {$this->db->table(Tables::ORGANIZATIONS)} WHERE id = %d",
			$id
		));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findBySlug(string $slug): ?array
	{
		return $this->hydrate($this->db->row(
			"SELECT * FROM {$this->db->table(Tables::ORGANIZATIONS)} WHERE slug = %s",
			$slug
		));
	}

	public function slugExists(string $slug): bool
	{
		return (bool) $this->db->value(
			"SELECT 1 FROM {$this->db->table(Tables::ORGANIZATIONS)} WHERE slug = %s",
			$slug
		);
	}

	public function create(string $name, string $slug, ?int $createdBy, string $plan = 'internal'): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::ORGANIZATIONS, [
			'uuid' => wp_generate_uuid4(),
			'name' => $name,
			'slug' => $slug,
			'plan' => $plan,
			'settings' => wp_json_encode(new \stdClass()),
			'created_by' => $createdBy,
			'created_at' => $now,
			'updated_at' => $now,
		]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		if (isset($data['settings']) && is_array($data['settings'])) {
			$data['settings'] = wp_json_encode($data['settings']);
		}

		$data['updated_at'] = Db::now();
		$this->db->update(Tables::ORGANIZATIONS, $data, ['id' => $id]);
	}

	/**
	 * Organizacje, do których należy użytkownik (z jego rolą).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forUser(int $userId): array
	{
		$rows = $this->db->rows(
			"SELECT o.*, m.role AS member_role
			FROM {$this->db->table(Tables::ORGANIZATIONS)} o
			INNER JOIN {$this->db->table(Tables::MEMBERS)} m ON m.organization_id = o.id
			WHERE m.user_id = %d
			ORDER BY o.name ASC",
			$userId
		);

		return array_map(fn ($r) => $this->hydrate($r), $rows);
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, mixed>|null
	 */
	private function hydrate(?array $row): ?array
	{
		if (! $row) {
			return null;
		}

		$row['id'] = (int) $row['id'];
		$row['settings'] = json_decode((string) ($row['settings'] ?? '{}'), true) ?: [];

		return $row;
	}
}
