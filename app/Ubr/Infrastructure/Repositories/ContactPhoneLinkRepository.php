<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Powiązania numer ↔ osoba (Prompt 10.2).
 *
 * org_key = 0 → powiązanie automatyczne z publicznej strony firmy (wspólne dla organizacji widzących firmę);
 * org_key = organizacja → decyzja organizacji (potwierdzenie / odrzucenie / ręczne przypisanie) — ma pierwszeństwo
 * przed automatycznym dla tej organizacji. Dla kontaktów ręcznych (contacts.org_key = organizacja) powiązanie
 * automatyczne ma org_key kontaktu; decyzji ręcznych (evidence MANUAL_*) ekstrakcja nigdy nie nadpisuje.
 */
class ContactPhoneLinkRepository
{
	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::CONTACT_PHONE_LINKS);
	}

	/**
	 * Upsert powiązania automatycznego (idempotentny: ten sam kontakt + numer = jeden wiersz). Wiersze z decyzją
	 * ręczną (evidence MANUAL_*) pozostają bez zmian.
	 *
	 * @param array<string, mixed> $l
	 */
	public function upsertAuto(array $l): void
	{
		$now = Db::now();
		$keep = "evidence LIKE 'MANUAL%'";

		$this->db->execute(
			"INSERT INTO {$this->t()} (contact_id, company_id, org_key, e164, raw_number, company_phone_id, link_status, evidence, source_type, source_url, evidence_excerpt, observed_name, observed_title, found_at, last_seen_at, created_at, updated_at)
			VALUES (%d, %d, %d, %s, NULLIF(%s, ''), NULLIF(%d, 0), %s, %s, 'WEBSITE', NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				link_status = IF({$keep}, link_status, VALUES(link_status)),
				evidence = IF({$keep}, evidence, VALUES(evidence)),
				raw_number = IF({$keep}, raw_number, COALESCE(VALUES(raw_number), raw_number)),
				company_phone_id = COALESCE(VALUES(company_phone_id), company_phone_id),
				source_url = IF({$keep}, source_url, COALESCE(VALUES(source_url), source_url)),
				evidence_excerpt = IF({$keep}, evidence_excerpt, COALESCE(VALUES(evidence_excerpt), evidence_excerpt)),
				observed_name = IF({$keep}, observed_name, COALESCE(VALUES(observed_name), observed_name)),
				observed_title = IF({$keep}, observed_title, COALESCE(VALUES(observed_title), observed_title)),
				last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)",
			(int) $l['contact_id'], (int) $l['company_id'], (int) ($l['org_key'] ?? 0), (string) $l['e164'], mb_substr((string) ($l['raw_number'] ?? ''), 0, 60),
			(int) ($l['company_phone_id'] ?? 0), (string) $l['link_status'], (string) $l['evidence'], mb_substr((string) ($l['source_url'] ?? ''), 0, 500),
			mb_substr((string) ($l['evidence_excerpt'] ?? ''), 0, 400), mb_substr((string) ($l['observed_name'] ?? ''), 0, 200), mb_substr((string) ($l['observed_title'] ?? ''), 0, 200),
			$now, $now, $now, $now
		);
	}

	/**
	 * Decyzja organizacji (potwierdź / odrzuć / przypisz) — własny wiersz organizacji; dane wspólne bez zmian.
	 *
	 * @param array<string, mixed> $extra raw_number, company_phone_id, source_url, evidence_excerpt, observed_name, observed_title
	 */
	public function decide(int $organizationId, int $contactId, int $companyId, string $e164, string $status, string $evidence, int $userId, array $extra = []): void
	{
		$now = Db::now();

		$this->db->execute(
			"INSERT INTO {$this->t()} (contact_id, company_id, org_key, e164, raw_number, company_phone_id, link_status, evidence, source_type, source_url, evidence_excerpt, observed_name, observed_title, found_at, last_seen_at, verified_at, verified_by, created_at, updated_at)
			VALUES (%d, %d, %d, %s, NULLIF(%s, ''), NULLIF(%d, 0), %s, %s, 'MANUAL', NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), %s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE link_status = VALUES(link_status), evidence = VALUES(evidence), source_type = 'MANUAL',
				raw_number = COALESCE(VALUES(raw_number), raw_number), company_phone_id = COALESCE(VALUES(company_phone_id), company_phone_id),
				source_url = COALESCE(VALUES(source_url), source_url), evidence_excerpt = COALESCE(VALUES(evidence_excerpt), evidence_excerpt),
				verified_at = VALUES(verified_at), verified_by = VALUES(verified_by), updated_at = VALUES(updated_at)",
			$contactId, $companyId, $organizationId, $e164, mb_substr((string) ($extra['raw_number'] ?? ''), 0, 60), (int) ($extra['company_phone_id'] ?? 0),
			$status, $evidence, mb_substr((string) ($extra['source_url'] ?? ''), 0, 500), mb_substr((string) ($extra['evidence_excerpt'] ?? ''), 0, 400),
			mb_substr((string) ($extra['observed_name'] ?? ''), 0, 200), mb_substr((string) ($extra['observed_title'] ?? ''), 0, 200),
			$now, $now, $now, $userId, $now, $now
		);
	}

	public function find(int $id): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id);
	}

	/** Usuwa WYŁĄCZNIE wiersz decyzji tej organizacji (nigdy powiązanie wspólne ani numer firmy). */
	public function deleteDecision(int $id, int $organizationId): int
	{
		return $this->db->execute("DELETE FROM {$this->t()} WHERE id = %d AND org_key = %d AND org_key <> 0", $id, $organizationId);
	}

	/**
	 * Powiązania widoczne dla organizacji (wspólne + jej decyzje) — efektywne: decyzja organizacji wygrywa.
	 *
	 * @param list<int> $contactIds
	 * @return array<int, list<array<string, mixed>>> contact_id → lista (VERIFIED → LIKELY → UNCERTAIN → REJECTED)
	 */
	public function effectiveFor(int $organizationId, array $contactIds): array
	{
		$contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));

		if ($contactIds === []) {
			return [];
		}

		$rows = $this->db->rows(
			"SELECT * FROM {$this->t()} WHERE contact_id IN (" . Db::placeholders($contactIds) . ') AND org_key IN (0, %d) ORDER BY org_key DESC, id ASC',
			...array_merge($contactIds, [$organizationId])
		);
		$by = [];

		foreach ($rows as $r) {
			$key = (int) $r['contact_id'] . '|' . $r['e164'];

			if (! isset($by[$key])) {
				$r['overridden'] = (int) $r['org_key'] !== 0; // decyzja organizacji
				$by[$key] = $r;
			} elseif ((int) $r['org_key'] === 0) {
				$by[$key]['auto'] = $r; // automatyczne pod decyzją — dowód ze strony nadal widoczny
			}
		}

		$rank = ['VERIFIED' => 0, 'LIKELY' => 1, 'UNCERTAIN' => 2, 'REJECTED' => 3];
		$out = [];

		foreach ($by as $r) {
			$out[(int) $r['contact_id']][] = $r;
		}

		foreach ($out as &$list) {
			usort($list, fn ($a, $b) => [$rank[$a['link_status']] ?? 9, (int) $a['id']] <=> [$rank[$b['link_status']] ?? 9, (int) $b['id']]);
		}

		unset($list);

		return $out;
	}

	/**
	 * Statystyki powiązań automatycznych (raport / walidacja).
	 *
	 * @return array<string, int>
	 */
	public function statusCounts(?array $companyIds = null): array
	{
		$where = $companyIds ? ' AND company_id IN (' . Db::placeholders($companyIds) . ')' : '';
		$out = [];

		foreach ($this->db->rows("SELECT link_status, COUNT(*) AS n FROM {$this->t()} WHERE org_key = 0{$where} GROUP BY link_status", ...($companyIds ?: [])) as $r) {
			$out[(string) $r['link_status']] = (int) $r['n'];
		}

		return $out;
	}
}
