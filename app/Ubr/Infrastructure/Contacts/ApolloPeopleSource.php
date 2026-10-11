<?php

namespace App\Ubr\Infrastructure\Contacts;

use App\Ubr\Domain\Contacts\PersonName;
use App\Ubr\Domain\Contacts\RoleCatalog;
use App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;

/**
 * Apollo People API Search — PRZYGOTOWANE, DOMYŚLNIE WYŁĄCZONE.
 *
 * Endpoint wg dokumentacji Apollo (docs.apollo.io/reference/people-api-search, 10.2026):
 * `POST /api/v1/mixed_people/api_search`, filtry `q_organization_domains_list[]`, `person_seniorities[]`, `person_titles[]`.
 * Dokumentacja podaje 0 kredytów, ale endpoint wymaga klucza master (403 „Need master API key”), nie zwraca e-maili
 * ani telefonów i może zwracać zamaskowane nazwiska. Enrichment (dane kontaktowe) jest płatny — NIE jest używany.
 *
 * Włączenie wymaga świadomej decyzji właściciela: `define('UBR_APOLLO_PEOPLE_ENABLED', true);` w wp-config.php.
 * Kandydaci z Apollo bez potwierdzenia w oficjalnym źródle mają status UNCERTAIN.
 */
class ApolloPeopleSource
{
	public const ENDPOINT = '/mixed_people/api_search';

	/**
	 * Stanowiska decydentów w sprawie strony WWW (EN + PL). Wg dokumentacji osoba musi pasować do JEDNEGO tytułu,
	 * a `include_similar_titles` dopuszcza podobne (np. „content marketing manager”). Bez filtra seniority — łączenie
	 * filtrów może zawężać wyniki (NOT VERIFIED). Dopasowanie roli i tak przechodzi przez RoleCatalog.
	 */
	public const TITLES = [
		'CEO', 'President', 'Founder', 'Co-Founder', 'Owner', 'Managing Director', 'General Manager', 'Head of Marketing', 'Marketing Director',
		'Marketing Manager', 'Head of Digital', 'Digital Marketing Manager', 'E-commerce Manager', 'E-commerce Director',
		'Prezes Zarządu', 'Właściciel', 'Założyciel', 'Dyrektor Zarządzający', 'Dyrektor Marketingu', 'Kierownik Marketingu',
		'Specjalista ds. marketingu', 'Dyrektor E-commerce', 'Kierownik E-commerce',
	];

	public function __construct(private ProviderHttpClient $http, private ApiRequestRepository $requests)
	{
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/contacts/apollo_people_enabled', defined('UBR_APOLLO_PEOPLE_ENABLED') && UBR_APOLLO_PEOPLE_ENABLED) && ApolloProvider::apiKey() !== null;
	}

	/**
	 * @return array{state: string, message: string}
	 */
	public static function status(): array
	{
		if (ApolloProvider::apiKey() === null) {
			return ['state' => 'not_configured', 'message' => 'Brak klucza Apollo.'];
		}

		if (! self::enabled()) {
			return ['state' => 'disabled', 'message' => 'Wyłączone. People API Search wymaga klucza master i nie zwraca e-maili; włączenie wymaga Twojej zgody (UBR_APOLLO_PEOPLE_ENABLED w wp-config.php). Płatny enrichment nie jest używany.'];
		}

		return ['state' => 'enabled', 'message' => 'Włączone (People API Search, bez enrichmentu).'];
	}

