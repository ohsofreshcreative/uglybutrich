<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\VerificationQueueRepository;
use Throwable;

/**
 * Przetwarzanie kolejki weryfikacji w tle (osobno od Discovery — wyszukiwanie nie czeka na rejestry).
 *
 * Jeden worker naraz (blokada w opcji) — rejestry mają limity częstotliwości, a LocalWP ma mało procesów PHP.
 * Każdy krok ma budżet czasu; po kroku worker zleca kolejny (loopback), a healthcheck WP-Cron co minutę
 * wznawia kolejkę, jeśli łańcuch się przerwał. Rejestr niedostępny → ponowienie z backoffem (2 min, 10 min, 1 h, 6 h).
 */
class VerificationRunner
{
	public const LOCK_OPTION = 'ubr_verify_lock';

	public const CRON_HOOK = 'ubr_verification_tick';

	public function __construct(
		private VerificationQueueRepository $queue,
		private CompanyVerifier $verifier,
		private AsyncWorker $worker,
	) {
	}

	/**
	 * @return array{processed: int, verified: int, retried: int, failed: int, remaining_due: int}
	 */
	public function tick(int $budgetSeconds = 20, bool $chain = true): array
	{
		$stats = ['processed' => 0, 'verified' => 0, 'retried' => 0, 'failed' => 0, 'remaining_due' => 0];

		if (! $this->acquireLock($budgetSeconds + 150)) {
			return $stats;
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit($budgetSeconds + 180);
		}

		$started = microtime(true);

		try {
			while (microtime(true) - $started < $budgetSeconds) {
				$item = $this->queue->claim(170);

				if (! $item) {
					break;
				}

				$stats['processed']++;

				try {
					$result = $this->verifier->verify((int) $item['company_id'], [
						'org' => (int) $item['requested_by_org'] ?: null,
						'user' => (int) $item['requested_by_user'] ?: null,
						'trigger' => (int) $item['force_check'] ? 'manual' : 'auto',
					]);

					if ($result['retry'] && (int) $item['attempts'] < VerificationQueueRepository::MAX_ATTEMPTS) {
						$this->queue->retryLater((int) $item['id'], (int) $item['attempts'], (string) $result['error'], (int) $result['retry_after']);
						$stats['retried']++;
					} else {
						$this->queue->complete((int) $item['id']);
						$stats['verified']++;
						do_action('ubr/company/changed', (int) $item['company_id'], 'verification_done', null);
					}
				} catch (Throwable $e) {
					error_log('[ubr] Verification of company #' . $item['company_id'] . ' failed: ' . $e->getMessage());
					$this->queue->retryLater((int) $item['id'], (int) $item['attempts'], $e->getMessage());
					$stats['failed']++;
				}
			}
		} finally {
			$this->releaseLock();
		}

		$stats['remaining_due'] = $this->queue->countDue();

		if ($chain && $stats['remaining_due'] > 0) {
			$this->worker->dispatchJob('verify', 0);
		}

		return $stats;
	}

	/**
	 * Wznowienie kolejki (healthcheck): jeśli są zaległe pozycje i nikt ich nie przetwarza.
	 */
	public function kick(): bool
	{
		if ($this->queue->countDue() === 0 || $this->isLocked()) {
			return false;
		}

		return $this->worker->dispatchJob('verify', 0);
	}

	public function scheduleBackup(int $delay = 60): void
	{
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + $delay, self::CRON_HOOK);
		}
	}

	public function isLocked(): bool
	{
		return (int) get_option(self::LOCK_OPTION, 0) > time();
	}

	private function acquireLock(int $seconds): bool
	{
		if (add_option(self::LOCK_OPTION, time() + $seconds, '', false)) {
			return true;
		}

		$until = (int) get_option(self::LOCK_OPTION, 0);

		if ($until > time()) {
			return false;
		}

		// Przeterminowana blokada (proces przerwany) — przejęcie warunkowe, odporne na wyścig.
		global $wpdb;

		$taken = (bool) $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) (time() + $seconds),
			self::LOCK_OPTION,
			(string) $until
		));
		wp_cache_delete(self::LOCK_OPTION, 'options');

		return $taken;
	}

	private function releaseLock(): void
	{
		delete_option(self::LOCK_OPTION);
	}
}
