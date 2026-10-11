<?php

namespace App\Ubr\Infrastructure\Http;

use Closure;

/**
 * Prompt 10.4 — równoległe (nieblokujące) pobieranie stron firm jednym procesem PHP (curl_multi).
 *
 * Po co: weryfikacja przetwarza firmy po kolei, bo rejestry (KRS, biała lista VAT) mają limity częstotliwości.
 * Strony WWW kilku firm mogą jednak pobierać się w tle — także w czasie przerw wymuszanych przez limity rejestrów
 * (`ubr/http/idle`) — więc jedna wolna lub niedostępna strona nie blokuje pozostałych firm.
 * LocalWP ma tylko 2 procesy PHP-FPM, a hosting współdzielony ogranicza procesy — dlatego jeden proces z wieloma
 * połączeniami zamiast wielu workerów.
 *
 * Zasady:
 * - Każdy adres (również każdy krok przekierowania) przez UrlGuard + przypięcie IP (CURLOPT_RESOLVE) — jak PageFetcher.
 * - Odpowiedzi trafiają do wspólnego `PageCache`; moduły (WebsiteInspector itd.) czytają je jak zwykłe pobranie.
 *   Adres już w cache albo serwer oznaczony jako niedostępny — bez ponownego zapytania.
 * - Limit równoległych połączeń (UBR_PREFETCH_CONCURRENCY, domyślnie 6, maks. 12), timeout jak w module docelowym,
 *   limit rozmiaru odpowiedzi, bez ciasteczek. Bez ponawiania (nie zwiększamy liczby zapytań).
 * - Grupy: firma = grupa; `onDone` może dodać kolejny adres tej grupy (np. podstrona, http zamiast https).
 * - Tryb symulacji (`ubr/http/parallel_sim`) — testy i pomiary na mockach (bez sieci), z opóźnieniami.
 */
class ParallelFetcher
{
	public const USER_AGENT = WebsiteInspector::USER_AGENT;

	/** @var \CurlMultiHandle|resource|null */
	private $multi = null;

	/** @var array<int, array<string, mixed>> uchwyt cURL (id) → zadanie */
	private array $active = [];

	/** @var list<array<string, mixed>> zadania czekające na wolne połączenie */
	private array $waiting = [];

	/** @var array<string, int> grupa → liczba niezakończonych zadań */
	private array $groups = [];

	/** @var array<string, true> adresy już zlecone w tym procesie */
	private array $seen = [];

	/** @var list<array<string, mixed>> symulacja: zadania z czasem zakończenia */
	private array $simulated = [];

	private ?Closure $sim = null;

	private int $requests = 0;

	public function __construct(private UrlGuard $guard, private PageCache $cache)
	{
		$sim = apply_filters('ubr/http/parallel_sim', null);
		$this->sim = is_callable($sim) ? Closure::fromCallable($sim) : null;
	}

	public static function concurrency(): int
	{
		return max(1, min(12, (int) apply_filters('ubr/http/prefetch_concurrency', defined('UBR_PREFETCH_CONCURRENCY') ? (int) constant('UBR_PREFETCH_CONCURRENCY') : 6)));
	}

	public static function available(): bool
	{
		return function_exists('curl_multi_init') && PageCache::enabled();
	}

