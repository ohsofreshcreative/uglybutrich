<?php

namespace App\Ubr\Http;

use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use App\Ubr\Services\DiscoveryRunner;

/**
 * Punkt wejścia workera async (admin-ajax.php?action=ubr_worker). Wywoływany wyłącznie loopbackiem
 * z podpisem HMAC — bez sesji użytkownika, bez danych w odpowiedzi.
 */
class WorkerEndpoint
{
	/** Maks. czas jednego oczekiwania na termin ponowienia (sekundy) — oszczędza procesy PHP-FPM. */
	private const MAX_WAIT_PER_HOP = 10;

	public function __construct(
		private AsyncWorker $worker,
		private SearchRepository $searches,
		private DiscoveryRunner $runner,
	) {
	}

	public function handle(): void
	{
		$searchId = (int) ($_POST['search_id'] ?? 0);
		$expires = (int) ($_POST['expires'] ?? 0);
		$token = (string) ($_POST['token'] ?? '');

		if ($searchId <= 0 || ! $this->worker->verify($searchId, $expires, $token)) {
			status_header(403);
			exit;
		}

		ignore_user_abort(true);

		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}

		$this->worker->heartbeat();
		$search = $this->searches->find($searchId);

		if (! $search || ! in_array($search['status'], SearchRepository::ACTIVE_STATUSES, true)) {
			exit;
		}

		$wait = $search['next_run_at'] ? strtotime($search['next_run_at'] . ' UTC') - time() : 0;

		if ($wait > 0) {
			// Jeden "czekający" worker na zadanie — kolejne zlecenia w tym czasie kończą się od razu.
			if (get_transient('ubr_waiter_' . $searchId)) {
				exit;
			}

			set_transient('ubr_waiter_' . $searchId, 1, self::MAX_WAIT_PER_HOP + 5);
			sleep(min($wait, self::MAX_WAIT_PER_HOP));
			delete_transient('ubr_waiter_' . $searchId);

			if ($wait > self::MAX_WAIT_PER_HOP) {
				$this->worker->dispatch($searchId);

				exit;
			}
		}

		try {
			$this->runner->tick($searchId, 20);
		} catch (\Throwable $e) {
			error_log('[ubr] Worker tick failed for search #' . $searchId . ': ' . $e->getMessage());
		}

		exit;
	}

	/**
	 * Zadania niezwiązane z krokiem wyszukiwania (admin-ajax.php?action=ubr_job_worker), podpisane HMAC.
	 */
	public function handleJob(): void
	{
		$job = sanitize_key((string) ($_POST['job'] ?? ''));
		$id = (int) ($_POST['id'] ?? 0);
		$expires = (int) ($_POST['expires'] ?? 0);
		$token = (string) ($_POST['token'] ?? '');

		if ($job === '' || ! $this->worker->verifyJob($job, $id, $expires, $token)) {
			status_header(403);
			exit;
		}

		ignore_user_abort(true);
		$this->worker->heartbeat();

		try {
			if ($job === 'verify') {
				app(\App\Ubr\Services\VerificationRunner::class)->tick();
			} elseif ($job === 'analyze') {
				app(\App\Ubr\Services\WebsiteAnalysisRunner::class)->tick();
			} elseif ($job === 'contacts') {
				app(\App\Ubr\Services\ContactResearchRunner::class)->tick();
			} elseif ($job === 'clickup') {
				app(\App\Ubr\Services\ClickUpRunner::class)->tick();
			} elseif ($job === 'lead') {
				app(\App\Ubr\Services\LeadIntelligenceRunner::class)->tick();
			} elseif ($job === 'phones') {
				app(\App\Ubr\Services\ApolloPhoneRunner::class)->tick();
			} elseif ($job === 'age') {
				app(\App\Ubr\Services\WebsiteAgeRunner::class)->tick();
			} elseif ($job === 'sequences') {
				app(\App\Ubr\Services\Sequences\SequenceRunner::class)->tick();
			}
		} catch (\Throwable $e) {
			error_log('[ubr] Job worker "' . $job . '" failed: ' . $e->getMessage());
		}

		exit;
	}

	/**
	 * Test loopbacku (nieuwierzytelniony, wp ubr doctor) — tylko odpowiedź, BEZ zapisu do bazy: wcześniej każdy
	 * odwiedzający mógł zapisywać opcję i fałszować znacznik życia workera (ukrywając zepsuty loopback).
	 */
	public function ping(): void
	{
		header('Content-Type: text/plain; charset=utf-8');
		echo 'ubr-pong';
		exit;
	}
}
