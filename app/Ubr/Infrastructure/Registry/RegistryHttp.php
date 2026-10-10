<?php

namespace App\Ubr\Infrastructure\Registry;

use App\Ubr\Domain\Verification\RegistryUnavailable;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;

/**
 * Wspólny klient HTTP rejestrów publicznych: cache odpowiedzi, odstęp między zapytaniami, dzienny limit,
 * timeout, dziennik w `ubr_api_requests` (billable = 0 — rejestry są bezpłatne).
 *
 * Błędy sieci/5xx/429/limit → RegistryUnavailable (kolejka ponowi weryfikację z backoffem).
 * 404 → status 404 (brak podmiotu), bez wyjątku.
 */
class RegistryHttp
{
	public function __construct(
		private ProviderHttpClient $http,
		private ApiRequestRepository $requests,
	) {
	}

	/**
	 * @param array<string, mixed> $args dodatkowe argumenty wp_remote_request (headers, body, timeout)
	 * @param array{cache_ttl?: int, not_found_ttl?: int, interval_ms?: int, daily_limit?: int, endpoint?: string, cache_key?: string} $options
	 * @return array{status: int, data: ?array, body: string, cached: bool}
	 */
	public function request(string $source, string $method, string $url, array $args = [], array $options = []): array
	{
		$cacheKey = 'ubr_reg_' . self::ns() . md5($options['cache_key'] ?? ($method . ' ' . $url));
		$cached = get_transient($cacheKey);
		$endpoint = mb_substr($options['endpoint'] ?? (string) parse_url($url, PHP_URL_PATH), 0, 190);

		if (is_array($cached)) {
			$this->requests->log(['provider' => $source, 'endpoint' => $endpoint, 'method' => $method, 'http_status' => $cached['status'], 'duration_ms' => 0, 'cached' => 1, 'billable' => 0]);

			return $cached + ['cached' => true];
		}

		$limit = (int) ($options['daily_limit'] ?? 0);

		if ($limit > 0 && $this->usedToday($source) >= $limit) {
			throw new RegistryUnavailable(sprintf('%s: wykorzystano dzienny limit zapytań (%d) — weryfikacja zostanie wznowiona jutro.', $source, $limit), $source, $this->secondsToMidnight());
		}

		$response = $this->http->send('reg_' . $source, $method, $url, $args + ['timeout' => 12], (int) ($options['interval_ms'] ?? 1000));
		$this->countToday($source);
		$status = $response['status'];

		$this->requests->log([
			'provider' => $source,
			'endpoint' => $endpoint,
			'method' => $method,
			'http_status' => $status,
			'duration_ms' => $response['ms'],
			'cached' => 0,
			'billable' => 0,
			'error' => $response['error'] ?? ($status !== null && $status >= 400 && $status !== 404 ? mb_substr(wp_strip_all_tags($response['body']), 0, 300) : null),
		]);

		if ($status === null) {
			throw new RegistryUnavailable($source . ': brak odpowiedzi (' . mb_substr((string) $response['error'], 0, 120) . ').', $source, 300);
		}

		if ($status === 429 || $status >= 500) {
			$retry = (int) ($response['headers']['retry-after'] ?? 0);

			throw new RegistryUnavailable(sprintf('%s: rejestr chwilowo niedostępny (HTTP %d).', $source, $status), $source, max(120, $retry));
		}

		$result = ['status' => $status, 'data' => $response['data'], 'body' => mb_substr($response['body'], 0, 400000)];

		if ($status === 200) {
			set_transient($cacheKey, $result, (int) ($options['cache_ttl'] ?? 7 * DAY_IN_SECONDS));
		} elseif ($status === 404) {
			set_transient($cacheKey, $result, (int) ($options['not_found_ttl'] ?? DAY_IN_SECONDS));
		}

		return $result + ['cached' => false];
	}

	public function usedToday(string $source): int
	{
		$data = (array) get_option('ubr_reg_daily_' . self::ns() . $source, []);

		return ($data['date'] ?? '') === gmdate('Y-m-d') ? (int) ($data['count'] ?? 0) : 0;
	}

	private function countToday(string $source): void
	{
		update_option('ubr_reg_daily_' . self::ns() . $source, ['date' => gmdate('Y-m-d'), 'count' => $this->usedToday($source) + 1], false);
	}

	/**
	 * Przestrzeń nazw cache/liczników (testy używają osobnej, żeby nie ruszać danych produkcyjnych).
	 */
	private static function ns(): string
	{
		return (string) apply_filters('ubr/registry/namespace', '');
	}

	private function secondsToMidnight(): int
	{
		return max(600, strtotime('tomorrow 00:05 UTC') - time());
	}
}
