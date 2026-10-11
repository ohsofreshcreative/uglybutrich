<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Domain\Phones\PhoneNumber;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * M010 — Phone Intelligence + Cold Calling Workspace (Prompt 10). Bez usuwania i bez nadpisywania danych.
 *
 * Nowe tabele:
 * - ubr_company_phones: numery firmy. org_key = 0 → numer z oficjalnej strony firmy (dane publiczne, wspólne);
 *   org_key = ID organizacji → numer dodany ręcznie przez organizację (prywatny, nigdy nie nadpisywany ekstrakcją).
 * - ubr_phone_reveals: zlecenia „Odblokuj telefon” (Apollo) per organizacja — idempotencja (UNIQUE idempotency_key),
 *   stan, request_id do odpytywania wyniku, zużyte kredyty (gdy znane).
 * - ubr_contact_phones: numery osób pobrane przez organizację (Apollo) — prywatne dla organizacji, która je pobrała.
 *
 * Nowe kolumny:
 * - ubr_companies: main_phone_* (zdenormalizowany numer główny ze strony — listy i filtry bez N+1), phones_count, phones_checked_at.
 * - ubr_contacts: phone_e164 (znormalizowany public_business_phone — odróżnienie numeru firmy od numeru osoby),
 *   external_provider / external_id (np. identyfikator osoby w Apollo — dowód powiązania, odblokowanie telefonu).
 * - ubr_organization_prospects: do_not_contact (+ powód, kto, kiedy) — zakaz kontaktu niezależny od etapu pipeline;
 *   last_contact_at / last_contact_outcome — ostatni kontakt zapisany ŚWIADOMIE w aktywnościach (bez nowej historii).
 * - ubr_prospect_activities: outcome — wynik rozmowy dla aktywności typu „Telefon”.
 */
final class M010PhonesOwnership implements Migration
{
	public function version(): int
	{
		return 10;
	}

