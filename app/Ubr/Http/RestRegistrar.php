<?php

namespace App\Ubr\Http;

use App\Ubr\Services\TenantResolver;
use App\Ubr\Support\UbrException;
use Illuminate\Contracts\Container\Container;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Rejestruje trasy z routes/api.php w WordPress REST API.
 *
 * Bezpieczeństwo:
 * - permission_callback ZAWSZE weryfikuje zalogowanie + członkostwo + rolę (po stronie serwera),
 * - uwierzytelnianie cookie WP wymaga nagłówka X-WP-Nonce (ochrona CSRF) — bez niego użytkownik jest anonimowy,
 * - kontrolery pobierają organizację wyłącznie z TenantResolver, nigdy z parametrów żądania.
 */
class RestRegistrar
{
	public const NAMESPACE = 'ubr/v1';

	public function __construct(private Container $container, private TenantResolver $tenants)
	{
	}

	public function register(): void
	{
		$routes = require get_theme_file_path('routes/api.php');

		foreach ($routes as [$method, $path, $handler, $ability]) {
			register_rest_route(self::NAMESPACE, $path, [
				'methods' => $method,
				'callback' => fn (WP_REST_Request $request) => $this->handle($handler, $request),
				'permission_callback' => fn () => $this->authorize($ability),
			]);
		}
	}

	public function authorize(string $ability): bool|WP_Error
	{
		if (! is_user_logged_in()) {
			return new WP_Error('ubr_unauthenticated', 'Musisz być zalogowany.', ['status' => 401]);
		}

		if ($ability === 'auth') {
			return true;
		}

		try {
			$this->tenants->require($ability);

			return true;
		} catch (UbrException $e) {
			return $e->toWpError();
		} catch (\Throwable $e) {
			// Np. błąd bazy w TenantResolver (okno migracji) — kontrolowany 500 zamiast fatal error / stack trace.
			error_log('[ubr] REST authorize error: ' . $e->getMessage());

			return new WP_Error('ubr_error', 'Wystąpił błąd serwera. Spróbuj ponownie za chwilę.', ['status' => 500]);
		}
	}

	/**
	 * @param array{0: class-string, 1: string} $handler
	 */
	private function handle(array $handler, WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		[$class, $method] = $handler;

		try {
			$result = $this->container->make($class)->{$method}($request);
			$response = $result instanceof WP_REST_Response ? $result : new WP_REST_Response($result);
			$response->header('Cache-Control', 'no-store, private');

			return $response;
		} catch (UbrException $e) {
			return $e->toWpError();
		} catch (Throwable $e) {
			error_log('[ubr] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

			return new WP_Error('ubr_server_error', 'Wystąpił błąd serwera. Szczegóły w logu.', ['status' => 500]);
		}
	}
}
