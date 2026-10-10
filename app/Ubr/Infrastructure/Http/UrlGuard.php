<?php

namespace App\Ubr\Infrastructure\Http;

/**
 * Ochrona przed SSRF dla adresów stron pochodzących z zewnętrznych źródeł.
 *
 * Dozwolone: http/https, port 80/443, host rozwiązujący się WYŁĄCZNIE na publiczne adresy IP (A i AAAA).
 * Blokowane: localhost i nazwy wewnętrzne (.local, .internal, …), adresy prywatne, loopback, link-local
 * (w tym usługa metadata 169.254.169.254), CGNAT, multicast, zarezerwowane, IPv6 ULA/link-local, adresy IPv4 w IPv6.
 *
 * Sprawdzenie wykonujemy przed każdym pobraniem (także po przekierowaniach — patrz PageFetcher)
 * i niezależnie w komponencie przeglądarki (każde żądanie, każdy krok przekierowania).
 */
class UrlGuard
{
	private const PRIVATE_V4 = [
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
		'192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
	];

	/** @var array<string, array{ok: bool, reason: ?string}> */
	private array $cache = [];

	/**
	 * @return array{ok: bool, reason: ?string}
	 */
	public function check(string $url): array
	{
		$parts = wp_parse_url($url);

		if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
			return ['ok' => false, 'reason' => 'nieprawidłowy adres URL'];
		}

		$scheme = strtolower($parts['scheme']);

		if (! in_array($scheme, ['http', 'https'], true)) {
			return ['ok' => false, 'reason' => 'niedozwolony protokół ' . $scheme];
		}

		$port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

		if (! in_array($port, [80, 443], true)) {
			return ['ok' => false, 'reason' => 'niedozwolony port ' . $port];
		}

		if (! empty($parts['user']) || ! empty($parts['pass'])) {
			return ['ok' => false, 'reason' => 'dane logowania w adresie są niedozwolone'];
		}

		$host = strtolower(trim($parts['host'], '[]'));

		return $this->cache[$host] ??= $this->checkHost($host);
	}

	/**
	 * @return array{ok: bool, reason: ?string}
	 */
	public function checkHost(string $host): array
	{
		if ($host === 'localhost' || preg_match('/\.(localhost|local|internal|lan|home|corp|intranet|test|invalid)$/', $host)) {
			return ['ok' => false, 'reason' => 'host wewnętrzny ' . $host];
		}

		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return self::isPublicIp($host) ? ['ok' => true, 'reason' => null] : ['ok' => false, 'reason' => 'prywatny adres IP ' . $host];
		}

		if (! str_contains($host, '.')) {
			return ['ok' => false, 'reason' => 'host bez domeny ' . $host];
		}

		$ips = $this->resolve($host);

		if ($ips === []) {
			return ['ok' => false, 'reason' => 'brak rekordu DNS dla ' . $host];
		}

		foreach ($ips as $ip) {
			if (! self::isPublicIp($ip)) {
				return ['ok' => false, 'reason' => sprintf('%s wskazuje na adres niepubliczny %s', $host, $ip)];
			}
		}

		return ['ok' => true, 'reason' => null];
	}

	/**
	 * @return list<string>
	 */
	protected function resolve(string $host): array
	{
		$filtered = apply_filters('ubr/url_guard/resolve', null, $host);

		if (is_array($filtered)) {
			return $filtered;
		}

		$ips = gethostbynamel($host) ?: [];

		if (function_exists('dns_get_record')) {
			foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
				if (! empty($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return array_values(array_unique($ips));
	}

	public static function isPublicIp(string $ip): bool
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$long = ip2long($ip);

			foreach (self::PRIVATE_V4 as $cidr) {
				[$base, $bits] = explode('/', $cidr);
				$mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

				if (($long & $mask) === (ip2long($base) & $mask)) {
					return false;
				}
			}

			return true;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$v = strtolower($ip);

			if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $v, $m)) {
				return self::isPublicIp($m[1]);
			}

			if (in_array($v, ['::', '::1'], true) || preg_match('/^(f[cd]|fe[89ab]|ff)/', $v) || preg_match('/^(2001:db8|64:ff9b|100::)/', $v)) {
				return false;
			}

			return true;
		}

		return false;
	}
}
