<?php

namespace App\Ubr\Services;

use App\Ubr\Support\Lock;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository;
use App\Ubr\Support\Redactor;
use Throwable;

/**
 * Przeliczanie Lead Intelligence w tle (batch): oceny oznaczone jako nieaktualne, ze starszą wersją algorytmu oraz
 * firmy widoczne dla organizacji bez oceny. Ten sam worker co pozostałe kolejki (`ubr_job_worker`, zadanie 'lead'),
 * zapasowo WP-Cron `ubr_lead_tick` i healthcheck. Liczy wyłącznie zapisane dane — szybkie, bez zapytań zewnętrznych.
 */
class LeadIntelligenceRunner
{
	public const CRON_HOOK = 'ubr_lead_tick';

	public const BATCH = 50;

	private static bool $kicked = false;

	public function __construct(private LeadIntelligenceRepository $repo, private LeadIntelligenceService $service, private AsyncWorker $worker)
	{
	}

	/**
	 * @return array{processed: int, failed: int, remaining: int}
	 */
	public function tick(int $max = self::BATCH, bool $chain = true, ?int $organizationId = null): array
	{
		if (! Lock::acquire('ubr_lead_lock')) {
			return ['processed' => 0, 'failed' => 0, 'remaining' => -1];
		}

		$processed = 0;
		$failed = 0;

		try {
			foreach ($this->repo->pending(LeadIntelligenceService::VERSION, $max, $organizationId) as $job) {
				try {
					$this->service->evaluate($job['organization_id'], $job['company_id'], $job['reason']);
					$processed++;
				} catch (Throwable $e) {
					$failed++;
					$this->repo->deferFailed($job['organization_id'], $job['company_id']);
					Redactor::log('Lead Intelligence: firma #' . $job['company_id'] . ' (org #' . $job['organization_id'] . '): ' . $e->getMessage());
				}
			}
		} finally {
			Lock::release('ubr_lead_lock');
		}

		$remaining = $this->repo->countPending(LeadIntelligenceService::VERSION, $organizationId);

		if ($chain && $remaining > 0 && $processed > 0) {
			$this->worker->dispatchJob('lead', 0);
		}

		return ['processed' => $processed, 'failed' => $failed, 'remaining' => $remaining];
	}

	/**
	 * Uruchomienie przeliczeń w tle (maks. raz na żądanie HTTP).
	 */
	public function kick(): bool
	{
		if (self::$kicked) {
			return false;
		}

		self::$kicked = true;

		try {
			$this->ageEvaluations();

			if ($this->repo->countPending(LeadIntelligenceService::VERSION) === 0) {
				return false;
			}
		} catch (Throwable) {
			return false;
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 30, self::CRON_HOOK);
		}

		return $this->worker->dispatchJob('lead', 0);
	}

	/**
	 * Raz na dobę: oceny nieprzeliczane od 7 dni oznaczamy do przeliczenia. Świeżość analizy (> 180 dni), kara
	 * w rankingu i coverage zależą od czasu, a nie od zdarzeń — bez tego ocena „starzała się” tylko przy innej zmianie.
	 */
	private function ageEvaluations(): void
	{
		$last = (int) get_option('ubr_lead_aging_at', 0);

		if ($last > time() - DAY_IN_SECONDS) {
			return;
		}

		update_option('ubr_lead_aging_at', time(), false);
		$this->repo->markAged(7);
	}

	public static function resetKick(): void
	{
		self::$kicked = false;
	}
}
