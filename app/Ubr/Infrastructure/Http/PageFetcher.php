<?php

namespace App\Ubr\Infrastructure\Http;

/**
 * Pobieranie HTML strony do analizy statycznej (SEO, treść, dostępność, technika) — bez przeglądarki.
 *
 * Przekierowania obsługujemy ręcznie (maks. 5), sprawdzając KAŻDY adres przez UrlGuard (SSRF) — nie ufamy
 * samej walidacji początkowego URL. Limit rozmiaru odpowiedzi 2 MB, timeout 12 s, bez ciasteczek i bez JS.
 */
class PageFetcher
{
	public const USER_AGENT = 'Mozilla/5.0 (compatible; UglyButRichBot/0.3; +https://ohsofresh.pl) website-analysis';

	public const MAX_BYTES = 2 * 1024 * 1024;

	public function __construct(private UrlGuard $guard)
	{
	}

	/**
	 * @return array{ok: bool, url: string, final_url: ?string, status: ?int, redirects: list<string>, headers: array<string, string>, body: string, bytes: int, ms: int, error: ?string, blocked: ?string, certificate_error: bool}
	 */
	public function fetch(string $url, int $timeout = 12): array
	{
		$result = ['ok' => false, 'url' => $url, 'final_url' => null, 'status' => null, 'redirects' => [], 'headers' => [], 'body' => '', 'bytes' => 0, 'ms' => 0, 'error' => null, 'blocked' => null, 'certificate_error' => false];
		$current = $url;
		$started = microtime(true);

		for ($hop = 0; $hop <= 5; $hop++) {
			$check = $this->guard->check($current);

			if (! $check['ok']) {
				$result['blocked'] = $check['reason'];
				$result['error'] = 'Adres zablokowany (ochrona SSRF): ' . $check['reason'];

				return $result;
			}

			$unpin = self::pin($current, $check['ips'] ?? []);
			$response = wp_remote_get($current, [
				'timeout' => $timeout,
				'redirection' => 0,
				'user-agent' => self::USER_AGENT,
				'limit_response_size' => self::MAX_BYTES,
				'headers' => ['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'pl,en;q=0.5'],
				'cookies' => [],
				'sslverify' => true,
			]);
			$unpin();

			if (is_wp_error($response)) {
				$message = $response->get_error_message();
				$result['error'] = mb_substr($message, 0, 250);
				$result['certificate_error'] = (bool) preg_match('/(ssl|certificate|cert)/i', $message);
				$result['ms'] = (int) round((microtime(true) - $started) * 1000);

				return $result;
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$location = (string) wp_remote_retrieve_header($response, 'location');

			if ($code >= 300 && $code < 400 && $location !== '') {
				$result['redirects'][] = $current;
				$current = self::absolute($location, $current);
				continue;
			}

			$headers = [];

			$raw = wp_remote_retrieve_headers($response);
			$raw = is_object($raw) && method_exists($raw, 'getAll') ? $raw->getAll() : (array) $raw;

			foreach ($raw as $name => $value) {
				$headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
			}

			$body = (string) wp_remote_retrieve_body($response);
			$result = array_merge($result, [
				'ok' => $code >= 200 && $code < 400,
				'final_url' => $current,
				'status' => $code,
				'headers' => array_intersect_key($headers, array_flip(['content-type', 'x-frame-options', 'content-security-policy', 'strict-transport-security', 'server', 'x-powered-by', 'x-robots-tag', 'content-encoding', 'cache-control', 'last-modified'])),
				'body' => $body,
				'bytes' => strlen($body),
				'ms' => (int) round((microtime(true) - $started) * 1000),
				'error' => $code >= 400 ? 'HTTP ' . $code : null,
			]);

			return $result;
		}

		$result['error'] = 'Zbyt wiele przekierowań.';

		return $result;
	}

	/**
	 * Przypina połączenie cURL do adresów IP sprawdzonych przez UrlGuard (CURLOPT_RESOLVE), aby cURL nie
	 * rozwiązywał nazwy ponownie — inaczej domena z krótkim TTL mogłaby po sprawdzeniu wskazać 127.0.0.1
	 * lub 169.254.169.254 (DNS rebinding). Zwraca funkcję zdejmującą przypięcie.
	 *
	 * @param  list<string>  $ips
	 */
	public static function pin(string $url, array $ips): \Closure
	{
		$p = wp_parse_url($url);
		$host = strtolower((string) ($p['host'] ?? ''));

		if ($ips === [] || $host === '' || ! defined('CURLOPT_RESOLVE') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
			return static function (): void {
			};
		}

		$port = isset($p['port']) ? (int) $p['port'] : (strtolower((string) ($p['scheme'] ?? '')) === 'https' ? 443 : 80);
		$v4 = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
		$ip = $v4[0] ?? $ips[0];
		$entry = sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
		$hook = static function ($handle) use ($entry): void {
			curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
		};
		add_action('http_api_curl', $hook, 99, 1);

		return static function () use ($hook): void {
			remove_action('http_api_curl', $hook, 99);
		};
	}

	public static function absolute(string $location, string $base): string
	{
		if (preg_match('#^https?://#i', $location)) {
			return $location;
		}

		$p = wp_parse_url($base);
		$origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');

		if (str_starts_with($location, '//')) {
			return ($p['scheme'] ?? 'https') . ':' . $location;
		}

		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}

		$dir = isset($p['path']) ? rtrim(dirname($p['path'] . 'x'), '/') : '';

		return $origin . $dir . '/' . $location;
	}
}
