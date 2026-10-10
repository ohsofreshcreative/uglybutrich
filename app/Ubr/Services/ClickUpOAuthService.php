<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\ClickUp\ClickUpClient;
use App\Ubr\Infrastructure\ClickUp\ClickUpError;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Security\SecretBox;
use App\Ubr\Support\Redactor;
use App\Ubr\Support\UbrException;

/**
 * ClickUp OAuth 2.0 per użytkownik (User A → konto A, User B → konto B; brak wspólnego tokenu).
 *
 * Konfiguracja aplikacji OAuth (wp-config.php, NIE w repozytorium i NIE w bazie):
 *   UBR_CLICKUP_CLIENT_ID, UBR_CLICKUP_CLIENT_SECRET, opcjonalnie UBR_CLICKUP_REDIRECT_URI
 *   (domyślnie {home}/app/clickup-callback/ — ten adres trzeba wpisać w aplikacji ClickUp).
 *
 * Bezpieczeństwo: start tylko przez REST (nonce WP = CSRF), `state` = 32 losowe bajty, zapis po stronie serwera
 * (skrót → user_id + organization_id, 10 min, jednorazowy); callback wymaga zalogowanego użytkownika, który
 * rozpoczął autoryzację, i jego członkostwa w organizacji; organization_id / user_id nie są brane z URL.
 * Token szyfrowany (SecretBox), nigdy nie wraca do przeglądarki ani do logów.
 */
class ClickUpOAuthService
{
	private const STATE_TTL = 600;

	public function __construct(
		private ClickUpRepository $repo,
		private ClickUpClient $client,
		private SecretBox $box,
		private MemberRepository $members,
	) {
	}

	public static function clientId(): ?string
	{
		$v = defined('UBR_CLICKUP_CLIENT_ID') ? trim((string) UBR_CLICKUP_CLIENT_ID) : '';

		return $v !== '' ? $v : null;
	}

	private static function clientSecret(): ?string
	{
		$v = defined('UBR_CLICKUP_CLIENT_SECRET') ? trim((string) UBR_CLICKUP_CLIENT_SECRET) : '';

		return $v !== '' ? $v : null;
	}

	public static function redirectUri(): string
	{
		$v = defined('UBR_CLICKUP_REDIRECT_URI') ? trim((string) UBR_CLICKUP_REDIRECT_URI) : '';

		return $v !== '' ? $v : home_url('/' . \App\Ubr\Http\AppPages::base() . '/clickup-callback/');
	}

	/**
	 * @return array{configured: bool, missing: list<string>, redirect_uri: string, https: bool, encryption: ?string, notes: list<string>}
	 */
	public static function configuration(): array
	{
		$missing = [];

		if (! self::clientId()) {
			$missing[] = 'UBR_CLICKUP_CLIENT_ID';
		}

		if (! self::clientSecret()) {
			$missing[] = 'UBR_CLICKUP_CLIENT_SECRET';
		}

		if (! SecretBox::available()) {
			$missing[] = 'UBR_ENCRYPTION_KEY (lub AUTH_KEY / SECURE_AUTH_KEY w wp-config.php)';
		}

		$https = str_starts_with(self::redirectUri(), 'https://');
		$notes = [];

		if (! $https) {
			$notes[] = 'Redirect URI używa HTTP. Dokumentacja ClickUp: adresy bez SSL „mogą nie być wspierane w przyszłości” — w produkcji wymagany HTTPS.';
		}

		if (wp_get_environment_type() === 'production' && ! $https) {
			$missing[] = 'HTTPS (środowisko produkcyjne)';
		}

		return ['configured' => $missing === [], 'missing' => $missing, 'redirect_uri' => self::redirectUri(), 'https' => $https, 'encryption' => SecretBox::source(), 'notes' => $notes];
	}

