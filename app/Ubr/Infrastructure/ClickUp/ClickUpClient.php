<?php

namespace App\Ubr\Infrastructure\ClickUp;

use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;
use App\Ubr\Support\Redactor;

/**
 * Klient ClickUp API v2 (endpointy zweryfikowane w dokumentacji developer.clickup.com, 10.2026):
 *
 * - OAuth: autoryzacja `https://app.clickup.com/api?client_id=&redirect_uri=&state=`,
 *   token `POST https://api.clickup.com/api/v2/oauth/token` (client_id, client_secret, code); token „obecnie nie wygasa”,
 *   brak refresh tokenu i brak udokumentowanego endpointu unieważniania; nagłówek `Authorization: Bearer {token}`,
 * - `GET /v2/user`, `GET /v2/team` (autoryzowane Workspaces), `GET /v2/team/{team_id}/space`, `GET /v2/space/{space_id}/folder`,
 *   `GET /v2/folder/{folder_id}/list`, `GET /v2/space/{space_id}/list` (listy bez folderu),
 * - `POST /v2/list/{list_id}/task` (name, markdown_content, …; odpowiedź: id, url), `PUT /v2/task/{task_id}`,
 *   `GET /v2/task/{task_id}?include_markdown_description=true`,
 * - `GET /v2/list/{list_id}/field` (Custom Fields listy), `POST /v2/task/{task_id}/field/{field_id}` ({"value": …}),
 * - limity: 100 zapytań / min / token (Free–Business), 429 z nagłówkami X-RateLimit-Limit / -Remaining / -Reset.
 *
 * Token nigdy nie trafia do logów: dziennik zapisuje tylko endpoint (bez identyfikatorów zapytania), status i
 * zredagowany komunikat błędu.
 */
class ClickUpClient
{
	public const API = 'https://api.clickup.com/api/v2';

	public const AUTHORIZE_URL = 'https://app.clickup.com/api';

	/** Odstęp między zapytaniami jednego tokenu (≈ 90 / min, poniżej limitu 100 / min). */
	private const MIN_INTERVAL_MS = 650;

	public function __construct(private ApiRequestRepository $requests)
	{
	}

