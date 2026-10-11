<?php

namespace App\Ubr\Infrastructure\Http;

use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;

/**
 * Prompt 10.4.3 — Internet Archive (Wayback Machine) dla Website Age Intelligence.
 *
 * - lista kopii: publiczne CDX API (`/cdx/search/cdx`), jedna kopia na miesiąc (collapse), tylko HTML 200,
 * - kopia archiwalna: `/web/<timestamp>id_/<url>` (oryginalny HTML bez paska archiwum),
 * - jedno zapytanie naraz (jeden worker), min. odstęp UBR_WAYBACK_INTERVAL_MS (domyślnie 2000 ms) wspólny dla procesów,
 *   krótkie timeouty, limit rozmiaru; 429 / 5xx / brak odpowiedzi → `unavailable` (ponowienie później, bez obchodzenia
 *   limitów archiwum), dziennik zapytań w `ubr_api_requests` (provider `wayback`, bezpłatne),
 * - lista kopii w cache (transient, 14 dni) — także wynik „brak kopii”.
 */
class WaybackClient
{
	public const BASE = 'https://web.archive.org';

	public const MAX_BYTES = 800 * 1024;

	public const USER_AGENT = 'UglyButRich/1.0 (website age research; +https://ohsofresh.pl)';

	public function __construct(private ProviderHttpClient $http, private ApiRequestRepository $requests)
	{
	}

	public static function interval(): int
	{
		return max(0, (int) apply_filters('ubr/wayback/interval_ms', max(1000, defined('UBR_WAYBACK_INTERVAL_MS') ? (int) constant('UBR_WAYBACK_INTERVAL_MS') : 2000)));
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/wayback/enabled', ! defined('UBR_WAYBACK_ENABLED') || constant('UBR_WAYBACK_ENABLED'));
	}

	/**
	 * Miesięczne kopie strony głównej domeny (najstarsze → najnowsze).
	 *
	 * @return array{ok: bool, captures: list<array{ts: string, digest: string}>, unavailable: bool, error: ?string, cached: bool}
	 */
	public function captures(string $domain, int $timeout = 6): array
	{
		$domain = strtolower(trim($domain));
		$key = 'ubr_wb_cap_' . md5($domain);
		$cached = get_transient($key);

		if (is_array($cached)) {
			return ['ok' => true, 'captures' => $cached, 'unavailable' => false, 'error' => null, 'cached' => true];
		}

		$url = add_query_arg([
			'url' => $domain . '/',
			'output' => 'json',
			'fl' => 'timestamp,digest,statuscode,mimetype',
			'filter' => 'statuscode:200',
			'collapse' => 'timestamp:6',
			'limit' => 400,
		], self::BASE . '/cdx/search/cdx');
		$r = $this->send($url, $timeout, '/cdx/search/cdx');

		if (! $r['ok']) {
			return ['ok' => false, 'captures' => [], 'unavailable' => true, 'error' => $r['error'], 'cached' => false];
		}

		$rows = json_decode($r['body'], true);
		$captures = [];

		if (is_array($rows)) {
			foreach (array_slice($rows, 1) as $row) {
				if (is_array($row) && preg_match('/^\d{14}$/', (string) ($row[0] ?? '')) && str_contains(strtolower((string) ($row[3] ?? 'text/html')), 'html')) {
					$captures[] = ['ts' => (string) $row[0], 'digest' => (string) ($row[1] ?? '')];
				}
			}
		}

		set_transient($key, $captures, 14 * DAY_IN_SECONDS);

		return ['ok' => true, 'captures' => $captures, 'unavailable' => false, 'error' => null, 'cached' => false];
	}

	/**
	 * Oryginalny HTML kopii archiwalnej.
	 *
	 * @return array{ok: bool, html: string, unavailable: bool, error: ?string}
	 */
	public function snapshot(string $ts, string $domain, int $timeout = 6): array
	{
		if (! preg_match('/^\d{14}$/', $ts)) {
			return ['ok' => false, 'html' => '', 'unavailable' => false, 'error' => 'Nieprawidłowy znacznik kopii.'];
		}

		$r = $this->send(self::BASE . '/web/' . $ts . 'id_/http://' . rawurlencode(strtolower($domain)) . '/', $timeout, '/web/id_');

		if (! $r['ok']) {
			return ['ok' => false, 'html' => '', 'unavailable' => $r['retryable'], 'error' => $r['error']];
		}

		return ['ok' => true, 'html' => substr($r['body'], 0, self::MAX_BYTES), 'unavailable' => false, 'error' => null];
	}

	/**
	 * @return array{ok: bool, body: string, error: ?string, retryable: bool}
	 */
	private function send(string $url, int $timeout, string $endpoint): array
	{
		if (! self::enabled()) {
			return ['ok' => false, 'body' => '', 'error' => 'Wayback Machine wyłączone (UBR_WAYBACK_ENABLED).', 'retryable' => false];
		}

		$r = $this->http->send('wayback', 'GET', $url, [
			'timeout' => max(2, $timeout),
			'redirection' => 3,
			'limit_response_size' => self::MAX_BYTES,
			'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/json'],
		], self::interval());
		$status = $r['status'];
		$error = $r['error'] ?? ($status !== null && $status >= 400 ? 'HTTP ' . $status : null);

		$this->requests->log(['provider' => 'wayback', 'endpoint' => $endpoint, 'method' => 'GET', 'http_status' => $status, 'duration_ms' => $r['ms'], 'cached' => 0, 'billable' => 0, 'error' => $error]);

		if ($status === 200) {
			return ['ok' => true, 'body' => (string) $r['body'], 'error' => null, 'retryable' => false];
		}

		return ['ok' => false, 'body' => '', 'error' => $error ?: 'Brak odpowiedzi archiwum.', 'retryable' => $status === null || $status === 429 || $status >= 500];
	}
}
