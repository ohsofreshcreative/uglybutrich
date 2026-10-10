<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * M007 — Lead Intelligence (Prompt 5). Tylko nowe tabele, bez zmian w istniejących danych.
 *
 * - ubr_lead_intelligence: bieżąca ocena firmy W KONTEKŚCIE ORGANIZACJI (Relevance, wykluczenia i decyzje o kontaktach są
 *   prywatne dla organizacji) — UNIQUE (organization_id, company_id); kolumny do filtrowania / sortowania w backendzie.
 * - ubr_lead_insights: wnioski z analizy strony (dane publiczne, wspólne dla organizacji) — UNIQUE (company_id, insight_key).
 * - ubr_lead_intelligence_history: historia ocen (nowy wpis tylko przy zmianie wyniku lub istotnych danych wejściowych).
 */
final class M007LeadIntelligence implements Migration
{
	public function version(): int
	{
		return 7;
	}

	public function description(): string
	{
		return 'Lead Intelligence (current evaluation per organization, website insights, evaluation history)';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$orgs = $db->table(Tables::ORGANIZATIONS);
		$companies = $db->table(Tables::COMPANIES);

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::LEAD_INTELLIGENCE)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			lead_score TINYINT UNSIGNED NULL,
			score_status VARCHAR(20) NOT NULL,
			confidence VARCHAR(10) NOT NULL,
			coverage TINYINT UNSIGNED NOT NULL DEFAULT 0,
			redesign VARCHAR(20) NOT NULL,
			recommendation VARCHAR(30) NOT NULL,
			rank_score DECIMAL(5,1) NULL,
			has_blocker TINYINT(1) NOT NULL DEFAULT 0,
			analysis_id BIGINT UNSIGNED NULL,
			input_hash CHAR(64) NOT NULL,
			input_snapshot LONGTEXT NOT NULL,
			result LONGTEXT NOT NULL,
			algorithm_version VARCHAR(30) NOT NULL,
			stale TINYINT(1) NOT NULL DEFAULT 0,
			stale_reason VARCHAR(40) NULL,
			calculated_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_company (organization_id, company_id),
			KEY org_rank (organization_id, has_blocker, rank_score),
			KEY org_score (organization_id, lead_score),
			KEY org_recommendation (organization_id, recommendation),
			KEY stale (stale, organization_id),
			KEY company (company_id),
			CONSTRAINT {$db->table('ubr_fk_lead_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_lead_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::LEAD_INSIGHTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			analysis_id BIGINT UNSIGNED NOT NULL,
			insight_key VARCHAR(40) NOT NULL,
			category VARCHAR(30) NOT NULL,
			severity VARCHAR(10) NOT NULL,
			title VARCHAR(190) NOT NULL,
			description TEXT NOT NULL,
			evidence LONGTEXT NOT NULL,
			source VARCHAR(60) NOT NULL,
			recommendation TEXT NOT NULL,
			confidence VARCHAR(10) NOT NULL,
			finding_keys TEXT NOT NULL,
			algorithm_version VARCHAR(30) NOT NULL,
			generated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_insight (company_id, insight_key),
			KEY company_analysis (company_id, analysis_id),
			CONSTRAINT {$db->table('ubr_fk_lead_insights_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::LEAD_HISTORY)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			lead_score TINYINT UNSIGNED NULL,
			previous_score TINYINT UNSIGNED NULL,
			score_status VARCHAR(20) NOT NULL,
			confidence VARCHAR(10) NOT NULL,
			coverage TINYINT UNSIGNED NOT NULL DEFAULT 0,
			redesign VARCHAR(20) NOT NULL,
			recommendation VARCHAR(30) NOT NULL,
			reason VARCHAR(40) NOT NULL,
			input_hash CHAR(64) NOT NULL,
			input_snapshot LONGTEXT NOT NULL,
			score_breakdown LONGTEXT NOT NULL,
			algorithm_version VARCHAR(30) NOT NULL,
			calculated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY org_company (organization_id, company_id, id),
			CONSTRAINT {$db->table('ubr_fk_lead_history_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_lead_history_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");
	}
}
