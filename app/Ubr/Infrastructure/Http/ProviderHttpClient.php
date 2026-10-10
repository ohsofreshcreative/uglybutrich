<?php

namespace App\Ubr\Infrastructure\Http;

use App\Ubr\Domain\Discovery\ProviderException;

/**
 * Klient HTTP dla dostawców: limity częstotliwości, klasyfikacja błędów (retry / brak retry), JSON.
 */
class ProviderHttpClient
{
	public const USER_AGENT = 'UglyButRich/0.1 (OhSoFresh Creative; kontakt@ohsofresh.pl) WordPress';

	/**
	 * @param array<string, mixed> $args argumenty wp_remote_request
	 * @return array<string, mixed>
	 */
	public function json(string $provider, string $method, string $url, array $args = [], int $minIntervalMs = 1000): array
	{
		$this->throttle($provider, $minIntervalMs);

		$args += ['timeout' => 30];
		$args['method'] = $method;
		$args['headers'] = ($args['headers'] ?? []) + ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'];
		$args['user-agent'] = self::USER_AGENT;

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			throw new ProviderException('Błąd połączenia: ' . $response->get_error_message(), true);
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		if ($code === 429 || $code >= 500) {
			$retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');

			throw new ProviderException(sprintf('Źródło chwilowo niedostępne (HTTP %d).', $code), true, $retryAfter ?: null);
		}

		if ($code >= 400) {
			throw new ProviderException(sprintf('Źródło odrzuciło zapytanie (HTTP %d): %s', $code, mb_substr(wp_strip_all_tags($body), 0, 300)), false);
		}

		$data = json_decode($body, true);

		if (! is_array($data)) {
			// Overpass zwraca HTML/XML z komunikatem przy przekroczeniu limitów czasu/pamięci.
			$snippet = mb_substr(trim(wp_strip_all_tags($body)), 0, 300);
			$retryable = str_contains(strtolower($snippet), 'timeout') || str_contains(strtolower($snippet), 'too busy') || str_contains(strtolower($snippet), 'rate_limited');

			throw new ProviderException('Nieprawidłowa odpowiedź źródła: ' . $snippet, $retryable);
		}

		return $data;
	}

	/**
	 * Surowe żądanie bez rzucania wyjątków dla kodów HTTP (dostawca sam interpretuje 401/403/429).
	 *
	 * @param array<string, mixed> $args
	 * @return array{status: ?int, data: ?array, body: string, headers: array<string, string>, ms: int, error: ?string}
	 */
	public function send(string $provider, string $method, string $url, array $args = [], int $minIntervalMs = 1000): array
	{
		$this->throttle($provider, $minIntervalMs);

		$args += ['timeout' => 30];
		$args['method'] = $method;
		$args['headers'] = ($args['headers'] ?? []) + ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'];
		$args['user-agent'] = self::USER_AGENT;

		$start = microtime(true);
		$response = wp_remote_request($url, $args);
		$ms = (int) round((microtime(true) - $start) * 1000);

		if (is_wp_error($response)) {
			return ['status' => null, 'data' => null, 'body' => '', 'headers' => [], 'ms' => $ms, 'error' => $response->get_error_message()];
		}

		$headers = [];

		$rawHeaders = wp_remote_retrieve_headers($response);
		$rawHeaders = is_object($rawHeaders) && method_exists($rawHeaders, 'getAll') ? $rawHeaders->getAll() : (array) $rawHeaders;

		foreach ($rawHeaders as $name => $value) {
			$headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
		}

		$body = (string) wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		return [
			'status' => (int) wp_remote_retrieve_response_code($response),
			'data' => is_array($data) ? $data : null,
			'body' => $body,
			'headers' => $headers,
			'ms' => $ms,
			'error' => null,
		];
	}

	/**
	 * Minimalny odstęp między zapytaniami do jednego dostawcy (wspólny dla wszystkich procesów).
	 */
	private function throttle(string $provider, int $minIntervalMs): void
	{
		if ($minIntervalMs <= 0) {
			return;
		}

		$key = 'ubr_rl_' . $provider;
		$last = (float) get_transient($key);
		$waitMs = (int) ceil(($last + $minIntervalMs / 1000 - microtime(true)) * 1000);

		if ($waitMs > 0) {
			usleep(min($waitMs, 10000) * 1000);
		}

		set_transient($key, (string) microtime(true), 3600);
	}
}
