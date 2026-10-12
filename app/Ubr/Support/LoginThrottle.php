<?php

namespace App\Ubr\Support;

/**
 * Ograniczenie prób logowania do panelu /app (ochrona przed zgadywaniem haseł).
 *
 * Dwa liczniki w oknie 15 minut:
 * - para IP + login: 5 nieudanych prób → blokada tej pary (atakujący z innego IP nie zablokuje właściciela konta),
 * - samo IP: 20 nieudanych prób (dowolne loginy) → blokada adresu IP.
 * Udane logowanie zeruje licznik pary. Dane w transientach (bez nowych tabel), klucze to skróty — bez loginów w bazie.
 */
final class LoginThrottle
{
	public const WINDOW = 15 * MINUTE_IN_SECONDS;

	public const MAX_PER_LOGIN = 5;

	public const MAX_PER_IP = 20;

	/**
	 * @return int sekundy do końca blokady (0 = można próbować)
	 */
	public static function lockedFor(string $ip, string $login): int
	{
		$wait = 0;

		foreach ([[self::pairKey($ip, $login), self::MAX_PER_LOGIN], [self::ipKey($ip), self::MAX_PER_IP]] as [$key, $max]) {
			$state = self::state($key);

			if ($state['n'] >= $max) {
				$wait = max($wait, $state['start'] + self::WINDOW - time());
			}
		}

		return max(0, $wait);
	}

	public static function fail(string $ip, string $login): void
	{
		foreach ([self::pairKey($ip, $login), self::ipKey($ip)] as $key) {
			$state = self::state($key);
			$state['n']++;
			set_transient($key, $state, max(60, $state['start'] + self::WINDOW - time()));
		}
	}

	public static function succeed(string $ip, string $login): void
	{
		delete_transient(self::pairKey($ip, $login));
	}

	public static function clientIp(): string
	{
		$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	/**
	 * @return array{n: int, start: int}
	 */
	private static function state(string $key): array
	{
		$state = get_transient($key);

		if (! is_array($state) || ! isset($state['n'], $state['start']) || (int) $state['start'] + self::WINDOW <= time()) {
			return ['n' => 0, 'start' => time()];
		}

		return ['n' => (int) $state['n'], 'start' => (int) $state['start']];
	}

	private static function pairKey(string $ip, string $login): string
	{
		return 'ubr_login_f_' . md5($ip . '|' . mb_strtolower(trim($login)));
	}

	private static function ipKey(string $ip): string
	{
		return 'ubr_login_ip_' . md5($ip);
	}
}
