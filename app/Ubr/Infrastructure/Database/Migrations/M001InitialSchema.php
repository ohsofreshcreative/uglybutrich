<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Schemat początkowy: multi-tenancy, globalna baza firm, prospekty organizacji, discovery.
 *
 * Klucze obce są fizyczne (InnoDB) pomiędzy tabelami aplikacji. Do tabel WordPressa (wp_users)
 * odwołujemy się tylko indeksem — sprzątanie robi hook `deleted_user`.
 */
final class M001InitialSchema implements Migration
{
	public function version(): int
	{
		return 1;
	}

	public function description(): string
	{
		return 'Initial schema: organizations, members, companies, prospects, discovery';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$t = fn (string $name) => $db->table($name);

		$statements = [];

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::ORGANIZATIONS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			name VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			plan VARCHAR(50) NOT NULL DEFAULT 'internal',
			settings LONGTEXT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY slug (slug)
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::MEMBERS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'member',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY organization_user (organization_id, user_id),
			KEY user_id (user_id),
			CONSTRAINT {$t('ubr_fk_members_org')} FOREIGN KEY (organization_id) REFERENCES {$t(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::COMPANIES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			normalized_name VARCHAR(255) NOT NULL,
			domain VARCHAR(190) NULL,
			website_url VARCHAR(500) NULL,
			domain_confidence VARCHAR(20) NOT NULL DEFAULT 'unavailable',
			domain_source VARCHAR(50) NULL,
			website_status VARCHAR(20) NOT NULL DEFAULT 'unchecked',
			website_http_code SMALLINT UNSIGNED NULL,
			website_final_url VARCHAR(500) NULL,
			website_title VARCHAR(255) NULL,
			website_checked_at DATETIME NULL,
			industry VARCHAR(255) NULL,
			description TEXT NULL,
			street VARCHAR(255) NULL,
			postal_code VARCHAR(20) NULL,
			city VARCHAR(120) NULL,
			region VARCHAR(120) NULL,
			country_code CHAR(2) NOT NULL DEFAULT 'PL',
			latitude DECIMAL(10,7) NULL,
			longitude DECIMAL(10,7) NULL,
			employees_min INT UNSIGNED NULL,
			employees_max INT UNSIGNED NULL,
			employees_confidence VARCHAR(20) NOT NULL DEFAULT 'unavailable',
			employees_source VARCHAR(50) NULL,
			nip CHAR(10) NULL,
			regon VARCHAR(14) NULL,
			krs CHAR(10) NULL,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY domain (domain),
			UNIQUE KEY nip (nip),
			UNIQUE KEY krs (krs),
			KEY normalized_name (normalized_name(191)),
			KEY city (city),
			KEY region (region)
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::COMPANY_EXTERNAL_IDS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(40) NOT NULL,
			external_id VARCHAR(190) NOT NULL,
			url VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY provider_external (provider, external_id),
			KEY company_id (company_id),
			CONSTRAINT {$t('ubr_fk_extid_company')} FOREIGN KEY (company_id) REFERENCES {$t(Tables::COMPANIES)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::COMPANY_EVIDENCE)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(40) NOT NULL,
			external_id VARCHAR(190) NULL,
			field VARCHAR(50) NOT NULL,
			value TEXT NULL,
			value_hash CHAR(40) NOT NULL,
			confidence VARCHAR(20) NOT NULL,
			source_url VARCHAR(500) NULL,
			search_id BIGINT UNSIGNED NULL,
			first_retrieved_at DATETIME NOT NULL,
			retrieved_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_provider_field_value (company_id, provider, field, value_hash),
			KEY company_field (company_id, field),
			CONSTRAINT {$t('ubr_fk_evidence_company')} FOREIGN KEY (company_id) REFERENCES {$t(Tables::COMPANIES)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::SEARCHES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			label VARCHAR(255) NOT NULL,
			criteria LONGTEXT NOT NULL,
			providers VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			state LONGTEXT NULL,
			results_count INT UNSIGNED NOT NULL DEFAULT 0,
			with_domain_count INT UNSIGNED NOT NULL DEFAULT 0,
			error TEXT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			ticks INT UNSIGNED NOT NULL DEFAULT 0,
			cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
			next_run_at DATETIME NULL,
			locked_until DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY organization_created (organization_id, created_at),
			KEY status_next_run (status, next_run_at),
			CONSTRAINT {$t('ubr_fk_searches_org')} FOREIGN KEY (organization_id) REFERENCES {$t(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::RESULTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			search_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(40) NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			matched_terms VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY search_company (search_id, company_id),
			KEY organization_company (organization_id, company_id),
			KEY company_id (company_id),
			CONSTRAINT {$t('ubr_fk_results_search')} FOREIGN KEY (search_id) REFERENCES {$t(Tables::SEARCHES)} (id) ON DELETE CASCADE,
			CONSTRAINT {$t('ubr_fk_results_org')} FOREIGN KEY (organization_id) REFERENCES {$t(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE,
			CONSTRAINT {$t('ubr_fk_results_company')} FOREIGN KEY (company_id) REFERENCES {$t(Tables::COMPANIES)} (id) ON DELETE RESTRICT
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::PROSPECTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'new',
			added_by BIGINT UNSIGNED NULL,
			source_search_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY organization_company (organization_id, company_id),
			KEY company_id (company_id),
			KEY organization_created (organization_id, created_at),
			CONSTRAINT {$t('ubr_fk_prospects_org')} FOREIGN KEY (organization_id) REFERENCES {$t(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE,
			CONSTRAINT {$t('ubr_fk_prospects_company')} FOREIGN KEY (company_id) REFERENCES {$t(Tables::COMPANIES)} (id) ON DELETE RESTRICT
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::EVENTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			search_id BIGINT UNSIGNED NOT NULL,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			message VARCHAR(500) NOT NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY search_id (search_id),
			CONSTRAINT {$t('ubr_fk_events_search')} FOREIGN KEY (search_id) REFERENCES {$t(Tables::SEARCHES)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t(Tables::USAGE)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			metric VARCHAR(50) NOT NULL,
			quantity INT NOT NULL DEFAULT 1,
			reference_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY organization_metric_created (organization_id, metric, created_at),
			CONSTRAINT {$t('ubr_fk_usage_org')} FOREIGN KEY (organization_id) REFERENCES {$t(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}";

		foreach ($statements as $sql) {
			$db->execute($sql);
		}
	}
}
