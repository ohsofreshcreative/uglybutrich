<?php
/**
 * Testy: własny ekran logowania /app/login (AppLogin) i limit prób (LoginThrottle).
 * Dołączane z tests/run.php. Bez prawdziwych kont — logika przekierowań i liczników (transienty testowe są sprzątane).
 * Przepływ w przeglądarce (formularz, ciasteczka, wylogowanie, telefon) sprawdza osobny test E2E.
 */

use App\Ubr\Http\AppLogin;
use App\Ubr\Http\AppPages;
use App\Ubr\Support\LoginThrottle;

UbrT::group('Logowanie /app/login — przekierowania i limit prób');

$app = AppPages::url();
$loginPage = AppPages::url('login');

// Adres logowania WordPressa prowadzi do panelu.
$lu = wp_login_url(AppPages::url('sequences'));
UbrT::ok(str_starts_with($lu, $loginPage) && str_contains(rawurldecode($lu), 'redirect_to=' . AppPages::url('sequences')), 'wp_login_url() → /app/login z redirect_to');
UbrT::ok(str_contains(wp_login_url('', true), 'reauth=1'), 'wp_login_url(force_reauth) → reauth=1');

// Przejęcie wp-login.php: tylko zwykłe logowanie (GET), reszta akcji WordPressa bez zmian.
UbrT::ok(AppLogin::shouldTakeOver('GET', []), 'wp-login.php (GET) → panel');
UbrT::ok(AppLogin::shouldTakeOver('GET', ['loggedout' => 'true']), 'wp-login.php?loggedout → panel (komunikat „Wylogowano”)');
UbrT::ok(! AppLogin::shouldTakeOver('POST', []), 'POST wp-login.php (np. wtyczki) — bez zmian');
foreach (['lostpassword', 'rp', 'resetpass', 'logout', 'postpass', 'confirmaction'] as $action) {
	UbrT::ok(! AppLogin::shouldTakeOver('GET', ['action' => $action]), "akcja „{$action}” zostaje w WordPressie");
}
UbrT::ok(! AppLogin::shouldTakeOver('GET', ['interim-login' => '1']), 'okienko ponownego logowania w wp-admin — bez zmian');
UbrT::ok(! AppLogin::shouldTakeOver('GET', ['checkemail' => 'confirm']), 'potwierdzenie wysłania linku resetu — bez zmian');
UbrT::ok(! AppLogin::shouldTakeOver('GET', ['native' => '1']), 'awaryjnie: ?native=1 = formularz WordPressa');

// Cel po zalogowaniu.
UbrT::eq($app, AppLogin::safeRedirect(''), 'pusty redirect_to → aplikacja');
UbrT::eq($app, AppLogin::safeRedirect('https://evil.example/phish'), 'obca domena → aplikacja (bez otwartego przekierowania)');
UbrT::eq($app, AppLogin::safeRedirect($loginPage), 'strona logowania → aplikacja (bez pętli)');
UbrT::eq($app, AppLogin::safeRedirect(site_url('wp-login.php')), 'wp-login.php → aplikacja (bez pętli)');
UbrT::eq(AppPages::url('prospects'), AppLogin::safeRedirect(AppPages::url('prospects')), 'adres aplikacji z tej samej domeny zachowany');

$user = new WP_User(0);
$user->ID = 1;
$lr = app(AppLogin::class);
UbrT::eq($app, $lr->loginRedirect(admin_url(), '', $user), 'login_redirect: brak celu (domyślnie wp-admin) → aplikacja');
UbrT::eq($app, $lr->loginRedirect(admin_url(), admin_url(), $user), 'login_redirect: cel = kokpit WordPressa → aplikacja');
UbrT::eq(admin_url('plugins.php'), $lr->loginRedirect(admin_url('plugins.php'), admin_url('plugins.php'), $user), 'login_redirect: konkretna strona wp-admin zachowana');
UbrT::eq(admin_url(), $lr->loginRedirect(admin_url(), '', new WP_Error('x')), 'login_redirect: nieudane logowanie — bez zmian');

// Link wylogowania: jedno kodowanie (Blade koduje sam) — nonce nie ginie.
$logout = html_entity_decode(wp_logout_url(AppLogin::url(['loggedout' => '1'])), ENT_QUOTES | ENT_HTML5);
UbrT::ok(str_contains($logout, '&_wpnonce=') && ! str_contains($logout, '&amp;'), 'link „Wyloguj”: bez podwójnego kodowania (&amp;) — nonce zachowany');

// Limit prób.
$ip = '203.0.113.' . wp_rand(10, 250);
$clear = function () use ($ip) {
	foreach (['ubrtest-login', 'ubrtest-other'] as $l) {
		delete_transient('ubr_login_f_' . md5($ip . '|' . $l));
	}
	delete_transient('ubr_login_ip_' . md5($ip));
};
$clear();
for ($i = 0; $i < LoginThrottle::MAX_PER_LOGIN - 1; $i++) {
	LoginThrottle::fail($ip, 'ubrtest-login');
}
UbrT::eq(0, LoginThrottle::lockedFor($ip, 'ubrtest-login'), (LoginThrottle::MAX_PER_LOGIN - 1) . ' błędne próby — jeszcze bez blokady');
LoginThrottle::fail($ip, 'UbrTest-Login ');
$wait = LoginThrottle::lockedFor($ip, 'ubrtest-login');
UbrT::ok($wait > 0 && $wait <= LoginThrottle::WINDOW, LoginThrottle::MAX_PER_LOGIN . '. błędna próba → blokada pary IP + login na ≤ 15 min (wielkość liter i spacje bez znaczenia)');
UbrT::eq(0, LoginThrottle::lockedFor($ip, 'ubrtest-other'), 'inny login z tego samego IP — bez blokady');
UbrT::eq(0, LoginThrottle::lockedFor('198.51.100.7', 'ubrtest-login'), 'ten sam login z innego IP — bez blokady (atakujący nie zablokuje właściciela)');
LoginThrottle::succeed($ip, 'ubrtest-login');
UbrT::eq(0, LoginThrottle::lockedFor($ip, 'ubrtest-login'), 'udane logowanie zeruje licznik pary');
for ($i = 0; $i < LoginThrottle::MAX_PER_IP; $i++) {
	LoginThrottle::fail($ip, 'ubrtest-other');
}
UbrT::ok(LoginThrottle::lockedFor($ip, 'ubrtest-login') > 0, LoginThrottle::MAX_PER_IP . ' błędnych prób z IP (dowolne loginy) → blokada adresu IP');
set_transient('ubr_login_ip_' . md5($ip), ['n' => 99, 'start' => time() - LoginThrottle::WINDOW - 5], 60);
set_transient('ubr_login_f_' . md5($ip . '|ubrtest-other'), ['n' => 99, 'start' => time() - LoginThrottle::WINDOW - 5], 60);
UbrT::eq(0, LoginThrottle::lockedFor($ip, 'ubrtest-other'), 'po 15 minutach blokada wygasa');
$clear();
