<?php

namespace App\Ubr\Infrastructure\Http;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;

/**
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

		$response = wp_safe_remote_get($url, [
			'timeout' => $timeout,
			'redirection' => 4,
			'user-agent' => self::USER_AGENT,
			'limit_response_size' => 400 * 1024,
			'headers' => ['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'pl,en;q=0.5'],
		]);

		if (is_wp_error($response)) {
			// Czasem serwery odrzucają tylko http — spróbuj https.
			if (str_starts_with($url, 'http://')) {
				$https = 'https://' . substr($url, 7);
				$response = wp_safe_remote_get($https, [
					'timeout' => $timeout,
					'redirection' => 4,
					'user-agent' => self::USER_AGENT,
					'limit_response_size' => 400 * 1024,
				]);
			}

			if (is_wp_error($response)) {
				$result['error'] = mb_substr($response->get_error_message(), 0, 250);

				return $result;
			}
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$result['http_code'] = $code ?: null;

		$finalUrl = $this->finalUrl($response) ?? $url;
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
	 * @param array<string, mixed> $response
	 */
	private function finalUrl(array $response): ?string
	{
		$http = $response['http_response'] ?? null;

		if ($http instanceof \WP_HTTP_Requests_Response) {
			$raw = $http->get_response_object();

			if (isset($raw->url) && is_string($raw->url)) {
				return $raw->url;
			}
		}

		return null;
	}
}
