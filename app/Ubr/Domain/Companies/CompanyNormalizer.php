<?php

namespace App\Ubr\Domain\Companies;

/**
 * Normalizacja nazw, identyfikatorów i domen. Czysta logika, bez zależności od WordPressa.
 */
final class CompanyNormalizer
{
	/**
	 * Formy prawne usuwane przy porównywaniu nazw (kolejność: dłuższe najpierw).
	 */
	private const LEGAL_FORMS = [
		'spolka z ograniczona odpowiedzialnoscia',
		'spolka komandytowo akcyjna',
		'spolka komandytowa',
		'spolka jawna',
		'spolka akcyjna',
		'spolka cywilna',
		'sp z o o sp k',
		'sp z o o sp j',
		'sp z oo',
		'sp z o o',
		'sp zoo',
		'sp k',
		'sp j',
		'sp p',
		's c',
		's a',
		'ska',
		'spolka',
		'sp',
		'gmbh',
		'ltd',
		'llc',
		'inc',
		'p p h u',
		'p p h',
		'p h u',
		'f h u',
		'pphu',
		'pph',
		'phu',
		'fhu',
		'zpu',
	];

	public static function foldAscii(string $value): string
	{
		$map = [
			'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
			'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
		];
		$value = strtr($value, $map);

		if (function_exists('remove_accents')) {
			$value = remove_accents($value);
		} elseif (function_exists('iconv')) {
			$value = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
		}

		return mb_strtolower($value);
	}

	/**
	 * Nazwa do porównań/deduplikacji: bez diakrytyków, interpunkcji i form prawnych.
	 */
	public static function normalizeName(string $name): string
	{
		$value = self::foldAscii(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
		$value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
		$value = ' ' . trim(preg_replace('/\s+/', ' ', $value) ?? '') . ' ';

		foreach (self::LEGAL_FORMS as $form) {
			$value = str_replace(' ' . $form . ' ', ' ', $value);
		}

		$value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

		return $value !== '' ? $value : trim(self::foldAscii($name));
	}

	/**
	 * Znaczące słowa nazwy (do weryfikacji strony WWW).
	 *
	 * @return list<string>
	 */
	public static function nameTokens(string $name): array
	{
		$stop = ['firma', 'przedsiebiorstwo', 'zaklad', 'zaklady', 'grupa', 'group', 'polska', 'poland', 'oddzial', 'centrum', 'the', 'and', 'oraz', 'produkcyjne', 'handlowe', 'uslugowe', 'produkcja', 'handel', 'uslugi'];
		$tokens = array_filter(
			explode(' ', self::normalizeName($name)),
			fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, $stop, true) && ! ctype_digit($t)
		);

		return array_values(array_unique($tokens));
	}

	public static function normalizeCity(?string $city): ?string
	{
		$city = trim((string) $city);

		return $city === '' ? null : mb_convert_case(mb_strtolower($city), MB_CASE_TITLE, 'UTF-8');
	}

	/**
	 * Zwraca poprawny NIP (10 cyfr, suma kontrolna) albo null.
	 */
	public static function nip(?string $value): ?string
	{
		$digits = preg_replace('/\D+/', '', preg_replace('/^\s*PL/i', '', (string) $value) ?? '') ?? '';

		if (strlen($digits) !== 10) {
			return null;
		}

		$weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
		$sum = 0;

		foreach ($weights as $i => $w) {
			$sum += (int) $digits[$i] * $w;
		}

		$check = $sum % 11;

		return ($check !== 10 && $check === (int) $digits[9]) ? $digits : null;
	}

	public static function regon(?string $value): ?string
	{
		$digits = preg_replace('/\D+/', '', (string) $value) ?? '';

		if (strlen($digits) === 9) {
			$weights = [8, 9, 2, 3, 4, 5, 6, 7];
		} elseif (strlen($digits) === 14) {
			$weights = [2, 4, 8, 5, 0, 9, 7, 3, 6, 1, 2, 4, 8];
		} else {
			return null;
		}

		$sum = 0;

		foreach ($weights as $i => $w) {
			$sum += (int) $digits[$i] * $w;
		}

		$check = $sum % 11 % 10;

		return $check === (int) $digits[strlen($digits) - 1] ? $digits : null;
	}

	public static function krs(?string $value): ?string
	{
		$digits = preg_replace('/\D+/', '', (string) $value) ?? '';

		return (strlen($digits) >= 1 && strlen($digits) <= 10 && (int) $digits > 0)
			? str_pad($digits, 10, '0', STR_PAD_LEFT)
			: null;
	}

