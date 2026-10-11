<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 10.2 — bezpośrednie telefony decydentów.
 *
 * - `ubr_contact_phone_links`: powiązanie numeru z osobą wraz z dowodem (VERIFIED / LIKELY / UNCERTAIN / REJECTED).
 *   `org_key = 0` — powiązanie automatyczne z publicznej strony firmy (wspólne); `org_key = organizacja` — decyzja
 *   organizacji (potwierdzenie, odrzucenie, ręczne przypisanie), która dla tej organizacji ma pierwszeństwo przed
 *   powiązaniem automatycznym i nigdy nie jest nadpisywana ekstrakcją.
 * - `ubr_company_phones`: właściciel numeru (PERSON_DIRECT / DEPARTMENT / COMPANY_GENERAL / BRANCH / UNCERTAIN) z opisem.
 * - Istniejące `contacts.phone_e164` (numer z bloku osoby, Prompt 3 / 10) → powiązania LIKELY (dowód sprzed 10.2
 *   nie był zapisany — wymaga ponownego sprawdzenia lub potwierdzenia). Numery dodane ręcznie z osobą → VERIFIED (MANUAL).
 *
 * Bez słów zastrzeżonych MySQL 8 w nazwach kolumn (LocalWP / produkcja: MySQL 8.4). Nie usuwa danych.
 */
final class M011DecisionMakerPhones implements Migration
{
	public function version(): int
	{
		return 11;
	}

	public function description(): string
	{
		return 'Decision maker phone links with evidence, company phone ownership classification';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$contacts = $db->table(Tables::CONTACTS);
		$links = $db->table(Tables::CONTACT_PHONE_LINKS);
		$phones = $db->table(Tables::COMPANY_PHONES);

		$db->execute("CREATE TABLE IF NOT EXISTS {$links} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			org_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
			e164 VARCHAR(20) NOT NULL,
			raw_number VARCHAR(60) NULL,
			company_phone_id BIGINT UNSIGNED NULL,
			link_status VARCHAR(12) NOT NULL,
			evidence VARCHAR(30) NOT NULL,
			source_type VARCHAR(20) NOT NULL DEFAULT 'WEBSITE',
			source_url VARCHAR(500) NULL,
			evidence_excerpt VARCHAR(400) NULL,
			observed_name VARCHAR(200) NULL,
			observed_title VARCHAR(200) NULL,
			found_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			verified_at DATETIME NULL,
			verified_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY contact_org_number (contact_id, org_key, e164),
			KEY company_org (company_id, org_key, link_status),
			KEY e164 (e164),
			CONSTRAINT {$db->table('ubr_fk_cplinks_contact')} FOREIGN KEY (contact_id) REFERENCES {$contacts} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		foreach ([
			'owner_class' => 'VARCHAR(16) NULL AFTER branch_label',
			'owner_note' => 'VARCHAR(200) NULL AFTER owner_class',
			'owner_checked_at' => 'DATETIME NULL AFTER owner_note',
		] as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$phones} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$phones} ADD COLUMN {$column} {$definition}");
			}
		}

		// Istniejące numery osób → powiązania (bez zmiany kontaktów i scoringu). INSERT IGNORE = idempotentnie.
		$now = gmdate('Y-m-d H:i:s');
		$db->execute(
			"INSERT IGNORE INTO {$links} (contact_id, company_id, org_key, e164, raw_number, link_status, evidence, source_type, source_url, evidence_excerpt, observed_name, observed_title, found_at, last_seen_at, verified_at, verified_by, created_at, updated_at)
			SELECT ct.id, ct.company_id, ct.org_key, ct.phone_e164, ct.public_business_phone,
				IF(ct.org_key = 0, 'LIKELY', 'VERIFIED'), IF(ct.org_key = 0, 'LEGACY_BLOCK', 'MANUAL_ASSIGN'), IF(ct.org_key = 0, 'WEBSITE', 'MANUAL'),
				ct.source_url, IF(ct.org_key = 0, 'Numer zapisany przy osobie przed etapem 10.2 (blok osoby na stronie) — dowód do ponownego sprawdzenia.', 'Numer podany ręcznie przy dodawaniu osoby.'),
				ct.full_name, ct.job_title, COALESCE(ct.source_observed_at, ct.created_at), COALESCE(ct.source_observed_at, ct.created_at),
				IF(ct.org_key = 0, NULL, ct.created_at), IF(ct.org_key = 0, NULL, ct.created_by), %s, %s
			FROM {$contacts} ct WHERE ct.phone_e164 IS NOT NULL AND ct.phone_e164 <> ''",
			$now,
			$now
		);
	}
}
