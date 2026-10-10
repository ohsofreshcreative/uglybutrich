<?php

namespace App\Ubr\Infrastructure\Queue;

/**
 * Planowanie kroków zadań w tle.
 *
 * Mechanizm główny: AsyncWorker (nieblokujący loopback do admin-ajax.php) — działa bez ruchu na stronie.
 * Siatka bezpieczeństwa: zdarzenie WP-Cron dla każdego kroku + cykliczny healthcheck (QueueMonitor),
 * oraz `wp ubr queue-work` dla systemowego crona.
 */
class JobDispatcher
{
	public const HOOK = 'ubr_discovery_tick';

	public const HEALTHCHECK_HOOK = 'ubr_queue_healthcheck';

	/** Zapas, po którym zapasowe zdarzenie WP-Cron wykona krok, jeśli worker async go nie wykonał. */
	private const CRON_BACKUP_DELAY = 45;

	public function __construct(private AsyncWorker $worker)
	{
	}

	public function dispatch(int $searchId, int $delaySeconds = 0, int $tick = 0): void
	{
		$delaySeconds = max(0, $delaySeconds);

		wp_schedule_single_event(time() + $delaySeconds + self::CRON_BACKUP_DELAY, self::HOOK, [$searchId, $tick]);

		// Worker async startuje od razu; przy opóźnieniu (ponowienie) sam odczeka do next_run_at.
		$this->worker->dispatch($searchId);
	}

	/**
	 * Uruchamia WP-Cron w tle (nieblokujące żądanie loopback), jeśli nie jest wyłączony.
	 */
	public function spawn(): void
	{
		if ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) || ! apply_filters('ubr/queue/spawn', true)) {
			return;
		}

		if (function_exists('spawn_cron')) {
			spawn_cron();
		}
	}

	public function hasPending(int $searchId): bool
	{
		foreach ((array) _get_cron_array() as $hooks) {
			foreach ((array) ($hooks[self::HOOK] ?? []) as $event) {
				if ((int) ($event['args'][0] ?? 0) === $searchId) {
					return true;
				}
			}
		}

		return false;
	}

	public function ensureHealthcheckScheduled(): void
	{
		if (! wp_next_scheduled(self::HEALTHCHECK_HOOK)) {
			wp_schedule_event(time() + 60, 'ubr_every_minute', self::HEALTHCHECK_HOOK);
		}
	}

	public function driver(): string
	{
		return 'async-loopback + wp-cron';
	}

	public function worker(): AsyncWorker
	{
		return $this->worker;
	}
}
