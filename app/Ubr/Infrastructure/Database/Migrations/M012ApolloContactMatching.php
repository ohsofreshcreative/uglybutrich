<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 10.3 — Apollo Contact Matching & Phone Unlock.
 *
 * - `ubr_apollo_people`: kandydaci z Apollo People API Search przy firmie (dane wspólne jak kontakty automatyczne):
 *   identyfikator osoby i firmy Apollo, imię, nazwisko TYLKO gdy Apollo je podało w całości, nazwisko zamaskowane,
 *   stanowisko, sygnał dostępności telefonu (surowa wartość z API + status), daty. Bez e-maili i numerów.
 * - `ubr_apollo_matches`: dopasowanie kandydat ↔ kontakt PRYWATNE dla organizacji (poziom EXACT / STRONG / POSSIBLE /
 *   NO_MATCH / CONFLICT, status SUGGESTED / APPROVED / REJECTED / DETACHED, dowody, kto i kiedy zatwierdził);
 *   `contact_id = 0` — kandydat bez lokalnego kontaktu (NO_MATCH). UNIQUE (organizacja, kandydat, kontakt) — bez duplikatów.
 *   Identyfikator Apollo przypisany do kontaktu = dopasowanie APPROVED tej organizacji (globalny kontakt bez zmian).
 * - `ubr_apollo_match_history`: historia zmian dopasowań (bez nadpisywania).
 * - `ubr_phone_reveals.apollo_person_id`: identyfikator osoby Apollo użyty w zleceniu odblokowania.
 * Bez słów zastrzeżonych MySQL 8 w nazwach kolumn. Nie usuwa danych.
 */
final class M012ApolloContactMatching implements Migration
{
	public function version(): int
	{
		return 12;
	}

	public function description(): string
	{
		return 'Apollo people candidates, organization-private contact matches with history';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$companies = $db->table(Tables::COMPANIES);
		$people = $db->table(Tables::APOLLO_PEOPLE);
		$matches = $db->table(Tables::APOLLO_MATCHES);

		$db->execute("CREATE TABLE IF NOT EXISTS {$people} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			apollo_person_id VARCHAR(64) NOT NULL,
			apollo_org_id VARCHAR(64) NULL,
			first_name VARCHAR(100) NULL,
			last_name VARCHAR(120) NULL,
			last_name_obfuscated VARCHAR(120) NULL,
			title VARCHAR(200) NULL,
			normalized_role VARCHAR(40) NULL,
			org_domain VARCHAR(190) NULL,
			belongs_to_company TINYINT(1) NOT NULL DEFAULT 0,
			phone_signal VARCHAR(120) NULL,
			phone_availability VARCHAR(12) NOT NULL DEFAULT 'UNKNOWN',
			has_email_signal TINYINT(1) NULL,
			apollo_refreshed_at VARCHAR(40) NULL,
			found_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			last_search_org BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_person (company_id, apollo_person_id),
			KEY apollo_person (apollo_person_id),
			CONSTRAINT {$db->table('ubr_fk_apollo_people_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$matches} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			candidate_id BIGINT UNSIGNED NOT NULL,
			apollo_person_id VARCHAR(64) NOT NULL,
			contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			match_level VARCHAR(10) NOT NULL,
			match_status VARCHAR(10) NOT NULL DEFAULT 'SUGGESTED',
			match_source VARCHAR(20) NOT NULL DEFAULT 'PEOPLE_SEARCH',
			evidence LONGTEXT NULL,
			matched_at DATETIME NOT NULL,
			decided_by BIGINT UNSIGNED NULL,
			decided_at DATETIME NULL,
			last_verified_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_candidate_contact (organization_id, candidate_id, contact_id),
			KEY org_company (organization_id, company_id),
			KEY org_person (organization_id, apollo_person_id, match_status),
			KEY org_contact (organization_id, contact_id, match_status),
			CONSTRAINT {$db->table('ubr_fk_apollo_matches_org')} FOREIGN KEY (organization_id) REFERENCES {$db->table(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_apollo_matches_candidate')} FOREIGN KEY (candidate_id) REFERENCES {$people} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::APOLLO_MATCH_HISTORY)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			match_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NOT NULL,
			change_type VARCHAR(20) NOT NULL,
			from_status VARCHAR(10) NULL,
			to_status VARCHAR(10) NOT NULL,
			match_level VARCHAR(10) NULL,
			contact_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			note VARCHAR(300) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY match_created (match_id, created_at),
			KEY org_created (organization_id, created_at),
			CONSTRAINT {$db->table('ubr_fk_apollo_history_match')} FOREIGN KEY (match_id) REFERENCES {$matches} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		// Zlecenie odblokowania zapamiętuje identyfikator osoby Apollo, dla którego je wysłano — wynik przypisujemy
		// wyłącznie tej osobie (także gdy dopasowanie zostanie później odłączone).
		$reveals = $db->table(Tables::PHONE_REVEALS);

		if (! $db->value("SHOW COLUMNS FROM {$reveals} LIKE %s", 'apollo_person_id')) {
			$db->execute("ALTER TABLE {$reveals} ADD COLUMN apollo_person_id VARCHAR(64) NULL AFTER provider");
		}
	}
}
