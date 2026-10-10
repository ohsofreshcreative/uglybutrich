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

	/** Po błędzie migracji kolejna automatyczna próba przy żądaniu WWW najwcześniej po tym czasie. */
	public const RETRY_AFTER_ERROR = 600;

	/**
	 * Wywoływane na `init` — tanie, gdy wszystko jest aktualne (2 odczyty opcji z autoload).
	 *
	 * Produkcja: zalecane `define('UBR_AUTO_MIGRATE', false);` w wp-config.php i migracje podczas wdrożenia
	 * (`wp ubr migrate`) — wtedy żądania WWW nigdy nie wykonują DDL. W WP-CLI migracje uruchamiają wyłącznie
	 * polecenia `wp ubr install` / `wp ubr migrate` (z raportem i kodem wyjścia), a nie każde wywołanie `wp`.
	 */
	public function maybeRun(): void
	{
		if (! $this->migrator->needsMigration() && (int) get_option(self::BOOTSTRAP_OPTION, 0) >= self::BOOTSTRAP_VERSION) {
			return;
		}

		if (! self::autoMigrateEnabled()) {
			return;
		}

		// Po błędzie nie ponawiamy DDL przy każdym żądaniu (koszt + zalew logów) — najwcześniej po 10 min.
		$error = $this->migrator->lastError();

		if ($error && time() - $error['at'] < self::RETRY_AFTER_ERROR) {
			return;
		}

		$this->run();
	}

	public static function autoMigrateEnabled(): bool
	{
		if (defined('WP_CLI') && WP_CLI) {
			return false;
		}

		return ! defined('UBR_AUTO_MIGRATE') || (bool) constant('UBR_AUTO_MIGRATE');
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
