<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 2: Website Analyzer + Opportunity Score.
 *
 * - ubr_website_analyses: historia analiz (nic nie jest nadpisywane), jednocześnie kolejka zadań (status/termin/blokada),
 * - ubr_companies: wskaźnik ostatniej analizy i zdenormalizowany Opportunity Score (szybkie filtrowanie i sortowanie list).
 *
 * Analiza strony to dane o PUBLICZNEJ stronie firmy — wspólne dla organizacji (jak baza firm). Kto zlecił analizę
 * (organization_id, user_id) jest prywatne i nie jest ujawniane innym organizacjom.
 */
final class M004WebsiteAnalysis implements Migration
{
	public function version(): int
	{
		return 4;
	}

	public function description(): string
	{
		return 'Website analyses (history + queue), opportunity score on companies';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$companies = $db->table(Tables::COMPANIES);

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::ANALYSES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			url VARCHAR(500) NOT NULL,
			final_url VARCHAR(500) NULL,
			algorithm_version VARCHAR(20) NOT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			locked_until DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			duration_ms INT UNSIGNED NULL,
			opportunity_score TINYINT UNSIGNED NULL,
			opportunity_level VARCHAR(20) NULL,
			confidence VARCHAR(10) NULL,
			coverage TINYINT UNSIGNED NULL,
			findings_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			results LONGTEXT NULL,
			findings LONGTEXT NULL,
			errors LONGTEXT NULL,
			tools LONGTEXT NULL,
			screenshots LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY company_created (company_id, created_at),
			KEY status_due (status, next_attempt_at),
			KEY organization_created (organization_id, created_at),
			CONSTRAINT {$db->table('ubr_fk_analyses_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$columns = [
			'last_analysis_id' => 'BIGINT UNSIGNED NULL AFTER fit_flags',
			'analysis_status' => 'VARCHAR(20) NULL AFTER last_analysis_id',
			'analyzed_at' => 'DATETIME NULL AFTER analysis_status',
			'opportunity_score' => 'TINYINT UNSIGNED NULL AFTER analyzed_at',
			'opportunity_level' => 'VARCHAR(20) NULL AFTER opportunity_score',
			'opportunity_confidence' => 'VARCHAR(10) NULL AFTER opportunity_level',
			'opportunity_coverage' => 'TINYINT UNSIGNED NULL AFTER opportunity_confidence',
			'findings_count' => 'SMALLINT UNSIGNED NULL AFTER opportunity_coverage',
		];

		foreach ($columns as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$companies} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$companies} ADD COLUMN {$column} {$definition}");
			}
		}

		if (! $db->value("SHOW INDEX FROM {$companies} WHERE Key_name = %s", 'opportunity_score')) {
			$db->execute("ALTER TABLE {$companies} ADD KEY opportunity_score (opportunity_score)");
		}
	}
}
