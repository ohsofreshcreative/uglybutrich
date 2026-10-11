<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\ProviderException;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Discovery\ProviderRegistry;
use App\Ubr\Infrastructure\Queue\JobDispatcher;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\EventRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use Throwable;

/**
 * Wykonuje zadanie Discovery krokami (tick) w tle.
 *
 * Etapy: fetch (kolejni dostawcy, ocena trafności każdego wyniku) → completed.
 * Weryfikacja firm (rejestry, domeny) NIE blokuje wyszukiwania: po pobraniu wyników firmy trafiają do osobnej
 * kolejki weryfikacji (VerificationRunner), a wyszukiwanie od razu się kończy. Awaria rejestru nie psuje Discovery.
 * Każdy tick ma budżet czasu i zapisuje stan, więc proces jest wznawialny, anulowalny
 * i odporny na przerwania. Błędy dostawców są ponawiane z backoffem (max 3 próby).
 */
class DiscoveryRunner
{
	public const MAX_PROVIDER_ATTEMPTS = 3;

	public function __construct(
		private SearchRepository $searches,
		private ResultRepository $results,
		private CompanyRepository $companies,
		private EventRepository $events,
		private ProviderRegistry $providers,
		private CompanyIngestService $ingest,
		private JobDispatcher $dispatcher,
		private RelevanceService $relevance,
		private VerificationService $verification,
	) {
	}

	/**
	 * @return string status po wykonaniu kroku
	 */
	public function tick(int $searchId, int $budgetSeconds = 25): string
	{
		$search = $this->searches->find($searchId);

		if (! $search || ! in_array($search['status'], SearchRepository::ACTIVE_STATUSES, true)) {
			return $search['status'] ?? 'missing';
		}

		// Ponowienie zaplanowane na później (np. po 429) — nie wykonuj kroku wcześniej, niezależnie od tego, kto go zlecił.
		if ($search['next_run_at'] !== null && strtotime($search['next_run_at'] . ' UTC') > time() + 2) {
			return $search['status'];
		}

		// Blokada = budżet kroku + maks. czas jednego zapytania HTTP (~70 s) + zapas. Po awarii procesu wygasa sama.
		if (! $this->searches->acquireLock($searchId, $budgetSeconds + 120)) {
			return $search['status'];
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit($budgetSeconds + 180);
		}

		$started = microtime(true);
		$rescheduleIn = 0;
		$status = $search['status'];

		try {
			if ($status === 'queued') {
				$status = 'running';
				$this->searches->update($searchId, ['status' => 'running', 'started_at' => Db::now()]);
				$this->events->log($searchId, 'info', 'Zadanie rozpoczęte.');
			}

			$criteria = DiscoveryCriteria::fromStored($search['criteria']);
			$state = $search['state'] + [
				'phase' => 'fetch',
				'pi' => 0,
				'cursor' => [],
				'attempts' => [],
				'errors' => [],
				'diagnostics' => ['returned' => 0, 'rejected' => [], 'provider_totals' => [], 'filters' => []],
			];
			$providerKeys = $search['providers'];

			while (microtime(true) - $started < $budgetSeconds) {
				$fresh = $this->searches->find($searchId);

				if (! empty($fresh['cancel_requested'])) {
					$this->finish($searchId, 'cancelled', null, $state);
					$this->events->log($searchId, 'info', 'Zadanie anulowane przez użytkownika.');

					return 'cancelled';
				}

				if ($state['phase'] === 'fetch' && $state['pi'] < count($providerKeys)) {
					$key = $providerKeys[$state['pi']];
					$rescheduleIn = $this->fetchStep($searchId, $search, $criteria, $key, $state);

					if ($rescheduleIn > 0) {
						break;
					}
				} else {
					// Koniec pobierania (także stan 'verify' z etapu 1.1): weryfikacja → osobna kolejka, wyszukiwanie kończy się od razu.
					return $this->complete($searchId, (int) $search['organization_id'], $criteria, $providerKeys, $state);
				}

				$this->saveProgress($searchId, $state, count($providerKeys));
			}

			$this->saveProgress($searchId, $state, count($providerKeys));
			$this->searches->update($searchId, [
				'ticks' => ($search['ticks'] ?? 0) + 1,
				'next_run_at' => gmdate('Y-m-d H:i:s', time() + $rescheduleIn),
			]);
		} catch (Throwable $e) {
			$attempts = ((int) $search['attempts']) + 1;
			// Treść błędu bazy / PHP (nazwy tabel, „Duplicate entry…”) tylko do logu serwera — użytkownik widzi
			// komunikat ogólny. Komunikaty przeznaczone dla użytkownika (UbrException, ProviderException) zostają.
			$public = $e instanceof \App\Ubr\Support\UbrException || $e instanceof \App\Ubr\Domain\Discovery\ProviderException
				? $e->getMessage()
				: 'błąd wewnętrzny (szczegóły w logu serwera)';
			\App\Ubr\Support\Redactor::log('Discovery #' . $searchId . ': ' . get_class($e) . ': ' . $e->getMessage());
			$this->events->log($searchId, 'error', 'Nieoczekiwany błąd: ' . $public);

			if ($attempts >= self::MAX_PROVIDER_ATTEMPTS) {
				$this->finish($searchId, 'failed', $public, $search['state'] ?: []);

				return 'failed';
			}

			$rescheduleIn = 60 * $attempts;
			$this->searches->update($searchId, [
				'attempts' => $attempts,
				'next_run_at' => gmdate('Y-m-d H:i:s', time() + $rescheduleIn),
			]);
		} finally {
			$this->searches->releaseLock($searchId);
			do_action('ubr/discovery/ticked', $searchId);
		}

		$this->dispatcher->dispatch($searchId, $rescheduleIn, ((int) ($search['ticks'] ?? 0)) + 1);

		return $status;
	}

