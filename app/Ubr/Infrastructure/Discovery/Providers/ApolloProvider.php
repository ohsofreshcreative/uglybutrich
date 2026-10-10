<?php

namespace App\Ubr\Infrastructure\Discovery\Providers;

use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\CityCatalog;
use App\Ubr\Domain\Discovery\CompanyCandidate;
use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\DiscoveryProvider;
use App\Ubr\Domain\Discovery\IndustryCodes;
use App\Ubr\Domain\Discovery\ProviderException;
use App\Ubr\Domain\Discovery\ProviderPage;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;
use App\Ubr\Services\ApolloBudget;

/**
 * Apollo.io — Organization Search (`POST /api/v1/mixed_companies/search`). Główne źródło Discovery.
 *
 * Wg dokumentacji Apollo (docs.apollo.io, stan na 10.2026):
 * - zużywa 1 kredyt za STRONĘ wyników (maks. 100 firm na stronę, maks. 500 stron),
 * - wymaga konta Apollo z firmowym adresem e-mail; dostęp do API zależy od planu (403 = plan/zakres klucza),
 * - klucz w nagłówku `x-api-key`, limity w nagłówkach `x-*-requests-left`, 429 + `retry-after` przy przekroczeniu,
 * - filtr lokalizacji dotyczy siedziby (HQ) i przyjmuje miasta / stany USA / kraje.
 *
 * Kontakty osób NIE są pobierane. Enrichment NIE jest wykonywany.
 * Klucz: stała UBR_APOLLO_API_KEY w wp-config.php lub zmienna środowiskowa o tej nazwie — nigdy w repozytorium.
 */
class ApolloProvider implements DiscoveryProvider
{
	public const BASE_URL = 'https://api.apollo.io/api/v1';

	public const SEARCH_ENDPOINT = '/mixed_companies/search';

	/** Cache identycznych zapytań (ta sama strona, te same filtry) — nie płacimy dwa razy za te same dane. */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public function __construct(
		private ProviderHttpClient $http,
		private ApolloBudget $budget,
		private ApiRequestRepository $requests,
	) {
	}

	public function key(): string
	{
		return 'apollo';
	}

	public function label(): string
	{
		return 'Apollo';
	}

	public static function apiKey(): ?string
	{
		$key = defined('UBR_APOLLO_API_KEY') ? (string) UBR_APOLLO_API_KEY : (string) getenv('UBR_APOLLO_API_KEY');
		$key = trim($key);

		return $key !== '' ? $key : null;
	}

	public static function keySource(): ?string
	{
		if (defined('UBR_APOLLO_API_KEY') && trim((string) UBR_APOLLO_API_KEY) !== '') {
			return 'wp-config.php (UBR_APOLLO_API_KEY)';
		}

		return trim((string) getenv('UBR_APOLLO_API_KEY')) !== '' ? 'zmienna środowiskowa UBR_APOLLO_API_KEY' : null;
	}

	public function isAvailable(): bool
	{
		return self::apiKey() !== null;
	}

	public function description(): string
	{
		return $this->isAvailable()
			? 'Główne źródło: baza firm B2B (domena, branża, zatrudnienie). Zużywa kredyty Apollo — 1 kredyt za stronę wyników (do 100 firm).'
			: 'Nieskonfigurowane — wymaga klucza API (stała UBR_APOLLO_API_KEY w wp-config.php).';
	}

	/**
	 * Parametry zapytania (format Apollo: tablice jako `name[]`).
	 *
	 * @return array<string, string|list<string>>
	 */
	public function buildParams(DiscoveryCriteria $criteria, int $page, int $perPage): array
	{
		// Filtry lokalizacji wykonuje Apollo (siedziba firmy). Wyniki wyszukiwania NIE zawierają miasta/województwa,
		// więc nie da się ich odfiltrować po pobraniu (sprawdzone na żywym API, 10.2026). Miasto przekazujemy
		// w wariantach (Kraków / Krakow / Cracow) — lokalizacje w Apollo są łączone przez OR.
		$locations = match (true) {
			(bool) $criteria->city => array_map(fn ($v) => $v . ', Poland', CityCatalog::variants($criteria->city)),
			(bool) $criteria->region => [RegionCatalog::APOLLO_LOCATIONS[$criteria->region] ?? 'poland'],
			default => ['poland'],
		};

		$params = [
			'organization_locations[]' => $locations,
			'page' => (string) $page,
			'per_page' => (string) $perPage,
		];

		$keywords = $criteria->apolloKeywords();

		if ($keywords !== []) {
			$params['q_organization_keyword_tags[]'] = array_slice($keywords, 0, 10);
		}

		[$min, $max] = $criteria->sizeRange();

		if ($min !== null || $max !== null) {
			$params['organization_num_employees_ranges[]'] = [($min ?? 1) . ',' . ($max ?? 1000000)];
		}

		return $params;
	}

