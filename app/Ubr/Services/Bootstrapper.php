<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Database\Migrator;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;

/**
 * Idempotentna inicjalizacja aplikacji: migracje + domyślna organizacja OhSoFresh.
 * Bezpieczna do wielokrotnego uruchomienia (nie duplikuje organizacji ani członkostw).
 */
class Bootstrapper
{
	public const DEFAULT_ORG_SLUG = 'ohsofresh';

	public const DEFAULT_ORG_NAME = 'OhSoFresh';

	public const BOOTSTRAP_OPTION = 'ubr_bootstrap_version';

	private const BOOTSTRAP_VERSION = 1;

	public function __construct(
		private Migrator $migrator,
		private OrganizationRepository $organizations,
		private MemberRepository $members,
	) {
	}

	/**
	 * Wywoływane na `init` — tanie, gdy wszystko jest aktualne (2 odczyty opcji z autoload).
	 */
	public function maybeRun(): void
	{
		if (! $this->migrator->needsMigration() && (int) get_option(self::BOOTSTRAP_OPTION, 0) >= self::BOOTSTRAP_VERSION) {
			return;
		}

		$this->run();
	}

	/**
	 * @return array{migrated: list<int>, organization_id: int, created: bool}
	 */
	public function run(): array
	{
		$migrated = $this->migrator->migrate();

		if ($this->migrator->needsMigration()) {
			// Inny proces właśnie migruje — spróbujemy przy kolejnym żądaniu.
			return ['migrated' => $migrated, 'organization_id' => 0, 'created' => false];
		}

		[$orgId, $created] = $this->ensureDefaultOrganization();
		update_option(self::BOOTSTRAP_OPTION, self::BOOTSTRAP_VERSION, true);

		return ['migrated' => $migrated, 'organization_id' => $orgId, 'created' => $created];
	}

	/**
	 * @return array{0: int, 1: bool}
	 */
	public function ensureDefaultOrganization(): array
	{
		$existing = $this->organizations->findBySlug(self::DEFAULT_ORG_SLUG);

		if ($existing) {
			return [$existing['id'], false];
		}

		$admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'orderby' => 'ID']);
		$owner = (int) ($admins[0] ?? 0);

		$orgId = $this->organizations->create(self::DEFAULT_ORG_NAME, self::DEFAULT_ORG_SLUG, $owner ?: null);

		// Administratorzy WordPressa w momencie inicjalizacji zostają właścicielami OhSoFresh.
		foreach ($admins as $adminId) {
			$this->members->add($orgId, (int) $adminId, Role::Owner);
		}

		return [$orgId, true];
	}
}
