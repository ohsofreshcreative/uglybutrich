<?php

namespace App\Ubr\Infrastructure\Http;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use Throwable;

/**
 * Prompt 10.4 — wspólna pamięć podręczna odpowiedzi HTTP stron firm (tabela `ubr_page_cache`).
 *
 * - Klucz: adres żądania (każdy krok przekierowania osobno) — dzięki temu WebsiteInspector (weryfikacja),
 *   PageFetcher (Website Analyzer, telefony, Decision Maker Finder, robots.txt) i ParallelFetcher korzystają
 *   z tych samych odpowiedzi.
 * - Czas życia: 2xx–4xx `ttl()` (domyślnie 1 h, UBR_PAGE_CACHE_TTL), 5xx 5 min, błąd połączenia (timeout, DNS,
 *   odmowa) — wiersz „origin” na 10 min: kolejne moduły nie czekają ponownie na niedostępny serwer.
 * - Tylko anonimowe żądania GET publicznych stron (bez ciasteczek / autoryzacji) — bez danych organizacji.
 *   Zapisujemy wyłącznie nagłówki techniczne z listy `HEADERS` (bez Set-Cookie).
 * - Odświeżenie: `fresh = true` w PageFetcher (np. „Odśwież numery”) pomija odczyt, a nowa odpowiedź nadpisuje wiersz.
 */
class PageCache
{
	public const HEADERS = ['content-type', 'location', 'x-frame-options', 'content-security-policy', 'strict-transport-security', 'server', 'x-powered-by', 'x-robots-tag', 'content-encoding', 'cache-control', 'last-modified'];

	public const MAX_STORE_BYTES = 2 * 1024 * 1024;

	public const ERROR_TTL = 600;

	public const SERVER_ERROR_TTL = 300;

	/** @var array<string, array<string, mixed>|false> pamięć w obrębie żądania PHP */
	private array $memo = [];

	public function __construct(private Db $db)
	{
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/http/page_cache_enabled', ! defined('UBR_PAGE_CACHE') || constant('UBR_PAGE_CACHE'));
	}

	public static function ttl(): int
	{
		return max(60, (int) apply_filters('ubr/http/page_cache_ttl', defined('UBR_PAGE_CACHE_TTL') ? (int) constant('UBR_PAGE_CACHE_TTL') : HOUR_IN_SECONDS));
	}

