<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\PhoneRevealRepository;
use App\Ubr\Support\Lock;
use App\Ubr\Support\Redactor;
use Throwable;

/**
 * Odbieranie wyników „Odblokuj telefon” (Apollo, tryb poll_only): odpytywanie `webhook_result/{request_id}` — 0 kredytów.
 * Worker loopback (`ubr_job_worker`, zadanie 'phones'), WP-Cron `ubr_phones_tick`, healthcheck. NIGDY nie zleca nowych
 * odblokowań — tylko odbiera wyniki zleceń zatwierdzonych przez użytkownika.
 */
class ApolloPhoneRunner
{
	public const CRON_HOOK = 'ubr_phones_tick';

	public function __construct(
		private PhoneRevealRepository $reveals,
		private AsyncWorker $worker,
	) {
	}

	/**
	 * @return array{processed: int, remaining_due: int, pending: int}
	 */
	public function tick(bool $chain = true, int $max = 10): array
	{
		if (! Lock::acquire('ubr_phones_lock')) {
			return ['processed' => 0, 'remaining_due' => $this->reveals->countDue(), 'pending' => $this->reveals->countPending()];
		}

		$processed = 0;

		try {
			$this->reveals->failStaleReserved(10);

			foreach ($this->reveals->due($max) as $reveal) {
				$processed++;

				try {
					app(ApolloPhoneService::class)->poll($reveal);
				} catch (Throwable $e) {
					Redactor::log('Phone reveal #' . $reveal['id'] . ' poll failed: ' . $e->getMessage());
					$this->reveals->update((int) $reveal['id'], ['attempts' => (int) $reveal['attempts'] + 1, 'next_poll_at' => gmdate('Y-m-d H:i:s', time() + 120)]);
				}
			}
		} finally {
			Lock::release('ubr_phones_lock');
		}

		$pending = $this->reveals->countPending();

		// Wyniki przychodzą po minutach — kolejne odpytanie przez WP-Cron (nie łańcuch loopback co sekundę).
		if ($chain && $pending > 0 && ! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 60, self::CRON_HOOK);
		}

		return ['processed' => $processed, 'remaining_due' => $this->reveals->countDue(), 'pending' => $pending];
	}

	public function kick(): bool
	{
		if ($this->reveals->countPending() === 0) {
			return false;
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 60, self::CRON_HOOK);
		}

		return $this->reveals->countDue() > 0 ? $this->worker->dispatchJob('phones', 0) : false;
	}
}
