<?php

namespace App\Ubr\Infrastructure\Http;

/**
 * Ochrona przed SSRF dla adresów stron pochodzących z zewnętrznych źródeł.
 *
 * Dozwolone: http/https, port 80/443, host rozwiązujący się WYŁĄCZNIE na publiczne adresy IP (A i AAAA).
 * Blokowane: localhost i nazwy wewnętrzne (.local, .internal, …), adresy prywatne, loopback, link-local
 * (w tym usługa metadata 169.254.169.254), CGNAT, multicast, zarezerwowane, IPv6 ULA/link-local/site-local, adresy IPv4 osadzone w IPv6 (każdy zapis: ::ffff:a.b.c.d,
 * ::ffff:7f00:1, NAT64, 6to4) — sprawdzane na bajtach adresu.
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
	 * @return array{ok: bool, reason: ?string, ips?: list<string>}
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

		// Zwracamy sprawdzone adresy, żeby pobranie połączyło się DOKŁADNIE z nimi (ochrona przed DNS rebinding).
		return ['ok' => true, 'reason' => null, 'ips' => $ips];
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

	/**
	 * Zewnętrzny link do wyświetlenia (href): wyłącznie http/https z hostem, bez danych logowania; inaczej null.
	 * Chroni przed javascript:/data: w danych pochodzących ze stron firm (JSON-LD sameAs, źródła) i z API.
	 */
	public static function httpUrl(mixed $url): ?string
	{
		if (! is_string($url) || ($url = trim($url)) === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
			return null;
		}

		$p = wp_parse_url($url);

		if (! is_array($p) || ! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host']) || ! empty($p['user']) || ! empty($p['pass'])) {
			return null;
		}

		return $url;
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
			// Analiza na bajtach (inet_pton), a nie na zapisie tekstowym: ten sam adres ma wiele zapisów
			// (np. ::ffff:127.0.0.1 i ::ffff:7f00:1), więc wyrażenia regularne na tekście dają się obejść.
			$bin = @inet_pton($ip);

			if ($bin === false || strlen($bin) !== 16) {
				return false;
			}

			$hex = bin2hex($bin);
			$v4 = fn (int $offset): string => (string) inet_ntop(substr($bin, $offset, 4));

			// ::/96 (w tym :: i ::1) oraz ::ffff:0:0/96 (IPv4 zmapowane) — decyduje osadzony adres IPv4.
			if (str_starts_with($hex, str_repeat('0', 24)) || str_starts_with($hex, str_repeat('0', 20) . 'ffff')) {
				return self::isPublicIp($v4(12));
			}

			// NAT64 (64:ff9b::/96) i 6to4 (2002::/16) — osadzony IPv4 też musi być publiczny.
			if (str_starts_with($hex, '0064ff9b' . str_repeat('0', 16))) {
				return self::isPublicIp($v4(12));
			}

			if (str_starts_with($hex, '2002')) {
				return self::isPublicIp($v4(2));
			}

			$first = hexdec(substr($hex, 0, 4));
			$blocked = ($first & 0xFE00) === 0xFC00 // fc00::/7 ULA
				|| ($first & 0xFFC0) === 0xFE80 // fe80::/10 link-local
				|| ($first & 0xFFC0) === 0xFEC0 // fec0::/10 site-local (przestarzałe)
				|| ($first & 0xFF00) === 0xFF00 // ff00::/8 multicast
				|| str_starts_with($hex, '20010db8') // dokumentacja
				|| str_starts_with($hex, '20010000') // Teredo (osadza adres IPv4)
				|| str_starts_with($hex, '0064ff9b0001') // NAT64 lokalny 64:ff9b:1::/48
				|| str_starts_with($hex, '0100000000000000'); // 100::/64 discard

			return ! $blocked;
		}

		return false;
	}
}
