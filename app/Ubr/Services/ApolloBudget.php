<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Discovery\ProviderException;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;

/**
 * Kontrola kosztów Apollo (klucz operatora SaaS → koszty ponosi operator).
 *
 * Wg dokumentacji Apollo wyszukiwanie organizacji (`mixed_companies/search`) zużywa 1 kredyt za stronę wyników.
 * Limitujemy więc liczbę płatnych stron: na wyszukiwanie, na organizację (dzień/miesiąc) i globalnie (dzień).
 * Wartości domyślne można zmienić filtrem `ubr/apollo/limits` (np. per plan organizacji) lub stałymi w wp-config.php.
 */
class ApolloBudget
{
	public const PROVIDER = 'apollo';

	/** Maks. 100 firm na stronę (limit API) — mniej stron = mniej kredytów. */
	public const MAX_PER_PAGE = 100;

	/** Twardy limit stron na jedno wyszukiwanie (500 firm). */
	public const MAX_PAGES_PER_SEARCH = 5;

	public function __construct(
		private ApiRequestRepository $requests,
		private OrganizationRepository $organizations,
	) {
	}

	/**
	 * @return array{max_pages_per_search: int, org_pages_per_day: int, org_pages_per_month: int, global_pages_per_day: int}
	 */
	public function limits(?int $organizationId): array
	{
		$plan = $organizationId ? (string) ($this->organizations->find($organizationId)['plan'] ?? 'internal') : 'internal';
		$defaults = [
			'max_pages_per_search' => self::MAX_PAGES_PER_SEARCH,
			'org_pages_per_day' => defined('UBR_APOLLO_ORG_PAGES_PER_DAY') ? (int) UBR_APOLLO_ORG_PAGES_PER_DAY : 30,
			'org_pages_per_month' => defined('UBR_APOLLO_ORG_PAGES_PER_MONTH') ? (int) UBR_APOLLO_ORG_PAGES_PER_MONTH : 300,
			'global_pages_per_day' => defined('UBR_APOLLO_GLOBAL_PAGES_PER_DAY') ? (int) UBR_APOLLO_GLOBAL_PAGES_PER_DAY : 100,
		];

		return apply_filters('ubr/apollo/limits', $defaults, $plan, $organizationId);
	}

	/**
	 * Strony potrzebne dla danego limitu firm (bez filtrów stosowanych po pobraniu).
	 *
	 * @return array{per_page: int, pages: int}
	 */
	public function plan(int $limit): array
	{
		$perPage = max(1, min(self::MAX_PER_PAGE, $limit));

		return ['per_page' => $perPage, 'pages' => (int) min(self::MAX_PAGES_PER_SEARCH, ceil($limit / $perPage))];
	}

	/**
	 * Sprawdzenie PRZED wykonaniem płatnego zapytania. Rzuca wyjątek (bez ponawiania), gdy limit wyczerpany.
	 */
	public function assertCanRequest(?int $organizationId, ?int $searchId): void
	{
		$limits = $this->limits($organizationId);
		$today = gmdate('Y-m-d 00:00:00');

		if ($searchId && $this->requests->countBillable(self::PROVIDER, '1970-01-01 00:00:00', null, $searchId) >= $limits['max_pages_per_search']) {
			throw new ProviderException(sprintf('Osiągnięto limit %d stron Apollo na jedno wyszukiwanie.', $limits['max_pages_per_search']), false);
		}

		if ($this->requests->countBillable(self::PROVIDER, $today) >= $limits['global_pages_per_day']) {
			throw new ProviderException('Osiągnięto dzienny limit zapytań Apollo dla całej instalacji (ochrona kosztów operatora).', false);
		}

		if ($organizationId) {
			if ($this->requests->countBillable(self::PROVIDER, $today, $organizationId) >= $limits['org_pages_per_day']) {
				throw new ProviderException('Organizacja wykorzystała dzienny limit zapytań Apollo.', false);
			}

			if ($this->requests->countBillable(self::PROVIDER, gmdate('Y-m-01 00:00:00'), $organizationId) >= $limits['org_pages_per_month']) {
				throw new ProviderException('Organizacja wykorzystała miesięczny limit zapytań Apollo.', false);
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function remaining(int $organizationId): array
	{
		$limits = $this->limits($organizationId);
		$usedDay = $this->requests->countBillable(self::PROVIDER, gmdate('Y-m-d 00:00:00'), $organizationId);
		$usedMonth = $this->requests->countBillable(self::PROVIDER, gmdate('Y-m-01 00:00:00'), $organizationId);

		return $limits + [
			'org_pages_used_today' => $usedDay,
			'org_pages_used_month' => $usedMonth,
			'org_pages_left_today' => max(0, min($limits['org_pages_per_day'] - $usedDay, $limits['org_pages_per_month'] - $usedMonth)),
		];
	}
}
