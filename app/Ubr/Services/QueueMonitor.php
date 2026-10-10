<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Queue\JobDispatcher;
use App\Ubr\Infrastructure\Repositories\EventRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;

/**
 * Wykrywanie i naprawa zablokowanych zadań Discovery.
 *
 * - zadanie zaległe (termin minął, brak aktywnej blokady) → ponowne zlecenie workera,
 * - zadanie bez postępu dłużej niż STALL_FAIL_AFTER → oznaczane jako błąd (można je ponowić z UI).
 *
 * Wywoływane z: healthchecku WP-Cron (co minutę), odpytywania statusu w UI i `wp ubr queue-work`.
 */
class QueueMonitor
{
	/** Ile sekund po terminie uznajemy zadanie za zaległe. */
	public const OVERDUE_AFTER = 20;

	/** Brak jakiejkolwiek aktualizacji zadania przez tyle sekund = zadanie zablokowane na stałe. */
	public const STALL_FAIL_AFTER = 900;

	public function __construct(
		private Db $db,
		private SearchRepository $searches,
		private EventRepository $events,
		private JobDispatcher $dispatcher,
	) {
	}

	/**
	 * @return array{resumed: list<int>, failed: list<int>}
	 */
	public function recover(?int $onlySearchId = null): array
	{
		$now = time();
		$sql = "SELECT id, status, next_run_at, locked_until, updated_at, ticks FROM {$this->db->table(Tables::SEARCHES)} WHERE status IN ('queued','running')";
		$rows = $onlySearchId
			? $this->db->rows($sql . ' AND id = %d', $onlySearchId)
			: $this->db->rows($sql . ' ORDER BY id ASC LIMIT 20');

		$resumed = [];
		$failed = [];

		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$locked = $row['locked_until'] !== null && strtotime($row['locked_until'] . ' UTC') > $now;
			$updatedAgo = $now - strtotime($row['updated_at'] . ' UTC');
			$overdueBy = $row['next_run_at'] === null ? PHP_INT_MAX : $now - strtotime($row['next_run_at'] . ' UTC');

			if (! $locked && $updatedAgo > self::STALL_FAIL_AFTER) {
				$this->searches->update($id, [
					'status' => 'failed',
					'error' => sprintf('Zadanie zablokowane — brak postępu od %d min. Użyj „Ponów”.', (int) round($updatedAgo / 60)),
					'finished_at' => Db::now(),
					'next_run_at' => null,
					'progress' => 100,
				]);
				$this->events->log($id, 'error', 'Wykryto zablokowane zadanie (brak postępu) — oznaczono jako błąd.');
				error_log("[ubr] Discovery search #{$id} stalled for {$updatedAgo}s — marked as failed.");
				$failed[] = $id;
				continue;
			}

			if (! $locked && $overdueBy > self::OVERDUE_AFTER) {
				// Nie spamuj dziennika: loguj wznowienie najwyżej raz na 2 minuty na zadanie.
				if (! get_transient('ubr_resume_logged_' . $id)) {
					$this->events->log($id, 'warning', sprintf('Zadanie czekało %s s ponad termin — wznawiam workera.', $overdueBy === PHP_INT_MAX ? '?' : $overdueBy));
					set_transient('ubr_resume_logged_' . $id, 1, 120);
				}

				$this->searches->update($id, ['next_run_at' => Db::now()]);
				$this->dispatcher->dispatch($id, 0, (int) $row['ticks'] + 1);
				$resumed[] = $id;
			}
		}

		return ['resumed' => $resumed, 'failed' => $failed];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function health(): array
	{
		$worker = app(AsyncWorker::class);

		return [
			'driver' => $this->dispatcher->driver(),
			'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
			'last_worker_heartbeat' => $worker->lastHeartbeat() ?: null,
			'last_dispatch' => $worker->lastDispatch() ?: null,
			'loopback_suspected_broken' => $worker->looksBroken(),
		];
	}
}