	/**
	 * Normalizuje URL strony (dodaje schemat, usuwa fragment). Null dla nie-URL.
	 */
	public static function websiteUrl(?string $value): ?string
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		// OSM pozwala na kilka wartości rozdzielonych średnikiem — bierzemy pierwszą.
		$value = trim(explode(';', $value)[0]);

		if (! preg_match('#^https?://#i', $value)) {
			$value = 'http://' . ltrim($value, '/');
		}

		$parts = parse_url($value);

		if (! $parts || empty($parts['host']) || ! str_contains($parts['host'], '.')) {
			return null;
		}

		$host = self::host($parts['host']);

		if ($host === null) {
			return null;
		}

		$scheme = strtolower($parts['scheme'] ?? 'http');
		$path = $parts['path'] ?? '';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';

		return $scheme . '://' . ($parts['host'] === $host ? $host : strtolower($parts['host'])) . $path . $query;
	}

	/**
	 * Domena (host) bez "www." w formie małych liter / punycode.
	 */
	public static function domain(?string $url): ?string
	{
		$url = self::websiteUrl($url);

		if (! $url) {
			return null;
		}

		$host = parse_url($url, PHP_URL_HOST);

		return $host ? self::host($host) : null;
	}

	private static function host(string $host): ?string
	{
		$host = strtolower(trim($host, ". \t\n\r\0\x0B"));

		if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
			$ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			$host = $ascii ?: $host;
		}

		$host = preg_replace('/^www\d?\./', '', $host) ?? $host;

		if (! preg_match('/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
			return null;
		}

		return $host;
	}

	/**
	 * Klasyfikacja domeny: czy to oficjalna witryna firmy, czy katalog / social / pośrednik.
	 *
	 * @return 'official'|'social'|'directory'|'marketplace'|'website_builder_subdomain'|'invalid'
	 */
	public static function classifyDomain(?string $domain): string
	{
		if (! $domain) {
			return 'invalid';
		}

		$matches = static function (string $domain, array $list): bool {
			foreach ($list as $d) {
				if ($domain === $d || str_ends_with($domain, '.' . $d)) {
					return true;
				}
			}

			return false;
		};

		$social = ['facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be', 'tiktok.com', 'pinterest.com', 'goo.gl', 'maps.app.goo.gl', 'linktr.ee', 'wa.me', 'm.me', 'vk.com'];
		$directories = [
			'panoramafirm.pl', 'pkt.pl', 'aleo.com', 'oferteo.pl', 'gowork.pl', 'firmy.net', 'cylex-polska.pl', 'cylex.pl', 'zumi.pl', 'baza-firm.com.pl',
			'krs-online.com.pl', 'rejestr.io', 'owg.pl', 'infoveriti.pl', 'bizraport.pl', 'ceidg.gov.pl', 'biznes.gov.pl', 'yelp.com', 'yelp.pl',
			'tripadvisor.com', 'tripadvisor.pl', 'booking.com', 'google.com', 'google.pl', 'business.site', 'firmania.pl', 'europages.com', 'europages.pl',
			'kompass.com', 'opencorporates.com', 'dnb.com', 'targeo.pl', 'katalog.onet.pl', 'pf.pl', 'znanylekarz.pl', 'wikipedia.org', 'wikidata.org',
			'openstreetmap.org', 'krs-pobierz.pl', 'mojepanstwo.pl', 'emis.com', 'bing.com',
		];
		$marketplaces = ['allegro.pl', 'olx.pl', 'otomoto.pl', 'amazon.com', 'amazon.pl', 'ebay.com', 'ebay.pl', 'etsy.com', 'erli.pl', 'ceneo.pl', 'sprzedajemy.pl', 'shopee.pl'];
		$builders = ['wixsite.com', 'blogspot.com', 'wordpress.com', 'weebly.com', 'jimdo.com', 'jimdofree.com', 'webnode.pl', 'webnode.com', 'strikingly.com', 'site123.me', 'mystrikingly.com', 'sites.google.com', 'github.io', 'netlify.app', 'vercel.app', 'manifo.com', 'cba.pl', 'za.pl', 'republika.pl'];

		return match (true) {
			$matches($domain, $social) => 'social',
			$matches($domain, $marketplaces) => 'marketplace',
			$matches($domain, $builders) => 'website_builder_subdomain',
			$matches($domain, $directories) => 'directory',
			default => 'official',
		};
	}
}
