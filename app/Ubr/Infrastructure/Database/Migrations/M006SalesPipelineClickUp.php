<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 4: Sales Pipeline + ClickUp.
 *
 * Wszystko tutaj jest PRYWATNE dla organizacji (status sprzedażowy, właściciel, notatki, aktywności, follow-upy,
 * historia, reguły i historia eksportu). Globalny rekord firmy nie jest zmieniany. Połączenie ClickUp należy
 * dodatkowo do konkretnego użytkownika (token zaszyfrowany, klucz poza bazą).
 *
 * - ubr_organization_prospects (rozszerzenie istniejącej tabeli): pipeline, właściciel, priorytet, kwalifikacja,
 *   wybrany kontakt, powód utraty, daty aktywności / następnego działania, archiwizacja,
 * - ubr_prospect_events: historia zmian (status, właściciel, priorytet, …) i dziennik istotnych operacji,
 * - ubr_prospect_notes, ubr_prospect_activities, ubr_prospect_followups,
 * - ubr_clickup_connections: połączenie OAuth użytkownika w organizacji + miejsce eksportu i mapowanie pól,
 * - ubr_clickup_exports: rekord eksportu firmy do listy ClickUp (deduplikacja: org + firma + lista + wariant),
 * - ubr_clickup_jobs: kolejka i historia operacji (create / update / evaluate),
 * - ubr_clickup_rules: reguły automatycznego eksportu (domyślnie wyłączone).
 */
final class M006SalesPipelineClickUp implements Migration
{
	public function version(): int
	{
		return 6;
	}