	/**
	 * Wymiana kodu autoryzacyjnego na token (żądanie serwer–serwer; sekret nie opuszcza serwera).
	 *
	 * @return array{token: ?string, error: ?ClickUpError}
	 */
	public function exchangeCode(string $clientId, string $clientSecret, string $code, ?int $organizationId = null): array
	{
		$started = microtime(true);
		$response = wp_remote_post(self::API . '/oauth/token', [
			'timeout' => 20,
			'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
			'body' => wp_json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret, 'code' => $code]),
		]);
		$parsed = $this->parse($response, $started, true);
		$this->log($organizationId, '/oauth/token', 'POST', $parsed, [$clientSecret, $code]);

		if ($parsed['error']) {
			return ['token' => null, 'error' => $parsed['error']];
		}

		$token = is_array($parsed['data']) ? (string) ($parsed['data']['access_token'] ?? '') : '';

		return $token !== '' ? ['token' => $token, 'error' => null] : ['token' => null, 'error' => new ClickUpError(ClickUpError::INVALID, 'ClickUp nie zwrócił tokenu dostępu.', $parsed['status'])];
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @param array<string, scalar> $query
	 * @return array{status: ?int, data: mixed, error: ?ClickUpError, headers: array<string, string>}
	 */
	public function request(string $token, string $method, string $path, ?array $body = null, array $query = [], ?int $organizationId = null, bool $isCreate = false): array
	{
		$this->throttle($token);
		$url = self::API . $path . ($query ? '?' . http_build_query($query) : '');
		$args = [
			'method' => $method,
			'timeout' => 20,
			'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
		];

		if ($body !== null) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($body);
		}

		$started = microtime(true);
		$parsed = $this->parse(wp_remote_request($url, $args), $started, false, $isCreate);
		// Dziennik: ścieżka bez identyfikatorów (np. /list/{id}/task) — bez tokenu i danych.
		$this->log($organizationId, (string) preg_replace('#/(team|space|folder|list|task|field)/[^/]+#', '/$1/{id}', $path), $method, $parsed, [$token]);

		return $parsed;
	}

	/* ------------------------------ hierarchia ------------------------------ */

	public function user(string $token): array
	{
		return $this->request($token, 'GET', '/user');
	}

	public function teams(string $token): array
	{
		return $this->request($token, 'GET', '/team');
	}

	public function spaces(string $token, string $teamId): array
	{
		return $this->request($token, 'GET', '/team/' . rawurlencode($teamId) . '/space', null, ['archived' => 'false']);
	}

	public function folders(string $token, string $spaceId): array
	{
		return $this->request($token, 'GET', '/space/' . rawurlencode($spaceId) . '/folder', null, ['archived' => 'false']);
	}

	public function folderLists(string $token, string $folderId): array
	{
		return $this->request($token, 'GET', '/folder/' . rawurlencode($folderId) . '/list', null, ['archived' => 'false']);
	}

	public function folderlessLists(string $token, string $spaceId): array
	{
		return $this->request($token, 'GET', '/space/' . rawurlencode($spaceId) . '/list', null, ['archived' => 'false']);
	}

	public function listFields(string $token, string $listId): array
	{
		return $this->request($token, 'GET', '/list/' . rawurlencode($listId) . '/field');
	}

	/* ------------------------------ zadania ------------------------------ */

	/**
	 * @param array<string, mixed> $payload
	 */
	public function createTask(string $token, string $listId, array $payload, ?int $organizationId = null): array
	{
		return $this->request($token, 'POST', '/list/' . rawurlencode($listId) . '/task', $payload, [], $organizationId, true);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function updateTask(string $token, string $taskId, array $payload, ?int $organizationId = null): array
	{
		return $this->request($token, 'PUT', '/task/' . rawurlencode($taskId), $payload, [], $organizationId);
	}

	public function getTask(string $token, string $taskId, ?int $organizationId = null): array
	{
		return $this->request($token, 'GET', '/task/' . rawurlencode($taskId), null, ['include_markdown_description' => 'true'], $organizationId);
	}

	/**
	 * Prompt 10.4.3 — zadania listy (ustalenie wyniku tworzenia po timeoucie: wyszukanie znacznika ubr-ref w opisie).
	 *
	 * @param array<string, scalar> $query
	 */
	public function listTasks(string $token, string $listId, array $query = [], ?int $organizationId = null): array
	{
		return $this->request($token, 'GET', '/list/' . rawurlencode($listId) . '/task', null, $query + ['include_markdown_description' => 'true', 'include_closed' => 'true', 'subtasks' => 'true', 'order_by' => 'created', 'reverse' => 'true', 'page' => 0], $organizationId);
	}

	public function setField(string $token, string $taskId, string $fieldId, mixed $value, ?int $organizationId = null): array
	{
		return $this->request($token, 'POST', '/task/' . rawurlencode($taskId) . '/field/' . rawurlencode($fieldId), ['value' => $value], [], $organizationId);
	}

	/* ------------------------------ błędy ------------------------------ */

	/**
	 * Klasyfikacja odpowiedzi: trwałe / tymczasowe / wymagające ponownego połączenia / niejednoznaczne (POST tworzący).
	 *
	 * @param array<string, mixed>|\WP_Error $response
	 * @return array{status: ?int, data: mixed, error: ?ClickUpError, headers: array<string, string>}
	 */
	private function parse($response, float $started, bool $isOauth = false, bool $isCreate = false): array
	{
		if (is_wp_error($response)) {
			$msg = $response->get_error_message();
			// cURL 6 (DNS) / 7 (połączenie odrzucone) = żądanie nie dotarło; timeout po wysłaniu = niejednoznaczne.
			$notSent = (bool) preg_match('/cURL error (6|7|35|60):/', $msg);
			$kind = $isCreate && ! $notSent ? ClickUpError::AMBIGUOUS : ClickUpError::NETWORK;

			return ['status' => null, 'data' => null, 'headers' => [], 'error' => new ClickUpError($kind, $kind === ClickUpError::AMBIGUOUS ? 'Brak odpowiedzi ClickUp po wysłaniu żądania (timeout) — nie wiadomo, czy zadanie powstało.' : 'Błąd sieci przy połączeniu z ClickUp.', null)];
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$raw = wp_remote_retrieve_headers($response);
		$raw = is_object($raw) && method_exists($raw, 'getAll') ? $raw->getAll() : (array) $raw;
		$headers = [];

		foreach ($raw as $k => $v) {
			$headers[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
		}

		$data = json_decode((string) wp_remote_retrieve_body($response), true);

		if ($status >= 200 && $status < 300) {
			return ['status' => $status, 'data' => $data, 'headers' => $headers, 'error' => null];
		}

		$apiCode = is_array($data) ? (string) ($data['ECODE'] ?? '') : '';
		$apiMsg = is_array($data) ? mb_substr(Redactor::redact((string) ($data['err'] ?? '')), 0, 160) : '';
		$error = match (true) {
			$status === 401 || ($status !== 403 && str_starts_with($apiCode, 'OAUTH_')) => new ClickUpError(ClickUpError::AUTH, $isOauth ? 'Autoryzacja ClickUp odrzucona (kod nieważny lub błędne dane aplikacji OAuth).' : 'Token ClickUp jest nieważny lub dostęp został odebrany — połącz konto ponownie.', $status, $apiCode),
			$status === 403 => new ClickUpError(ClickUpError::FORBIDDEN, 'Brak uprawnień w ClickUp do tego zasobu (Workspace / lista).', $status, $apiCode),
			$status === 404 => new ClickUpError(ClickUpError::NOT_FOUND, 'Zasób ClickUp nie istnieje (lista lub zadanie usunięte albo brak dostępu).', $status, $apiCode),
			$status === 429 => new ClickUpError(ClickUpError::RATE_LIMITED, 'Limit zapytań ClickUp — ponowienie później.', $status, $apiCode, $this->retryAfter($headers)),
			$status >= 500 => new ClickUpError($isCreate ? ClickUpError::AMBIGUOUS : ClickUpError::SERVER, $isCreate ? 'Błąd serwera ClickUp (' . $status . ') po wysłaniu żądania — nie wiadomo, czy zadanie powstało.' : 'Błąd serwera ClickUp (' . $status . ').', $status, $apiCode),
			default => new ClickUpError(ClickUpError::INVALID, 'ClickUp odrzucił żądanie (' . $status . ($apiMsg !== '' ? ': ' . $apiMsg : '') . ').', $status, $apiCode),
		};

		return ['status' => $status, 'data' => null, 'headers' => $headers, 'error' => $error];
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function retryAfter(array $headers): int
	{
		if (isset($headers['retry-after']) && ctype_digit(trim($headers['retry-after']))) {
			return max(5, min(3600, (int) $headers['retry-after']));
		}

		if (isset($headers['x-ratelimit-reset']) && ctype_digit(trim($headers['x-ratelimit-reset']))) {
			return max(5, min(3600, (int) $headers['x-ratelimit-reset'] - time()));
		}

		return 60;
	}

	private function throttle(string $token): void
	{
		$key = 'ubr_cu_last_' . substr(hash('sha256', $token), 0, 16);
		$last = (float) get_transient($key);
		$wait = $last + self::MIN_INTERVAL_MS / 1000 - microtime(true);

		if ($wait > 0 && $wait < 2 && apply_filters('ubr/clickup/throttle', true)) {
			usleep((int) ($wait * 1e6));
		}

		set_transient($key, (string) microtime(true), 60);
	}

	/**
	 * @param array<string, mixed> $parsed
	 * @param list<string> $secrets
	 */
	private function log(?int $organizationId, string $endpoint, string $method, array $parsed, array $secrets): void
	{
		$this->requests->log([
			'organization_id' => $organizationId,
			'provider' => 'clickup',
			'endpoint' => mb_substr($endpoint, 0, 190),
			'method' => $method,
			'http_status' => $parsed['status'],
			'duration_ms' => 0,
			'cached' => 0,
			'billable' => 0,
			'error' => $parsed['error'] ? Redactor::redact($parsed['error']->getMessage(), $secrets) : null,
		]);
	}
}