	/**
	 * Zlecenie pobrania (nieblokujące). `$onDone(array $response, string $url)` — po zakończeniu (także z cache).
	 */
	public function add(string $url, string $group, int $timeout = 7, int $byteLimit = WebsiteInspector::MAX_BYTES, ?Closure $onDone = null, int $hop = 0): void
	{
		if (isset($this->seen[$url]) || ! (self::available() || $this->sim)) {
			return;
		}

		$this->seen[$url] = true;
		$this->groups[$group] = ($this->groups[$group] ?? 0) + 1;
		$job = ['url' => $url, 'group' => $group, 'timeout' => $timeout, 'limit' => $byteLimit, 'done' => $onDone, 'hop' => $hop];

		// Już w cache / serwer niedostępny — bez zapytania, od razu dalej (np. kolejna podstrona).
		$hit = $this->cache->lookup($url, $byteLimit);
		$down = $hit ? null : $this->cache->downFor($url);

		if ($hit || $down !== null) {
			$this->finish($job, $hit ?? ['code' => null, 'headers' => [], 'body' => '', 'location' => '', 'error' => $down, 'ms' => 0], false);

			return;
		}

		$check = $this->guard->check($url);

		if (! $check['ok']) {
			$this->finish($job, ['code' => null, 'headers' => [], 'body' => '', 'location' => '', 'error' => 'Adres zablokowany (ochrona SSRF): ' . $check['reason'], 'ms' => 0], false);

			return;
		}

		$job['ips'] = $check['ips'] ?? [];
		$this->waiting[] = $job;
		$this->start();
	}

	/** Czy grupa ma jeszcze niezakończone pobrania. */
	public function busy(string $group): bool
	{
		return ($this->groups[$group] ?? 0) > 0;
	}

	public function pending(): int
	{
		return count($this->active) + count($this->waiting) + count($this->simulated);
	}

	public function requests(): int
	{
		return $this->requests;
	}

	/**
	 * Prowadzi transfery przez maks. `$seconds` (wraca wcześniej, gdy nie ma aktywnych transferów).
	 */
	public function pump(float $seconds): void
	{
		$end = microtime(true) + max(0.0, $seconds);

		do {
			if ($this->sim) {
				$this->pumpSimulated($end);
			} else {
				$this->pumpCurl(min(0.25, max(0.0, $end - microtime(true))));
			}
		} while ($this->pending() > 0 && microtime(true) < $end);
	}

	/**
	 * Czeka na zakończenie pobrań grupy (z limitem czasu). Inne grupy pobierają się w tym czasie równolegle.
	 */
	public function wait(string $group, float $maxSeconds): void
	{
		$end = microtime(true) + $maxSeconds;

		while ($this->busy($group) && microtime(true) < $end) {
			$this->pump(min(0.05, $end - microtime(true)));
		}
	}

	/**
	 * Przerwanie niezakończonych transferów (koniec kroku workera).
	 */
	public function abort(): void
	{
		foreach ($this->active as $id => $job) {
			curl_multi_remove_handle($this->multi, $job['handle']);
			curl_close($job['handle']);
		}

		$this->active = [];
		$this->waiting = [];
		$this->simulated = [];
		$this->groups = [];
	}

	private function start(): void
	{
		if ($this->sim) {
			while ($this->waiting) {
				$job = array_shift($this->waiting);
				$this->requests++;
				[$response, $latency] = ($this->sim)($job['url'], ['timeout' => $job['timeout'], 'limit_response_size' => $job['limit'], 'redirection' => 0, 'user-agent' => self::USER_AGENT]);
				$job['response'] = PageFetcher::normalize($response, (int) round($latency * 1000));
				$job['at'] = microtime(true) + (float) $latency;
				$this->simulated[] = $job;
			}

			return;
		}

		$this->multi ??= curl_multi_init();

		while ($this->waiting && count($this->active) < self::concurrency()) {
			$job = array_shift($this->waiting);
			$job['body'] = '';
			$job['headers'] = [];
			$job['started'] = microtime(true);
			$handle = curl_init($job['url']);
			$limit = (int) $job['limit'];
			$jobRef = &$job;
			curl_setopt_array($handle, [
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT => max(1, (int) $job['timeout']),
				CURLOPT_CONNECTTIMEOUT => max(1, min(5, (int) $job['timeout'])),
				CURLOPT_USERAGENT => self::USER_AGENT,
				CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: pl,en;q=0.5'],
				CURLOPT_ENCODING => '',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CAINFO => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$jobRef): int {
					if (str_contains($line, ':')) {
						[$name, $value] = explode(':', $line, 2);
						$jobRef['headers'][strtolower(trim($name))] = trim($value);
					}

					return strlen($line);
				},
				CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$jobRef, $limit): int {
					$room = $limit - strlen($jobRef['body']);

					if ($room <= 0) {
						return 0; // limit rozmiaru — przerwanie transferu (treść do limitu zostaje)
					}

					$jobRef['body'] .= substr($chunk, 0, $room);

					return strlen($chunk) <= $room ? strlen($chunk) : 0;
				},
			]);
			$entry = PageFetcher::resolveEntry($job['url'], $job['ips']);

