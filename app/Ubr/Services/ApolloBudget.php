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
 * Kolejność (od najważniejszej): filtr `ubr/apollo/limits` → ustawienia organizacji (`ubr_organizations.settings.apollo`,
 * zmienia tylko operator SaaS w Settings → Apollo) → stałe w wp-config.php → wartości domyślne.
 * Wykorzystanie liczone zawsze z `ubr_api_requests` — zmiana limitów nie zeruje statystyk.
 */
class ApolloBudget
{
	public const PROVIDER = 'apollo';

	/** Maks. 100 firm na stronę (limit API) — mniej stron = mniej kredytów. */
	public const MAX_PER_PAGE = 100;

	/** Twardy limit stron na jedno wyszukiwanie (500 firm). */
	public const MAX_PAGES_PER_SEARCH = 5;

	public const DEFAULT_ORG_PAGES_PER_DAY = 100;

	public const DEFAULT_ORG_PAGES_PER_MONTH = 2000;

	/** Górne granice wartości wpisywanych w ustawieniach (ochrona przed literówką). */
	public const MAX_SETTING_PER_DAY = 1000;

	public const MAX_SETTING_PER_MONTH = 20000;

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
		$org = $organizationId ? $this->organizations->find($organizationId) : null;
		$plan = (string) ($org['plan'] ?? 'internal');
		$stored = (array) ($org['settings']['apollo'] ?? []);
		$defaults = [
			'max_pages_per_search' => self::MAX_PAGES_PER_SEARCH,
			'org_pages_per_day' => isset($stored['org_pages_per_day']) ? (int) $stored['org_pages_per_day'] : (defined('UBR_APOLLO_ORG_PAGES_PER_DAY') ? (int) UBR_APOLLO_ORG_PAGES_PER_DAY : self::DEFAULT_ORG_PAGES_PER_DAY),
			'org_pages_per_month' => isset($stored['org_pages_per_month']) ? (int) $stored['org_pages_per_month'] : (defined('UBR_APOLLO_ORG_PAGES_PER_MONTH') ? (int) UBR_APOLLO_ORG_PAGES_PER_MONTH : self::DEFAULT_ORG_PAGES_PER_MONTH),
			'global_pages_per_day' => defined('UBR_APOLLO_GLOBAL_PAGES_PER_DAY') ? (int) UBR_APOLLO_GLOBAL_PAGES_PER_DAY : 100,
		];

		return apply_filters('ubr/apollo/limits', $defaults, $plan, $organizationId);
	}

	/**
	 * Zapis limitów organizacji w jej ustawieniach. Nie dotyka zapisanych zapytań (statystyki zostają).
	 *
	 * @return array<string, mixed>
	 */
	public function saveOrganizationLimits(int $organizationId, int $perDay, int $perMonth): array
	{
		if ($perDay < 0 || $perDay > self::MAX_SETTING_PER_DAY || $perMonth < 0 || $perMonth > self::MAX_SETTING_PER_MONTH) {
			throw \App\Ubr\Support\UbrException::invalid(sprintf('Limit dzienny 0–%d, miesięczny 0–%d stron.', self::MAX_SETTING_PER_DAY, self::MAX_SETTING_PER_MONTH));
		}

		if ($perDay > $perMonth) {
			throw \App\Ubr\Support\UbrException::invalid('Limit dzienny nie może być większy niż miesięczny.');
		}

		$org = $this->organizations->find($organizationId);
		$settings = (array) ($org['settings'] ?? []);
		$settings['apollo'] = ['org_pages_per_day' => $perDay, 'org_pages_per_month' => $perMonth];
		$this->organizations->update($organizationId, ['settings' => $settings]);

		return $this->remaining($organizationId);
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
			'global_pages_used_today' => $this->requests->countBillable(self::PROVIDER, gmdate('Y-m-d 00:00:00')),
			'org_pages_left_today' => max(0, min($limits['org_pages_per_day'] - $usedDay, $limits['org_pages_per_month'] - $usedMonth)),
		];
	}
}
