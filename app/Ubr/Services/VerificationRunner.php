<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Http\ParallelFetcher;
use App\Ubr\Infrastructure\Http\WebsiteInspector;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\VerificationQueueRepository;
use Closure;
use Throwable;

/**
 * Przetwarzanie kolejki weryfikacji w tle (osobno od Discovery — wyszukiwanie nie czeka na rejestry).
 *
 * Jeden worker naraz (blokada w opcji) — rejestry mają limity częstotliwości, a LocalWP ma mało procesów PHP.
 * Każdy krok ma budżet czasu; po kroku worker zleca kolejny (loopback), a healthcheck WP-Cron co minutę
 * wznawia kolejkę, jeśli łańcuch się przerwał. Rejestr niedostępny → ponowienie z backoffem (2 min, 10 min, 1 h, 6 h).
 *
 * Prompt 10.4: worker pobiera PACZKĘ firm (UBR_VERIFY_BATCH, domyślnie 6) i ich strony WWW pobierają się równolegle
 * w tle (ParallelFetcher, curl_multi w tym samym procesie) — także w czasie przerw wymuszanych limitami rejestrów.
 * Firmy są weryfikowane po kolei (rejestry), ale wolna lub niedostępna strona jednej firmy nie blokuje pozostałych.
 * Pobierane są dokładnie te strony, które pobrałaby inspekcja (bez dodatkowych zapytań) — odpowiedzi trafiają do
 * wspólnej pamięci podręcznej i są potem używane także przez Analyzer, telefony i Decision Maker Findera.
 */
class VerificationRunner
{
	public const LOCK_OPTION = 'ubr_verify_lock';

	public const CRON_HOOK = 'ubr_verification_tick';

	/**
	 * Prompt 10.4.3 (diagnostyka 10.4.1, Hostinger/LiteSpeed): krok workera WWW był przerywany po ok. 10 s, a blokada
	 * (170 s) wstrzymywała kolejkę na ~3 min. Teraz: krótka blokada odnawiana po każdej firmie i krótkie pobranie firm.
	 */
	public const LOCK_TTL = 60;

	public const CLAIM_TTL = 90;

	public function __construct(
		private VerificationQueueRepository $queue,
		private CompanyVerifier $verifier,
		private AsyncWorker $worker,
	) {
	}

	/**
	 * Budżet kroku workera WWW (sekundy) — krótszy niż czas, po którym hosting przerywa proces loopbacku.
	 * Kolejne kroki są łańcuchowane, więc przepustowość nie spada. UBR_VERIFY_STEP_SECONDS, domyślnie 8.
	 */
	public static function stepSeconds(): int
	{
		return max(3, min(60, (int) apply_filters('ubr/verification/step_seconds', defined('UBR_VERIFY_STEP_SECONDS') ? (int) constant('UBR_VERIFY_STEP_SECONDS') : 8)));
	}

	public static function batchSize(): int
	{
		return max(1, min(12, (int) apply_filters('ubr/verification/batch', defined('UBR_VERIFY_BATCH') ? (int) constant('UBR_VERIFY_BATCH') : 6)));
	}

