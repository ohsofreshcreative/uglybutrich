<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 3: Decision Maker Finder + Contact Priority Score.
 *
 * Dane GLOBALNE (publiczne informacje zawodowe z oficjalnych źródeł, wspólne dla organizacji):
 * - ubr_contact_research_runs: historia wyszukiwań kontaktów, jednocześnie kolejka zadań (nic nie jest nadpisywane),
 * - ubr_contacts: kandydaci (org_key = 0 — wynik automatycznego researchu); kontakty dodane ręcznie są PRYWATNE (org_key = id organizacji),
 * - ubr_contact_sources: źródła i dowody (krótki fragment, data pobrania), wiele źródeł na osobę,
 * - ubr_contact_score_history: historia wyniku, statusu i pozycji w rankingu (zmiany stanowisk i rankingu),
 * - ubr_contact_suppressions: osoby wyłączone z researchu (sprzeciw / prośba o usunięcie) — decyzja administratora aplikacji.
 *
 * Dane PRYWATNE organizacji: ubr_contact_reviews (zatwierdzenie, odrzucenie, nieaktualny, wykluczenie, preferowany, notatka).
 * Prywatna decyzja nigdy nie zmienia danych globalnych.
 */
final class M005DecisionMakers implements Migration
{
	public function version(): int
	{
		return 5;
	}

	public function description(): string
	{
		return 'Decision makers: research runs, contacts, sources, score history, private reviews, suppressions';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$companies = $db->table(Tables::COMPANIES);
		$contacts = $db->table(Tables::CONTACTS);

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CONTACT_RUNS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			research_version VARCHAR(30) NOT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			locked_until DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			duration_ms INT UNSIGNED NULL,
			candidates_found SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			top_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			sources LONGTEXT NULL,
			pages LONGTEXT NULL,
			snapshot LONGTEXT NULL,
			changes LONGTEXT NULL,
			errors LONGTEXT NULL,
			company_channels LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY company_created (company_id, created_at),
			KEY status_due (status, next_attempt_at),
			KEY organization_created (organization_id, created_at),
			CONSTRAINT {$db->table('ubr_fk_contact_runs_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$contacts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			org_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
			person_key VARCHAR(190) NOT NULL,
			first_name VARCHAR(100) NULL,
			last_name VARCHAR(120) NULL,
			full_name VARCHAR(200) NOT NULL,
			job_title VARCHAR(200) NULL,
			registry_role VARCHAR(120) NULL,
			normalized_role VARCHAR(40) NOT NULL DEFAULT 'other',
			department VARCHAR(30) NULL,
			seniority VARCHAR(20) NULL,
			decision_authority TINYINT UNSIGNED NULL,
			contact_priority_score TINYINT UNSIGNED NULL,
			score_status VARCHAR(20) NULL,
			score_breakdown LONGTEXT NULL,
			confidence_level VARCHAR(10) NULL,
			confidence_breakdown LONGTEXT NULL,
			verification_status VARCHAR(20) NOT NULL DEFAULT 'uncertain',
			verification_reason TEXT NULL,
			company_match_status VARCHAR(30) NULL,
			employment_status VARCHAR(20) NULL,
			public_business_email VARCHAR(190) NULL,
			public_business_phone VARCHAR(40) NULL,
			professional_profile_url VARCHAR(300) NULL,
			source_type VARCHAR(30) NULL,
			source_url VARCHAR(500) NULL,
			source_observed_at DATETIME NULL,
			last_verified_at DATETIME NULL,
			rank_position TINYINT UNSIGNED NULL,
			rank_reason TEXT NULL,
			needs_review TINYINT(1) NOT NULL DEFAULT 0,
			flags LONGTEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			research_version VARCHAR(30) NULL,
			first_run_id BIGINT UNSIGNED NULL,
			last_run_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_person (company_id, org_key, person_key),
			KEY company_rank (company_id, rank_position),
			CONSTRAINT {$db->table('ubr_fk_contacts_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CONTACT_SOURCES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			run_id BIGINT UNSIGNED NULL,
			source_type VARCHAR(30) NOT NULL,
			source_url VARCHAR(500) NULL,
			source_title VARCHAR(255) NULL,
			observed_role VARCHAR(200) NULL,
			evidence_excerpt VARCHAR(400) NULL,
			confidence VARCHAR(10) NULL,
			retrieved_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY contact_retrieved (contact_id, retrieved_at),
			CONSTRAINT {$db->table('ubr_fk_contact_sources_contact')} FOREIGN KEY (contact_id) REFERENCES {$contacts} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CONTACT_SCORE_HISTORY)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			run_id BIGINT UNSIGNED NULL,
			job_title VARCHAR(200) NULL,
			contact_priority_score TINYINT UNSIGNED NULL,
			confidence_level VARCHAR(10) NULL,
			verification_status VARCHAR(20) NULL,
			rank_position TINYINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY contact_created (contact_id, created_at),
			CONSTRAINT {$db->table('ubr_fk_contact_history_contact')} FOREIGN KEY (contact_id) REFERENCES {$contacts} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CONTACT_REVIEWS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NULL,
			preferred TINYINT(1) NOT NULL DEFAULT 0,
			note TEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_contact (organization_id, contact_id),
			KEY org_company (organization_id, company_id),
			CONSTRAINT {$db->table('ubr_fk_contact_reviews_contact')} FOREIGN KEY (contact_id) REFERENCES {$contacts} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CONTACT_SUPPRESSIONS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			person_key VARCHAR(190) NOT NULL,
			reason VARCHAR(255) NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_person (company_id, person_key)
		) ENGINE=InnoDB {$c}");

		$columns = [
			'contacts_status' => 'VARCHAR(20) NULL AFTER findings_count',
			'contacts_researched_at' => 'DATETIME NULL AFTER contacts_status',
			'contacts_count' => 'SMALLINT UNSIGNED NULL AFTER contacts_researched_at',
			'last_contact_run_id' => 'BIGINT UNSIGNED NULL AFTER contacts_count',
			'top_contact_id' => 'BIGINT UNSIGNED NULL AFTER last_contact_run_id',
			'top_contact_name' => 'VARCHAR(200) NULL AFTER top_contact_id',
			'top_contact_title' => 'VARCHAR(200) NULL AFTER top_contact_name',
			'top_contact_role' => 'VARCHAR(40) NULL AFTER top_contact_title',
			'top_contact_score' => 'TINYINT UNSIGNED NULL AFTER top_contact_role',
			'top_contact_confidence' => 'VARCHAR(10) NULL AFTER top_contact_score',
			'company_channels' => 'LONGTEXT NULL AFTER top_contact_confidence',
		];

		foreach ($columns as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$companies} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$companies} ADD COLUMN {$column} {$definition}");
			}
		}

		if (! $db->value("SHOW INDEX FROM {$companies} WHERE Key_name = %s", 'top_contact_score')) {
			$db->execute("ALTER TABLE {$companies} ADD KEY top_contact_score (top_contact_score)");
		}
	}
}
