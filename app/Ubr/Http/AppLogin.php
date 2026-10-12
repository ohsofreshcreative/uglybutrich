<?php

namespace App\Ubr\Http;

use App\Ubr\Support\LoginThrottle;
use Illuminate\Support\Facades\Vite;
use WP_Error;
use WP_User;

/**
 * Logowanie do panelu pod /app/login — własny ekran w stylu aplikacji zamiast wp-login.php.
 *
 * - Uwierzytelnianie przez wp_signon(): działają hasła WordPressa, logowanie e-mailem, wtyczki zabezpieczeń
 *   (filtry authenticate / wp_login_failed) i ciasteczka „Nie wylogowuj mnie”.
 * - Po zalogowaniu: aplikacja (albo bezpieczny redirect_to z tej samej domeny) — nie kokpit WordPressa.
 * - wp-login.php (zwykłe logowanie) przekierowuje tutaj; reset hasła, wylogowanie i logowanie w okienku wp-admin
 *   zostają w WordPressie (ekrany dostają logo i kolory aplikacji). Awaryjnie: /wp-login.php?native=1.
 * - Limit nieudanych prób (LoginThrottle), komunikat błędu bez wskazania, czy istnieje konto.
 */
class AppLogin
{
	public const NONCE = 'ubr_login';

	public static function url(array $args = []): string
	{
		return add_query_arg(array_map('rawurlencode', array_filter($args, fn ($v) => $v !== null && $v !== '')), AppPages::url('login'));
	}

	public function register(): void
	{
		add_filter('login_url', [$this, 'loginUrl'], 20, 3);
		add_filter('login_redirect', [$this, 'loginRedirect'], 20, 3);
		add_action('login_init', [$this, 'takeOverWpLogin'], 1);
		add_action('admin_init', [$this, 'keepMembersInApp'], 1);
		add_filter('show_admin_bar', [$this, 'showAdminBar'], 20);
		add_action('login_head', [$this, 'brandWpScreens'], 100);
		add_filter('login_headerurl', fn () => self::url());
		add_filter('login_headertext', fn () => 'Ugly but Rich');
		add_filter('login_title', fn () => 'Logowanie · Ugly but Rich');
	}

	/**
	 * Wszystkie odnośniki „zaloguj się” WordPressa (auth_redirect, wp_login_url) prowadzą do panelu.
	 */
	public function loginUrl(string $loginUrl, string $redirect = '', bool $forceReauth = false): string
	{
		if (! apply_filters('ubr/login/enabled', true)) {
			return $loginUrl;
		}

		return self::url(['redirect_to' => $redirect, 'reauth' => $forceReauth ? '1' : null]);
	}

	/**
	 * Po zalogowaniu (także formularzem WordPressa) domyślnym celem jest aplikacja, nie /wp-admin/.
	 *
	 * @param WP_User|WP_Error $user
	 */
	public function loginRedirect(string $redirectTo, string $requested, $user): string
	{
		if (! $user instanceof WP_User) {
			return $redirectTo;
		}

		$default = rtrim(admin_url(), '/');

		if ($requested === '' || rtrim($requested, '/') === $default || rtrim($redirectTo, '/') === $default) {
			return AppPages::url();
		}

		return $redirectTo;
	}

	/**
	 * Zwykły ekran logowania wp-login.php → /app/login. Pozostałe akcje WordPressa (reset hasła, wylogowanie,
	 * potwierdzenia, okienko ponownego logowania w wp-admin) działają bez zmian.
	 *
	 * @param array<string, mixed> $query
	 */
	public static function shouldTakeOver(string $method, array $query): bool
	{
		if (strtoupper($method) !== 'GET' || ! empty($query['native']) || ! empty($query['interim-login'])) {
			return false;
		}

		$action = (string) ($query['action'] ?? 'login');

		if (! in_array($action, ['login', ''], true)) {
			return false;
		}

		foreach (['checkemail', 'key', 'wp_lang_switch', 'customize-login'] as $param) {
			if (isset($query[$param])) {
				return false;
			}
		}

		return true;
	}

