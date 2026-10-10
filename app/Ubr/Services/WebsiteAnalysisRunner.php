<?php

namespace App\Ubr\Services;

use App\Ubr\Support\Lock;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use Throwable;

/**
 * Kolejka analiz stron (ten sam mechanizm co Discovery/weryfikacja: worker loopback + healthcheck WP-Cron).
 *
 * Równoległość ograniczona (domyślnie 1 przeglądarka naraz — LocalWP), jedna analiza na krok workera
 * (każda trwa do ~100 s), łańcuch kolejnych kroków, ponowienie przy błędach przejściowych (maks. 2 próby).
 */
class WebsiteAnalysisRunner
{
	public const CRON_HOOK = 'ubr_analysis_tick';

	public const LOCK_PREFIX = 'ubr_analysis_slot_';

	public function __construct(
		private AnalysisRepository $analyses,
		private WebsiteAnalyzerService $analyzer,
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
			return ['processed' => 0, 'status' => null, 'remaining_due' => $this->analyses->countDue()];
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit(240);
		}

		$status = null;
		$processed = 0;

		try {
			// Analizy przerwane twardo (proces zabity) po wyczerpaniu prób — 'failed' zamiast nieskończonego ponawiania.
			foreach ($this->analyses->failExhausted(3) as $dead) {
				$this->analyses->update((int) $dead['id'], ['finished_at' => Db::now(), 'errors' => [['stage' => 'internal', 'message' => 'Analiza przerwana — proces workera został zakończony (limit czasu lub pamięci serwera) po kilku próbach.']]]);
				$this->analyzer->syncCompany((int) $dead['company_id']);
			}

			$item = $this->analyses->claim((int) ceil(AnalysisLimits::analysisTimeoutMs() / 1000) + 120);

			if ($item) {
				$processed = 1;

				try {
					$r = $this->analyzer->run($item);
					$status = $r['status'];

					if ($r['retry']) {
						$this->analyses->update((int) $item['id'], ['status' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 300), 'finished_at' => null]);
						$status = 'queued';
					}
				} catch (Throwable $e) {
					error_log('[ubr] Website analysis #' . $item['id'] . ' failed: ' . $e->getMessage());
					$retry = (int) $item['attempts'] < 2;
					$this->analyses->update((int) $item['id'], $retry
						? ['status' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 300), 'locked_until' => null]
						: ['status' => 'failed', 'finished_at' => Db::now(), 'locked_until' => null, 'errors' => [['stage' => 'internal', 'message' => 'Nieoczekiwany błąd analizy.']]]);
					$this->analyzer->syncCompany((int) $item['company_id']);
					$status = $retry ? 'queued' : 'failed';
				}
			}
		} finally {
			Lock::release(self::LOCK_PREFIX . $slot);
		}

		$due = $this->analyses->countDue();

		if ($chain && $due > 0) {
			$this->worker->dispatchJob('analyze', 0);
		}

		return ['processed' => $processed, 'status' => $status, 'remaining_due' => $due];
	}

	public function kick(): bool
	{
		if ($this->analyses->countDue() === 0) {
			return false;
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 90, self::CRON_HOOK);
		}

		return $this->worker->dispatchJob('analyze', 0);
	}

	/**
	 * Slot równoległości — maks. `concurrency` przeglądarek naraz. Blokada MySQL (GET_LOCK): atomowa i zwalniana
	 * automatycznie po śmierci procesu (wcześniej: opcja z terminem — nieatomowe przejęcie, możliwe 2 Chromium naraz).
	 */
	private function acquireSlot(): ?int
	{
		for ($slot = 1; $slot <= AnalysisLimits::limits()['concurrency']; $slot++) {
			if (Lock::acquire(self::LOCK_PREFIX . $slot)) {
				return $slot;
			}
		}

		return null;
	}
}