	/**
	 * @return array{candidates: list<array<string, mixed>>, error: ?string, state: string}
	 */
	public function search(string $domain, ?int $organizationId = null, ?string $apolloOrganizationId = null): array
	{
		if (! self::enabled()) {
			return ['candidates' => [], 'error' => null, 'state' => self::status()['state']];
		}

		[$data, $error] = $this->request($domain, $organizationId, 25);

		if ($error) {
			return ['candidates' => [], 'error' => $error, 'state' => 'error'];
		}

		$out = [];

		foreach ((array) ($data['people'] ?? []) as $p) {
			if (! is_array($p) || empty($p['title'])) {
				continue;
			}

			$last = (string) ($p['last_name'] ?? '');
			$obfuscated = $last === '' || str_contains($last . (string) ($p['last_name_obfuscated'] ?? ''), '*');
			$name = $obfuscated ? null : PersonName::parse(trim((string) ($p['first_name'] ?? '') . ' ' . $last));
			$role = RoleCatalog::match((string) $p['title']);

			// Zamaskowane nazwisko = brak pełnej osoby — nie tworzymy kontaktu.
			if (! $name || ! $role) {
				continue;
			}

			// Powiązanie z firmą: zapytanie filtrowane domeną; jeśli Apollo podaje firmę osoby, musi się zgadzać domena
			// albo identyfikator organizacji Apollo. Sama podobna nazwa firmy NIE wystarcza.
			if (! self::belongsToCompany($p, $domain, $apolloOrganizationId)) {
				continue;
			}

			$profile = (string) ($p['linkedin_url'] ?? '');
			$out[] = [
				'name' => $name, 'title' => mb_substr((string) $p['title'], 0, 200), 'role' => $role, 'source_type' => 'APOLLO',
				'source_url' => null, 'source_title' => 'Apollo People API Search', 'page_kind' => 'apollo',
				'excerpt' => mb_substr($name->full() . ' · ' . $p['title'], 0, 300),
				'email' => null, 'role_email' => null, 'phone' => null,
				'profile' => preg_match('#^https?://([a-z]{2,3}\.)?linkedin\.com/in/#i', $profile) ? $profile : null,
				'former' => false, 'other_entity' => null, 'obfuscated' => false,
				'external_provider' => 'apollo', 'external_id' => isset($p['id']) && preg_match('/^[A-Za-z0-9]{6,64}$/', (string) $p['id']) ? (string) $p['id'] : null,
			];
		}

		return ['candidates' => $out, 'error' => null, 'state' => 'enabled'];
	}

	/** Liczba osób na zapytanie w Apollo Contact Matching (Prompt 10.3) — taka sama jak w teście kontrolnym (wspólny cache). */
	public const MATCHING_PER_PAGE = 10;

	/**
	 * Adres zapytania People API Search (bez klucza API — klucz idzie wyłącznie w nagłówku).
	 */
	public static function url(string $domain, int $perPage): string
	{
		$query = http_build_query([
			'q_organization_domains_list' => [$domain],
			'person_titles' => self::TITLES,
			'include_similar_titles' => 'true',
			'per_page' => $perPage,
			'page' => 1,
		]);

		return ApolloProvider::BASE_URL . self::ENDPOINT . '?' . preg_replace('/%5B\d+%5D/', '%5B%5D', $query);
	}

	public static function cacheKey(string $domain, int $perPage): string
	{
		return 'ubr_apollo_people_' . md5(self::url($domain, $perPage));
	}

	/**
	 * Prompt 10.3 — osoby z People API Search dla domeny: najpierw cache (bez zapytania), zapytanie sieciowe TYLKO gdy
	 * `$allowNetwork` (funkcja włączona + świadoma zgoda — sprawdza wywołujący) i klucz Apollo jest skonfigurowany.
	 *
	 * @return array{data: array<string, mixed>, error: ?string, cached: bool, executed: bool}
	 */
	public function people(string $domain, ?int $organizationId, bool $allowNetwork): array
	{
		$cached = get_transient(self::cacheKey($domain, self::MATCHING_PER_PAGE));

		if (is_array($cached)) {
			return ['data' => $cached, 'error' => null, 'cached' => true, 'executed' => false];
		}

		if (! $allowNetwork || ApolloProvider::apiKey() === null) {
			return ['data' => [], 'error' => null, 'cached' => false, 'executed' => false];
		}

		[$data, $error] = $this->request($domain, $organizationId, self::MATCHING_PER_PAGE);

		return ['data' => $data, 'error' => $error, 'cached' => false, 'executed' => true];
	}

	/**
	 * Jedno zapytanie People API Search (0 kredytów wg dokumentacji; bez e-maili i telefonów). Cache 1 dzień.
	 *
	 * @return array{0: array<string, mixed>, 1: ?string}
	 */
	private function request(string $domain, ?int $organizationId, int $perPage): array
	{
		$url = self::url($domain, $perPage);
		$cacheKey = self::cacheKey($domain, $perPage);
		$data = get_transient($cacheKey);

		if (! is_array($data)) {
			$response = $this->http->send('apollo', 'POST', $url, [
				'timeout' => 20,
				'headers' => ['x-api-key' => (string) ApolloProvider::apiKey(), 'Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'],
				'body' => '{}',
			], 1300);
			$status = $response['status'];
			$error = match (true) {
				$status === null => 'Apollo People Search: brak odpowiedzi (' . mb_substr((string) $response['error'], 0, 80) . ').',
				$status === 401 => 'Apollo People Search: nieprawidłowy klucz API.',
				$status === 403 => 'Apollo People Search: brak dostępu na obecnym planie / wymagany klucz master.',
				$status === 422 => 'Apollo People Search: nieprawidłowe żądanie.',
				$status === 429 => 'Apollo People Search: przekroczony limit zapytań — spróbuj później.',
				$status >= 400 => 'Apollo People Search: błąd HTTP ' . $status . '.',
				! is_array($response['data'] ?? null) => 'Apollo People Search: nieprawidłowa odpowiedź.',
				default => null,
			};
			$this->requests->log(['organization_id' => $organizationId, 'provider' => 'apollo', 'endpoint' => self::ENDPOINT, 'method' => 'POST', 'http_status' => $status, 'duration_ms' => $response['ms'], 'cached' => 0, 'billable' => 0, 'error' => $error]);

			if ($error) {
				return [[], $error];
			}

			$data = (array) $response['data'];
			set_transient($cacheKey, $data, DAY_IN_SECONDS);
		}

		return [(array) $data, null];
	}