	/**
	 * Rozpoczęcie OAuth: zwraca adres autoryzacji ClickUp (frontend przekierowuje przeglądarkę).
	 */
	public function start(TenantContext $ctx): string
	{
		$this->assert($ctx, 'clickup.connect');
		$config = self::configuration();

		if (! $config['configured']) {
			throw UbrException::invalid('Integracja ClickUp nie jest skonfigurowana: brak ' . implode(', ', $config['missing']) . '.');
		}

		$state = bin2hex(random_bytes(32));
		set_transient('ubr_cu_state_' . hash('sha256', $state), ['user_id' => $ctx->userId, 'organization_id' => $ctx->organizationId, 'created' => time()], self::STATE_TTL);

		return ClickUpClient::AUTHORIZE_URL . '?' . http_build_query(['client_id' => self::clientId(), 'redirect_uri' => self::redirectUri(), 'state' => $state]);
	}

	/**
	 * Callback OAuth (GET ?code=&state=). Zwraca kod wyniku dla przekierowania do Settings (bez danych wrażliwych).
	 *
	 * @param array<string, mixed> $query
	 */
	public function handleCallback(array $query): string
	{
		$state = is_string($query['state'] ?? null) ? (string) $query['state'] : '';
		$code = is_string($query['code'] ?? null) ? (string) $query['code'] : '';
		$userId = get_current_user_id();

		if ($state === '' || ! preg_match('/^[a-f0-9]{64}$/', $state)) {
			return 'invalid_state';
		}

		$key = 'ubr_cu_state_' . hash('sha256', $state);
		$saved = get_transient($key);
		// Jednorazowy: usuwamy niezależnie od wyniku.
		delete_transient($key);

		if (! is_array($saved) || time() - (int) ($saved['created'] ?? 0) > self::STATE_TTL) {
			return 'invalid_state';
		}

		// Callback musi wykonać ten sam użytkownik, który rozpoczął autoryzację, nadal członek tej organizacji.
		if ((int) $saved['user_id'] !== $userId || $userId <= 0) {
			return 'wrong_user';
		}

		$orgId = (int) $saved['organization_id'];

		if (! $this->members->role($orgId, $userId)) {
			return 'not_member';
		}

		if ($code === '' || strlen($code) > 512) {
			return ! empty($query['error']) ? 'denied' : 'missing_code';
		}

		if (! self::configuration()['configured']) {
			return 'not_configured';
		}

		$result = $this->client->exchangeCode((string) self::clientId(), (string) self::clientSecret(), $code, $orgId);

		if (! $result['token']) {
			Redactor::log('ClickUp OAuth: wymiana kodu nieudana (' . ($result['error']?->kind ?? '?') . ').', [$code]);

			return 'exchange_failed';
		}

		$token = $result['token'];
		$user = $this->client->user($token);
		$teams = $this->client->teams($token);

		$this->repo->saveConnection($orgId, $userId, [
			'status' => 'connected',
			'token_ciphertext' => $this->box->encrypt($token),
			'key_id' => SecretBox::keyId(),
			'clickup_user_id' => isset($user['data']['user']['id']) ? (string) $user['data']['user']['id'] : null,
			'clickup_username' => isset($user['data']['user']['username']) ? mb_substr((string) $user['data']['user']['username'], 0, 190) : null,
			'authorized_teams' => array_map(fn ($t) => ['id' => (string) ($t['id'] ?? ''), 'name' => (string) ($t['name'] ?? '')], (array) ($teams['data']['teams'] ?? [])),
			'connected_at' => Db::now(),
			'last_checked_at' => Db::now(),
			'last_error' => null,
		]);

		return 'connected';
	}

	/**
	 * Token aktywnego użytkownika (odszyfrowany tylko na czas wywołania API).
	 *
	 * @param array<string, mixed> $connection
	 */
	public function token(array $connection): ?string
	{
		if (($connection['status'] ?? '') !== 'connected') {
			return null;
		}

		return $this->box->decrypt($connection['token_ciphertext'] ?? null);
	}

	/**
	 * Oznaczenie połączenia jako wymagającego ponownej autoryzacji (401 / odebrany dostęp / zmiana klucza).
	 */
	public function markReconnect(int $connectionId, string $reason): void
	{
		$this->repo->updateConnection($connectionId, ['status' => 'reconnect_required', 'last_error' => mb_substr(Redactor::redact($reason), 0, 500)]);
	}

