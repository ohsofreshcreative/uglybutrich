<?php

namespace App\Ubr\Domain\Sequences;

/**
 * Prompt 10.5 — adres odbiorcy: normalizacja i kontrola techniczna (składnia + domena przyjmująca pocztę).
 * Nie weryfikuje istnienia skrzynki (bez „pingowania” serwerów pocztowych).
 */
final class EmailAddress
{
	public static function normalize(?string $email): string
	{
		return strtolower(trim((string) $email));
	}

	public static function syntaxOk(string $email): bool
	{
		$email = self::normalize($email);

		return strlen($email) <= 190
			&& (bool) is_email($email)
			&& (bool) preg_match('/^[a-z0-9._%+\-]+@([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $email)
			&& ! str_contains($email, '..');
	}

	public static function domain(string $email): string
	{
		return (string) substr(strrchr(self::normalize($email), '@') ?: '', 1);
	}

	/**
	 * Domena ma rekord MX (albo A — RFC 5321 „implicit MX”). Wynik w cache 1 dzień. Filtr `ubr/mail/domain_accepts` (testy).
	 */
	public static function domainAcceptsMail(string $email): bool
	{
		$domain = self::domain($email);

		if ($domain === '') {
			return false;
		}

		$filtered = apply_filters('ubr/mail/domain_accepts', null, $domain);

		if ($filtered !== null) {
			return (bool) $filtered;
		}

		$key = 'ubr_mx_' . md5($domain);
		$cached = get_transient($key);

		if ($cached !== false) {
			return $cached === 'yes';
		}

		$ok = function_exists('checkdnsrr') && (checkdnsrr($domain . '.', 'MX') || checkdnsrr($domain . '.', 'A'));
		set_transient($key, $ok ? 'yes' : 'no', DAY_IN_SECONDS);

		return $ok;
	}

	/**
	 * @return array{ok: bool, message: ?string}
	 */
	public static function check(?string $email): array
	{
		$email = self::normalize($email);

		if (! self::syntaxOk($email)) {
			return ['ok' => false, 'message' => 'Niepoprawny adres e-mail.'];
		}

		if (! self::domainAcceptsMail($email)) {
			return ['ok' => false, 'message' => 'Domena adresu nie przyjmuje poczty (brak rekordu MX / A).'];
		}

		return ['ok' => true, 'message' => null];
	}
}
