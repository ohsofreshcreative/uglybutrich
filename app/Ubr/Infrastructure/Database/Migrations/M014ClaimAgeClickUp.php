<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 10.4.3 — Discover / Website Age Intelligence / „Przejmij firmę” / ClickUp dwuetapowo.
 *
 * Tylko nowe kolumny (bez nowych tabel i bez zmiany istniejących danych):
 * - `ubr_companies`: ogólny e-mail firmowy (ze stron pobranych przy weryfikacji) oraz wynik i stan kolejki
 *   Website Age Intelligence (dane publiczne, wspólne dla organizacji — jak pozostałe dane firmy),
 * - `ubr_contact_research_runs.stage`: etap analizy decydenta (statusy po przejęciu firmy),
 * - `ubr_clickup_exports.synced_fields` / `conflicts`: ostatnie wartości pól zapisane przez aplikację (ochrona ręcznych
 *   zmian w ClickUp) i wykryte konflikty synchronizacji.
 */
final class M014ClaimAgeClickUp implements Migration
{
	public function version(): int
	{
		return 14;
	}

	public function description(): string
	{
		return 'General company e-mail, Website Age Intelligence, claim research stage, ClickUp synced fields';
	}

	public function up(Db $db): void
	{
		$companies = $db->table(Tables::COMPANIES);

		$this->addColumns($db, $companies, [
			'general_email' => 'VARCHAR(190) NULL',
			'general_email_source' => 'VARCHAR(500) NULL',
			'general_email_at' => 'DATETIME NULL',
			'site_age_state' => 'VARCHAR(12) NULL',
			'site_age_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
			'site_age_next_at' => 'DATETIME NULL',
			'site_age_locked_until' => 'DATETIME NULL',
			'site_age_status' => 'VARCHAR(12) NULL',
			'site_age_confidence' => 'VARCHAR(10) NULL',
			'site_launch_year' => 'SMALLINT UNSIGNED NULL',
			'site_launch_from' => 'SMALLINT UNSIGNED NULL',
			'site_launch_to' => 'SMALLINT UNSIGNED NULL',
			'site_age_years' => 'TINYINT UNSIGNED NULL',
			'site_last_redesign_year' => 'SMALLINT UNSIGNED NULL',
			'site_modernity' => 'VARCHAR(12) NULL',
			'site_age_evidence' => 'LONGTEXT NULL',
			'site_age_checked_at' => 'DATETIME NULL',
		]);
		$this->addIndex($db, $companies, 'site_age_queue', '(site_age_state, site_age_next_at)');
		$this->addIndex($db, $companies, 'site_age_years', '(site_age_years)');

		$this->addColumns($db, $db->table(Tables::CONTACT_RUNS), [
			'stage' => 'VARCHAR(20) NULL',
		]);

		$this->addColumns($db, $db->table(Tables::CLICKUP_EXPORTS), [
			'synced_fields' => 'LONGTEXT NULL',
			'conflicts' => 'LONGTEXT NULL',
		]);
	}

	/**
	 * @param array<string, string> $columns
	 */
	private function addColumns(Db $db, string $table, array $columns): void
	{
		foreach ($columns as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$table} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
			}
		}
	}

	private function addIndex(Db $db, string $table, string $name, string $columns): void
	{
		if (! $db->value("SHOW INDEX FROM {$table} WHERE Key_name = %s", $name)) {
			$db->execute("ALTER TABLE {$table} ADD KEY {$name} {$columns}");
		}
	}
}
