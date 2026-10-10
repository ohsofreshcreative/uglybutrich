<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\IndustryCatalog;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Discovery\ProviderRegistry;
use App\Ubr\Infrastructure\Queue\JobDispatcher;
use App\Ubr\Infrastructure\Repositories\EventRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use App\Ubr\Support\UbrException;

/**
 * Przypadki użycia Discovery dla organizacji: uruchomienie, status, wyniki, anulowanie.
 */
class DiscoveryService
{
	public function __construct(
		private SearchRepository $searches,
		private ResultRepository $results,
		private EventRepository $events,
		private ProviderRegistry $providers,
		private JobDispatcher $dispatcher,
		private UsageLimits $limits,
		private QueueMonitor $monitor,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function options(?TenantContext $ctx = null): array
	{
		$sizes = [];

		foreach (DiscoveryCriteria::SIZES as $key => [, , $label]) {
			$sizes[] = ['key' => $key, 'label' => $label];
		}

		return [
			'industries' => IndustryCatalog::options(),
			'regions' => RegionCatalog::options(),
			'sizes' => $sizes,
			'providers' => $this->providers->describe(),
			'queue_driver' => $this->dispatcher->driver(),
			'limits' => DiscoveryCriteria::LIMITS,
			'apollo' => [
				'configured' => \App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider::apiKey() !== null,
				'per_page' => \App\Ubr\Services\ApolloBudget::MAX_PER_PAGE,
				'max_pages' => \App\Ubr\Services\ApolloBudget::MAX_PAGES_PER_SEARCH,
				'budget' => $ctx ? app(ApolloBudget::class)->remaining($ctx->organizationId) : null,
				'note' => 'Zużycie kredytów zależy od planu Apollo i rodzaju wykonywanych operacji. Wg dokumentacji Apollo wyszukiwanie firm zużywa 1 kredyt za stronę wyników (do 100 firm na stronę).',
			],
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function start(TenantContext $ctx, array $input): array
	{
		$this->assert($ctx, 'discovery.run');
		$criteria = DiscoveryCriteria::fromArray($input, $this->providers->availableKeys());
		$this->limits->assertCanStartSearch($ctx);

		$id = $this->searches->create([
			'organization_id' => $ctx->organizationId,
			'user_id' => $ctx->userId,
			'label' => $criteria->label(),
			'criteria' => $criteria->toArray(),
			'providers' => $criteria->providers,
			'state' => [],
		]);

		$this->limits->recordSearch($ctx, $id);
		$this->events->log($id, 'info', 'Zadanie dodane do kolejki (' . $this->dispatcher->driver() . ').', ['terms' => $criteria->terms()]);
		$this->dispatcher->dispatch($id);

		return $this->present($this->searches->find($id));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(TenantContext $ctx, int $id, bool $kick = true): array
	{
		$this->assert($ctx, 'discovery.view');
		$search = $this->searches->findForOrganization($id, $ctx->organizationId) ?? throw UbrException::notFound('Nie znaleziono wyszukiwania.');

		if ($kick) {
			$search = $this->kick($search);
		}

		$data = $this->present($search);
		$data['events'] = $this->events->forSearch($id, 30);
		$data['diagnostics'] = $this->diagnostics($search);

		return $data;
	}

	public function cancel(TenantContext $ctx, int $id): array
	{
		$this->assert($ctx, 'discovery.cancel');
		$search = $this->searches->findForOrganization($id, $ctx->organizationId) ?? throw UbrException::notFound('Nie znaleziono wyszukiwania.');

		if (! in_array($search['status'], SearchRepository::ACTIVE_STATUSES, true)) {
			throw UbrException::invalid('To wyszukiwanie już się zakończyło.');
		}

		if ($search['status'] === 'queued' && $this->searches->acquireLock($id, 30)) {
			$this->searches->update($id, ['status' => 'cancelled', 'cancel_requested' => 1, 'finished_at' => Db::now(), 'next_run_at' => null, 'progress' => 100]);
			$this->searches->releaseLock($id);
		} else {
			$this->searches->update($id, ['cancel_requested' => 1]);
			$this->dispatcher->spawn();
		}

		$this->events->log($id, 'info', 'Zażądano anulowania.');

		return $this->present($this->searches->find($id));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function results(TenantContext $ctx, int $id, int $page, int $perPage, string $filter, string $sort = 'relevance', array $extra = []): array
	{
		$this->assert($ctx, 'discovery.view');
		$this->searches->findForOrganization($id, $ctx->organizationId) ?? throw UbrException::notFound('Nie znaleziono wyszukiwania.');

		$data = $this->results->paginate($id, $ctx->organizationId, max(1, $page), max(1, min(100, $perPage)), $filter, $sort, $extra);

		// Firmy bez aktualnej oceny Lead Intelligence → przeliczenie w tle (bez liczenia w tym żądaniu).
		if (array_filter($data['items'], fn ($r) => ($r['li_status'] ?? null) === null || ! empty($r['li_stale']))) {
			app(LeadIntelligenceRunner::class)->kick();
		}

		return [
			'items' => app(ContactResearchService::class)->applyOrganizationView($ctx, array_map(fn ($r) => CompanyPresenter::row($r), $data['items'])),
			'total' => $data['total'],
			'page' => $page,
			'per_page' => $perPage,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent(TenantContext $ctx, int $limit = 20): array
	{
		$this->assert($ctx, 'discovery.view');

		return array_map(fn ($s) => $this->present($s), $this->searches->listForOrganization($ctx->organizationId, $limit));
	}

	/**
	 * Diagnostyka wyszukiwania: co zwróciło źródło, co odrzucono i dlaczego, kompletność danych, postęp weryfikacji.
	 *
	 * @param array<string, mixed> $search
	 * @return array<string, mixed>
	 */
	public function diagnostics(array $search): array
	{
		$state = (array) ($search['state'] ?? []);
		$diag = (array) ($state['diagnostics'] ?? []);
		$results = $this->results->diagnostics((int) $search['id']);
		$queue = app(\App\Ubr\Infrastructure\Repositories\VerificationQueueRepository::class)->statsForSearch((int) $search['id']);

		return [
			'returned' => (int) ($diag['returned'] ?? 0),
			'saved' => (int) $search['results_count'],
			'rejected' => array_map('intval', (array) ($diag['rejected'] ?? [])),
			'rejected_label' => DiscoveryRunner::reasonsLabel((array) ($diag['rejected'] ?? [])),
			'provider_totals' => $diag['provider_totals'] ?? (object) [],
			'filters' => $diag['filters'] ?? (object) [],
			'explanation' => array_values((array) ($state['explanation'] ?? [])),
			'results' => $results,
			'verification_queue' => $queue,
		];
	}

	/**
	 * Samonaprawa kolejki przy odpytywaniu statusu: zaległe zadanie → ponowne zlecenie workera,
	 * zadanie bez postępu → błąd (z możliwością ponowienia). Nie wykonuje kroków w żądaniu przeglądarki.
	 *
	 * @param array<string, mixed> $search
	 * @return array<string, mixed>
	 */
	private function kick(array $search): array
	{
		if (! in_array($search['status'], SearchRepository::ACTIVE_STATUSES, true)) {
			return $search;
		}

		$result = $this->monitor->recover((int) $search['id']);

		return ($result['resumed'] || $result['failed']) ? ($this->searches->find((int) $search['id']) ?? $search) : $search;
	}

	/**
	 * Ponowienie wyszukiwania: nowe zadanie z tymi samymi kryteriami (historia poprzedniego zostaje).
	 *
	 * @return array<string, mixed>
	 */
	public function retry(TenantContext $ctx, int $id): array
	{
		$this->assert($ctx, 'discovery.run');
		$search = $this->searches->findForOrganization($id, $ctx->organizationId) ?? throw UbrException::notFound('Nie znaleziono wyszukiwania.');

		if (in_array($search['status'], SearchRepository::ACTIVE_STATUSES, true)) {
			throw UbrException::invalid('To wyszukiwanie jeszcze trwa.');
		}

		$new = $this->start($ctx, (array) $search['criteria']);
		$this->events->log((int) $new['id'], 'info', sprintf('Ponowienie wyszukiwania #%d.', $id));

		return $new;
	}

	/**
	 * @param array<string, mixed>|null $s
	 * @return array<string, mixed>
	 */
	public function present(?array $s): array
	{
		if (! $s) {
			return [];
		}

		$state = (array) ($s['state'] ?? []);

		return [
			'id' => (int) $s['id'],
			'label' => $s['label'],
			'criteria' => $s['criteria'],
			'providers' => $s['providers'],
			'status' => $s['status'],
			'progress' => (int) $s['progress'],
			'phase' => $state['phase'] ?? null,
			'results_count' => (int) $s['results_count'],
			'with_domain_count' => (int) $s['with_domain_count'],
			'verification_queued' => (int) ($state['verification_queued'] ?? 0),
			'explanation' => array_values((array) ($state['explanation'] ?? [])),
			'rejected_label' => DiscoveryRunner::reasonsLabel((array) ($state['diagnostics']['rejected'] ?? [])),
			'returned' => (int) ($state['diagnostics']['returned'] ?? 0),
			'provider_errors' => $state['errors'] ?? [],
			'current_provider' => $state['current_provider'] ?? null,
			'counts' => $state['counts'] ?? (object) [],
			'new_count' => array_sum(array_map(fn ($c) => (int) ($c['new'] ?? 0), (array) ($state['counts'] ?? []))),
			'duplicate_count' => array_sum(array_map(fn ($c) => (int) ($c['duplicates'] ?? 0), (array) ($state['counts'] ?? []))),
			'error' => $s['error'] ?? null,
			'next_run_at' => $this->iso($s['next_run_at'] ?? null),
			'queue_warning' => in_array($s['status'], SearchRepository::ACTIVE_STATUSES, true) && app(\App\Ubr\Infrastructure\Queue\AsyncWorker::class)->looksBroken()
				? 'Worker w tle nie odpowiada (żądanie loopback do strony nie dochodzi). Uruchom `wp ubr queue-work` lub sprawdź `wp ubr doctor`.'
				: null,
			'user_name' => $s['user_name'] ?? null,
			'created_at' => $this->iso($s['created_at'] ?? null),
			'finished_at' => $this->iso($s['finished_at'] ?? null),
		];
	}

	private function iso(?string $utc): ?string
	{
		return $utc ? gmdate('c', strtotime($utc . ' UTC')) : null;
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}
}
