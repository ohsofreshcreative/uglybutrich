<?php

namespace App\Ubr\Support;

/**
 * Blokada procesu na poziomie MySQL/MariaDB (GET_LOCK). Atomowa (brak wyścigu jak przy add_option, które w WP jest
 * upsertem) i zwalniana automatycznie, gdy proces PHP zginie (koniec połączenia z bazą) — bez „wiszących” blokad
 * do wygaśnięcia. Nazwa jest prefiksowana bazą i prefiksem tabel, więc kilka instalacji na jednym serwerze się nie blokuje.
 */
final class Lock
{
	public static function acquire(string $name): bool
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::key($name))) === 1;
	}

	/** Blokada z oczekiwaniem (maks. `$seconds` s) — np. wspólny limit częstotliwości dostawcy dla wielu procesów. */
	public static function acquireWait(string $name, int $seconds): bool
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::key($name), max(0, $seconds))) === 1;
	}

	public static function release(string $name): void
	{
		global $wpdb;

		$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::key($name)));
	}

	public static function isFree(string $name): bool
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', self::key($name))) === 1;
	}

	private static function key(string $name): string
	{
		global $wpdb;

		return substr(md5(DB_NAME . '|' . $wpdb->prefix) . ':' . $name, 0, 64);
	}
}
