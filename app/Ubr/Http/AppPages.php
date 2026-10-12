<?php

namespace App\Ubr\Http;

use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\TenantResolver;
use Illuminate\Support\Facades\Vite;

/**
 * Panel aplikacji jako osobny frontend pod /app (rewrite rules WordPressa + widoki Blade).
 * Dane ekranów pobiera JS z REST API (ubr/v1) — widoki nie zawierają logiki biznesowej.
 */
class AppPages
{
	public const QUERY_VAR = 'ubr_page';

	private const REWRITE_VERSION = 1;

	public function __construct(private TenantResolver $tenants, private OrganizationService $organizations)
	{
	}

	public static function base(): string
	{
		return trim((string) apply_filters('ubr/app_base', 'app'), '/');
	}

	/**
	 * @return array<string, array{slug: string, title: string, icon: string, view: string}>
	 */
	public static function routes(): array
	{
		return require get_theme_file_path('routes/app.php');
	}

	public static function url(string $page = 'dashboard'): string
	{
		$slug = self::routes()[$page]['slug'] ?? '';

		if (get_option('permalink_structure')) {
			return home_url('/' . self::base() . '/' . ($slug !== '' ? $slug . '/' : ''));
		}

		return add_query_arg(self::QUERY_VAR, $page, home_url('/'));
	}

	public function registerRewrites(): void
	{
		$base = preg_quote(self::base(), '#');
		add_rewrite_rule('^' . $base . '/?$', 'index.php?' . self::QUERY_VAR . '=dashboard', 'top');
		add_rewrite_rule('^' . $base . '/([a-z-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');

		if ((int) get_option('ubr_rewrite_version') !== self::REWRITE_VERSION) {
			flush_rewrite_rules(false);
			update_option('ubr_rewrite_version', self::REWRITE_VERSION, true);
		}
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public function queryVars(array $vars): array
	{
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public function maybeRender(): void
	{
		$requested = (string) get_query_var(self::QUERY_VAR);

		if ($requested === '') {
			return;
		}

		$page = $this->pageFromRequest($requested);

		nocache_headers();
		header('X-Frame-Options: SAMEORIGIN');
		header('X-Robots-Tag: noindex, nofollow');
		header('Referrer-Policy: same-origin');

		// Ekran logowania — jedyny dostępny bez sesji.
		if ($page === 'login') {
			app(AppLogin::class)->handle();
			exit;
		}

		if (! is_user_logged_in()) {
			wp_safe_redirect(AppLogin::url(['redirect_to' => $page && $page !== 'dashboard' ? self::url($page) : null]));
			exit;
		}

		if ($page === null) {
			status_header(404);
			$page = 'dashboard';
		}

		// Callback OAuth ClickUp: weryfikacja state / użytkownika / organizacji i wymiana kodu — potem powrót do Settings.
		if ($page === 'clickup-callback') {
			$result = app(\App\Ubr\Services\ClickUpOAuthService::class)->handleCallback($_GET);
			wp_safe_redirect(add_query_arg('clickup', $result, self::url('settings')) . '#clickup');
			exit;
		}

		$ctx = $this->tenants->resolve();
		$user = wp_get_current_user();
		$routes = self::routes();
		$nav = [];

		foreach ($routes as $key => $route) {
			if (! empty($route['hidden'])) {
				continue;
			}

			$nav[] = ['key' => $key, 'title' => $route['title'], 'icon' => $route['icon'], 'url' => self::url($key), 'active' => $key === $page];
		}

		$data = [
			'page' => $page,
			'title' => $routes[$page]['title'],
			'nav' => $nav,
			'user' => $user,
			'ctx' => $ctx,
			'memberships' => $this->tenants->memberships($user->ID),
			'logoUrl' => $this->asset('resources/images/ubr-logo.png'),
			'iconUrl' => $this->asset('resources/images/ubr-icon.png'),
			// wp_logout_url() zwraca adres już zakodowany dla HTML (&amp;) — Blade koduje sam, więc dekodujemy (inaczej znika nonce).
			'logoutUrl' => html_entity_decode(wp_logout_url(AppLogin::url(['loggedout' => '1'])), ENT_QUOTES | ENT_HTML5),
			'config' => [
				'restUrl' => esc_url_raw(rest_url('ubr/v1')),
				'nonce' => wp_create_nonce('wp_rest'),
				'page' => $page,
				'urls' => array_map(fn ($key) => self::url($key), array_combine(array_keys($routes), array_keys($routes))),
				'userId' => (int) $user->ID,
				'canCreateOrganization' => $this->organizations->canCreate($user->ID),
				// Tylko do ukrywania przycisków w UI — każde uprawnienie i tak sprawdza serwer.
				'abilities' => $ctx ? $ctx->role->abilities() : [],
			],
		];

		if (! $ctx) {
			status_header(403);
			echo view('ubr.pages.no-access', $data)->render();
			exit;
		}

		echo view($routes[$page]['view'], $data)->render();
		exit;
	}

	/**
	 * @param mixed $redirect
	 * @return mixed
	 */
	public function disableCanonicalRedirect($redirect)
	{
		return get_query_var(self::QUERY_VAR) ? false : $redirect;
	}

	private function pageFromRequest(string $requested): ?string
	{
		foreach (self::routes() as $key => $route) {
			if ($requested === $key || ($route['slug'] !== '' && $requested === $route['slug'])) {
				return $key;
			}
		}

		return null;
	}

	private function asset(string $path): ?string
	{
		try {
			return Vite::asset($path);
		} catch (\Throwable) {
			return null;
		}
	}
}
