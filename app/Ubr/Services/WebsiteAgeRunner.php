<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\VerificationQueueRepository;
use App\Ubr\Support\Lock;

/**
 * Prompt 10.4.3 — kolejka Website Age Intelligence w tle, NAJNIŻSZY priorytet.
 *
 * - jeden worker naraz (blokada MySQL — zwalnia się sama, gdy proces zostanie przerwany),
 * - ustępuje weryfikacji firm: dopóki kolejka weryfikacji ma zaległe pozycje lub pracuje, krok jest odkładany,
 * - krótki krok (jak weryfikacja, UBR_VERIFY_STEP_SECONDS) z łańcuchowaniem; postęp analizy zapisywany po każdym
 *   zapytaniu do archiwum, więc przerwany proces nie traci pracy,
 * - zapas: zdarzenie WP-Cron i healthcheck co minutę.
 */
class WebsiteAgeRunner
{
	public const CRON_HOOK = 'ubr_site_age_tick';

	public const LOCK = 'ubr_site_age';

	public function __construct(private WebsiteAgeService $service, private AsyncWorker $worker)
	{
	}

	/**
	 * @return array{processed: int, finished: int, retried: int, deferred: bool, remaining_due: int}
	 */
	public function tick(?int $budgetSeconds = null, bool $chain = true): array
	{
		$stats = ['processed' => 0, 'finished' => 0, 'retried' => 0, 'deferred' => false, 'remaining_due' => 0];

		if (! WebsiteAgeService::enabled() || ! Lock::acquire(self::LOCK)) {
			return $stats;
		}

		try {
			if ($this->verificationBusy()) {
				$stats['deferred'] = true;
				$this->scheduleBackup(60);

				return $stats;
			}

			$budgetSeconds ??= VerificationRunner::stepSeconds();
			$deadline = microtime(true) + $budgetSeconds;

			while (microtime(true) < $deadline - 1.0) {
				$company = $this->service->claim();

				if (! $company) {
					break;
				}

				$stats['processed']++;

				try {
					$r = $this->service->step($company, $deadline);
				} catch (\Throwable $e) {
					error_log('[ubr] Website age for company #' . $company['id'] . ' failed: ' . $e->getMessage());
					$this->service->release((int) $company['id']);
					break;
				}

				if ($r['finished']) {
					$stats['finished']++;
				} elseif ($r['retry']) {
					$stats['retried']++;
				} else {
					$this->service->release((int) $company['id']);
					break;
				}
			}
		} finally {
			Lock::release(self::LOCK);
		}

		$stats['remaining_due'] = $this->service->countDue();

		if ($chain && $stats['remaining_due'] > 0 && ! $stats['deferred']) {
			$this->worker->dispatchJob('age', 0);
		}

		return $stats;
	}

	public function kick(): bool
	{
		if (! WebsiteAgeService::enabled() || $this->service->countDue() === 0) {
			return false;
		}

		$this->scheduleBackup(90);

		return $this->worker->dispatchJob('age', 0);
	}

	public function scheduleBackup(int $delay): void
	{
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
		}
	}

	private function verificationBusy(): bool
	{
		return (bool) apply_filters('ubr/site_age/verification_busy', app(VerificationRunner::class)->isLocked() || app(VerificationQueueRepository::class)->countDue() > 0);
	}
}
