<?php

namespace App\Ubr\Infrastructure\Http;

/**
 * Pobieranie HTML strony do analizy statycznej (SEO, treść, dostępność, technika) — bez przeglądarki.
 *
 * Przekierowania obsługujemy ręcznie (maks. 5), sprawdzając KAŻDY adres przez UrlGuard (SSRF) — nie ufamy
 * samej walidacji początkowego URL. Limit rozmiaru odpowiedzi 2 MB, timeout 12 s, bez ciasteczek i bez JS.
 * Prompt 10.4: kroki przez wspólną pamięć podręczną `PageCache` (`fresh` = bez odczytu cache, np. ręczne odświeżenie).
 */
class PageFetcher
{
	public const USER_AGENT = 'Mozilla/5.0 (compatible; UglyButRichBot/0.3; +https://ohsofresh.pl) website-analysis';

	public const MAX_BYTES = 2 * 1024 * 1024;

	public function __construct(private UrlGuard $guard)
	{
	}

	/**
	 * @return array{ok: bool, url: string, final_url: ?string, status: ?int, redirects: list<string>, headers: array<string, string>, body: string, bytes: int, ms: int, error: ?string, blocked: ?string, certificate_error: bool, cached: bool}
	 */
	public function fetch(string $url, int $timeout = 12, bool $fresh = false): array
	{
		$result = ['ok' => false, 'url' => $url, 'final_url' => null, 'status' => null, 'redirects' => [], 'headers' => [], 'body' => '', 'bytes' => 0, 'ms' => 0, 'error' => null, 'blocked' => null, 'certificate_error' => false, 'cached' => false];
		$current = $url;
		$started = microtime(true);
		$allCached = true;
		$cachedMs = 0; // czas pobrania odpowiedzi z cache (oryginalny), żeby pomiar czasu strony był rzetelny

		for ($hop = 0; $hop <= 5; $hop++) {
			$check = $this->guard->check($current);

			if (! $check['ok']) {
				$result['blocked'] = $check['reason'];
				$result['error'] = 'Adres zablokowany (ochrona SSRF): ' . $check['reason'];

				return $result;
			}

			$response = self::hop($current, $check['ips'] ?? [], [
				'timeout' => $timeout,
				'user-agent' => self::USER_AGENT,
				'headers' => ['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'pl,en;q=0.5'],
				'cookies' => [],
				'sslverify' => true,
			], self::MAX_BYTES, $fresh);
			$allCached = $allCached && $response['cached'];
			$cachedMs += $response['cached'] ? (int) $response['ms'] : 0;

			if ($response['code'] === null) {
				$message = (string) $response['error'];
				$result['error'] = mb_substr($message, 0, 250);
				$result['certificate_error'] = (bool) preg_match('/(ssl|certificate|cert)/i', $message);
				$result['ms'] = (int) round((microtime(true) - $started) * 1000) + $cachedMs;
				$result['cached'] = $allCached;

				return $result;
			}

			$code = (int) $response['code'];

			if ($code >= 300 && $code < 400 && $response['location'] !== '') {
				$result['redirects'][] = $current;
				$current = self::absolute($response['location'], $current);
				continue;
			}

			$body = $response['body'];
			$result = array_merge($result, [
				'ok' => $code >= 200 && $code < 400,
				'final_url' => $current,
				'status' => $code,
				'headers' => array_intersect_key($response['headers'], array_flip(['content-type', 'x-frame-options', 'content-security-policy', 'strict-transport-security', 'server', 'x-powered-by', 'x-robots-tag', 'content-encoding', 'cache-control', 'last-modified'])),
				'body' => $body,
				'bytes' => strlen($body),
				'ms' => (int) round((microtime(true) - $started) * 1000) + $cachedMs,
				'error' => $code >= 400 ? 'HTTP ' . $code : null,
				'cached' => $allCached,
			]);

			return $result;
		}

		$result['error'] = 'Zbyt wiele przekierowań.';

		return $result;
	}

	/**
	 * Prompt 10.4 — jeden krok żądania GET (bez podążania za przekierowaniem) przez wspólną pamięć podręczną
	 * (`PageCache`): najpierw zapisana odpowiedź / informacja „serwer nie odpowiada”, potem żądanie z przypięciem IP.
	 * Wywołujący MUSI wcześniej sprawdzić adres przez UrlGuard (każdy krok przekierowania).
	 *
	 * @param list<string> $ips
	 * @param array<string, mixed> $args
	 * @return array{code: ?int, headers: array<string, string>, body: string, location: string, error: ?string, ms: int, cached: bool}
	 */
	public static function hop(string $url, array $ips, array $args, int $byteLimit, bool $fresh = false): array
	{
		$cache = app(PageCache::class);

		if (! $fresh) {
			$hit = $cache->lookup($url, $byteLimit);

			if ($hit) {
				return $hit + ['cached' => true];
			}

			$down = $cache->downFor($url);

			if ($down !== null) {
				return ['code' => null, 'headers' => [], 'body' => '', 'location' => '', 'error' => $down . ' (zapamiętane — serwer nie odpowiadał przed chwilą)', 'ms' => 0, 'cached' => true];
			}
		}

		$started = microtime(true);
		$unpin = self::pin($url, $ips);
		$response = wp_remote_get($url, ['redirection' => 0, 'limit_response_size' => $byteLimit] + $args);
		$unpin();
		$out = self::normalize($response, (int) round((microtime(true) - $started) * 1000));
		$cache->store($url, $out, $byteLimit);

		return $out + ['cached' => false];
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response
	 * @return array{code: ?int, headers: array<string, string>, body: string, location: string, error: ?string, ms: int}
	 */
	public static function normalize($response, int $ms): array
	{
		if (is_wp_error($response)) {
			return ['code' => null, 'headers' => [], 'body' => '', 'location' => '', 'error' => mb_substr($response->get_error_message(), 0, 250), 'ms' => $ms];
		}

		$headers = [];
		$raw = wp_remote_retrieve_headers($response);
		$raw = is_object($raw) && method_exists($raw, 'getAll') ? $raw->getAll() : (array) $raw;

		foreach ($raw as $name => $value) {
			$headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		return ['code' => $code ?: null, 'headers' => $headers, 'body' => (string) wp_remote_retrieve_body($response), 'location' => (string) ($headers['location'] ?? ''), 'error' => $code ? null : 'Brak kodu odpowiedzi.', 'ms' => $ms];
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
		$entry = self::resolveEntry($url, $ips);

		if ($entry === null) {
			return static function (): void {
			};
		}

		$hook = static function ($handle) use ($entry): void {
			curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
		};
		add_action('http_api_curl', $hook, 99, 1);

		return static function () use ($hook): void {
			remove_action('http_api_curl', $hook, 99);
		};
	}

	/**
	 * Wpis CURLOPT_RESOLVE „host:port:ip” dla adresów sprawdzonych przez UrlGuard (null = bez przypięcia).
	 *
	 * @param list<string> $ips
	 */
	public static function resolveEntry(string $url, array $ips): ?string
	{
		$p = wp_parse_url($url);
		$host = strtolower((string) ($p['host'] ?? ''));

		if ($ips === [] || $host === '' || ! defined('CURLOPT_RESOLVE') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
			return null;
		}

		$port = isset($p['port']) ? (int) $p['port'] : (strtolower((string) ($p['scheme'] ?? '')) === 'https' ? 443 : 80);
		$v4 = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
		$ip = $v4[0] ?? $ips[0];

		return sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
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
