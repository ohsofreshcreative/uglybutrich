<?php

namespace App\Ubr\Domain\Phones;

/**
 * Normalizacja numerów telefonów (domyślnie Polska) do E.164 — bez zgadywania.
 *
 * Obsługiwane zapisy: „+48 123 456 789”, „123 456 789”, „+48 (12) 345 67 89”, „(0-12) 345 67 89”, „0048 …”, „tel:+48123456789”,
 * numery zagraniczne z „+” lub „00” oraz numery wewnętrzne („wew. 12”, „ext. 12”). Odrzucane: ciągi niebędące numerem
 * (np. NIP 10 cyfr bez prefiksu, REGON, rachunki bankowe, kody pocztowe), numery polskie o nieistniejącym prefiksie.
 */
final class PhoneNumber
{
	/** Prefiksy (dwie pierwsze cyfry) numerów polskich: strefy stacjonarne, komórkowe, VoIP (39), infolinie (80). */
	private const PL_PREFIXES = [
		'12', '13', '14', '15', '16', '17', '18', '22', '23', '24', '25', '29', '32', '33', '34', '41', '42', '43', '44', '46', '48',
		'52', '54', '55', '56', '58', '59', '61', '62', '63', '65', '67', '68', '71', '74', '75', '76', '77', '81', '82', '83', '84',
		'85', '86', '87', '89', '91', '94', '95',
		'45', '50', '51', '53', '57', '60', '66', '69', '72', '73', '78', '79', '88',
		'39', '80',
	];

	private const PL_MOBILE = ['45', '50', '51', '53', '57', '60', '66', '69', '72', '73', '78', '79', '88'];

	/** Kierunkowe krajów (najczęstsze w danych firm z Polski) → kod kraju. Dłuższe kody sprawdzane najpierw. */
	private const CALLING_CODES = [
		'420' => 'CZ', '421' => 'SK', '380' => 'UA', '370' => 'LT', '371' => 'LV', '372' => 'EE', '375' => 'BY', '358' => 'FI', '359' => 'BG',
		'385' => 'HR', '386' => 'SI', '381' => 'RS', '351' => 'PT', '353' => 'IE', '352' => 'LU', '971' => 'AE', '972' => 'IL', '373' => 'MD',
		'48' => 'PL', '49' => 'DE', '44' => 'GB', '33' => 'FR', '39' => 'IT', '34' => 'ES', '31' => 'NL', '32' => 'BE', '41' => 'CH', '43' => 'AT',
		'46' => 'SE', '47' => 'NO', '45' => 'DK', '36' => 'HU', '40' => 'RO', '30' => 'GR', '90' => 'TR', '86' => 'CN', '81' => 'JP', '82' => 'KR',
		'1' => 'US', '7' => 'RU',
	];

	/**
	 * @return array{raw: string, e164: string, country: ?string, national: string, extension: ?string, mobile: bool}|null
	 */
	public static function parse(string $value, string $defaultCountry = 'PL'): ?array
	{
		$raw = trim(html_entity_decode(rawurldecode($value), ENT_QUOTES, 'UTF-8'));

		if ($raw === '' || mb_strlen($raw) > 60) {
			return null;
		}

		$text = preg_replace('/^(tel|callto|phone):/i', '', $raw) ?? $raw;
		$extension = null;

		// Numer wewnętrzny: „wew. 12”, „ext. 12”, „w. 12”, „x12”, „;ext=12” (RFC 3966).
		if (preg_match('/\s*(?:;ext=|\b(?:wew|ext|w|x)\.?\s*)(\d{1,6})\s*$/iu', $text, $m)) {
			$extension = $m[1];
			$text = (string) substr($text, 0, -strlen($m[0]));
		}

		// Dozwolone znaki zapisu numeru; inne litery = to nie jest numer telefonu.
		if (preg_match('/[^\d\s+().\/\-\x{2013}\x{00A0}]/u', $text)) {
			return null;
		}

		$plus = str_starts_with(ltrim($text), '+');
		$digits = preg_replace('/\D+/', '', $text) ?? '';

		if ($digits === '') {
			return null;
		}

		if (! $plus && str_starts_with($digits, '00')) {
			$plus = true;
			$digits = substr($digits, 2);
		}

		if ($plus) {
			return self::international($raw, $digits, $extension);
		}

		if ($defaultCountry !== 'PL') {
			return null; // bez prefiksu kraju potrafimy rozpoznać tylko numery polskie
		}

		// Stary zapis z zerem („0 12 …”, „(022) …”).
		if (strlen($digits) === 10 && $digits[0] === '0') {
			$digits = substr($digits, 1);
		}

		// „48 123 456 789” bez plusa.
		if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
			$digits = substr($digits, 2);
		}

		return self::polish($raw, $digits, $extension);
	}

	public static function format(string $e164): string
	{
		if (str_starts_with($e164, '+48') && strlen($e164) === 12) {
			$n = substr($e164, 3);

			return in_array(substr($n, 0, 2), self::PL_MOBILE, true) || str_starts_with($n, '80') || str_starts_with($n, '39')
				? substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6, 3)
				: substr($n, 0, 2) . ' ' . substr($n, 2, 3) . ' ' . substr($n, 5, 2) . ' ' . substr($n, 7, 2);
		}

		foreach (self::CALLING_CODES as $code => $country) {
			if (str_starts_with(substr($e164, 1), (string) $code)) {
				return '+' . $code . ' ' . trim(chunk_split(substr($e164, 1 + strlen((string) $code)), 3, ' '));
			}
		}

		return $e164;
	}

	/**
	 * @return array{raw: string, e164: string, country: ?string, national: string, extension: ?string, mobile: bool}|null
	 */
	private static function polish(string $raw, string $digits, ?string $extension): ?array
	{
		if (strlen($digits) !== 9 || ! in_array(substr($digits, 0, 2), self::PL_PREFIXES, true)) {
			return null;
		}

		// Infolinie tylko 800/801/804 (inne 80x nie istnieją).
		if (str_starts_with($digits, '80') && ! in_array(substr($digits, 0, 3), ['800', '801', '804'], true)) {
			return null;
		}

		$e164 = '+48' . $digits;

		return ['raw' => $raw, 'e164' => $e164, 'country' => 'PL', 'national' => self::format($e164), 'extension' => $extension, 'mobile' => in_array(substr($digits, 0, 2), self::PL_MOBILE, true)];
	}

	/**
	 * @return array{raw: string, e164: string, country: ?string, national: string, extension: ?string, mobile: bool}|null
	 */
	private static function international(string $raw, string $digits, ?string $extension): ?array
	{
		if (str_starts_with($digits, '48')) {
			$rest = substr($digits, 2);

			// „+48 (0) 12 …” — zbędne zero po kierunkowym.
			if (strlen($rest) === 10 && $rest[0] === '0') {
				$rest = substr($rest, 1);
			}

			return self::polish($raw, $rest, $extension);
		}

		if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
			return null;
		}

		$country = null;

		foreach (self::CALLING_CODES as $code => $cc) {
			if (str_starts_with($digits, (string) $code)) {
				$country = $cc;
				break;
			}
		}

		$e164 = '+' . $digits;

		return ['raw' => $raw, 'e164' => $e164, 'country' => $country, 'national' => self::format($e164), 'extension' => $extension, 'mobile' => false];
	}
}
