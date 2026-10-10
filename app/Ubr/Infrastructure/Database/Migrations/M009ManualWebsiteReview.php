<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * M009 — Manual Website Review (Prompt 6). Dwie nowe tabele, bez zmian w istniejących tabelach i danych.
 * (M008 = Action Center, już zastosowana — wdrożonych migracji się nie zmienia.)
 *
 * - ubr_manual_reviews: bieżąca ręczna ocena strony W KONTEKŚCIE ORGANIZACJI (prywatna), UNIQUE (organization_id, company_id)
 *   — do filtrów i list (JOIN po kluczu).
 * - ubr_manual_review_history: każda zmiana oceny (kto, kiedy, poprzednia → nowa, notatka) — historia nie jest nadpisywana.
 * Ocena ręczna jest subiektywna i NIE wpływa na Opportunity Score, Lead Potential ani Redesign Assessment.
 */
final class M009ManualWebsiteReview implements Migration
{
	public function version(): int
	{
		return 9;
	}

	public function description(): string
	{
		return 'Manual website review per organization (current review + history)';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$orgs = $db->table(Tables::ORGANIZATIONS);
		$companies = $db->table(Tables::COMPANIES);

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::MANUAL_REVIEWS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			review_status VARCHAR(20) NOT NULL,
			review_note TEXT NULL,
			reviewer_id BIGINT UNSIGNED NOT NULL,
			reviewed_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_company (organization_id, company_id),
			KEY org_status (organization_id, review_status),
			CONSTRAINT {$db->table('ubr_fk_mreview_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_mreview_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::MANUAL_REVIEW_HISTORY)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			review_status VARCHAR(20) NOT NULL,
			previous_status VARCHAR(20) NULL,
			review_note TEXT NULL,
			reviewer_id BIGINT UNSIGNED NOT NULL,
			reviewed_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY org_company (organization_id, company_id, id),
			CONSTRAINT {$db->table('ubr_fk_mreview_hist_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_mreview_hist_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");
	}
}
