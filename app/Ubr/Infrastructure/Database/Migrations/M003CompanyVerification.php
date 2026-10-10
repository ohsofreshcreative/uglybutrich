<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Etap 1.2: weryfikacja przedsiębiorstw i domen, trafność wyników (Relevance Score), korekta danych.
 *
 * Schemat:
 * - companies: status przedsiębiorstwa (VERIFIED/UNCERTAIN/INACTIVE/SUSPENDED), status domeny, dane z rejestrów
 *   (forma prawna, nazwa rejestrowa, PKD), kody branżowe dostawców, pewność i źródło lokalizacji, flagi dopasowania,
 * - discovery_results: relevance_score + szczegóły kryteriów + stan dopasowania lokalizacji,
 * - company_checks: historia weryfikacji,
 * - verification_queue: kolejka weryfikacji (oddzielona od pobierania wyników Discovery).
 *
 * Korekta danych (GPT/1.2): Apollo w wynikach wyszukiwania nie zwraca miasta, województwa ani liczby pracowników.
 * Etap 1.1 wpisywał firmom województwo/miasto/przedział zatrudnienia z FILTRA wyszukiwania. Migracja usuwa te wartości
 * z pól firmy (dane nie są kasowane — zostają w historii pochodzenia jako „search_filter_*”).
 */
final class M003CompanyVerification implements Migration
{
	public function version(): int
	{
		return 3;
	}

	public function description(): string
	{
		return 'Company/domain verification, relevance score, verification queue, correction of filter-derived data';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$companies = $db->table(Tables::COMPANIES);
		$results = $db->table(Tables::RESULTS);

		$this->addColumns($db, $companies, [
			'verification_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER krs",
			'verification_confidence' => 'VARCHAR(20) NULL AFTER verification_status',
			'verification_source' => 'VARCHAR(100) NULL AFTER verification_confidence',
			'verification_checked_at' => 'DATETIME NULL AFTER verification_source',
			'verification_summary' => 'LONGTEXT NULL AFTER verification_checked_at',
			'domain_status' => "VARCHAR(20) NOT NULL DEFAULT 'unchecked' AFTER verification_summary",
			'domain_checked_at' => 'DATETIME NULL AFTER domain_status',
			'domain_summary' => 'LONGTEXT NULL AFTER domain_checked_at',
			'registry_name' => 'VARCHAR(500) NULL AFTER domain_summary',
			'legal_form' => 'VARCHAR(120) NULL AFTER registry_name',
			'pkd_main' => 'VARCHAR(20) NULL AFTER legal_form',
			'pkd_description' => 'VARCHAR(255) NULL AFTER pkd_main',
			'industry_codes' => 'LONGTEXT NULL AFTER pkd_description',
			'location_confidence' => "VARCHAR(20) NOT NULL DEFAULT 'unavailable' AFTER industry_codes",
			'location_source' => 'VARCHAR(50) NULL AFTER location_confidence',
			'fit_flags' => 'LONGTEXT NULL AFTER location_source',
		]);

		$this->addIndex($db, $companies, 'verification_status', '(verification_status)');
		$this->addIndex($db, $companies, 'domain_status', '(domain_status)');

		$this->addColumns($db, $results, [
			'relevance_score' => 'TINYINT UNSIGNED NULL AFTER matched_terms',
			'relevance' => 'LONGTEXT NULL AFTER relevance_score',
			'location_match' => 'VARCHAR(20) NULL AFTER relevance',
		]);

		$this->addIndex($db, $results, 'search_relevance', '(search_id, relevance_score)');

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::COMPANY_CHECKS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			confidence VARCHAR(20) NULL,
			source VARCHAR(100) NULL,
			summary LONGTEXT NULL,
			requested_by_org BIGINT UNSIGNED NULL,
			requested_by_user BIGINT UNSIGNED NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'auto',
			duration_ms INT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY company_kind_created (company_id, kind, created_at),
			CONSTRAINT {$db->table('ubr_fk_checks_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::VERIFICATION_QUEUE)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
			force_check TINYINT(1) NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			locked_until DATETIME NULL,
			last_error VARCHAR(500) NULL,
			requested_by_org BIGINT UNSIGNED NULL,
			requested_by_user BIGINT UNSIGNED NULL,
			search_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_id (company_id),
			KEY status_due (status, next_attempt_at, priority),
			KEY search_id (search_id),
			CONSTRAINT {$db->table('ubr_fk_vqueue_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$this->correctFilterDerivedData($db);
	}

	/**
	 * Usuwa z pól firm wartości wpisane z filtra wyszukiwania Apollo (etap 1.1). Idempotentna.
	 */
	private function correctFilterDerivedData(Db $db): void
	{
		$companies = $db->table(Tables::COMPANIES);
		$evidence = $db->table(Tables::COMPANY_EVIDENCE);

		foreach (['region', 'city'] as $field) {
			// Pole firmy wyczyść tylko wtedy, gdy żadne inne źródło nie podało tej samej wartości.
			$db->execute(
				"UPDATE {$companies} c
				SET c.{$field} = NULL
				WHERE c.{$field} IS NOT NULL
					AND EXISTS (SELECT 1 FROM {$evidence} e WHERE e.company_id = c.id AND e.provider = 'apollo' AND e.field = %s)
					AND NOT EXISTS (SELECT 1 FROM {$evidence} e2 WHERE e2.company_id = c.id AND e2.provider <> 'apollo' AND e2.field = %s AND e2.value = c.{$field})",
				$field,
				$field
			);

			// Historia: wartość zostaje, ale jako „filtr wyszukiwania”, nie dana firmy.
			$db->execute(
				"UPDATE IGNORE {$evidence}
				SET field = %s, confidence = 'uncertain', value_hash = SHA1(CONCAT(%s, '|', IFNULL(value, '')))
				WHERE provider = 'apollo' AND field = %s",
				'search_filter_' . $field,
				'search_filter_' . $field,
				$field
			);
		}

		// Zatrudnienie: przedział (min ≠ max) od Apollo = filtr wyszukiwania. Szacunek Apollo ma min = max — zostaje (deklaracja dostawcy).
		$db->execute(
			"UPDATE {$companies}
			SET employees_min = NULL, employees_max = NULL, employees_confidence = 'unavailable', employees_source = NULL
			WHERE employees_source = 'apollo' AND (employees_min IS NULL OR employees_max IS NULL OR employees_min <> employees_max)"
		);
		$db->execute(
			"UPDATE IGNORE {$evidence}
			SET field = 'search_filter_employees', confidence = 'uncertain', value_hash = SHA1(CONCAT('search_filter_employees|', IFNULL(value, '')))
			WHERE provider = 'apollo' AND field = 'employees' AND SUBSTRING_INDEX(value, '-', 1) <> SUBSTRING_INDEX(value, '-', -1)"
		);
		$db->execute("UPDATE {$companies} SET employees_confidence = 'declared' WHERE employees_source = 'apollo' AND employees_min IS NOT NULL");

		// Pewność lokalizacji pozostałych firm: dane z rekordu dostawcy (Wikidata/OSM) = deklaracja dostawcy.
		$db->execute(
			"UPDATE {$companies} c SET c.location_confidence = 'declared', c.location_source = (
				SELECT e.provider FROM {$evidence} e WHERE e.company_id = c.id AND e.field IN ('region', 'city') ORDER BY e.id ASC LIMIT 1
			)
			WHERE (c.city IS NOT NULL OR c.region IS NOT NULL) AND c.location_confidence = 'unavailable'"
		);
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
