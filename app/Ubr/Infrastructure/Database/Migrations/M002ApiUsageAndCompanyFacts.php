<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Etap 1.1 (Apollo): dziennik zapytań do zewnętrznych API (kontrola kosztów) oraz dodatkowe fakty o firmie
 * (rok założenia, szacowane przychody, LinkedIn). Idempotentna — dodaje tylko brakujące kolumny/tabele.
 */
final class M002ApiUsageAndCompanyFacts implements Migration
{
	public function version(): int
	{
		return 2;
	}

	public function description(): string
	{
		return 'API request log + company founded_year / revenue / linkedin';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$companies = $db->table(Tables::COMPANIES);

		$columns = [
			'founded_year' => 'SMALLINT UNSIGNED NULL AFTER employees_source',
			'revenue_estimate' => 'BIGINT UNSIGNED NULL AFTER founded_year',
			'revenue_source' => 'VARCHAR(50) NULL AFTER revenue_estimate',
			'linkedin_url' => 'VARCHAR(255) NULL AFTER revenue_source',
		];

		foreach ($columns as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$companies} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$companies} ADD COLUMN {$column} {$definition}");
			}
		}

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::API_REQUESTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NULL,
			search_id BIGINT UNSIGNED NULL,
			provider VARCHAR(40) NOT NULL,
			endpoint VARCHAR(190) NOT NULL,
			method VARCHAR(10) NOT NULL DEFAULT 'GET',
			http_status SMALLINT UNSIGNED NULL,
			duration_ms INT UNSIGNED NULL,
			cached TINYINT(1) NOT NULL DEFAULT 0,
			billable TINYINT(1) NOT NULL DEFAULT 0,
			results_count INT UNSIGNED NULL,
			new_count INT UNSIGNED NULL,
			duplicate_count INT UNSIGNED NULL,
			credits_reported INT NULL,
			rate_limit LONGTEXT NULL,
			error VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY provider_created (provider, created_at),
			KEY organization_provider_created (organization_id, provider, created_at),
			KEY search_id (search_id)
		) ENGINE=InnoDB {$c}");
	}
}
