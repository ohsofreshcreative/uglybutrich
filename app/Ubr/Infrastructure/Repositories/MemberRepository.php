<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

class MemberRepository
{
	public function __construct(private Db $db)
	{
	}

	public function role(int $organizationId, int $userId): ?Role
	{
		$role = $this->db->value(
			"SELECT role FROM {$this->db->table(Tables::MEMBERS)} WHERE organization_id = %d AND user_id = %d",
			$organizationId,
			$userId
		);

		return $role ? Role::tryFrom((string) $role) : null;
	}

	/**
	 * Dodaje członka. Jeżeli już jest członkiem — nic nie zmienia (idempotentnie).
	 */
	public function add(int $organizationId, int $userId, Role $role): bool
	{
		$now = Db::now();

		return $this->db->execute(
			"INSERT IGNORE INTO {$this->db->table(Tables::MEMBERS)} (organization_id, user_id, role, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)",
			$organizationId,
			$userId,
			$role->value,
			$now,
			$now
		) > 0;
	}

	public function updateRole(int $organizationId, int $userId, Role $role): void
	{
		$this->db->update(
			Tables::MEMBERS,
			['role' => $role->value, 'updated_at' => Db::now()],
			['organization_id' => $organizationId, 'user_id' => $userId]
		);
	}

	public function remove(int $organizationId, int $userId): int
	{
		return $this->db->delete(Tables::MEMBERS, ['organization_id' => $organizationId, 'user_id' => $userId]);
	}

	public function removeUserEverywhere(int $userId): int
	{
		return $this->db->delete(Tables::MEMBERS, ['user_id' => $userId]);
	}

	public function countOwners(int $organizationId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->db->table(Tables::MEMBERS)} WHERE organization_id = %d AND role = 'owner'",
			$organizationId
		);
	}

	public function countMembers(int $organizationId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->db->table(Tables::MEMBERS)} WHERE organization_id = %d",
			$organizationId
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function list(int $organizationId): array
	{
		global $wpdb;

		return $this->db->rows(
			"SELECT m.user_id, m.role, m.created_at, u.display_name, u.user_email
			FROM {$this->db->table(Tables::MEMBERS)} m
			LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
			WHERE m.organization_id = %d
			ORDER BY FIELD(m.role, 'owner', 'admin', 'member'), u.display_name ASC",
			$organizationId
		);
	}
}
