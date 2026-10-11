<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Kontakty (ContactCandidateRepository), ich źródła, historia wyniku, prywatne decyzje organizacji i wykluczenia.
 *
 * org_key = 0 → wynik automatycznego researchu (globalny, publiczne dane zawodowe),
 * org_key = id organizacji → kontakt dodany ręcznie (prywatny, USER_PROVIDED).
 */
class ContactRepository
{
	private const JSON = ['score_breakdown', 'confidence_breakdown', 'flags'];

	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::CONTACTS);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $id): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByKey(int $companyId, int $orgKey, string $personKey): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t()} WHERE company_id = %d AND org_key = %d AND person_key = %s", $companyId, $orgKey, $personKey));
	}

	/**
	 * Kontakty widoczne dla organizacji: globalne + jej prywatne (ręczne). Nigdy prywatne innych organizacji.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forCompany(int $companyId, ?int $organizationId, bool $activeOnly = true): array
	{
		$active = $activeOnly ? ' AND active = 1' : '';

		return array_map(fn ($r) => $this->decode($r), $this->db->rows(
			"SELECT * FROM {$this->t()} WHERE company_id = %d AND (org_key = 0 OR org_key = %d){$active} ORDER BY (rank_position IS NULL), rank_position, contact_priority_score DESC, person_key",
			$companyId,
			(int) $organizationId
		));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(array $data): int
	{
		$now = Db::now();

		return $this->db->insert(Tables::CONTACTS, $this->encode($data) + ['created_at' => $now, 'updated_at' => $now]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$data = $this->encode($data);
		$data['updated_at'] = Db::now();
		$this->db->update(Tables::CONTACTS, $data, ['id' => $id]);
	}

	/**
	 * Kontakty globalne, których nie znaleziono w bieżącym wyszukiwaniu — oznaczane jako nieaktywne (bez usuwania historii).
	 *
	 * @param list<int> $keepIds
	 */
	public function deactivateMissing(int $companyId, array $keepIds, int $runId): int
	{
		$keep = $keepIds ? ' AND id NOT IN (' . Db::placeholders($keepIds) . ')' : '';

		return $this->db->execute("UPDATE {$this->t()} SET active = 0, rank_position = NULL, updated_at = %s WHERE company_id = %d AND org_key = 0 AND active = 1{$keep}", Db::now(), $companyId, ...$keepIds);
	}

	/**
	 * @param array<string, mixed> $source
	 */
	public function addSource(int $contactId, ?int $runId, array $source): void
	{
		$now = Db::now();
		$this->db->insert(Tables::CONTACT_SOURCES, [
			'contact_id' => $contactId,
			'run_id' => $runId,
			'source_type' => $source['source_type'],
			'source_url' => isset($source['source_url']) ? mb_substr((string) $source['source_url'], 0, 500) : null,
			'source_title' => isset($source['source_title']) ? mb_substr((string) $source['source_title'], 0, 255) : null,
			'observed_role' => isset($source['observed_role']) ? mb_substr((string) $source['observed_role'], 0, 200) : null,
			'evidence_excerpt' => isset($source['excerpt']) ? mb_substr((string) $source['excerpt'], 0, 400) : null,
			'confidence' => $source['confidence'] ?? null,
			'retrieved_at' => $source['retrieved_at'] ?? $now,
			'created_at' => $now,
		]);
	}

	/**
	 * Najnowsze źródła kontaktu (po jednym na typ + URL).
	 *
	 * @param list<int> $contactIds
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function sources(array $contactIds): array
	{
		if ($contactIds === []) {
			return [];
		}

		$out = [];
		$rows = $this->db->rows(
			"SELECT * FROM {$this->db->table(Tables::CONTACT_SOURCES)} WHERE contact_id IN (" . Db::placeholders($contactIds) . ') ORDER BY retrieved_at DESC, id DESC',
			...$contactIds
		);

		foreach ($rows as $r) {
			$key = $r['source_type'] . '|' . $r['source_url'] . '|' . $r['observed_role'];
			$cid = (int) $r['contact_id'];

			if (isset($out[$cid][$key])) {
				continue;
			}

			$out[$cid][$key] = $r;
		}

		return array_map('array_values', $out);
	}

	public function addHistory(int $contactId, ?int $runId, array $data): void
	{
		$this->db->insert(Tables::CONTACT_SCORE_HISTORY, [
			'contact_id' => $contactId,
			'run_id' => $runId,
			'job_title' => isset($data['job_title']) ? mb_substr((string) $data['job_title'], 0, 200) : null,
			'contact_priority_score' => $data['contact_priority_score'] ?? null,
			'confidence_level' => $data['confidence_level'] ?? null,
			'verification_status' => $data['verification_status'] ?? null,
			'rank_position' => $data['rank_position'] ?? null,
			'created_at' => Db::now(),
		]);
	}

	/**
	 * @param list<int> $contactIds
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function history(array $contactIds, int $perContact = 10): array
	{
		if ($contactIds === []) {
			return [];
		}

		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->db->table(Tables::CONTACT_SCORE_HISTORY)} WHERE contact_id IN (" . Db::placeholders($contactIds) . ') ORDER BY id DESC', ...$contactIds) as $r) {
			if (count($out[(int) $r['contact_id']] ?? []) < $perContact) {
				$out[(int) $r['contact_id']][] = $r;
			}
		}

		return $out;
	}

	/* ------------------------------ prywatne decyzje organizacji ------------------------------ */

	/**
	 * @return array<int, array<string, mixed>> wg contact_id
	 */
	public function reviews(int $organizationId, int $companyId): array
	{
		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->db->table(Tables::CONTACT_REVIEWS)} WHERE organization_id = %d AND company_id = %d", $organizationId, $companyId) as $r) {
			$out[(int) $r['contact_id']] = $r;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $data status / preferred / note
	 */
	public function saveReview(int $organizationId, int $contactId, int $companyId, int $userId, array $data): void
	{
		$table = $this->db->table(Tables::CONTACT_REVIEWS);
		$existing = $this->db->row("SELECT * FROM {$table} WHERE organization_id = %d AND contact_id = %d", $organizationId, $contactId);
		$now = Db::now();

		if (! empty($data['preferred'])) {
			// Jedna preferowana osoba na firmę w organizacji.
			$this->db->execute("UPDATE {$table} SET preferred = 0, updated_at = %s WHERE organization_id = %d AND company_id = %d", $now, $organizationId, $companyId);
		}

		if ($existing) {
			$this->db->update(Tables::CONTACT_REVIEWS, $data + ['user_id' => $userId, 'updated_at' => $now], ['id' => (int) $existing['id']]);
		} else {
			$this->db->insert(Tables::CONTACT_REVIEWS, $data + ['organization_id' => $organizationId, 'contact_id' => $contactId, 'company_id' => $companyId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
		}
	}

	/**
	 * Firmy, w których organizacja ma prywatne decyzje (do przeliczenia Top 1 na listach).
	 *
	 * @param list<int> $companyIds
	 * @return list<int>
	 */
	public function companiesWithReviews(int $organizationId, array $companyIds): array
	{
		if ($companyIds === []) {
			return [];
		}

		return array_map('intval', $this->db->column(
			"SELECT DISTINCT company_id FROM {$this->db->table(Tables::CONTACT_REVIEWS)} WHERE organization_id = %d AND (status IS NOT NULL OR preferred = 1) AND company_id IN (" . Db::placeholders($companyIds) . ')',
			$organizationId,
			...$companyIds
		));
	}

	/* ------------------------------ wykluczenia z researchu (globalne) ------------------------------ */

	public function isSuppressed(int $companyId, string $personKey): bool
	{
		return (bool) $this->db->value("SELECT 1 FROM {$this->db->table(Tables::CONTACT_SUPPRESSIONS)} WHERE company_id = %d AND person_key = %s", $companyId, $personKey);
	}

	public function suppress(int $companyId, string $personKey, ?string $reason, ?int $userId): void
	{
		if ($this->isSuppressed($companyId, $personKey)) {
			return;
		}

		$this->db->insert(Tables::CONTACT_SUPPRESSIONS, ['company_id' => $companyId, 'person_key' => $personKey, 'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'created_by' => $userId, 'created_at' => Db::now()]);
		// Dane osoby usuwane z aktywnych wyników (źródła i historia pozostają do audytu do czasu retencji).
		$this->db->execute("UPDATE {$this->t()} SET active = 0, rank_position = NULL, public_business_email = NULL, public_business_phone = NULL, phone_e164 = NULL, professional_profile_url = NULL, updated_at = %s WHERE company_id = %d AND person_key = %s", Db::now(), $companyId, $personKey);
	}

	/**
	 * Retencja: usuwa nieaktywne kontakty automatyczne niewidziane od X dni (wraz ze źródłami i historią — kaskada).
	 */
	public function purgeInactiveOlderThan(int $days): int
	{
		return $this->db->execute("DELETE FROM {$this->t()} WHERE org_key = 0 AND active = 0 AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS));
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function encode(array $data): array
	{
		// Znormalizowany numer osoby (E.164) — do odróżnienia numeru firmy (centrala) od bezpośredniego numeru osoby.
		if (array_key_exists('public_business_phone', $data)) {
			$n = $data['public_business_phone'] ? \App\Ubr\Domain\Phones\PhoneNumber::parse((string) $data['public_business_phone']) : null;
			$data['phone_e164'] = $n['e164'] ?? null;
		}

		foreach (self::JSON as $key) {
			if (array_key_exists($key, $data) && is_array($data[$key])) {
				$data[$key] = wp_json_encode($data[$key]);
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, mixed>|null
	 */
	private function decode(?array $row): ?array
	{
		if (! $row) {
			return null;
		}

		foreach (self::JSON as $key) {
			if (array_key_exists($key, $row)) {
				$row[$key] = $row[$key] !== null ? (json_decode((string) $row[$key], true) ?: []) : [];
			}
		}

		return $row;
	}
}