	/**
	 * @param array<string, string|list<string>> $params
	 */
	public static function queryString(array $params): string
	{
		$parts = [];

		foreach ($params as $name => $value) {
			foreach ((array) $value as $v) {
				$parts[] = str_replace('%5B%5D', '[]', rawurlencode($name)) . '=' . rawurlencode((string) $v);
			}
		}

		return implode('&', $parts);
	}

	public function fetch(DiscoveryCriteria $criteria, array $cursor): ProviderPage
	{
		$apiKey = self::apiKey() ?? throw new ProviderException('Apollo nie jest skonfigurowane (brak UBR_APOLLO_API_KEY).', false);
		$organizationId = isset($cursor['_organization_id']) ? (int) $cursor['_organization_id'] : null;
		$searchId = isset($cursor['_search_id']) ? (int) $cursor['_search_id'] : null;
		$page = max(1, (int) ($cursor['page'] ?? 1));
		$plan = $this->budget->plan($criteria->limit);
		$limits = $this->budget->limits($organizationId);

		$maxPages = min($limits['max_pages_per_search'], $plan['pages']);

		$params = $this->buildParams($criteria, $page, $plan['per_page']);
		$url = self::BASE_URL . self::SEARCH_ENDPOINT . '?' . self::queryString($params);
		$cacheKey = 'ubr_apollo_' . md5($url);
		$cached = get_transient($cacheKey);
		$logBase = ['organization_id' => $organizationId, 'search_id' => $searchId, 'provider' => 'apollo', 'endpoint' => self::SEARCH_ENDPOINT, 'method' => 'POST'];

		if (is_array($cached)) {
			$requestId = $this->requests->log($logBase + ['http_status' => 200, 'duration_ms' => 0, 'cached' => 1, 'billable' => 0]);
			$data = $cached;
		} else {
			$this->budget->assertCanRequest($organizationId, $searchId);

			$response = $this->http->send('apollo', 'POST', $url, [
				'timeout' => 30,
				'headers' => [
					'x-api-key' => $apiKey,
					'Content-Type' => 'application/json',
					'Cache-Control' => 'no-cache',
				],
				'body' => '{}',
			], 1300);

			$rate = array_filter(array_intersect_key($response['headers'], array_flip([
				'x-minute-requests-left', 'x-hourly-requests-left', 'x-24-hour-requests-left', 'x-rate-limit-minute', 'x-rate-limit-hourly', 'x-rate-limit-24-hour', 'retry-after',
			])), fn ($v) => $v !== '');

			$status = $response['status'];
			$error = $this->errorFor($status, $response);

			// Kredyt mógł zostać naliczony, jeśli Apollo przyjęło zapytanie (200) albo nie wiemy (timeout, 5xx).
			$billable = $status === 200 || $status === null || $status >= 500;
			$requestId = $this->requests->log($logBase + [
				'http_status' => $status,
				'duration_ms' => $response['ms'],
				'cached' => 0,
				'billable' => $billable ? 1 : 0,
				'rate_limit' => $rate,
				'error' => $error?->getMessage(),
			]);

			if ($error) {
				throw $error;
			}

			$data = $response['data'] ?? [];
			set_transient($cacheKey, $data, self::CACHE_TTL);
		}

		$pagination = (array) ($data['pagination'] ?? []);
		$totalPages = (int) ($pagination['total_pages'] ?? 0);
		$rows = array_merge((array) ($data['organizations'] ?? []), (array) ($data['accounts'] ?? []));

		$candidates = [];
		$seen = [];
		$filteredOut = 0;
		$rejected = [];

		foreach ($rows as $row) {
			$reason = null;
			$candidate = is_array($row) ? $this->map($row, $criteria, $reason) : null;

			if (! $candidate) {
				$filteredOut++;
				$reason ??= 'invalid_record';
				$rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
				continue;
			}

			if (isset($seen[$candidate->externalId])) {
				$rejected['duplicate_in_page'] = ($rejected['duplicate_in_page'] ?? 0) + 1;
				continue;
			}

			$seen[$candidate->externalId] = true;
			$candidates[] = $candidate;
		}

		$next = ($rows !== [] && $page < $maxPages && ($totalPages === 0 || $page < $totalPages))
			? ['page' => $page + 1]
			: null;

		return new ProviderPage($candidates, $next, [
			'api_request_id' => $requestId,
			'page' => $page,
			'total_pages' => $totalPages ?: null,
			'total_entries' => isset($pagination['total_entries']) ? (int) $pagination['total_entries'] : null,
			'returned' => count($rows),
			'filtered_out' => $filteredOut,
			'rejected' => $rejected,
			'cached' => is_array($cached),
			// Filtry wykonane po stronie Apollo — firmy NIE dostają tych wartości jako swoich danych.
			'filters' => [
				'locations' => $params['organization_locations[]'],
				'keyword_tags' => $params['q_organization_keyword_tags[]'] ?? [],
				'employees' => $params['organization_num_employees_ranges[]'] ?? [],
			],
		]);
	}

