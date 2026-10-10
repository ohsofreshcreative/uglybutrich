<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\EventRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use App\Ubr\Support\UbrException;

/**
 * Limity kosztownych operacji (ochrona przed nadużyciem) — przygotowane pod przyszłe plany abonamentowe.
 * Wartości zależą od `organization.plan` i można je zmienić filtrem `ubr/limits`.
 */
class UsageLimits
{
	public const METRIC_DISCOVERY = 'discovery.search';

	public function __construct(
		private SearchRepository $searches,
		private EventRepository $events,
	) {
	}

	/**
	 * @return array{max_concurrent_searches: int, max_searches_per_day: int, min_seconds_between_searches: int}
	 */
	public function limitsFor(TenantContext $ctx): array
	{
		$defaults = [
			'max_concurrent_searches' => 2,
			'max_searches_per_day' => 40,
			'min_seconds_between_searches' => 10,
		];

		return apply_filters('ubr/limits', $defaults, (string) ($ctx->organization['plan'] ?? 'internal'), $ctx->organizationId);
	}

	public function assertCanStartSearch(TenantContext $ctx): void
	{
		$limits = $this->limitsFor($ctx);

		if ($this->searches->countActive($ctx->organizationId) >= $limits['max_concurrent_searches']) {
			throw UbrException::limit('Twoja organizacja ma już uruchomione wyszukiwania. Poczekaj na ich zakończenie lub je anuluj.');
		}

		$dayAgo = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

		if ($this->events->usageSince($ctx->organizationId, self::METRIC_DISCOVERY, $dayAgo) >= $limits['max_searches_per_day']) {
			throw UbrException::limit('Osiągnięto dzienny limit wyszukiwań dla organizacji.');
		}

		$key = 'ubr_last_search_' . $ctx->userId;
		$cooldown = (int) $limits['min_seconds_between_searches'];

		if ($cooldown > 0 && get_transient($key)) {
			throw UbrException::limit('Odczekaj chwilę przed uruchomieniem kolejnego wyszukiwania.');
		}

		if ($cooldown > 0) {
			set_transient($key, 1, $cooldown);
		}
	}

	public function recordSearch(TenantContext $ctx, int $searchId): void
	{
		$this->events->recordUsage($ctx->organizationId, $ctx->userId, self::METRIC_DISCOVERY, 1, $searchId);
	}
}
