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
	public function search(string $domain, ?int $organizationId = null): array
	{
		if (! self::enabled()) {
			return ['candidates' => [], 'error' => null, 'state' => self::status()['state']];
		}

		$query = http_build_query([
			'q_organization_domains_list' => [$domain],
			'person_seniorities' => ['owner', 'founder', 'c_suite', 'partner', 'head', 'director', 'vp'],
			'per_page' => 10,
			'page' => 1,
		]);
		$url = ApolloProvider::BASE_URL . self::ENDPOINT . '?' . preg_replace('/%5B\d+%5D/', '%5B%5D', $query);
		$cacheKey = 'ubr_apollo_people_' . md5($url);
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
				$status === 429 => 'Apollo People Search: przekroczony limit zapytań.',
				$status >= 400 => 'Apollo People Search: błąd HTTP ' . $status . '.',
				default => null,
			};
			$this->requests->log(['organization_id' => $organizationId, 'provider' => 'apollo', 'endpoint' => self::ENDPOINT, 'method' => 'POST', 'http_status' => $status, 'duration_ms' => $response['ms'], 'cached' => 0, 'billable' => 0, 'error' => $error]);

			if ($error) {
				return ['candidates' => [], 'error' => $error, 'state' => 'error'];
			}

			$data = (array) ($response['data'] ?? []);
			set_transient($cacheKey, $data, DAY_IN_SECONDS);
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

			$profile = (string) ($p['linkedin_url'] ?? '');
			$out[] = [
				'name' => $name, 'title' => mb_substr((string) $p['title'], 0, 200), 'role' => $role, 'source_type' => 'APOLLO',
				'source_url' => null, 'source_title' => 'Apollo People API Search', 'page_kind' => 'apollo',
				'excerpt' => mb_substr($name->full() . ' · ' . $p['title'], 0, 300),
				'email' => null, 'role_email' => null, 'phone' => null,
				'profile' => preg_match('#^https?://([a-z]{2,3}\.)?linkedin\.com/in/#i', $profile) ? $profile : null,
				'former' => false, 'other_entity' => null, 'obfuscated' => false,
			];
		}

		return ['candidates' => $out, 'error' => null, 'state' => 'enabled'];
	}
}
