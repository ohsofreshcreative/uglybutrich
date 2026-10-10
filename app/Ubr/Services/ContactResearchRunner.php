<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\ContactRunRepository;
use Throwable;

/**
 * Kolejka wyszukiwań decydentów (ContactResearchJob) — ten sam mechanizm co analizy stron: worker loopback
 * (`ubr_job_worker`, zadanie 'contacts') + WP-Cron + healthcheck. Jedno wyszukiwanie na krok, ograniczona
 * równoległość, łańcuch kroków, ponowienie przy błędach przejściowych.
 */
class ContactResearchRunner
{
	public const CRON_HOOK = 'ubr_contacts_tick';

	public const LOCK_PREFIX = 'ubr_contacts_slot_';

	public function __construct(
		private ContactRunRepository $runs,
		private DecisionMakerFinderService $finder,
		private AsyncWorker $worker,
	) {
	}

	/**
	 * @return array{processed: int, status: ?string, remaining_due: int}
	 */
	public function tick(bool $chain = true): array
	{
		$slot = $this->acquireSlot();

		if ($slot === null) {
			return ['processed' => 0, 'status' => null, 'remaining_due' => $this->runs->countDue()];
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit(180);
		}

		$status = null;
		$processed = 0;

		try {
			$item = $this->runs->claim(ContactLimits::limits()['time_budget_seconds'] + 120);

			if ($item) {
				$processed = 1;

				try {
					$r = $this->finder->run($item);
					$status = $r['status'];

					if ($r['retry']) {
						$status = 'queued';
					}
				} catch (Throwable $e) {
					error_log('[ubr] Contact research #' . $item['id'] . ' failed: ' . $e->getMessage());
					$retry = (int) $item['attempts'] < ContactLimits::limits()['max_attempts'];
					$this->runs->update((int) $item['id'], $retry
						? ['status' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 300), 'locked_until' => null]
						: ['status' => 'failed', 'finished_at' => Db::now(), 'locked_until' => null, 'errors' => [['stage' => 'internal', 'message' => 'Nieoczekiwany błąd wyszukiwania.']]]);
					app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->update((int) $item['company_id'], ['contacts_status' => $retry ? 'queued' : 'failed']);
					$status = $retry ? 'queued' : 'failed';
				}
			}
		} finally {
			delete_option(self::LOCK_PREFIX . $slot);
		}

		$due = $this->runs->countDue();

		if ($chain && $due > 0) {
			$this->worker->dispatchJob('contacts', 0);
		}

		return ['processed' => $processed, 'status' => $status, 'remaining_due' => $due];
	}

	public function kick(): bool
	{
		if ($this->runs->countDue() === 0) {
			return false;
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 90, self::CRON_HOOK);
		}

		return $this->worker->dispatchJob('contacts', 0);
	}

	/**
	 * Slot równoległości (opcja z terminem wygaśnięcia) — maks. `concurrency` przeglądarek naraz.
	 */
	private function acquireSlot(): ?int
	{
		$seconds = ContactLimits::limits()['time_budget_seconds'] + 150;

		for ($slot = 1; $slot <= ContactLimits::limits()['concurrency']; $slot++) {
			$name = self::LOCK_PREFIX . $slot;

			if (add_option($name, time() + $seconds, '', false)) {
				return $slot;
			}

			wp_cache_delete($name, 'options');

			if ((int) get_option($name, 0) < time()) {
				delete_option($name);

				if (add_option($name, time() + $seconds, '', false)) {
					return $slot;
				}
			}
		}

		return null;
	}
}
