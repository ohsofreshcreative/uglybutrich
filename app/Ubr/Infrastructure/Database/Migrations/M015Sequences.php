<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 10.5 — Sequences: biblioteka kampanii e-mail, sekwencje przypisane do firm, skrzynki pracowników,
 * kolejka wysyłki i blokady adresów. Tylko nowe tabele (bez zmian istniejących danych).
 *
 * - `ubr_seq_campaigns` / `ubr_seq_campaign_steps` — szablony (wspólne organizacji albo prywatne pracownika) i ich kroki;
 *   `version` rośnie przy każdej zmianie kroków (informacja, z której wersji powstała sekwencja).
 * - `ubr_seq_sequences` — NIEZALEŻNA kopia kampanii dla firmy (snapshot: nazwa, wersja i id szablonu tylko jako źródło);
 *   `active_recipient` = `org|adres` dla sekwencji zaplanowanych / aktywnych / wstrzymanych / z błędem, inaczej NULL —
 *   UNIQUE gwarantuje jedną aktywną sekwencję na adres odbiorcy w organizacji (także między różnymi firmami).
 * - `ubr_seq_messages` — kroki sekwencji = harmonogram = trwała kolejka wysyłki = historia (wysłana treść, Message-ID,
 *   odpowiedź SMTP). `send_token` UNIQUE — identyfikator operacji wysyłki (ochrona przed podwójną wysyłką).
 * - `ubr_seq_mailboxes` — skrzynki pracowników (hasło aplikacji / token zaszyfrowane SecretBox, klucz poza bazą).
 * - `ubr_seq_suppressions` — adresy wypisane / z trwałym błędem / wykluczone ręcznie (per organizacja).
 */
final class M015Sequences implements Migration
{
	public function version(): int
	{
		return 15;
	}

	public function description(): string
	{
		return 'Sequences: campaign library, company sequences, mailboxes, send queue, suppressions';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::SEQ_CAMPAIGNS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			scope VARCHAR(10) NOT NULL DEFAULT 'personal',
			owner_user_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(160) NOT NULL,
			description TEXT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'draft',
			settings LONGTEXT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			archived_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY org_status (organization_id, status),
			KEY owner (organization_id, owner_user_id)
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::SEQ_CAMPAIGN_STEPS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			body MEDIUMTEXT NULL,
			delay_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY campaign (campaign_id, position)
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::SEQ_SEQUENCES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			prospect_id BIGINT UNSIGNED NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			campaign_id BIGINT UNSIGNED NULL,
			campaign_version INT UNSIGNED NULL,
			campaign_name VARCHAR(160) NULL,
			name VARCHAR(160) NOT NULL,
			mailbox_id BIGINT UNSIGNED NULL,
			recipient_email VARCHAR(190) NULL,
			recipient_name VARCHAR(190) NULL,
			recipient_source VARCHAR(20) NULL,
			contact_id BIGINT UNSIGNED NULL,
			active_recipient VARCHAR(220) NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'draft',
			test_mode TINYINT(1) NOT NULL DEFAULT 0,
			settings LONGTEXT NULL,
			variables LONGTEXT NULL,
			error_code VARCHAR(40) NULL,
			error_message VARCHAR(500) NULL,
			step_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			sent_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_send_at DATETIME NULL,
			last_sent_at DATETIME NULL,
			approved_by BIGINT UNSIGNED NULL,
			approved_at DATETIME NULL,
			started_at DATETIME NULL,
			paused_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY active_recipient (active_recipient),
			KEY org_status (organization_id, status, next_send_at),
			KEY company (organization_id, company_id),
			KEY owner (organization_id, owner_user_id),
			KEY mailbox (mailbox_id),
			KEY recipient (organization_id, recipient_email),
			KEY campaign (campaign_id)
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::SEQ_MESSAGES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sequence_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NOT NULL,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			body MEDIUMTEXT NULL,
			delay_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			scheduled_at DATETIME NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			locked_until DATETIME NULL,
			send_token CHAR(32) NULL,
			message_id VARCHAR(190) NULL,
			mailbox_id BIGINT UNSIGNED NULL,
			from_email VARCHAR(190) NULL,
			to_email VARCHAR(190) NULL,
			sent_subject VARCHAR(255) NULL,
			sent_body MEDIUMTEXT NULL,
			sent_at DATETIME NULL,
			transport VARCHAR(10) NULL,
			smtp_response VARCHAR(500) NULL,
			error VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY send_token (send_token),
			KEY sequence (sequence_id, position),
			KEY due (status, scheduled_at),
			KEY org_sent (organization_id, status, sent_at),
			KEY mailbox_sent (mailbox_id, sent_at)
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::SEQ_MAILBOXES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(12) NOT NULL DEFAULT 'smtp',
			email VARCHAR(190) NOT NULL,
			from_name VARCHAR(120) NULL,
			host VARCHAR(190) NULL,
			port SMALLINT UNSIGNED NULL,
			encryption VARCHAR(5) NULL,
			username VARCHAR(190) NULL,
			secret_ciphertext TEXT NULL,
			key_id VARCHAR(20) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'untested',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			limits LONGTEXT NULL,
			dns_check LONGTEXT NULL,
			last_tested_at DATETIME NULL,
			last_error VARCHAR(500) NULL,
			last_sent_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_email (organization_id, user_id, email),
			KEY org_user (organization_id, user_id)
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::SEQ_SUPPRESSIONS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(190) NOT NULL,
			reason VARCHAR(20) NOT NULL,
			note VARCHAR(500) NULL,
			sequence_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_email (organization_id, email)
		) ENGINE=InnoDB {$c}");
	}
}