	/**
	 * Mapowanie rekordu Apollo. Zapisujemy WYŁĄCZNIE to, co jest w rekordzie — żadnych wartości z filtrów
	 * (województwo, miasto, przedział zatrudnienia). Brak pola = brak danych (stan „nieznane”/„filtr dostawcy” w ocenie trafności).
	 *
	 * @param array<string, mixed> $o rekord `organizations[]` lub `accounts[]`
	 * @param-out ?string $reason powód odrzucenia
	 */
	public function map(array $o, DiscoveryCriteria $criteria, ?string &$reason = null): ?CompanyCandidate
	{
		$id = (string) ($o['organization_id'] ?? $o['id'] ?? '');
		$name = trim((string) ($o['name'] ?? ''));

		if ($id === '' || $name === '') {
			$reason = 'invalid_record';

			return null;
		}

		// Tylko firmy z siedzibą w Polsce (jeśli Apollo podaje kraj).
		$country = mb_strtolower(trim((string) ($o['country'] ?? '')));

		if ($country !== '' && ! in_array($country, ['poland', 'polska', 'pl'], true)) {
			$reason = 'country_mismatch';

			return null;
		}

		// Lokalizacja tylko z rekordu (deklaracja dostawcy). Jawna niezgodność z kryteriami = odrzucenie z powodem.
		$city = isset($o['city']) && trim((string) $o['city']) !== '' ? CityCatalog::canonical((string) $o['city']) : null;
		$regionKey = RegionCatalog::keyFromName($o['state'] ?? null) ?? CityCatalog::regionFor($city);

		if ($criteria->regionKey() && $regionKey && $regionKey !== $criteria->regionKey()) {
			$reason = 'location_mismatch';

			return null;
		}

		if ($criteria->city && $city && ! CityCatalog::same($city, $criteria->city)) {
			$reason = 'location_mismatch';

			return null;
		}

		$website = $o['website_url'] ?? null;

		if (! $website && ! empty($o['primary_domain'])) {
			$website = 'https://' . $o['primary_domain'];
		}

		// Zatrudnienie: tylko szacunek z rekordu Apollo (deklaracja). NIE przedział z filtra.
		$employees = isset($o['estimated_num_employees']) && is_numeric($o['estimated_num_employees']) && (int) $o['estimated_num_employees'] > 0 ? (int) $o['estimated_num_employees'] : null;
		$keywords = array_values(array_filter(array_map('strval', (array) ($o['keywords'] ?? []))));
		$sic = array_values(array_filter(array_map('strval', (array) ($o['sic_codes'] ?? []))));
		$naics = array_values(array_filter(array_map('strval', (array) ($o['naics_codes'] ?? []))));
		$industry = $o['industry'] ?? null;
		$industry = $industry ?: ($keywords ? implode(', ', array_slice($keywords, 0, 4)) : null);
		$industry = $industry ?: IndustryCodes::describe($sic, $naics);
		// Apollo zwraca 0, gdy nie zna przychodu — 0 traktujemy jako brak danych.
		$revenue = $o['organization_revenue'] ?? $o['annual_revenue'] ?? null;
		$revenue = is_numeric($revenue) && (float) $revenue > 0 ? $revenue : null;

		$description = isset($o['short_description']) ? mb_substr((string) $o['short_description'], 0, 2000) : null;
		$matched = $criteria->matchedTerms(implode(' ', array_filter([$name, $industry, $description, implode(' ', $keywords)])));

		return new CompanyCandidate(
			provider: 'apollo',
			externalId: $id,
			name: $name,
			sourceUrl: 'https://app.apollo.io/#/organizations/' . rawurlencode($id),
			website: $website ? (string) $website : null,
			websiteConfidence: Confidence::UNCERTAIN,
			industry: $industry ? (string) $industry : null,
			description: $description,
			street: isset($o['street_address']) && $o['street_address'] !== '' ? (string) $o['street_address'] : null,
			postalCode: isset($o['postal_code']) && $o['postal_code'] !== '' ? (string) $o['postal_code'] : null,
			city: $city,
			region: RegionCatalog::label($regionKey),
			employeesMin: $employees,
			employeesMax: $employees,
			employeesConfidence: $employees ? Confidence::DECLARED : Confidence::UNAVAILABLE,
			matchedTerms: $matched,
			raw: ['id' => $id, 'country' => $o['country'] ?? null, 'state' => $o['state'] ?? null],
			foundedYear: isset($o['founded_year']) && is_numeric($o['founded_year']) ? (int) $o['founded_year'] : null,
			revenueEstimate: is_numeric($revenue) ? (int) $revenue : null,
			linkedinUrl: isset($o['linkedin_url']) ? (string) $o['linkedin_url'] : null,
			locationConfidence: ($city || $regionKey) ? Confidence::DECLARED : Confidence::UNAVAILABLE,
			industryCodes: array_filter(['sic' => $sic, 'naics' => $naics]),
			keywords: $keywords,
		);
	}

