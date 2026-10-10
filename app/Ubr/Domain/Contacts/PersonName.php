<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Imię i nazwisko osoby ze źródła (strona firmy, Apollo, dane wpisane ręcznie).
 *
 * Walidacja jest celowo ostrożna: pierwszy człon musi być znanym imieniem (słownik polskich i popularnych
 * zagranicznych imion), żeby nagłówki typu „Strona Główna” czy „Obróbka Metali” nie zostały uznane za osoby.
 * Nie wymyślamy brakujących części — niepełne lub zamaskowane dane nie tworzą osoby.
 */
final class PersonName
{
	private const HONORIFICS = '/^(dr|dr\.|mgr|mgr\.|inż|inż\.|inz\.|prof|prof\.|hab\.|hab|lek\.|med\.|phd|ph\.d\.|mba|ing\.|dipl\.|pan|pani|mr\.?|mrs\.?|ms\.?)$/iu';

	private const STOP = ['zarzad', 'zarzadu', 'prezes', 'dyrektor', 'marketing', 'kontakt', 'firma', 'spolka', 'grupa', 'group', 'oferta', 'strona', 'glowna', 'sprzedaz', 'dzial', 'biuro', 'serwis', 'rada', 'nadzorcza', 'polska', 'poland', 'team', 'zespol', 'manager', 'head', 'director', 'board', 'czlonek', 'wiceprezes', 'prokurent', 'sekretariat', 'centrala', 'oddzial', 'ltd', 'gmbh', 'sp', 'sa', 'o.o', 'news', 'aktualnosci', 'kariera', 'produkty', 'uslugi', 'realizacje'];

	public function __construct(
		public readonly string $first,
		public readonly string $last,
		public readonly ?string $middle = null,
	) {
	}

	public function full(): string
	{
		return trim($this->first . ' ' . ($this->middle ? $this->middle . ' ' : '') . $this->last);
	}

	/**
	 * Klucz deduplikacji: imię + nazwisko bez diakrytyków, małymi literami (bez drugiego imienia).
	 */
	public function key(): string
	{
		return self::fold($this->first) . ' ' . self::fold($this->last);
	}

	/**
	 * Parsuje tekst w „Jan Kowalski”, „JAN KOWALSKI”, „dr inż. Jan Maria Kowalska-Nowak”. Zwraca null, gdy to nie wygląda na osobę.
	 */
	public static function parse(string $text): ?self
	{
		$text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', strip_tags($text)) ?? '');
		$text = trim($text, " \t,;:–—-|•·()\"'");

		if ($text === '' || mb_strlen($text) > 60 || preg_match('/[\d@\/\\\\]|https?:/u', $text)) {
			return null;
		}

		$tokens = array_values(array_filter(explode(' ', $text), fn ($t) => $t !== '' && ! preg_match(self::HONORIFICS, $t)));

		if (count($tokens) < 2 || count($tokens) > 3) {
			return null;
		}

		foreach ($tokens as $t) {
			// Litery (w tym polskie), łącznik w nazwisku dwuczłonowym, apostrof (O'Neill). Każdy człon z wielkiej litery.
			if (! preg_match("/^\p{Lu}[\p{L}'’]*(-\p{Lu}[\p{L}'’]*)?$/u", $t) || mb_strlen($t) < 2 || mb_strlen($t) > 30) {
				return null;
			}

			if (in_array(self::fold($t), self::STOP, true)) {
				return null;
			}
		}

		$tokens = array_map([self::class, 'titleCase'], $tokens);

		if (! FirstNames::has($tokens[0])) {
			return null;
		}

		if (count($tokens) === 3) {
			// „Anna Maria Kowalska” — drugi człon to imię; inaczej odrzucamy (np. „Jan Kowalski Prezes”).
			if (! FirstNames::has($tokens[1])) {
				return null;
			}

			return new self($tokens[0], $tokens[2], $tokens[1]);
		}

		// Nazwisko nie może być imieniem z listy, jeśli wygląda na dwa imiona („Anna Maria”) — wtedy brak nazwiska.
		if (FirstNames::has($tokens[1]) && ! FirstNames::alsoSurname($tokens[1])) {
			return null;
		}

		return new self($tokens[0], $tokens[1]);
	}

	/**
	 * KRS (API MS) maskuje dane osób: pierwsza litera + gwiazdki do pełnej długości („Marek” → „M****”).
	 * Zgodność = ta sama pierwsza litera i ta sama długość imienia i nazwiska.
	 */
	public function matchesMask(string $firstMask, string $lastMask): bool
	{
		return self::maskMatch($this->first, $firstMask) && self::maskMatch($this->last, $lastMask);
	}

	public static function maskMatch(string $value, string $mask): bool
	{
		$mask = trim($mask);
		$value = trim($value);

		if ($mask === '' || $value === '') {
			return false;
		}

		$first = mb_strtoupper(mb_substr($mask, 0, 1));

		if ($first === '*') {
			return false;
		}

		$sameLetter = mb_strtoupper(mb_substr($value, 0, 1)) === $first || self::fold(mb_substr($value, 0, 1)) === self::fold($first);

		return $sameLetter && mb_strlen($value) === mb_strlen($mask);
	}

	/**
	 * Inicjały z maski KRS („M**** A******” → „M. A.”) — do wyświetlania struktury zarządu bez danych osobowych.
	 */
	public static function initials(string $firstMask, string $lastMask): string
	{
		return mb_substr(trim($firstMask), 0, 1) . '. ' . mb_substr(trim($lastMask), 0, 1) . '.';
	}

	public static function fold(string $value): string
	{
		$value = mb_strtolower(trim($value));
		$value = strtr($value, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'í' => 'i', 'ú' => 'u', 'č' => 'c', 'š' => 's', 'ž' => 'z', 'ř' => 'r', '’' => "'"]);

		return preg_replace('/\s+/', ' ', $value) ?? $value;
	}

	private static function titleCase(string $token): string
	{
		if (mb_strtoupper($token) !== $token) {
			return $token;
		}

		return implode('-', array_map(fn ($p) => mb_convert_case(mb_strtolower($p), MB_CASE_TITLE), explode('-', $token)));
	}
}
