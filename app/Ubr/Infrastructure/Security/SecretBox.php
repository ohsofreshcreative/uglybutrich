<?php

namespace App\Ubr\Infrastructure\Security;

use RuntimeException;

/**
 * Szyfrowanie sekretów (tokeny OAuth) po stronie serwera: libsodium secretbox (XSalsa20-Poly1305).
 *
 * Klucz NIGDY nie jest w bazie:
 * - zalecane: `define('UBR_ENCRYPTION_KEY', '<base64 32 bajtów>');` w wp-config.php (`wp ubr secrets key` generuje wartość),
 * - awaryjnie: klucz wyprowadzony z AUTH_KEY + SECURE_AUTH_KEY z wp-config.php (stałe, nie opcje w bazie).
 * Bez żadnego z nich szyfrowanie jest niedostępne i integracje z tokenami są wyłączone.
 *
 * Format: `v1:<key_id>:<base64(nonce|ciphertext)>`; key_id = 8 znaków skrótu klucza (wykrycie zmiany klucza).
 */
class SecretBox
{
	public static function available(): bool
	{
		return function_exists('sodium_crypto_secretbox') && self::key() !== null;
	}

	public static function source(): ?string
	{
		if (self::dedicatedKey() !== null) {
			return 'UBR_ENCRYPTION_KEY (wp-config.php)';
		}

		return self::derivedKey() !== null ? 'wyprowadzony z AUTH_KEY / SECURE_AUTH_KEY (wp-config.php) — zalecany osobny UBR_ENCRYPTION_KEY' : null;
	}

	public static function keyId(): ?string
	{
		$key = self::key();

		return $key !== null ? substr(hash('sha256', 'ubr-key-id' . $key), 0, 8) : null;
	}

	public function encrypt(string $plain): string
	{
		$key = self::key() ?? throw new RuntimeException('Brak klucza szyfrowania (UBR_ENCRYPTION_KEY).');
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = sodium_crypto_secretbox($plain, $nonce, $key);

		return 'v1:' . self::keyId() . ':' . base64_encode($nonce . $cipher);
	}

	/**
	 * @return string|null null = nie da się odszyfrować (inny klucz, uszkodzone dane)
	 */
	public function decrypt(?string $stored): ?string
	{
		$key = self::key();

		if ($key === null || ! is_string($stored) || ! str_starts_with($stored, 'v1:')) {
			return null;
		}

		$parts = explode(':', $stored, 3);

		if (count($parts) !== 3 || $parts[1] !== self::keyId()) {
			return null;
		}

		$raw = base64_decode($parts[2], true);

		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

		return $plain === false ? null : $plain;
	}

	private static function key(): ?string
	{
		return self::dedicatedKey() ?? self::derivedKey();
	}

	private static function dedicatedKey(): ?string
	{
		$value = defined('UBR_ENCRYPTION_KEY') ? (string) UBR_ENCRYPTION_KEY : '';
		$raw = $value !== '' ? base64_decode($value, true) : false;

		return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $raw : null;
	}

	private static function derivedKey(): ?string
	{
		if (! defined('AUTH_KEY') || ! defined('SECURE_AUTH_KEY')) {
			return null;
		}

		$material = (string) AUTH_KEY . '|' . (string) SECURE_AUTH_KEY;

		// Domyślne „put your unique phrase here” = brak prawdziwego sekretu.
		if (strlen($material) < 40 || str_contains($material, 'put your unique phrase here')) {
			return null;
		}

		return hash_hmac('sha256', 'ubr-secretbox-v1', $material, true);
	}
}
