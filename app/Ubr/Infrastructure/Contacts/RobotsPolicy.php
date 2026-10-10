<?php

namespace App\Ubr\Infrastructure\Contacts;

use App\Ubr\Infrastructure\Http\PageFetcher;

/**
 * robots.txt: reguły dla `*` i naszego User-Agenta (UglyButRichBot). Najdłuższe dopasowanie wygrywa (Allow vs Disallow).
 * Brak pliku / błąd = brak ograniczeń (standard REP). Wynik w cache 1 dzień na host.
 */
class RobotsPolicy
{
	public const AGENT = 'uglybutrichbot';

	public function __construct(private PageFetcher $fetcher)
	{
	}

	/**
	 * @return list<array{type: string, path: string}>
	 */
	public function rulesFor(string $url, int &$requests): array
	{
		$scheme = (string) parse_url($url, PHP_URL_SCHEME) ?: 'https';
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		if ($host === '') {
			return [];
		}

		$cacheKey = 'ubr_robots_' . (string) apply_filters('ubr/registry/namespace', '') . md5($scheme . '://' . $host);
		$cached = get_transient($cacheKey);

		if (is_array($cached)) {
			return $cached;
		}

		$res = $this->fetcher->fetch($scheme . '://' . $host . '/robots.txt', 6);
		$requests++;
		$rules = $res['ok'] && $res['status'] === 200 ? self::parse($res['body']) : [];
		set_transient($cacheKey, $rules, DAY_IN_SECONDS);

		return $rules;
	}

	/**
	 * @return list<array{type: string, path: string}>
	 */
	public static function parse(string $body): array
	{
		$groups = [];
		$agents = [];
		$lastWasAgent = false;

		foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
			$line = trim((string) preg_replace('/#.*$/', '', $line));

			if ($line === '' || ! str_contains($line, ':')) {
				continue;
			}

			[$field, $value] = array_map('trim', explode(':', $line, 2));
			$field = strtolower($field);

			if ($field === 'user-agent') {
				if (! $lastWasAgent) {
					$agents = [];
				}

				$agents[] = strtolower($value);
				$lastWasAgent = true;
				continue;
			}

			$lastWasAgent = false;

			if (in_array($field, ['allow', 'disallow'], true)) {
				foreach ($agents as $agent) {
					$groups[$agent][] = ['type' => $field, 'path' => $value];
				}
			}
		}

		// Grupa dedykowana naszemu botowi zastępuje grupę `*`.
		$rules = $groups[self::AGENT] ?? ($groups['*'] ?? []);

		return array_values(array_filter($rules, fn ($r) => $r['type'] === 'allow' || $r['path'] !== ''));
	}

	/**
	 * @param list<array{type: string, path: string}> $rules
	 */
	public function allowed(array $rules, string $path): bool
	{
		$best = null;
		$bestLen = -1;

		foreach ($rules as $rule) {
			$pattern = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule['path'], '#')) . '#';

			if ($rule['path'] !== '' && preg_match($pattern, $path) && strlen($rule['path']) > $bestLen) {
				$best = $rule['type'];
				$bestLen = strlen($rule['path']);
			}
		}

		return $best !== 'disallow';
	}
}