			if ($entry !== null) {
				curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
			}

			$job['handle'] = $handle;
			curl_multi_add_handle($this->multi, $handle);
			$this->active[(int) spl_object_id($handle)] = &$job;
			unset($job, $jobRef);
			$this->requests++;
		}
	}

	private function pumpCurl(float $timeout): void
	{
		if (! $this->multi || ! $this->active) {
			if ($timeout > 0) {
				usleep((int) ($timeout * 1e6));
			}

			return;
		}

		do {
			$status = curl_multi_exec($this->multi, $running);
		} while ($status === CURLM_CALL_MULTI_PERFORM);

		while ($info = curl_multi_info_read($this->multi)) {
			$handle = $info['handle'];
			$id = (int) spl_object_id($handle);
			$job = $this->active[$id] ?? null;
			unset($this->active[$id]);
			curl_multi_remove_handle($this->multi, $handle);

			if (! $job) {
				curl_close($handle);
				continue;
			}

			$code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			$ms = (int) round((microtime(true) - $job['started']) * 1000);
			$truncated = $info['result'] === CURLE_WRITE_ERROR && strlen($job['body']) >= (int) $job['limit'];

			if (($info['result'] === CURLE_OK || $truncated) && $code > 0) {
				$response = ['code' => $code, 'headers' => $job['headers'], 'body' => $job['body'], 'location' => (string) ($job['headers']['location'] ?? ''), 'error' => null, 'ms' => $ms];
			} else {
				$response = ['code' => null, 'headers' => [], 'body' => '', 'location' => '', 'error' => mb_substr('cURL error ' . $info['result'] . ': ' . curl_error($handle), 0, 250), 'ms' => $ms];
			}

			curl_close($handle);
			$this->finish($job, $response, true);
		}

		$this->start();

		if ($this->active && $timeout > 0) {
			curl_multi_select($this->multi, $timeout);
		}
	}

	private function pumpSimulated(float $end): void
	{
		if (! $this->simulated) {
			return;
		}

		usort($this->simulated, fn ($a, $b) => $a['at'] <=> $b['at']);
		$next = $this->simulated[0]['at'];
		$sleep = min($next, $end) - microtime(true);

		if ($sleep > 0) {
			usleep((int) ($sleep * 1e6));
		}

		while ($this->simulated && $this->simulated[0]['at'] <= microtime(true)) {
			$job = array_shift($this->simulated);
			$this->finish($job, $job['response'], true);
		}
	}

	/**
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $response
	 */
	private function finish(array $job, array $response, bool $store): void
	{
		if ($store) {
			$this->cache->store($job['url'], $response, (int) $job['limit']);
		}

		$code = $response['code'] ?? null;

		// Przekierowanie: kolejny krok (z kontrolą UrlGuard w add()), maks. 5 — jak PageFetcher / WebsiteInspector.
		if ($code !== null && $code >= 300 && $code < 400 && ($response['location'] ?? '') !== '' && $job['hop'] < 5) {
			$this->add(PageFetcher::absolute((string) $response['location'], $job['url']), $job['group'], (int) $job['timeout'], (int) $job['limit'], $job['done'], $job['hop'] + 1);
		} elseif ($job['done']) {
			($job['done'])($response, $job['url']);
		}

		$this->groups[$job['group']] = max(0, ($this->groups[$job['group']] ?? 1) - 1);
	}
}
