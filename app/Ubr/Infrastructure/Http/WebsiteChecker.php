<?php

namespace App\Ubr\Infrastructure\Http;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;

/**
 * Sprawdza dostępność strony firmy (obecnie używane przez testy i jako narzędzie pomocnicze; weryfikacja
 * w kolejce korzysta z WebsiteInspector). Pobieranie przez UrlGuard + przypięcie IP — jak PageFetcher.
 * Sprawdza dostępność strony firmy i to, czy strona odpowiada nazwie firmy.
 * Brak odpowiedzi NIE powoduje odrzucenia firmy — zapisujemy tylko status.
 */
class WebsiteChecker
{
	public const USER_AGENT = 'UglyButRichBot/0.1 (+https://ohsofresh.pl; prospecting research)';

	/**
	 * @return array{status: string, http_code: ?int, final_url: ?string, final_domain: ?string, title: ?string, name_match: bool, error: ?string}
	 */
	public function check(string $url, string $companyName, int $timeout = 8): array
	{
		$result = ['status' => 'unreachable', 'http_code' => null, 'final_url' => null, 'final_domain' => null, 'title' => null, 'name_match' => false, 'error' => null];

		[$response, $finalUrl] = $this->get($url, $timeout);

		if (is_wp_error($response)) {
			// Czasem serwery odrzucają tylko http — spróbuj https.
			if (str_starts_with($url, 'http://')) {
				[$response, $finalUrl] = $this->get('https://' . substr($url, 7), $timeout);
			}

			if (is_wp_error($response)) {
				$result['error'] = mb_substr($response->get_error_message(), 0, 250);

				return $result;
			}
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$result['http_code'] = $code ?: null;

		$result['final_url'] = mb_substr($finalUrl, 0, 500);
		$result['final_domain'] = N::domain($finalUrl);

		if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
			$title = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8')) ?? '');
			$result['title'] = $title !== '' ? mb_substr($title, 0, 255) : null;
		}

		$result['name_match'] = $this->nameMatches($companyName, $body, $result['final_domain']);
		$originalDomain = N::domain($url);

		$result['status'] = match (true) {
			$code >= 200 && $code < 400 && $result['final_domain'] && $originalDomain && $result['final_domain'] !== $originalDomain => 'redirected',
			$code >= 200 && $code < 400 => 'reachable',
			$code >= 400 => 'http_error',
			default => 'unreachable',
		};

		return $result;
	}

	public function nameMatches(string $companyName, string $html, ?string $domain): bool
	{
		$tokens = N::nameTokens($companyName);

		if ($tokens === []) {
			return false;
		}

		$text = N::foldAscii(wp_strip_all_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? ''));
		$text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
		$domainText = preg_replace('/[^a-z0-9]+/', '', N::foldAscii((string) $domain)) ?? '';

		foreach ($tokens as $token) {
			if (mb_strlen($token) >= 4 && ($domainText !== '' && str_contains($domainText, $token))) {
				return true;
			}

			if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $text)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * GET z ręczną obsługą przekierowań — każdy krok przez UrlGuard (SSRF) z przypięciem IP (DNS rebinding).
	 *
	 * @return array{0: array<string, mixed>|\WP_Error, 1: string}
	 */
	private function get(string $url, int $timeout): array
	{
		$guard = app(UrlGuard::class);
		$current = $url;

		for ($hop = 0; $hop <= 4; $hop++) {
			$check = $guard->check($current);

			if (! $check['ok']) {
				return [new \WP_Error('ubr_ssrf', 'Adres zablokowany (ochrona SSRF): ' . $check['reason']), $current];
			}

			$unpin = PageFetcher::pin($current, $check['ips'] ?? []);
			$response = wp_remote_get($current, [
				'timeout' => $timeout,
				'redirection' => 0,
				'user-agent' => self::USER_AGENT,
				'limit_response_size' => 400 * 1024,
				'headers' => ['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'pl,en;q=0.5'],
			]);
			$unpin();

			if (is_wp_error($response)) {
				return [$response, $current];
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$location = (string) wp_remote_retrieve_header($response, 'location');

			if ($code >= 300 && $code < 400 && $location !== '') {
				$current = PageFetcher::absolute($location, $current);
				continue;
			}

			return [$response, $current];
		}

		return [new \WP_Error('ubr_redirects', 'Zbyt wiele przekierowań.'), $current];
	}
}
