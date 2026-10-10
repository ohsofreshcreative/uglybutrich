<?php

namespace App\Ubr\Infrastructure\Ai;

use App\Ubr\Domain\Analysis\Vision\VisionSchema;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;

/**
 * AI Vision — analiza screenshotów przez model z obsługą obrazów. PRZYGOTOWANE, DOMYŚLNIE WYŁĄCZONE.
 *
 * Włączenie wymaga ŚWIADOMEJ konfiguracji w wp-config.php (płatne API — nie uruchamiamy bez zgody właściciela):
 *   define('UBR_AI_VISION_ENABLED', true);
 *   define('UBR_AI_VISION_API_KEY', '…');          // klucz tylko w wp-config / env, nigdy w repo ani w odpowiedziach API
 *   define('UBR_AI_VISION_MODEL', 'claude-sonnet-5-5');
 *   define('UBR_AI_VISION_MONTHLY_LIMIT', 100);   // twardy limit analiz w miesiącu (domyślnie 0 = nic)
 *
 * Kontrola kosztów: limit miesięczny, cache wyniku po hashu screenshotów (ta sama strona = brak ponownego zapytania),
 * log w ubr_api_requests (billable = 1), wersja promptu i model zapisywane przy wyniku. Odpowiedź walidowana (VisionSchema).
 */
class VisionService
{
	public const PROVIDER = 'anthropic_vision';

	public function __construct(private ApiRequestRepository $requests)
	{
	}

	public static function apiKey(): ?string
	{
		$key = defined('UBR_AI_VISION_API_KEY') ? (string) UBR_AI_VISION_API_KEY : (string) getenv('UBR_AI_VISION_API_KEY');

		return trim($key) !== '' ? trim($key) : null;
	}

	public static function model(): string
	{
		return defined('UBR_AI_VISION_MODEL') && UBR_AI_VISION_MODEL ? (string) UBR_AI_VISION_MODEL : 'claude-sonnet-5-5';
	}

	public static function monthlyLimit(): int
	{
		return (int) apply_filters('ubr/ai_vision/monthly_limit', defined('UBR_AI_VISION_MONTHLY_LIMIT') ? (int) UBR_AI_VISION_MONTHLY_LIMIT : 0);
	}

	public static function enabled(): bool
	{
		return defined('UBR_AI_VISION_ENABLED') && UBR_AI_VISION_ENABLED && self::apiKey() !== null && self::monthlyLimit() > 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array
	{
		return [
			'enabled' => self::enabled(),
			'configured' => self::apiKey() !== null,
			'model' => self::model(),
			'prompt_version' => VisionSchema::PROMPT_VERSION,
			'monthly_limit' => self::monthlyLimit(),
			'used_this_month' => $this->usedThisMonth(),
			'categories' => VisionSchema::CATEGORIES,
			'note' => self::enabled() ? 'Włączone — każda analiza zużywa płatne zapytanie (w limicie miesięcznym).' : 'Wyłączone. Kategorie wizualne mają status NOT_EVALUATED. Włączenie wymaga zgody właściciela (płatne API) i konfiguracji w wp-config.php.',
		];
	}

	public function usedThisMonth(): int
	{
		return $this->requests->countBillable(self::PROVIDER, gmdate('Y-m-01 00:00:00'));
	}

	/**
	 * @param array<string, string> $screenshots view => ścieżka pliku JPEG
	 * @param array<string, mixed> $facts obiektywne wyniki pomiarów
	 * @return array{status: string, result: ?array<string, mixed>, errors: list<string>}
	 */
	public function analyze(array $screenshots, array $facts, ?int $organizationId): array
	{
		if (! self::enabled()) {
			return ['status' => 'disabled', 'result' => null, 'errors' => []];
		}

		if ($this->usedThisMonth() >= self::monthlyLimit()) {
			return ['status' => 'limit', 'result' => null, 'errors' => ['Wykorzystano miesięczny limit analiz AI Vision.']];
		}

		$hash = md5(implode('|', array_map(fn ($p) => is_file($p) ? md5_file($p) : '', $screenshots)) . VisionSchema::PROMPT_VERSION . self::model());
		$cached = get_transient('ubr_vision_' . $hash);

		if (is_array($cached)) {
			return ['status' => 'cached', 'result' => $cached, 'errors' => []];
		}

		$content = [];

		foreach ($screenshots as $view => $path) {
			if (is_file($path)) {
				$content[] = ['type' => 'text', 'text' => 'Screenshot: ' . $view];
				$content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($path))]];
			}
		}

		$content[] = ['type' => 'text', 'text' => VisionSchema::prompt($facts)];
		$started = microtime(true);
		$response = wp_remote_post('https://api.anthropic.com/v1/messages', [
			'timeout' => 90,
			'headers' => ['x-api-key' => self::apiKey(), 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
			'body' => wp_json_encode(['model' => self::model(), 'max_tokens' => 2500, 'messages' => [['role' => 'user', 'content' => $content]]]),
		]);
		$code = is_wp_error($response) ? null : (int) wp_remote_retrieve_response_code($response);
		$this->requests->log(['organization_id' => $organizationId, 'provider' => self::PROVIDER, 'endpoint' => '/v1/messages', 'method' => 'POST', 'http_status' => $code, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'billable' => ($code === 200 || $code === null) ? 1 : 0, 'error' => is_wp_error($response) ? $response->get_error_message() : ($code !== 200 ? 'HTTP ' . $code : null)]);

		if ($code !== 200) {
			return ['status' => 'error', 'result' => null, 'errors' => ['AI Vision: ' . (is_wp_error($response) ? 'brak odpowiedzi' : 'HTTP ' . $code)]];
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$text = (string) ($body['content'][0]['text'] ?? '');

		if (preg_match('/\{.*\}/s', $text, $m)) {
			$text = $m[0];
		}

		$validation = VisionSchema::validate(json_decode($text, true), self::model());

		if (! $validation['ok']) {
			return ['status' => 'invalid', 'result' => null, 'errors' => $validation['errors']];
		}

		set_transient('ubr_vision_' . $hash, $validation['result'], 30 * DAY_IN_SECONDS);

		return ['status' => 'ok', 'result' => $validation['result'], 'errors' => $validation['errors']];
	}
}