	/**
	 * Sprawdzenie klucza: `GET /api/v1/auth/health` (bez wyszukiwania).
	 *
	 * @return array{ok: bool, status: ?int, message: string, ms: int}
	 */
	public function testConnection(?int $organizationId = null): array
	{
		$apiKey = self::apiKey();

		if (! $apiKey) {
			return ['ok' => false, 'status' => null, 'message' => 'Brak klucza UBR_APOLLO_API_KEY.', 'ms' => 0];
		}

		$response = $this->http->send('apollo_meta', 'GET', self::BASE_URL . '/auth/health', [
			'timeout' => 15,
			'headers' => ['x-api-key' => $apiKey, 'Cache-Control' => 'no-cache'],
		], 0);

		$data = $response['data'] ?? [];
		$ok = $response['status'] === 200 && ! empty($data['healthy']) && ! empty($data['is_logged_in']);
		$message = $ok ? 'Połączenie działa, klucz rozpoznany.' : ($this->errorFor($response['status'], $response)?->getMessage() ?? 'Klucz nie został rozpoznany (is_logged_in=false).');

		$this->requests->log(['organization_id' => $organizationId, 'provider' => 'apollo', 'endpoint' => '/auth/health', 'method' => 'GET', 'http_status' => $response['status'], 'duration_ms' => $response['ms'], 'billable' => 0, 'error' => $ok ? null : $message]);

		return ['ok' => $ok, 'status' => $response['status'], 'message' => $message, 'ms' => $response['ms']];
	}

	/**
	 * Jawny test dostępu do wyszukiwania firm (1 strona × 1 firma = wg dokumentacji 1 kredyt). Tylko na żądanie użytkownika.
	 *
	 * @return array{ok: bool, status: ?int, message: string, total_entries: ?int}
	 */
	public function testSearchAccess(int $organizationId): array
	{
		$apiKey = self::apiKey();

		if (! $apiKey) {
			return ['ok' => false, 'status' => null, 'message' => 'Brak klucza.', 'total_entries' => null];
		}

		try {
			$this->budget->assertCanRequest($organizationId, null);
		} catch (ProviderException $e) {
			return ['ok' => false, 'status' => null, 'message' => $e->getMessage(), 'total_entries' => null];
		}

		$url = self::BASE_URL . self::SEARCH_ENDPOINT . '?' . self::queryString(['organization_locations[]' => ['poland'], 'page' => '1', 'per_page' => '1']);
		$response = $this->http->send('apollo', 'POST', $url, [
			'timeout' => 30,
			'headers' => ['x-api-key' => $apiKey, 'Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'],
			'body' => '{}',
		], 1300);

		$error = $this->errorFor($response['status'], $response);
		$billable = $response['status'] === 200 || $response['status'] === null || $response['status'] >= 500;
		$total = isset($response['data']['pagination']['total_entries']) ? (int) $response['data']['pagination']['total_entries'] : null;

		$this->requests->log(['organization_id' => $organizationId, 'provider' => 'apollo', 'endpoint' => self::SEARCH_ENDPOINT . ' (test)', 'method' => 'POST', 'http_status' => $response['status'], 'duration_ms' => $response['ms'], 'billable' => $billable ? 1 : 0, 'results_count' => count((array) ($response['data']['organizations'] ?? [])), 'error' => $error?->getMessage()]);

		return [
			'ok' => $error === null,
			'status' => $response['status'],
			'message' => $error ? $error->getMessage() : sprintf('Wyszukiwanie firm dostępne. Apollo raportuje %s firm z siedzibą w Polsce.', $total !== null ? number_format_i18n($total) : 'nieznaną liczbę'),
			'total_entries' => $total,
		];
	}

