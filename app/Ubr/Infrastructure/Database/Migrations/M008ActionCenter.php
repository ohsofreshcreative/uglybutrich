<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * M008 — Action Center (Prompt 6). Jedna nowa tabela, bez zmian w istniejących tabelach i danych.
 *
 * ubr_action_snoozes: ręczne odłożenie działania z rekomendacji („przypomnij za N dni”) — prywatne dla organizacji,
 * UNIQUE (organization_id, company_id, action_type), więc ponowne odłożenie aktualizuje termin zamiast dublować wpis.
 * Follow-upów nie odkłada się tutaj (przekłada się ich termin istniejącym endpointem).
 */
final class M008ActionCenter implements Migration
{
	public function version(): int
	{
		return 8;
	}

	public function description(): string
	{
		return 'Action Center (manual snooze of recommended actions per organization)';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::ACTION_SNOOZES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			action_type VARCHAR(30) NOT NULL,
			snoozed_until DATETIME NOT NULL,
			reason VARCHAR(255) NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_company_action (organization_id, company_id, action_type),
			KEY org_until (organization_id, snoozed_until),
			CONSTRAINT {$db->table('ubr_fk_snooze_org')} FOREIGN KEY (organization_id) REFERENCES {$db->table(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_snooze_company')} FOREIGN KEY (company_id) REFERENCES {$db->table(Tables::COMPANIES)} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");
	}
}