	/**
	 * @param array<string, mixed> $search
	 * @param array<string, mixed> $state
	 * @return int opóźnienie ponowienia w sekundach (0 = kontynuuj)
	 */
	private function fetchStep(int $searchId, array $search, DiscoveryCriteria $criteria, string $key, array &$state): int
	{
		$provider = $this->providers->get($key);

		if (! $provider || ! $provider->isAvailable()) {
			$state['errors'][$key] = 'Źródło niedostępne lub nieskonfigurowane.';
			$this->events->log($searchId, 'warning', sprintf('Pominięto źródło %s: niedostępne.', $key));
			$this->nextProvider($state);

			return 0;
		}

		try {
			// Numer próby pozwala dostawcy np. przełączyć się na zapasowy serwer.
			$state['current_provider'] = $key;
			$page = $provider->fetch($criteria, [
				'_attempt' => (int) ($state['attempts'][$key] ?? 0),
				'_organization_id' => (int) $search['organization_id'],
				'_search_id' => $searchId,
			] + (array) $state['cursor']);
		} catch (ProviderException $e) {
			$attempt = (int) ($state['attempts'][$key] ?? 0) + 1;
			$state['attempts'][$key] = $attempt;

			if ($e->retryable && $attempt < self::MAX_PROVIDER_ATTEMPTS) {
				$delay = min(120, $e->retryAfter ?: [15, 45, 90][$attempt - 1]);
				$this->events->log($searchId, 'warning', sprintf('%s: %s — ponowienie za %d s (próba %d/%d).', $provider->label(), $e->getMessage(), $delay, $attempt + 1, self::MAX_PROVIDER_ATTEMPTS));
				$this->searches->update($searchId, ['state' => $state]);

				return $delay;
			}

			$state['errors'][$key] = $e->getMessage();
			$this->events->log($searchId, 'error', sprintf('%s: %s — źródło pominięte.', $provider->label(), $e->getMessage()));
			$this->nextProvider($state);

			return 0;
		}

		$saved = 0;
		$new = 0;
		$existing = 0;
		$skippedSize = 0;
		$alreadyInSearch = 0;
		$added = [];
		$position = $this->results->countForSearch($searchId);

		foreach ($page->candidates as $candidate) {
			if ($position >= $criteria->limit) {
				break;
			}

			// Wielkość odrzucamy tylko przy JAWNEJ niezgodności danych firmy (brak danych ≠ niezgodność).
			if (! $criteria->acceptsSize($candidate->employeesMin, $candidate->employeesMax)) {
				$skippedSize++;
				continue;
			}

			try {
				$ingested = $this->ingest->ingest($candidate, $searchId);
			} catch (Throwable $e) {
				$this->events->log($searchId, 'warning', sprintf('Nie zapisano "%s": %s', mb_substr($candidate->name, 0, 80), $e->getMessage()));
				continue;
			}

			if ($this->results->add($searchId, (int) $search['organization_id'], $ingested['company_id'], $key, $position + 1, $candidate->matchedTerms)) {
				$position++;
				$saved++;
				$added[] = (int) $ingested['company_id'];
				$ingested['created'] ? $new++ : $existing++;
				$this->relevance->scoreResult($searchId, $ingested['company_id'], $criteria, $key, $candidate->matchedTerms);
			} else {
				$alreadyInSearch++;
			}
		}

		// Prompt 10.4 — Progressive Discovery: firmy z tej strony wyników od razu trafiają do kolejki weryfikacji (w tle,
		// osobny worker), zamiast czekać na zakończenie całego wyszukiwania. Weryfikacja nigdy nie psuje wyszukiwania.
		if ($added !== []) {
			try {
				$queued = $this->verification->enqueueCompanies($added, (int) $search['organization_id'], $searchId);
				$state['verification_queued'] = (int) ($state['verification_queued'] ?? 0) + $queued;
			} catch (Throwable $e) {
				$this->events->log($searchId, 'warning', 'Nie udało się zlecić weryfikacji: ' . $e->getMessage());
			}
		}

		// Diagnostyka: ile zwróciło źródło, ile i dlaczego odrzucono, jakie filtry wykonało źródło.
		$diag = (array) ($state['diagnostics'] ?? []);
		$diag['returned'] = (int) ($diag['returned'] ?? 0) + (int) ($page->meta['returned'] ?? count($page->candidates));
		$rejected = (array) ($diag['rejected'] ?? []);

		foreach ((array) ($page->meta['rejected'] ?? []) as $reason => $n) {
			$rejected[$reason] = (int) ($rejected[$reason] ?? 0) + (int) $n;
		}

		if ($skippedSize) {
			$rejected['size_mismatch'] = (int) ($rejected['size_mismatch'] ?? 0) + $skippedSize;
		}

		if ($alreadyInSearch) {
			$rejected['duplicate_in_search'] = (int) ($rejected['duplicate_in_search'] ?? 0) + $alreadyInSearch;
		}

		$diag['rejected'] = $rejected;

		if (isset($page->meta['total_entries'])) {
			$diag['provider_totals'][$key] = ['entries' => $page->meta['total_entries'], 'pages' => $page->meta['total_pages'] ?? null];
		}

		if (! empty($page->meta['filters'])) {
			$diag['filters'][$key] = $page->meta['filters'];
		}

		$state['diagnostics'] = $diag;

		// Statystyki per źródło: znalezione, nowe w globalnej bazie, już znane (duplikaty), odfiltrowane.
		$counts = (array) ($state['counts'][$key] ?? []);
		$state['counts'][$key] = [
			'found' => (int) ($counts['found'] ?? 0) + $saved,
			'new' => (int) ($counts['new'] ?? 0) + $new,
			'duplicates' => (int) ($counts['duplicates'] ?? 0) + $existing,
			'filtered' => (int) ($counts['filtered'] ?? 0) + $skippedSize + (int) ($page->meta['filtered_out'] ?? 0),
			'requests' => (int) ($counts['requests'] ?? 0) + (empty($page->meta['cached']) ? 1 : 0),
		];

		if (! empty($page->meta['api_request_id'])) {
			app(\App\Ubr\Infrastructure\Repositories\ApiRequestRepository::class)->update((int) $page->meta['api_request_id'], [
				'results_count' => (int) ($page->meta['returned'] ?? count($page->candidates)),
				'new_count' => $new,
				'duplicate_count' => $existing,
			]);
		}

		$this->events->log($searchId, 'info', sprintf(
			'%s%s: %d kandydatów, zapisano %d (nowe: %d, już w bazie: %d)%s%s.',
			$provider->label(),
			isset($page->meta['page']) ? ' — strona ' . $page->meta['page'] . (! empty($page->meta['total_pages']) ? '/' . $page->meta['total_pages'] : '') . (! empty($page->meta['cached']) ? ' (z cache, bez kosztu)' : '') : '',
			count($page->candidates),
			$saved,
			$new,
			$existing,
			$skippedSize ? sprintf(', poza zakresem wielkości: %d', $skippedSize) : '',
			! empty($page->meta['rejected']) ? ', odrzucone: ' . self::reasonsLabel((array) $page->meta['rejected']) : ''
		));

		if ($page->nextCursor !== null && $position < $criteria->limit) {
			$state['cursor'] = $page->nextCursor;
		} else {
			$this->nextProvider($state);
		}

		$this->searches->update($searchId, [
			'results_count' => $this->results->countForSearch($searchId),
			'with_domain_count' => $this->results->countWithDomainForSearch($searchId),
			'state' => $state,
		]);

		return 0;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function nextProvider(array &$state): void
	{
		$state['pi']++;
		$state['cursor'] = [];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function saveProgress(int $searchId, array $state, int $providerCount): void
	{
		$progress = (int) round(min($state['pi'], $providerCount) / max(1, $providerCount) * 95);
		$this->searches->update($searchId, ['progress' => max(1, $progress), 'state' => $state]);
	}

	/**
	 * Zakończenie: statystyki, wyjaśnienie (szczególnie przy 0 wyników), zlecenie weryfikacji w tle.
	 *
	 * @param list<string> $providerKeys
	 * @param array<string, mixed> $state
	 */
	private function complete(int $searchId, int $organizationId, DiscoveryCriteria $criteria, array $providerKeys, array $state): string
	{
		$count = $this->results->countForSearch($searchId);
		$allFailed = count($state['errors']) >= count($providerKeys);
		$final = ($count === 0 && $allFailed) ? 'failed' : 'completed';
		$error = $state['errors'] ? implode(' | ', array_map(fn ($k, $v) => $k . ': ' . $v, array_keys($state['errors']), $state['errors'])) : null;
		$state['phase'] = 'done';
		$state['explanation'] = $this->explain($criteria, $providerKeys, $state, $count);

		try {
			// Firmy zlecone już po kolejnych stronach wyników są w kolejce lub zweryfikowane — tu tylko uzupełnienie.
			$state['verification_queued'] = (int) ($state['verification_queued'] ?? 0) + ($count > 0 ? $this->verification->enqueueForSearch($searchId, $organizationId) : 0);
		} catch (Throwable $e) {
			// Weryfikacja nigdy nie psuje wyszukiwania.
			$state['verification_queued'] = 0;
			$this->events->log($searchId, 'warning', 'Nie udało się zlecić weryfikacji: ' . $e->getMessage());
		}

		// Prompt 10.4.3 — Lead Intelligence dla nowych wyników liczone w tle (wcześniej przy wyświetlaniu listy).
		try {
			app(LeadIntelligenceRunner::class)->kick();
		} catch (Throwable) {
			// ocena nigdy nie psuje wyszukiwania
		}

		$this->finish($searchId, $final, $error, $state);

		if ($state['verification_queued']) {
			$this->events->log($searchId, 'info', sprintf('Weryfikacja firm (rejestry, domeny) zlecona w tle: %d.', $state['verification_queued']));
		}

		$this->events->log($searchId, $final === 'failed' ? 'error' : 'info', $final === 'failed' ? 'Zadanie zakończone błędem — żadne źródło nie odpowiedziało.' : sprintf('Zadanie zakończone: %d firm.', $count));

		if ($count === 0 && $state['explanation']) {
			$this->events->log($searchId, 'warning', mb_substr(implode(' ', $state['explanation']), 0, 480));
		}

		return $final;
	}

	/**
	 * Czytelne wyjaśnienie wyniku — nigdy „0 wyników” bez powodu.
	 *
	 * @param list<string> $providerKeys
	 * @param array<string, mixed> $state
	 * @return list<string>
	 */
	public function explain(DiscoveryCriteria $criteria, array $providerKeys, array $state, int $count): array
	{
		$diag = (array) ($state['diagnostics'] ?? []);
		$lines = [];

		foreach ($providerKeys as $key) {
			if (! empty($state['errors'][$key])) {
				$lines[] = sprintf('%s: błąd — %s', $this->providers->get($key)?->label() ?? $key, $state['errors'][$key]);
			}
		}

		$returned = (int) ($diag['returned'] ?? 0);
		$rejected = array_filter((array) ($diag['rejected'] ?? []));

		if ($count > 0) {
			if ($rejected) {
				$lines[] = 'Odrzucone: ' . self::reasonsLabel($rejected) . '.';
			}

			return $lines;
		}

		if ($returned === 0 && ! array_filter($state['errors'] ?? [])) {
			$filters = [];

			foreach ((array) ($diag['filters'] ?? []) as $key => $f) {
				$filters[] = sprintf('%s: lokalizacja %s; tagi: %s%s', $this->providers->get($key)?->label() ?? $key, implode(' / ', (array) ($f['locations'] ?? [])) ?: 'Polska', implode(', ', array_slice((array) ($f['keyword_tags'] ?? []), 0, 6)) ?: '—', ! empty($f['employees']) ? '; zatrudnienie ' . implode(', ', (array) $f['employees']) : '');
			}

			$lines[] = 'Źródła nie zwróciły żadnych firm dla tych kryteriów' . ($filters ? ' (' . implode(' | ', $filters) . ')' : '') . '.';
			$lines[] = 'Spróbuj: szerszej lokalizacji (województwo zamiast miasta), branży z listy zamiast własnej frazy (tagi Apollo są angielskie) albo bez filtra wielkości.';
		} elseif ($returned > 0) {
			$lines[] = sprintf('Źródła zwróciły %d rekordów, ale wszystkie odrzucono: %s.', $returned, self::reasonsLabel($rejected) ?: 'brak szczegółów');

			if (isset($rejected['location_unknown'])) {
				$lines[] = 'Część firm nie ma w źródle danych o siedzibie — nie zgadujemy lokalizacji. Użyj Apollo (filtruje po siedzibie) albo wyszukaj bez województwa.';
			}
		}

		return $lines;
	}

	/**
	 * @param array<string, int> $reasons
	 */
	public static function reasonsLabel(array $reasons): string
	{
		$labels = [
			'country_mismatch' => 'siedziba poza Polską',
			'location_mismatch' => 'inna lokalizacja (wg danych źródła)',
			'location_unknown' => 'brak danych o lokalizacji',
			'size_mismatch' => 'zatrudnienie poza przedziałem (wg danych źródła)',
			'not_a_company' => 'rekord nie jest firmą',
			'invalid_record' => 'niekompletny rekord',
			'duplicate_in_page' => 'duplikat w odpowiedzi źródła',
			'duplicate_in_search' => 'już w wynikach (inne źródło)',
		];
		$parts = [];

		foreach ($reasons as $reason => $n) {
			if ((int) $n > 0) {
				$parts[] = ($labels[$reason] ?? $reason) . ': ' . (int) $n;
			}
		}

		return implode(', ', $parts);
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function finish(int $searchId, string $status, ?string $error, array $state): void
	{
		$this->searches->update($searchId, [
			'status' => $status,
			'progress' => 100,
			'error' => $error ? mb_substr($error, 0, 2000) : null,
			'state' => $state,
			'results_count' => $this->results->countForSearch($searchId),
			'with_domain_count' => $this->results->countWithDomainForSearch($searchId),
			'finished_at' => Db::now(),
			'next_run_at' => null,
		]);
	}
}