	public function description(): string
	{
		return 'Sales pipeline (prospect fields, events, notes, activities, follow-ups) + ClickUp (connections, exports, jobs, rules)';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$prospects = $db->table(Tables::PROSPECTS);
		$companies = $db->table(Tables::COMPANIES);
		$orgs = $db->table(Tables::ORGANIZATIONS);

		$columns = [
			'owner_user_id' => 'BIGINT UNSIGNED NULL AFTER added_by',
			'priority' => "VARCHAR(10) NOT NULL DEFAULT 'medium' AFTER owner_user_id",
			'qualification' => 'VARCHAR(20) NULL AFTER priority',
			'selected_contact_id' => 'BIGINT UNSIGNED NULL AFTER qualification',
			'lost_reason' => 'VARCHAR(500) NULL AFTER selected_contact_id',
			'status_changed_at' => 'DATETIME NULL AFTER lost_reason',
			'last_activity_at' => 'DATETIME NULL AFTER status_changed_at',
			'next_action_at' => 'DATETIME NULL AFTER last_activity_at',
			'archived_at' => 'DATETIME NULL AFTER next_action_at',
			'archived_by' => 'BIGINT UNSIGNED NULL AFTER archived_at',
		];

		foreach ($columns as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$prospects} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$prospects} ADD COLUMN {$column} {$definition}");
			}
		}

		foreach (['org_status' => '(organization_id, archived_at, status)', 'org_owner' => '(organization_id, owner_user_id)', 'org_next_action' => '(organization_id, next_action_at)'] as $key => $cols) {
			if (! $db->value("SHOW INDEX FROM {$prospects} WHERE Key_name = %s", $key)) {
				$db->execute("ALTER TABLE {$prospects} ADD KEY {$key} {$cols}");
			}
		}

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::PROSPECT_EVENTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			prospect_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			type VARCHAR(30) NOT NULL,
			old_value VARCHAR(255) NULL,
			new_value VARCHAR(255) NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY prospect_created (prospect_id, created_at),
			KEY org_type_created (organization_id, type, created_at),
			CONSTRAINT {$db->table('ubr_fk_prospect_events_prospect')} FOREIGN KEY (prospect_id) REFERENCES {$prospects} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::PROSPECT_NOTES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			prospect_id BIGINT UNSIGNED NOT NULL,
			author_id BIGINT UNSIGNED NOT NULL,
			body TEXT NOT NULL,
			deleted_at DATETIME NULL,
			deleted_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY prospect_created (prospect_id, created_at),
			KEY org (organization_id),
			CONSTRAINT {$db->table('ubr_fk_prospect_notes_prospect')} FOREIGN KEY (prospect_id) REFERENCES {$prospects} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::PROSPECT_ACTIVITIES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			prospect_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			description TEXT NULL,
			contact_id BIGINT UNSIGNED NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY prospect_occurred (prospect_id, occurred_at),
			KEY org_occurred (organization_id, occurred_at),
			CONSTRAINT {$db->table('ubr_fk_prospect_activities_prospect')} FOREIGN KEY (prospect_id) REFERENCES {$prospects} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::PROSPECT_FOLLOWUPS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			prospect_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(200) NOT NULL,
			description TEXT NULL,
			due_at DATETIME NOT NULL,
			assigned_user_id BIGINT UNSIGNED NULL,
			status VARCHAR(15) NOT NULL DEFAULT 'open',
			completed_at DATETIME NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY prospect_status (prospect_id, status),
			KEY org_status_due (organization_id, status, due_at),
			CONSTRAINT {$db->table('ubr_fk_prospect_followups_prospect')} FOREIGN KEY (prospect_id) REFERENCES {$prospects} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CLICKUP_CONNECTIONS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'connected',
			token_ciphertext TEXT NULL,
			key_id VARCHAR(16) NULL,
			clickup_user_id VARCHAR(40) NULL,
			clickup_username VARCHAR(190) NULL,
			authorized_teams LONGTEXT NULL,
			destination LONGTEXT NULL,
			settings LONGTEXT NULL,
			connected_at DATETIME NULL,
			last_checked_at DATETIME NULL,
			last_error VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_user (organization_id, user_id),
			CONSTRAINT {$db->table('ubr_fk_clickup_conn_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CLICKUP_EXPORTS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			prospect_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			connection_id BIGINT UNSIGNED NULL,
			rule_id BIGINT UNSIGNED NULL,
			workspace_id VARCHAR(40) NOT NULL,
			list_id VARCHAR(40) NOT NULL,
			list_name VARCHAR(255) NULL,
			variant SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			task_id VARCHAR(40) NULL,
			task_url VARCHAR(255) NULL,
			export_status VARCHAR(20) NOT NULL DEFAULT 'queued',
			managed_hash CHAR(64) NULL,
			exported_at DATETIME NULL,
			last_synced_at DATETIME NULL,
			last_error VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_company_list (organization_id, company_id, list_id, variant),
			KEY org_status (organization_id, export_status),
			KEY company (company_id),
			CONSTRAINT {$db->table('ubr_fk_clickup_exports_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_clickup_exports_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CLICKUP_JOBS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			connection_id BIGINT UNSIGNED NULL,
			export_id BIGINT UNSIGNED NULL,
			rule_id BIGINT UNSIGNED NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			operation VARCHAR(20) NOT NULL,
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			batch_id VARCHAR(40) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			locked_until DATETIME NULL,
			http_status SMALLINT UNSIGNED NULL,
			error_kind VARCHAR(30) NULL,
			error_message VARCHAR(500) NULL,
			result LONGTEXT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status_due (status, next_attempt_at),
			KEY org_created (organization_id, created_at),
			KEY org_company (organization_id, company_id),
			KEY batch (batch_id),
			KEY rule_company (rule_id, company_id)
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CLICKUP_RULES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			connection_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			conditions LONGTEXT NOT NULL,
			workspace_id VARCHAR(40) NOT NULL,
			list_id VARCHAR(40) NOT NULL,
			list_name VARCHAR(255) NULL,
			enabled TINYINT(1) NOT NULL DEFAULT 0,
			daily_limit SMALLINT UNSIGNED NOT NULL DEFAULT 10,
			activated_at DATETIME NULL,
			backfill_confirmed_at DATETIME NULL,
			last_run_at DATETIME NULL,
			exported_count INT UNSIGNED NOT NULL DEFAULT 0,
			error_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY org_enabled (organization_id, enabled),
			CONSTRAINT {$db->table('ubr_fk_clickup_rules_org')} FOREIGN KEY (organization_id) REFERENCES {$orgs} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");
	}
}
