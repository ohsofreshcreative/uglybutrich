<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Domain\Phones\PhonePicker;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Numery telefonów firm. org_key = 0 → numer ze strony firmy (wspólny); org_key = organizacja → numer ręczny (prywatny).
 * Ekstrakcja nigdy nie modyfikuje numerów ręcznych (inny klucz org_key, manual = 1).
 */
class CompanyPhoneRepository
{
	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::COMPANY_PHONES);
	}

	/**
	 * Zapis numerów znalezionych na stronie (upsert po firmie + numerze) i przeliczenie numeru głównego.
	 *
	 * @param list<array<string, mixed>> $candidates wynik CompanyPhoneExtractor::extract()
	 */
	public function recordExtracted(int $companyId, array $candidates): int
	{
		$now = Db::now();
		$saved = 0;

		foreach ($candidates as $p) {
			$saved += $this->db->execute(
				"INSERT INTO {$this->t()} (company_id, org_key, raw_number, e164, extension, country_code, phone_type, source, source_url, page_kind, context, branch_label, confidence, is_primary, `manual`, found_at, last_seen_at, created_at, updated_at)
				VALUES (%d, 0, %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), %s, %s, %s, %s, %s, NULLIF(%s, ''), %s, 0, 0, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at),
					phone_type = IF(VALUES(phone_type) = 'MAIN' OR phone_type = 'UNKNOWN', VALUES(phone_type), phone_type),
					confidence = IF(VALUES(confidence) = 'high', 'high', confidence),
					source = IF(VALUES(source) IN ('TEL_LINK', 'SCHEMA') AND source = 'TEXT', VALUES(source), source),
					page_kind = IF(VALUES(page_kind) = 'contact', 'contact', page_kind),
					branch_label = COALESCE(branch_label, VALUES(branch_label)),
					context = IF(VALUES(page_kind) = 'contact' OR context IS NULL, VALUES(context), context),
					source_url = IF(VALUES(page_kind) = 'contact' OR source_url IS NULL, VALUES(source_url), source_url)",
				$companyId, (string) $p['raw'], (string) $p['e164'], (string) ($p['extension'] ?? ''), (string) ($p['country'] ?? ''), (string) $p['type'],
				(string) $p['source'], mb_substr((string) $p['source_url'], 0, 500), (string) $p['page_kind'], mb_substr((string) $p['context'], 0, 160),
				(string) ($p['branch_label'] ?? ''), (string) $p['confidence'], $now, $now, $now, $now
			) > 0 ? 1 : 0;

			// Stopka: znacznik w kontekście (kolumny in_footer nie ma — preferencja stopki zapisana w page_kind „footer”, gdy brak kontaktu).
			if (! empty($p['in_footer'])) {
				$this->db->execute("UPDATE {$this->t()} SET page_kind = 'footer' WHERE company_id = %d AND org_key = 0 AND e164 = %s AND page_kind NOT IN ('contact')", $companyId, (string) $p['e164']);
			}
		}

		$this->refreshPrimary($companyId);

		return $saved;
	}

	/**
	 * Zastąpienie numerów ze stron pobranych ponownie (org_key 0, te same adresy źródłowe). Numery ręczne nietknięte.
	 *
	 * @param list<string> $sourceUrls
	 * @param list<array<string, mixed>> $candidates
	 */
	public function replaceExtracted(int $companyId, array $sourceUrls, array $candidates): int
	{
		$sourceUrls = array_values(array_unique(array_filter(array_map(fn ($u) => mb_substr((string) $u, 0, 500), $sourceUrls))));

		if ($sourceUrls !== []) {
			$this->db->execute(
				"DELETE FROM {$this->t()} WHERE company_id = %d AND org_key = 0 AND `manual` = 0 AND source_url IN (" . Db::placeholders($sourceUrls) . ')',
				$companyId,
				...$sourceUrls
			);
		}

		return $this->recordExtracted($companyId, $candidates);
	}

	public function refreshPrimary(int $companyId): void
	{
		$rows = $this->db->rows("SELECT * FROM {$this->t()} WHERE company_id = %d AND org_key = 0 ORDER BY id ASC", $companyId);
		$country = (string) ($this->db->value("SELECT country_code FROM {$this->db->table(Tables::COMPANIES)} WHERE id = %d", $companyId) ?: 'PL');
		$primary = PhonePicker::primary(array_map(fn ($r) => $r + ['in_footer' => $r['page_kind'] === 'footer'], $rows), $country);
		$this->db->execute("UPDATE {$this->t()} SET is_primary = IF(id = %d, 1, 0) WHERE company_id = %d AND org_key = 0", (int) ($primary['id'] ?? 0), $companyId);
		$this->db->update(Tables::COMPANIES, [
			'main_phone_e164' => $primary['e164'] ?? null,
			'main_phone_raw' => $primary ? ($primary['raw_number'] . ($primary['extension'] ? ' wew. ' . $primary['extension'] : '')) : null,
			'main_phone_type' => $primary['phone_type'] ?? null,
			'phones_count' => count($rows),
			'phones_checked_at' => Db::now(),
			'updated_at' => Db::now(), // Prompt 10.4: lekkie odświeżanie listy Discover wykrywa zmianę
		], ['id' => $companyId]);
	}

	/**
	 * Numery firmy widoczne dla organizacji: ze strony (org_key 0) + ręczne tej organizacji.
	 *
	 * @param list<int> $companyIds
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function forCompanies(array $companyIds, int $organizationId): array
	{
		$companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));

		if ($companyIds === []) {
			return [];
		}

		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->t()} WHERE company_id IN (" . Db::placeholders($companyIds) . ") AND org_key IN (0, %d) ORDER BY `manual` DESC, is_primary DESC, id ASC", ...array_merge($companyIds, [$organizationId])) as $r) {
			$out[(int) $r['company_id']][] = $r;
		}

		return $out;
	}

	public function find(int $id): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id);
	}

	/**
	 * @param array<string, mixed> $p
	 */
	public function addManual(int $companyId, int $organizationId, array $p, int $userId): int
	{
		$now = Db::now();
		$this->db->execute(
			"INSERT INTO {$this->t()} (company_id, org_key, raw_number, e164, extension, country_code, phone_type, source, branch_label, context, confidence, is_primary, `manual`, created_by, found_at, last_seen_at, created_at, updated_at)
			VALUES (%d, %d, %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), %s, 'MANUAL', NULLIF(%s, ''), NULLIF(%s, ''), 'high', 0, 1, %d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE phone_type = VALUES(phone_type), branch_label = VALUES(branch_label), context = VALUES(context), raw_number = VALUES(raw_number), extension = VALUES(extension), updated_at = VALUES(updated_at)",
			$companyId, $organizationId, (string) $p['raw'], (string) $p['e164'], (string) ($p['extension'] ?? ''), (string) ($p['country'] ?? ''), (string) $p['type'],
			(string) ($p['label'] ?? ''), (string) ($p['note'] ?? ''), $userId, $now, $now, $now, $now
		);

		return (int) $this->db->value("SELECT id FROM {$this->t()} WHERE company_id = %d AND org_key = %d AND e164 = %s", $companyId, $organizationId, (string) $p['e164']);
	}

	public function deleteManual(int $id, int $organizationId): int
	{
		return $this->db->execute("DELETE FROM {$this->t()} WHERE id = %d AND org_key = %d AND `manual` = 1", $id, $organizationId);
	}

	/**
	 * @return list<string> numery E.164 firmy (strona + ręczne organizacji) — do odróżnienia numeru firmy od numeru osoby
	 */
	public function numbersFor(int $companyId, int $organizationId): array
	{
		return $this->db->column("SELECT e164 FROM {$this->t()} WHERE company_id = %d AND org_key IN (0, %d)", $companyId, $organizationId);
	}
}