	/**
	 * Limity i zużycie API wg Apollo: `POST /api/v1/usage_stats/api_usage_stats` (0 kredytów, wymaga klucza master).
	 *
	 * @return array{ok: bool, status: ?int, message: string, search_limits: ?array}
	 */
	public function usageStats(?int $organizationId = null): array
	{
		$apiKey = self::apiKey();

		if (! $apiKey) {
			return ['ok' => false, 'status' => null, 'message' => 'Brak klucza.', 'search_limits' => null];
		}

		$response = $this->http->send('apollo_meta', 'POST', self::BASE_URL . '/usage_stats/api_usage_stats', [
			'timeout' => 15,
			'headers' => ['x-api-key' => $apiKey, 'Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'],
			'body' => '{}',
		], 0);

		$this->requests->log(['organization_id' => $organizationId, 'provider' => 'apollo', 'endpoint' => '/usage_stats/api_usage_stats', 'method' => 'POST', 'http_status' => $response['status'], 'duration_ms' => $response['ms'], 'billable' => 0]);

		if ($response['status'] !== 200 || ! is_array($response['data'])) {
			$message = $response['status'] === 403
				? 'Statystyki użycia wymagają klucza master — to nie blokuje wyszukiwania.'
				: ($this->errorFor($response['status'], $response)?->getMessage() ?? 'Brak danych.');

			return ['ok' => false, 'status' => $response['status'], 'message' => $message, 'search_limits' => null];
		}

		// Odpowiedź jest mapą "ścieżka endpointu" → limity/zużycie. Wyciągamy wpis dla wyszukiwania firm, jeśli istnieje.
		$searchLimits = null;

		foreach ($response['data'] as $endpoint => $info) {
			if (is_string($endpoint) && str_contains($endpoint, 'mixed_companies') && is_array($info)) {
				$searchLimits = $info;
				break;
			}
		}

		return ['ok' => true, 'status' => 200, 'message' => $searchLimits ? 'Pobrano limity wyszukiwania firm.' : 'Pobrano statystyki (brak osobnego wpisu dla wyszukiwania firm).', 'search_limits' => $searchLimits];
	}

	/**
	 * @param array{status: ?int, data: ?array, body: string, headers: array<string, string>, error: ?string} $response
	 */
	private function errorFor(?int $status, array $response): ?ProviderException
	{
		if ($status === 200) {
			return null;
		}

		$apiMessage = '';

		if (is_array($response['data'] ?? null)) {
			$apiMessage = (string) ($response['data']['error'] ?? $response['data']['message'] ?? '');
		}

		$suffix = $apiMessage !== '' ? ' (' . mb_substr(wp_strip_all_tags($apiMessage), 0, 160) . ')' : '';

		return match (true) {
			$status === null => new ProviderException('Apollo: brak odpowiedzi (' . mb_substr((string) $response['error'], 0, 120) . '). Nie ponawiam automatycznie — zapytanie mogło zostać naliczone.', false),
			$status === 401 => new ProviderException('Apollo: nieprawidłowy klucz API (401).' . $suffix, false),
			$status === 403 => new ProviderException('Apollo: brak dostępu do wyszukiwania firm (403) — sprawdź plan Apollo i zakres klucza (endpoint mixed_companies/search).' . $suffix, false),
			$status === 422 => new ProviderException('Apollo: nieprawidłowe parametry zapytania (422).' . $suffix, false),
			$status === 429 => new ProviderException('Apollo: przekroczono limit zapytań (429).' . $suffix, true, max(5, (int) ($response['headers']['retry-after'] ?? 30))),
			$status >= 500 => new ProviderException(sprintf('Apollo: błąd serwera (HTTP %d). Nie ponawiam automatycznie — zapytanie mogło zostać naliczone.', $status), false),
			default => new ProviderException(sprintf('Apollo: odrzucono zapytanie (HTTP %d).', $status) . $suffix, false),
		};
	}
}