	public function takeOverWpLogin(): void
	{
		if (! apply_filters('ubr/login/enabled', true) || ! self::shouldTakeOver((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET)) {
			return;
		}

		wp_safe_redirect(self::url([
			'redirect_to' => isset($_GET['redirect_to']) ? (string) wp_unslash($_GET['redirect_to']) : null,
			'reauth' => ! empty($_GET['reauth']) ? '1' : null,
			'loggedout' => ! empty($_GET['loggedout']) ? '1' : null,
		]));
		exit;
	}

	/**
	 * Członkowie zespołu bez uprawnień redakcyjnych nie lądują w kokpicie WordPressa — wracają do aplikacji.
	 * Profil (zmiana hasła), admin-ajax i admin-post działają jak dotąd.
	 */
	public function keepMembersInApp(): void
	{
		global $pagenow;

		if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || ! is_user_logged_in() || current_user_can(self::adminCapability())) {
			return;
		}

		if (($pagenow ?? '') === 'index.php') {
			wp_safe_redirect(AppPages::url());
			exit;
		}
	}

	public function showAdminBar(bool $show): bool
	{
		return $show && current_user_can(self::adminCapability());
	}

	public static function adminCapability(): string
	{
		return (string) apply_filters('ubr/login/admin_capability', 'edit_posts');
	}

	/**
	 * Ekran logowania /app/login: GET = formularz, POST = logowanie. Wywoływane z AppPages przed sprawdzeniem sesji.
	 */
	public function handle(): void
	{
		if (! defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}

		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('X-LiteSpeed-Cache-Control: no-cache');

		$redirect = self::safeRedirect(isset($_REQUEST['redirect_to']) ? (string) wp_unslash($_REQUEST['redirect_to']) : '');
		$reauth = ! empty($_GET['reauth']);
		$post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
		$error = null;
		$login = '';

		if (! $post) {
			if (is_user_logged_in() && ! $reauth) {
				wp_safe_redirect($redirect);
				exit;
			}

			if ($reauth) {
				wp_clear_auth_cookie();
			}
		} else {
			$login = trim(sanitize_user(wp_unslash((string) ($_POST['log'] ?? '')), false));
			$password = (string) wp_unslash($_POST['pwd'] ?? '');
			$remember = ! empty($_POST['rememberme']);
			$result = $this->attempt($login, $password, $remember);

			if ($result instanceof WP_User) {
				$target = apply_filters('login_redirect', $redirect, isset($_REQUEST['redirect_to']) ? $redirect : '', $result);
				wp_safe_redirect(self::safeRedirect((string) $target));
				exit;
			}

			$error = $result;

			if (str_starts_with($error, 'Za dużo')) {
				status_header(429);
			}
		}

		$notice = match (true) {
			$error !== null => null,
			! empty($_GET['loggedout']) => 'Wylogowano. Do zobaczenia!',
			$reauth => 'Sesja wygasła — zaloguj się ponownie.',
			default => null,
		};

		echo view('ubr.pages.login', [
			'error' => $error,
			'notice' => $notice,
			'login' => $login,
			'redirect' => $redirect === AppPages::url() ? '' : $redirect,
			'nonce' => wp_create_nonce(self::NONCE),
			'action' => self::url(),
			'lostUrl' => wp_lostpassword_url(self::url()),
			'logoUrl' => self::asset('resources/images/ubr-logo.png'),
			'iconUrl' => self::asset('resources/images/ubr-icon.png'),
		])->render();
		exit;
	}

	/**
	 * @return WP_User|string użytkownik albo komunikat błędu do wyświetlenia
	 */
	private function attempt(string $login, string $password, bool $remember): WP_User|string
	{
		if (! $this->requestTrusted()) {
			return self::message('expired');
		}

		if ($login === '' || $password === '') {
			return self::message('empty');
		}

		$ip = LoginThrottle::clientIp();
		$wait = LoginThrottle::lockedFor($ip, $login);

		if ($wait > 0) {
			return sprintf(self::message('locked'), max(1, (int) ceil($wait / 60)));
		}

		$user = wp_signon(['user_login' => $login, 'user_password' => $password, 'remember' => $remember], '');

		if ($user instanceof WP_User) {
			LoginThrottle::succeed($ip, $login);
			wp_set_current_user($user->ID);

			return $user;
		}

		LoginThrottle::fail($ip, $login);
		$code = $user instanceof WP_Error ? (string) $user->get_error_code() : '';

		// Standardowe błędy: jeden komunikat (bez zdradzania, czy konto istnieje). Inne (np. wtyczka 2FA) — jej komunikat.
		if ($code === '' || in_array($code, ['invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password', 'authentication_failed'], true)) {
			return self::message('invalid');
		}

		$text = trim(wp_strip_all_tags((string) $user->get_error_message()));

		return $text !== '' ? mb_substr($text, 0, 300) : self::message('invalid');
	}

