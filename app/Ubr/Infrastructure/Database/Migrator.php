<?php

namespace App\Ubr\Infrastructure\Database;

use App\Ubr\Infrastructure\Database\Migrations\M001InitialSchema;
use App\Ubr\Infrastructure\Database\Migrations\M002ApiUsageAndCompanyFacts;
use App\Ubr\Infrastructure\Database\Migrations\M003CompanyVerification;
use App\Ubr\Infrastructure\Database\Migrations\M004WebsiteAnalysis;
use App\Ubr\Infrastructure\Database\Migrations\M005DecisionMakers;
use App\Ubr\Infrastructure\Database\Migrations\M006SalesPipelineClickUp;
use App\Ubr\Infrastructure\Database\Migrations\Migration;
use Throwable;

/**
 * Wersjonowane, idempotentne migracje. Aktualna wersja w opcji `ubr_db_version`.
 * Nowe migracje dopisuj na końcu listy — nigdy nie zmieniaj już wdrożonych.
 */
class Migrator
{
	public const OPTION = 'ubr_db_version';

	private const LOCK = 'ubr_migration_lock';

	public function __construct(private Db $db)
	{
	}

	/**
	 * @return list<Migration>
	 */
	public function migrations(): array
	{
		return [
			new M001InitialSchema(),
			new M002ApiUsageAndCompanyFacts(),
			new M003CompanyVerification(),
			new M004WebsiteAnalysis(),
			new M005DecisionMakers(),
			new M006SalesPipelineClickUp(),
		];
	}

	public function latestVersion(): int
	{
		return max(array_map(fn (Migration $m) => $m->version(), $this->migrations()));
	}

	public function currentVersion(): int
	{
		return (int) get_option(self::OPTION, 0);
	}

	public function needsMigration(): bool
	{
		return $this->currentVersion() < $this->latestVersion();
	}

	/**
	 * @return list<int> zastosowane wersje
	 */
	public function migrate(): array
	{
		if (! $this->needsMigration() || ! $this->acquireLock()) {
			return [];
		}

		$applied = [];

		try {
			foreach ($this->migrations() as $migration) {
				if ($migration->version() <= $this->currentVersion()) {
					continue;
				}

				$migration->up($this->db);
				update_option(self::OPTION, $migration->version(), true);
				$applied[] = $migration->version();
			}
		} catch (Throwable $e) {
			error_log('[ubr] Migration failed: ' . $e->getMessage());

			throw $e;
		} finally {
			delete_option(self::LOCK);
		}

		if ($applied !== []) {
			do_action('ubr/migrated', $applied);
		}

		return $applied;
	}

	/**
	 * @return array<string, bool>
	 */
	public function tableStatus(): array
	{
		$status = [];

		foreach (Tables::all() as $table) {
			$name = $this->db->table($table);
			$status[$table] = $this->db->value('SHOW TABLES LIKE %s', $name) === $name;
		}

		return $status;
	}

	private function acquireLock(): bool
	{
		if (add_option(self::LOCK, time(), '', false)) {
			return true;
		}

		// Przeterminowana blokada (np. po fatal error) — przejmij.
		if (time() - (int) get_option(self::LOCK, 0) > 300) {
			update_option(self::LOCK, time(), false);

			return true;
		}

		return false;
	}
}
