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
		$removed = $this->db->delete(Tables::MEMBERS, ['organization_id' => $organizationId, 'user_id' => $userId]);
		$this->releaseAssignments($userId, $organizationId);

		return $removed;
	}

	public function removeUserEverywhere(int $userId): int
	{
		$removed = $this->db->delete(Tables::MEMBERS, ['user_id' => $userId]);
		$this->releaseAssignments($userId, null);

		return $removed;
	}

	/**
	 * Osoba spoza organizacji nie może zostać właścicielem prospektów / osobą przypisaną do follow-upów
	 * (inaczej znikały z „Unassigned” i z „My Work” wszystkich), a jej reguły ClickUp nie mogą działać dalej.
	 */
	private function releaseAssignments(int $userId, ?int $organizationId): void
	{
		$org = $organizationId !== null ? ' AND organization_id = ' . (int) $organizationId : '';
		$now = Db::now();
		$this->db->execute("UPDATE {$this->db->table(Tables::PROSPECTS)} SET owner_user_id = NULL WHERE owner_user_id = %d{$org}", $userId);
		$this->db->execute("UPDATE {$this->db->table(Tables::PROSPECT_FOLLOWUPS)} SET assigned_user_id = NULL, updated_at = %s WHERE assigned_user_id = %d{$org}", $now, $userId);

		try {
			$this->db->execute("UPDATE {$this->db->table(Tables::CLICKUP_RULES)} SET enabled = 0, updated_at = %s WHERE created_by = %d{$org}", $now, $userId);
		} catch (\Throwable) {
			// Tabela reguł niezmigrowana — nic do wyłączenia.
		}

		if ($organizationId !== null) {
			\App\Ubr\Services\ActionCenterService::bumpOrganization($organizationId);
		}
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
