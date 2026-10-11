<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Kolejka weryfikacji firm (globalna — firmy są wspólne; organizacja zlecająca zapisywana do limitów i historii).
 * Jedna pozycja na firmę (UNIQUE company_id): ponowne zlecenie podnosi priorytet / wymusza sprawdzenie, nie duplikuje.
 */
class VerificationQueueRepository
{
	public const MAX_ATTEMPTS = 4;

	public function __construct(private Db $db)
	{
	}

	private function t(): string
	{
		return $this->db->table(Tables::VERIFICATION_QUEUE);
	}

	/**
	 * @return bool true = dodano lub odświeżono pozycję
	 */
	public function enqueue(int $companyId, ?int $organizationId, ?int $userId, ?int $searchId, int $priority = 5, bool $force = false): bool
	{
		$now = Db::now();

		return $this->db->execute(
			"INSERT INTO {$this->t()} (company_id, status, priority, force_check, attempts, next_attempt_at, requested_by_org, requested_by_user, search_id, created_at, updated_at)
			VALUES (%d, 'queued', %d, %d, 0, %s, %d, %d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				priority = LEAST(priority, VALUES(priority)),
				force_check = GREATEST(force_check, VALUES(force_check)),
				requested_by_org = VALUES(requested_by_org),
				requested_by_user = VALUES(requested_by_user),
				search_id = IF(VALUES(search_id) > 0, VALUES(search_id), search_id),
				attempts = IF(status = 'running' AND locked_until > %s, attempts, 0),
				next_attempt_at = IF(status = 'running' AND locked_until > %s, next_attempt_at, LEAST(next_attempt_at, VALUES(next_attempt_at))),
				last_error = IF(status = 'running', last_error, NULL),
				status = IF(status = 'running' AND locked_until > %s, status, 'queued'),
				updated_at = VALUES(updated_at)",
			$companyId,
			$priority,
			$force ? 1 : 0,
			$now,
			(int) $organizationId,
			(int) $userId,
			(int) $searchId,
			$now,
			$now,
			$now,
			$now,
			$now
		) > 0;
	}

	/**
	 * Pobiera i blokuje kolejną pozycję gotową do przetworzenia (priorytet, potem najstarszy termin).
	 *
	 * @return array<string, mixed>|null
	 */
	public function claim(int $lockSeconds = 180): ?array
	{
		$now = Db::now();

		for ($i = 0; $i < 3; $i++) {
			$row = $this->db->row(
				"SELECT * FROM {$this->t()}
				WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s))
				ORDER BY priority ASC, next_attempt_at ASC, id ASC
				LIMIT 1",
				$now,
				$now
			);

			if (! $row) {
				return null;
			}

			$claimed = $this->db->execute(
				"UPDATE {$this->t()} SET status = 'running', locked_until = %s, attempts = attempts + 1, updated_at = %s
				WHERE id = %d AND (status = 'queued' OR (status = 'running' AND locked_until < %s))",
				gmdate('Y-m-d H:i:s', time() + $lockSeconds),
				$now,
				(int) $row['id'],
				$now
			);

			if ($claimed > 0) {
				return $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", (int) $row['id']);
			}
		}

		return null;
	}

	/**
	 * Prompt 10.4.3 — zwrot pobranej, a nieprzetworzonej pozycji do kolejki (koniec budżetu kroku workera):
	 * bez zużywania próby i bez czekania na wygaśnięcie blokady.
	 */
	public function release(int $id): void
	{
		$this->db->execute(
			"UPDATE {$this->t()} SET status = 'queued', locked_until = NULL, attempts = GREATEST(attempts - 1, 0), updated_at = %s WHERE id = %d AND status = 'running'",
			Db::now(),
			$id
		);
	}

	public function complete(int $id): void
	{
		$this->db->delete(Tables::VERIFICATION_QUEUE, ['id' => $id]);
	}

	/**
	 * Ponowienie z backoffem; po MAX_ATTEMPTS pozycja zostaje jako 'failed' (widoczna w diagnostyce).
	 */
	public function retryLater(int $id, int $attempts, string $error, int $minDelay = 0): void
	{
		$delays = [120, 600, 3600, 6 * 3600];
		$delay = max($minDelay, $delays[min($attempts, count($delays)) - 1] ?? 3600);

		$this->db->update(Tables::VERIFICATION_QUEUE, [
			'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued',
			'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay),
			'locked_until' => null,
			'last_error' => mb_substr($error, 0, 500),
			'updated_at' => Db::now(),
		], ['id' => $id]);
	}

	/**
	 * Pozycje „running” z wygasłą blokadą i wyczerpanym limitem prób (worker zabity: timeout, OOM, restart PHP) —
	 * oznacza je jako 'failed' (warunkowo, bez wyścigu) i zwraca. Bez tego claim() podejmował je w nieskończoność.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function failExhausted(int $maxAttempts): array
	{
		$now = Db::now();
		$out = [];

		foreach ($this->db->rows("SELECT * FROM {$this->t()} WHERE status = 'running' AND locked_until < %s AND attempts >= %d ORDER BY id ASC LIMIT 20", $now, $maxAttempts) as $row) {
			if ($this->db->execute("UPDATE {$this->t()} SET status = 'failed', locked_until = NULL, updated_at = %s WHERE id = %d AND status = 'running' AND locked_until < %s", $now, (int) $row['id'], $now) > 0) {
				$out[] = $row;
			}
		}

		return $out;
	}

	public function countDue(): int
	{
		$now = Db::now();

		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->t()} WHERE next_attempt_at <= %s AND (status = 'queued' OR (status = 'running' AND locked_until < %s))",
			$now,
			$now
		);
	}

	public function nextDueAt(): ?int
	{
		$value = $this->db->value("SELECT MIN(next_attempt_at) FROM {$this->t()} WHERE status IN ('queued','running')");

		return $value ? (int) strtotime($value . ' UTC') : null;
	}

	/**
	 * @return array<string, int>
	 */
	public function stats(): array
	{
		$out = ['queued' => 0, 'running' => 0, 'failed' => 0];

		foreach ($this->db->rows("SELECT status, COUNT(*) AS n FROM {$this->t()} GROUP BY status") as $row) {
			$out[$row['status']] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Pozycje kolejki dla firm z danego wyszukiwania (postęp weryfikacji w UI).
	 *
	 * @return array<string, int>
	 */
	public function statsForSearch(int $searchId): array
	{
		$out = ['queued' => 0, 'running' => 0, 'failed' => 0];

		foreach ($this->db->rows(
			"SELECT q.status, COUNT(*) AS n FROM {$this->t()} q
			INNER JOIN {$this->db->table(Tables::RESULTS)} r ON r.company_id = q.company_id AND r.search_id = %d
			GROUP BY q.status",
			$searchId
		) as $row) {
			$out[$row['status']] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function forCompany(int $companyId): ?array
	{
		return $this->db->row("SELECT status, attempts, next_attempt_at, last_error FROM {$this->t()} WHERE company_id = %d", $companyId);
	}

	public function countRequestedToday(int $organizationId): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->db->table(Tables::COMPANY_CHECKS)} WHERE requested_by_org = %d AND trigger_type = 'manual' AND kind = 'company' AND created_at >= %s",
			$organizationId,
			gmdate('Y-m-d 00:00:00')
		);
	}
}