	/**
	 * @return array{processed: int, verified: int, retried: int, failed: int, remaining_due: int}
	 */
	public function tick(?int $budgetSeconds = null, bool $chain = true): array
	{
		$budgetSeconds ??= self::stepSeconds();
		$stats = ['processed' => 0, 'verified' => 0, 'retried' => 0, 'failed' => 0, 'remaining_due' => 0];

		if (! $this->acquireLock(self::LOCK_TTL)) {
			return $stats;
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit($budgetSeconds + 180);
		}

		$started = microtime(true);

		try {
			foreach ($this->queue->failExhausted(VerificationQueueRepository::MAX_ATTEMPTS + 1) as $dead) {
				$this->queue->retryLater((int) $dead['id'], VerificationQueueRepository::MAX_ATTEMPTS, 'Weryfikacja przerwana — proces workera został zakończony po kilku próbach.');
			}

			// Okno przesuwne: do batchSize() firm „w toku” — ich strony pobierają się równolegle w tle; weryfikowana jest
			// najpierw firma, której strony są już pobrane (wolna / niedostępna strona nie blokuje kolejnych firm).
			$fetcher = $this->parallel();
			$idle = $fetcher ? static function (float $seconds) use ($fetcher): void {
				$fetcher->pump($seconds);
			} : null;

			if ($idle) {
				add_action('ubr/http/idle', $idle);
			}

			$pool = [];

			try {
				while (true) {
					while (count($pool) < self::batchSize() && microtime(true) - $started < $budgetSeconds) {
						$item = $this->queue->claim(self::CLAIM_TTL);

						if (! $item) {
							break;
						}

						$item['_since'] = microtime(true);
						$pool[] = $item;

						if ($fetcher) {
							$this->prefetch($fetcher, $item);
						}
					}

					if (! $pool) {
						break;
					}

					$ready = null;

					foreach ($pool as $i => $item) {
						if (! $fetcher || ! $fetcher->busy('c' . $item['company_id']) || microtime(true) - $item['_since'] > 25) {
							$ready = $i;
							break;
						}
					}

					if ($ready === null) {
						// Koniec budżetu, a strony pozostałych firm jeszcze się pobierają → zwrot do kolejki (następny krok).
						if (microtime(true) - $started >= $budgetSeconds) {
							$this->releasePool($pool);
							$pool = [];
							break;
						}

						$fetcher->pump(0.05);
						continue;
					}

					$item = $pool[$ready];
					unset($pool[$ready]);
					$pool = array_values($pool);
					$this->process($item, $stats);
					$this->renewLock();

					// Budżet kroku wyczerpany — nieprzetworzone firmy wracają do kolejki, kolejny krok zleca łańcuch.
					if ($pool && microtime(true) - $started >= $budgetSeconds) {
						$this->releasePool($pool);
						$pool = [];
						break;
					}
				}
			} finally {
				if ($idle) {
					remove_action('ubr/http/idle', $idle);
				}

				$fetcher?->abort();
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
	 * @param array<string, mixed> $item
	 * @param array<string, int> $stats
	 */
	private function process(array $item, array &$stats): void
	{
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

				// Prompt 10.4.3 — Discover: Website Opportunity Score w tle po weryfikacji (osobna kolejka, niższy priorytet).
				if ((int) ($item['search_id'] ?? 0) > 0 && (int) ($item['requested_by_org'] ?? 0) > 0) {
					try {
						app(AnalysisService::class)->queueAuto((int) $item['company_id'], (int) $item['requested_by_org']);
					} catch (Throwable $e) {
						error_log('[ubr] Auto analysis for company #' . $item['company_id'] . ' not queued: ' . $e->getMessage());
					}
				}
			}
		} catch (Throwable $e) {
			error_log('[ubr] Verification of company #' . $item['company_id'] . ' failed: ' . $e->getMessage());
			$this->queue->retryLater((int) $item['id'], (int) $item['attempts'], $e->getMessage());
			$stats['failed']++;
		}
	}

	private function parallel(): ?ParallelFetcher
	{
		if (! ParallelFetcher::available() && ! has_filter('ubr/http/parallel_sim')) {
			return null;
		}

		return app(ParallelFetcher::class);
	}

	/**
	 * Równoległe pobranie stron firmy w tle: strona główna (https, przy błędzie połączenia http — jak inspekcja),
	 * przekierowania i kolejne podstrony dokładnie według reguł WebsiteInspector::inspect(). Wyniki → PageCache,
	 * z którego inspekcja korzysta bez ponownych zapytań.
	 *
	 * @param array<string, mixed> $item
	 */
	private function prefetch(ParallelFetcher $fetcher, array $item): void
	{
		$company = app(CompanyRepository::class)->find((int) $item['company_id']);
		$url = (string) ($company['website_url'] ?? '');

		if (! $company || empty($company['domain']) || $url === '') {
			return;
		}

		$inspector = app(WebsiteInspector::class);
		$group = 'c' . $company['id'];
		$subs = [];
		$home = null;
		$onSub = null;
		$onSub = static function (array $response, string $final) use (&$onSub, &$subs, &$home, $fetcher, $inspector, $group): void {
			$ok = ($response['code'] ?? null) !== null && $response['code'] >= 200 && $response['code'] < 400;
			$subs[] = $ok ? (string) $response['body'] : '';
			$next = $home ? $inspector->nextSubpage($home['body'], $home['final'], $subs) : null;

			if ($next !== null && count($subs) < 3) {
				$fetcher->add($next, $group, 7, WebsiteInspector::MAX_BYTES, Closure::fromCallable($onSub));
			}
		};
		$onReachable = static function (array $response, string $final) use (&$home, &$onSub, $fetcher, $inspector, $group): void {
			if (($response['code'] ?? 0) >= 200 && $response['code'] < 400) {
				$home = ['body' => (string) $response['body'], 'final' => $final];
				$next = $inspector->nextSubpage($home['body'], $final, []);

				if ($next !== null) {
					$fetcher->add($next, $group, 7, WebsiteInspector::MAX_BYTES, Closure::fromCallable($onSub));
				}
			}
		};
		$onHome = static function (array $response, string $final) use ($onReachable, $fetcher, $group, $url): void {
			if (($response['code'] ?? null) === null) {
				// Błąd połączenia przez https → inspekcja próbuje http (starsze strony) — pobieramy to samo z wyprzedzeniem.
				if (str_starts_with($url, 'https://') && $final === $url) {
					$fetcher->add('http://' . substr($url, 8), $group, 7, WebsiteInspector::MAX_BYTES, Closure::fromCallable($onReachable));
				}

				return;
			}

			$onReachable($response, $final);
		};

		$fetcher->add($url, $group, 7, WebsiteInspector::MAX_BYTES, Closure::fromCallable($onHome));
	}

	/**
	 * Wznowienie kolejki (healthcheck): jeśli są zaległe pozycje i nikt ich nie przetwarza.
	 */
	public function kick(): bool
	{
		if ($this->queue->countDue() === 0 || $this->isLocked()) {
			return false;
		}

		// Zapasowe zdarzenie WP-Cron (jak w pozostałych kolejkach) — gdy loopback jest zablokowany (częste na
		// hostingu współdzielonym), kolejkę przetworzy systemowy cron (`wp cron event run --due-now`).
		$this->scheduleBackup(60);

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

	/** @param list<array<string, mixed>> $pool */
	private function releasePool(array $pool): void
	{
		foreach ($pool as $item) {
			$this->queue->release((int) $item['id']);
		}
	}

	private function renewLock(): void
	{
		update_option(self::LOCK_OPTION, time() + self::LOCK_TTL, false);
	}

	private function releaseLock(): void
	{
		delete_option(self::LOCK_OPTION);
	}
}
