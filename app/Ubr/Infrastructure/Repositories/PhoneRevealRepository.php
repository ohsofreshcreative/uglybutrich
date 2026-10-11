<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Zlecenia „Odblokuj telefon” (Apollo) i numery osób pobrane przez organizację (prywatne dla tej organizacji).
 */
class PhoneRevealRepository
{
	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::PHONE_REVEALS);
	}

	private function phones(): string
	{
		return $this->db->table(Tables::CONTACT_PHONES);
	}

	/**
	 * Idempotentne utworzenie zlecenia: ten sam klucz (organizacja + osoba + numer kolejny) = to samo zlecenie,
	 * nawet przy podwójnym kliknięciu lub równoległych żądaniach (UNIQUE idempotency_key).
	 *
	 * @return array{id: int, created: bool}
	 */
	public function reserve(int $organizationId, int $contactId, int $companyId, int $userId, string $key): array
	{
		$now = Db::now();
		$created = $this->db->execute(
			"INSERT IGNORE INTO {$this->t()} (organization_id, contact_id, company_id, user_id, provider, idempotency_key, status, requested_at, updated_at)
			VALUES (%d, %d, %d, %d, 'apollo', %s, 'reserved', %s, %s)",
			$organizationId, $contactId, $companyId, $userId, $key, $now, $now
		) > 0;

		return ['id' => (int) $this->db->value("SELECT id FROM {$this->t()} WHERE idempotency_key = %s", $key), 'created' => $created];
	}

	public function find(int $id): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$this->db->update(Tables::PHONE_REVEALS, $data + ['updated_at' => Db::now()], ['id' => $id]);
	}

	public function countFor(int $organizationId, int $contactId): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE organization_id = %d AND contact_id = %d", $organizationId, $contactId);
	}

	public function latest(int $organizationId, int $contactId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t()} WHERE organization_id = %d AND contact_id = %d ORDER BY id DESC LIMIT 1", $organizationId, $contactId);
	}

	/**
	 * @param list<int> $contactIds
	 * @return array<int, array<string, mixed>> najnowsze zlecenie per osoba
	 */
	public function latestFor(int $organizationId, array $contactIds): array
	{
		$contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));

		if ($contactIds === []) {
			return [];
		}

		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->t()} WHERE organization_id = %d AND contact_id IN (" . Db::placeholders($contactIds) . ') ORDER BY id ASC', $organizationId, ...$contactIds) as $r) {
			$out[(int) $r['contact_id']] = $r;
		}

		return $out;
	}

	public function countSince(int $organizationId, string $sinceUtc): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE organization_id = %d AND requested_at >= %s AND status <> 'cancelled'", $organizationId, $sinceUtc);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function due(int $limit = 10): array
	{
		return $this->db->rows("SELECT * FROM {$this->t()} WHERE status = 'pending' AND next_poll_at <= %s ORDER BY next_poll_at ASC, id ASC LIMIT %d", Db::now(), $limit);
	}

	public function countDue(): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE status = 'pending' AND next_poll_at <= %s", Db::now());
	}

	/**
	 * Zlecenia „reserved” starsze niż $minutes (proces przerwany między rezerwacją a odpowiedzią Apollo) → błąd
	 * BEZ automatycznego ponowienia (zapytanie mogło zużyć kredyty). Zwraca liczbę oznaczonych.
	 */
	public function failStaleReserved(int $minutes = 10): int
	{
		return (int) $this->db->execute(
			"UPDATE {$this->t()} SET status = 'error', error = %s, completed_at = %s, updated_at = %s WHERE status = 'reserved' AND requested_at < %s",
			'Wynik zlecenia nieznany (proces przerwany). Sprawdź w Apollo przed ponowieniem — ponowienie wymaga osobnej decyzji.',
			Db::now(),
			Db::now(),
			gmdate('Y-m-d H:i:s', time() - $minutes * 60)
		);
	}

	public function countPending(): int
	{
		return (int) $this->db->value("SELECT COUNT(*) FROM {$this->t()} WHERE status = 'pending'");
	}

	/**
	 * @param list<array{raw: string, e164: string, type: string, confidence: ?string}> $phones
	 */
	public function savePhones(int $organizationId, int $contactId, int $companyId, int $revealId, array $phones): int
	{
		$now = Db::now();
		$n = 0;

		foreach ($phones as $p) {
			$n += $this->db->execute(
				"INSERT INTO {$this->phones()} (organization_id, contact_id, company_id, raw_number, e164, phone_type, source, confidence, reveal_id, created_at, updated_at)
				VALUES (%d, %d, %d, %s, %s, %s, 'APOLLO', NULLIF(%s, ''), %d, %s, %s)
				ON DUPLICATE KEY UPDATE phone_type = VALUES(phone_type), confidence = VALUES(confidence), reveal_id = VALUES(reveal_id), updated_at = VALUES(updated_at)",
				$organizationId, $contactId, $companyId, mb_substr($p['raw'], 0, 60), $p['e164'], $p['type'], (string) ($p['confidence'] ?? ''), $revealId, $now, $now
			) > 0 ? 1 : 0;
		}

		return $n;
	}

	/**
	 * @param list<int> $contactIds
	 * @return array<int, list<array<string, mixed>>>
	 */
	public function phonesFor(int $organizationId, array $contactIds): array
	{
		$contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));

		if ($contactIds === []) {
			return [];
		}

		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->phones()} WHERE organization_id = %d AND contact_id IN (" . Db::placeholders($contactIds) . ") ORDER BY FIELD(phone_type, 'DIRECT', 'MOBILE', 'OTHER', 'UNKNOWN'), id ASC", $organizationId, ...$contactIds) as $r) {
			$out[(int) $r['contact_id']][] = $r;
		}

		return $out;
	}
}