	public function description(): string
	{
		return 'Company phones, Apollo phone reveals, contact phones, do-not-contact, last contact, call outcomes';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();
		$companies = $db->table(Tables::COMPANIES);
		$contacts = $db->table(Tables::CONTACTS);
		$prospects = $db->table(Tables::PROSPECTS);
		$activities = $db->table(Tables::PROSPECT_ACTIVITIES);

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::COMPANY_PHONES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			org_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
			raw_number VARCHAR(60) NOT NULL,
			e164 VARCHAR(20) NOT NULL,
			extension VARCHAR(10) NULL,
			country_code CHAR(2) NULL,
			phone_type VARCHAR(10) NOT NULL DEFAULT 'UNKNOWN',
			source VARCHAR(20) NOT NULL,
			source_url VARCHAR(500) NULL,
			page_kind VARCHAR(20) NULL,
			context VARCHAR(160) NULL,
			branch_label VARCHAR(120) NULL,
			confidence VARCHAR(10) NOT NULL DEFAULT 'medium',
			is_primary TINYINT(1) NOT NULL DEFAULT 0,
			`manual` TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			found_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY company_org_number (company_id, org_key, e164),
			KEY e164 (e164),
			KEY company_primary (company_id, is_primary),
			CONSTRAINT {$db->table('ubr_fk_cphones_company')} FOREIGN KEY (company_id) REFERENCES {$companies} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::PHONE_REVEALS)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(20) NOT NULL DEFAULT 'apollo',
			idempotency_key VARCHAR(80) NOT NULL,
			status VARCHAR(20) NOT NULL,
			request_id VARCHAR(40) NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_poll_at DATETIME NULL,
			credits_consumed DECIMAL(8,2) NULL,
			phones_found SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			error VARCHAR(500) NULL,
			requested_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idempotency (idempotency_key),
			KEY org_contact (organization_id, contact_id, id),
			KEY status_poll (status, next_poll_at),
			CONSTRAINT {$db->table('ubr_fk_reveals_org')} FOREIGN KEY (organization_id) REFERENCES {$db->table(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_reveals_contact')} FOREIGN KEY (contact_id) REFERENCES {$contacts} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::CONTACT_PHONES)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			contact_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			raw_number VARCHAR(60) NOT NULL,
			e164 VARCHAR(20) NOT NULL,
			phone_type VARCHAR(12) NOT NULL DEFAULT 'UNKNOWN',
			source VARCHAR(20) NOT NULL DEFAULT 'APOLLO',
			confidence VARCHAR(10) NULL,
			reveal_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY org_contact_number (organization_id, contact_id, e164),
			KEY org_company (organization_id, company_id),
			CONSTRAINT {$db->table('ubr_fk_ctphones_org')} FOREIGN KEY (organization_id) REFERENCES {$db->table(Tables::ORGANIZATIONS)} (id) ON DELETE CASCADE,
			CONSTRAINT {$db->table('ubr_fk_ctphones_contact')} FOREIGN KEY (contact_id) REFERENCES {$contacts} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$c}");

		$this->columns($db, $companies, [
			'main_phone_e164' => 'VARCHAR(20) NULL AFTER company_channels',
			'main_phone_raw' => 'VARCHAR(60) NULL AFTER main_phone_e164',
			'main_phone_type' => 'VARCHAR(10) NULL AFTER main_phone_raw',
			'phones_count' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER main_phone_type',
			'phones_checked_at' => 'DATETIME NULL AFTER phones_count',
		]);
		$this->columns($db, $contacts, [
			'phone_e164' => 'VARCHAR(20) NULL AFTER public_business_phone',
			'external_provider' => 'VARCHAR(20) NULL AFTER source_observed_at',
			'external_id' => 'VARCHAR(64) NULL AFTER external_provider',
		]);
		$this->columns($db, $prospects, [
			'do_not_contact' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER qualification',
			'do_not_contact_reason' => 'VARCHAR(500) NULL AFTER do_not_contact',
			'do_not_contact_by' => 'BIGINT UNSIGNED NULL AFTER do_not_contact_reason',
			'do_not_contact_at' => 'DATETIME NULL AFTER do_not_contact_by',
			'last_contact_at' => 'DATETIME NULL AFTER last_activity_at',
			'last_contact_outcome' => 'VARCHAR(30) NULL AFTER last_contact_at',
		]);
		$this->columns($db, $activities, [
			'outcome' => 'VARCHAR(30) NULL AFTER type',
		]);

		$this->index($db, $companies, 'main_phone', '(main_phone_e164)');
		$this->index($db, $contacts, 'company_phone', '(company_id, phone_e164)');
		$this->index($db, $contacts, 'external', '(external_provider, external_id)');
		$this->index($db, $prospects, 'org_last_contact', '(organization_id, last_contact_at)');

		// Uzupełnienie phone_e164 dla istniejących osób (tylko odczyt public_business_phone; puste pola pozostają puste).
		foreach ($db->rows("SELECT id, public_business_phone FROM {$contacts} WHERE public_business_phone IS NOT NULL AND public_business_phone <> '' AND phone_e164 IS NULL") as $row) {
			$n = PhoneNumber::parse((string) $row['public_business_phone']);

			if ($n) {
				$db->execute("UPDATE {$contacts} SET phone_e164 = %s WHERE id = %d", $n['e164'], (int) $row['id']);
			}
		}

		// Ostatni kontakt z istniejących aktywności (telefon, e-mail, LinkedIn, spotkanie) — bez tworzenia nowych wpisów.
		$db->execute(
			"UPDATE {$prospects} p INNER JOIN (
				SELECT prospect_id, MAX(occurred_at) AS last_at FROM {$activities} WHERE type IN ('call','email','linkedin','meeting') GROUP BY prospect_id
			) a ON a.prospect_id = p.id SET p.last_contact_at = a.last_at WHERE p.last_contact_at IS NULL"
		);
	}

	/**
	 * @param array<string, string> $columns
	 */
	private function columns(Db $db, string $table, array $columns): void
	{
		foreach ($columns as $column => $definition) {
			if (! $db->value("SHOW COLUMNS FROM {$table} LIKE %s", $column)) {
				$db->execute("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
			}
		}
	}

	private function index(Db $db, string $table, string $key, string $cols): void
	{
		if (! $db->value("SHOW INDEX FROM {$table} WHERE Key_name = %s", $key)) {
			$db->execute("ALTER TABLE {$table} ADD KEY {$key} {$cols}");
		}
	}
}