	/**
	 * Nonce formularza; gdy strona logowania była zbuforowana przez cache hostingu (stary nonce) — wystarczy
	 * żądanie z tej samej domeny (nagłówek Origin / Referer).
	 */
	private function requestTrusted(): bool
	{
		if (wp_verify_nonce((string) ($_POST['_ubr_login'] ?? ''), self::NONCE)) {
			return true;
		}

		$home = wp_parse_url(home_url(), PHP_URL_HOST);

		foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
			if (! empty($_SERVER[$header])) {
				return wp_parse_url((string) $_SERVER[$header], PHP_URL_HOST) === $home;
			}
		}

		return false;
	}

	public static function message(string $key): string
	{
		return match ($key) {
			'invalid' => 'Nieprawidłowy e-mail / login lub hasło.',
			'empty' => 'Podaj e-mail lub login oraz hasło.',
			'expired' => 'Formularz wygasł — odśwież stronę i spróbuj ponownie.',
			'locked' => 'Za dużo nieudanych prób logowania. Spróbuj ponownie za %d min.',
			default => 'Nie udało się zalogować.',
		};
	}

	/**
	 * Cel po zalogowaniu: adres z tej samej domeny, nigdy strona logowania (pętla) — domyślnie aplikacja.
	 */
	public static function safeRedirect(string $url): string
	{
		$app = AppPages::url();
		$url = trim($url);

		if ($url === '') {
			return $app;
		}

		$valid = wp_validate_redirect($url, '');

		if ($valid === '' || str_contains($valid, 'wp-login.php') || str_contains($valid, '/' . AppPages::base() . '/login') || str_contains($valid, AppPages::QUERY_VAR . '=login')) {
			return $app;
		}

		return $valid;
	}

	/**
	 * Ekrany WordPressa, które zostają (reset hasła, wylogowanie): logo i kolory aplikacji.
	 */
	public function brandWpScreens(): void
	{
		$logo = self::asset('resources/images/ubr-logo.png');
		$css = 'body.login{background:#f6f5f1;font-family:Manrope,system-ui,sans-serif;color:#141b2d}'
			. 'body.login div#login h1 a{background-size:contain;background-position:center;width:240px;height:113px;margin-bottom:16px}'
			. 'body.login div#login form{border:1px solid #e8e5dd;border-radius:18px;box-shadow:0 1px 2px rgba(20,27,45,.04),0 8px 24px rgba(20,27,45,.06)}'
			. 'body.login.wp-core-ui .button-primary{background:#141b2d;border-color:#141b2d;border-radius:10px;box-shadow:none;text-shadow:none;color:#fff}'
			. 'body.login.wp-core-ui .button-primary:hover,body.login.wp-core-ui .button-primary:focus{background:#2b3346;border-color:#2b3346}'
			. 'body.login input[type=text]:focus,body.login input[type=password]:focus,body.login input[type=email]:focus{border-color:#141b2d;box-shadow:0 0 0 3px rgba(244,178,35,.3)}'
			. 'body.login #nav a,body.login #backtoblog a{color:#6b7180}'
			. 'body.login div#login .message,body.login div#login .notice,body.login div#login .success{border-left-color:#0b6b43;border-radius:10px}'
			. 'body.login div#login #login_error,body.login div#login .notice-error{border-left-color:#c2410c}';

		if ($logo) {
			$css .= 'body.login div#login h1 a{background-image:url(' . esc_url($logo) . ')}';
		}

		echo '<style id="ubr-login-brand">' . $css . '</style>';
	}

	private static function asset(string $path): ?string
	{
		try {
			return Vite::asset($path);
		} catch (\Throwable) {
			return null;
		}
	}
}