	public static function origin(string $url): string
	{
		$p = wp_parse_url($url);

		return strtolower(($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
	}

	/**
	 * Zapisana odpowiedź (świeża) albo null. `$minLimit` — limit rozmiaru, z jakim chce czytać wywołujący:
	 * odpowiedź ucięta na mniejszym limicie nie jest zwracana.
	 *
	 * @return array{code: ?int, headers: array<string, string>, body: string, location: string, error: ?string, ms: int}|null
	 */
	public function lookup(string $url, int $minLimit = 0): ?array
	{
		if (! self::enabled()) {
			return null;
		}

		$key = sha1('url:' . $url);

		if (! array_key_exists($key, $this->memo)) {
			try {
				$row = $this->db->row("SELECT * FROM {$this->t()} WHERE url_hash = %s AND expires_at > %s", $key, Db::now());
			} catch (Throwable) {
				$row = null; // tabela jeszcze nie istnieje (przed migracją) — bez cache
			}

			$this->memo[$key] = $row ?: false;
		}

		$row = $this->memo[$key];

		if (! $row) {
			return null;
		}

		$truncated = (int) $row['byte_limit'] > 0 && (int) $row['body_bytes'] >= (int) $row['byte_limit'];

		if ($truncated && $minLimit > (int) $row['byte_limit']) {
			return null;
		}

		$body = '';

		if ($row['body'] !== null && $row['body'] !== '') {
			$raw = base64_decode((string) $row['body'], true);
			$body = $raw !== false ? (string) @gzuncompress($raw) : '';
		}

		return [
			'code' => $row['http_status'] !== null ? (int) $row['http_status'] : null,
			'headers' => (array) (json_decode((string) $row['headers'], true) ?: []),
			'body' => $body,
			'location' => (string) $row['location'],
			'error' => $row['fetch_error'],
			'ms' => (int) $row['fetch_ms'],
		];
	}

	/** Komunikat błędu połączenia z serwerem (origin), jeśli był niedawno — bez ponownego czekania. */
	public function downFor(string $url): ?string
	{
		if (! self::enabled()) {
			return null;
		}

		$origin = self::origin($url);
		$key = sha1('origin:' . $origin);

		if (! array_key_exists($key, $this->memo)) {
			try {
				$row = $this->db->row("SELECT fetch_error FROM {$this->t()} WHERE url_hash = %s AND expires_at > %s", $key, Db::now());
			} catch (Throwable) {
				$row = null;
			}

			$this->memo[$key] = $row ?: false;
		}

		return $this->memo[$key] ? (string) $this->memo[$key]['fetch_error'] : null;
	}

	/**
	 * @param array{code: ?int, headers?: array<string, string>, body?: string, location?: string, error?: ?string, ms?: int} $response
	 */
	public function store(string $url, array $response, int $byteLimit = 0): void
	{
		if (! self::enabled()) {
			return;
		}

		$now = time();

		try {
			if ($response['code'] === null) {
				// Błąd połączenia (timeout, DNS, odmowa, SSL) — zapamiętany dla serwera, nie dla adresu.
				$origin = self::origin($url);
				$this->upsert(sha1('origin:' . $origin), $origin, $origin, null, null, null, null, 0, 0, mb_substr((string) ($response['error'] ?? 'błąd połączenia'), 0, 250), (int) ($response['ms'] ?? 0), $now + self::ERROR_TTL);
				unset($this->memo[sha1('origin:' . $origin)]);

				return;
			}

			$body = (string) ($response['body'] ?? '');
			$bytes = strlen($body);

			if ($bytes > self::MAX_STORE_BYTES) {
				return; // za duża odpowiedź — bez zapisu (moduły pobiorą ją samodzielnie)
			}

			$stored = $bytes > 0 ? base64_encode((string) gzcompress($body, 6)) : null;
			$headers = array_intersect_key((array) ($response['headers'] ?? []), array_flip(self::HEADERS));
			$ttl = (int) $response['code'] >= 500 ? self::SERVER_ERROR_TTL : self::ttl();

			$this->upsert(sha1('url:' . $url), mb_substr($url, 0, 1000), self::origin($url), (int) $response['code'], mb_substr((string) ($response['location'] ?? ''), 0, 1000) ?: null, wp_json_encode($headers), $stored, $bytes, $byteLimit, $response['error'] ?? null, (int) ($response['ms'] ?? 0), $now + $ttl);
			unset($this->memo[sha1('url:' . $url)]);
		} catch (Throwable) {
			// Cache nigdy nie psuje pobierania (np. przed migracją M013).
		}
	}

	/**
	 * Treść stron z cache (np. strony pobrane przy weryfikacji → ekstrakcja telefonów bez nowych zapytań).
	 * Podąża za zapisanymi przekierowaniami.
	 *
	 * @return array{code: ?int, body: string, final_url: string}|null
	 */
	public function follow(string $url, int $maxHops = 5): ?array
	{
		$current = $url;

		for ($hop = 0; $hop <= $maxHops; $hop++) {
			$hit = $this->lookup($current);

			if (! $hit || $hit['code'] === null) {
				return null;
			}

			if ($hit['code'] >= 300 && $hit['code'] < 400 && $hit['location'] !== '') {
				$current = PageFetcher::absolute($hit['location'], $current);
				continue;
			}

			return ['code' => $hit['code'], 'body' => $hit['body'], 'final_url' => $current];
		}

		return null;
	}

	public function purgeExpired(int $limit = 500): int
	{
		try {
			return $this->db->execute("DELETE FROM {$this->t()} WHERE expires_at < %s LIMIT %d", Db::now(), max(1, $limit));
		} catch (Throwable) {
			return 0;
		}
	}

	/** Statystyki (raport / walidacja). @return array<string, int> */
	public function stats(): array
	{
		try {
			$row = $this->db->row("SELECT COUNT(*) AS n, SUM(expires_at > %s) AS fresh, SUM(http_status IS NULL) AS down, COALESCE(SUM(LENGTH(body)), 0) AS bytes FROM {$this->t()}", Db::now());
		} catch (Throwable) {
			return ['rows' => 0, 'fresh' => 0, 'down' => 0, 'bytes' => 0];
		}

		return ['rows' => (int) $row['n'], 'fresh' => (int) $row['fresh'], 'down' => (int) $row['down'], 'bytes' => (int) $row['bytes']];
	}

	public function forget(): void
	{
		$this->memo = [];
	}

	private function upsert(string $hash, string $url, string $origin, ?int $status, ?string $location, ?string $headers, ?string $body, int $bytes, int $limit, ?string $error, int $ms, int $expires): void
	{
		$now = Db::now();

		$this->db->execute(
			"INSERT INTO {$this->t()} (url_hash, url, origin, http_status, location, headers, body, body_bytes, byte_limit, fetch_error, fetch_ms, fetched_at, expires_at)
			VALUES (%s, %s, %s, " . ($status === null ? 'NULL' : '%d') . ', ' . ($location === null ? 'NULL' : '%s') . ', ' . ($headers === null ? 'NULL' : '%s') . ', ' . ($body === null ? 'NULL' : '%s') . ', %d, %d, ' . ($error === null ? 'NULL' : '%s') . ", %d, %s, %s)
			ON DUPLICATE KEY UPDATE http_status = VALUES(http_status), location = VALUES(location), headers = VALUES(headers), body = VALUES(body),
				body_bytes = VALUES(body_bytes), byte_limit = VALUES(byte_limit), fetch_error = VALUES(fetch_error), fetch_ms = VALUES(fetch_ms),
				fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)",
			...array_values(array_filter([$hash, $url, $origin, $status, $location, $headers, $body, $bytes, $limit, $error, $ms, $now, gmdate('Y-m-d H:i:s', $expires)], fn ($v) => $v !== null))
		);
	}

	private function t(): string
	{
		return $this->db->table(Tables::PAGE_CACHE);
	}
}