	/**
	 * Prompt 10.2 — JEDEN kontrolowany test dostępu (bez włączania funkcji, bez zapisu osób). Wymaga klucza Apollo
	 * i wywołania przez właściciela / administratora z potwierdzeniem. Zwraca podsumowanie odpowiedzi.
	 *
	 * @return array<string, mixed>
	 */
	public function controlledTest(string $domain, ?int $organizationId, ?string $apolloOrganizationId): array
	{
		if (ApolloProvider::apiKey() === null) {
			return ['ok' => false, 'error' => 'Brak klucza Apollo.', 'people' => []];
		}

		[$data, $error] = $this->request($domain, $organizationId, 10);

		if ($error) {
			return ['ok' => false, 'error' => $error, 'people' => []];
		}

		$people = [];

		foreach (array_slice((array) ($data['people'] ?? []), 0, 10) as $p) {
			if (! is_array($p)) {
				continue;
			}

			$last = (string) ($p['last_name'] ?? '');
			$masked = $last === '' || str_contains($last . (string) ($p['last_name_obfuscated'] ?? ''), '*');
			$role = RoleCatalog::match((string) ($p['title'] ?? ''));
			$people[] = [
				'first_name' => mb_substr((string) ($p['first_name'] ?? ''), 0, 60),
				'last_name_masked' => $masked,
				'last_name_obfuscated' => mb_substr((string) ($p['last_name_obfuscated'] ?? ''), 0, 60) ?: null,
				'title' => mb_substr((string) ($p['title'] ?? ''), 0, 120),
				'role' => $role['role'] ?? null,
				'has_person_id' => ! empty($p['id']),
				'belongs_to_company' => self::belongsToCompany($p, $domain, $apolloOrganizationId),
				'has_email_field' => array_key_exists('email', $p) && ! empty($p['email']),
				'has_phone_field' => ! empty($p['phone_numbers']) || ! empty($p['sanitized_phone']),
				'has_direct_phone_flag' => $p['has_direct_phone'] ?? null,
			];
		}

		return [
			'ok' => true,
			'error' => null,
			'total_entries' => (int) ($data['total_entries'] ?? ($data['pagination']['total_entries'] ?? count($people))),
			'returned' => count((array) ($data['people'] ?? [])),
			'people' => $people,
			'accepted' => count(array_filter($people, fn ($p) => ! $p['last_name_masked'] && $p['role'] && $p['belongs_to_company'])),
			'keys' => array_slice(array_keys((array) (($data['people'] ?? [])[0] ?? [])), 0, 40),
		];
	}

	/**
	 * @param array<string, mixed> $p osoba z odpowiedzi People API Search
	 */
	public static function belongsToCompany(array $p, string $domain, ?string $apolloOrganizationId): bool
	{
		$org = is_array($p['organization'] ?? null) ? $p['organization'] : [];
		$orgId = (string) ($p['organization_id'] ?? $org['id'] ?? '');
		$orgDomain = (string) ($org['primary_domain'] ?? '');

		if ($orgDomain === '' && ! empty($org['website_url'])) {
			$orgDomain = (string) parse_url((string) $org['website_url'], PHP_URL_HOST);
		}

		$norm = fn (string $d): string => preg_replace('/^www\./', '', strtolower(trim($d))) ?? '';

		if ($apolloOrganizationId !== null && $orgId !== '' && $orgId === $apolloOrganizationId) {
			return true;
		}

		if ($orgDomain !== '') {
			return $norm($orgDomain) === $norm($domain);
		}

		// Brak danych o firmie w rekordzie: dowodem jest wyłącznie filtr domeny zapytania — chyba że Apollo podało inną firmę.
		return $orgId === '' || $apolloOrganizationId === null;
	}
}