	/**
	 * Stan połączenia bieżącego użytkownika (bez tokenu).
	 *
	 * @return array<string, mixed>
	 */
	public function status(TenantContext $ctx): array
	{
		$this->assert($ctx, 'clickup.connect');
		$c = $this->repo->connection($ctx->organizationId, $ctx->userId);
		$config = self::configuration();

		if ($c && $c['status'] === 'connected' && $this->box->decrypt($c['token_ciphertext']) === null) {
			// Klucz szyfrowania zmieniony / dane uszkodzone — nie da się użyć tokenu.
			$this->markReconnect((int) $c['id'], 'Nie można odszyfrować tokenu (zmiana klucza szyfrowania).');
			$c = $this->repo->connection($ctx->organizationId, $ctx->userId);
		}

		return [
			'configuration' => $config,
			'state' => ! $config['configured'] && ! $c ? 'not_configured' : ($c ? $c['status'] : 'not_connected'),
			'connected' => $c && $c['status'] === 'connected',
			'account' => $c ? ['username' => $c['clickup_username'], 'user_id' => $c['clickup_user_id']] : null,
			'connected_at' => $c && $c['connected_at'] ? gmdate('c', strtotime($c['connected_at'] . ' UTC')) : null,
			'last_checked_at' => $c && $c['last_checked_at'] ? gmdate('c', strtotime($c['last_checked_at'] . ' UTC')) : null,
			'last_error' => $c['last_error'] ?? null,
			'authorized_teams' => $c['authorized_teams'] ?? [],
			'destination' => $c['destination'] ?? null,
			'settings' => ($c['settings'] ?? []) + ['task_name_template' => '{company} — Website Redesign Opportunity', 'field_mapping' => []],
			'revocation_note' => 'ClickUp nie udostępnia w API endpointu unieważniania tokenu — rozłączenie usuwa token z Ugly but Rich; dostęp aplikacji możesz dodatkowo odebrać w ustawieniach ClickUp (Settings → Apps).',
		];
	}

	/**
	 * Test połączenia: GET /user (bez tworzenia czegokolwiek w ClickUp).
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function test(TenantContext $ctx): array
	{
		$this->assert($ctx, 'clickup.connect');
		$c = $this->repo->connection($ctx->organizationId, $ctx->userId) ?? throw UbrException::invalid('Konto ClickUp nie jest połączone.');
		$token = $this->token($c) ?? throw UbrException::invalid('Połączenie wymaga ponownej autoryzacji.');
		$res = $this->client->user($token);

		if ($res['error']) {
			if ($res['error']->needsReconnect()) {
				$this->markReconnect((int) $c['id'], $res['error']->getMessage());
			} else {
				$this->repo->updateConnection((int) $c['id'], ['last_error' => $res['error']->getMessage(), 'last_checked_at' => Db::now()]);
			}

			return ['ok' => false, 'message' => $res['error']->getMessage()];
		}

		$teams = $this->client->teams($token);
		$this->repo->updateConnection((int) $c['id'], [
			'last_checked_at' => Db::now(),
			'last_error' => null,
			'clickup_username' => mb_substr((string) ($res['data']['user']['username'] ?? $c['clickup_username']), 0, 190),
			'authorized_teams' => $teams['error'] ? $c['authorized_teams'] : array_map(fn ($t) => ['id' => (string) ($t['id'] ?? ''), 'name' => (string) ($t['name'] ?? '')], (array) ($teams['data']['teams'] ?? [])),
		]);

		return ['ok' => true, 'message' => 'Połączenie działa (konto: ' . ($res['data']['user']['username'] ?? '—') . ').'];
	}

	public function disconnect(TenantContext $ctx): void
	{
		$this->assert($ctx, 'clickup.connect');
		$c = $this->repo->connection($ctx->organizationId, $ctx->userId);

		if (! $c) {
			return;
		}

		// Reguły korzystające z tego połączenia zostają wyłączone (bez tokenu nie mogą działać).
		foreach ($this->repo->rules($ctx->organizationId) as $rule) {
			if ((int) $rule['connection_id'] === (int) $c['id'] && $rule['enabled']) {
				$this->repo->updateRule((int) $rule['id'], ['enabled' => 0]);
			}
		}

		$this->repo->deleteConnection($ctx->organizationId, $ctx->userId);
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}
}
