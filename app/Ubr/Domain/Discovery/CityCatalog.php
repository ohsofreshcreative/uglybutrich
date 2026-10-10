<?php

namespace App\Ubr\Domain\Discovery;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;

/**
 * Miasta → województwo + warianty nazw (polska, bez znaków diakrytycznych, angielska).
 *
 * Służy do: (1) przekazania miasta do Apollo w wariantach, które Apollo może stosować („Kraków”, „Krakow”, „Cracow”),
 * (2) ustalenia województwa z miasta podanego przez rejestr/stronę/dostawcę, (3) wykrycia sprzeczności miasto ↔ województwo.
 * Lista obejmuje miasta na prawach powiatu i większe ośrodki przemysłowe. Miasto spoza listy = województwo nieznane
 * (nie zgadujemy) — chyba że województwo podał rejestr.
 */
final class CityCatalog
{
	/**
	 * nazwa PL => [klucz województwa, warianty EN/inne]
	 *
	 * @var array<string, array{0: string, 1?: list<string>}>
	 */
	public const CITIES = [
		'Warszawa' => ['mazowieckie', ['Warsaw']],
		'Radom' => ['mazowieckie'], 'Płock' => ['mazowieckie'], 'Siedlce' => ['mazowieckie'], 'Ostrołęka' => ['mazowieckie'],
		'Pruszków' => ['mazowieckie'], 'Legionowo' => ['mazowieckie'], 'Piaseczno' => ['mazowieckie'], 'Ożarów Mazowiecki' => ['mazowieckie'],
		'Mińsk Mazowiecki' => ['mazowieckie'], 'Ciechanów' => ['mazowieckie'], 'Grodzisk Mazowiecki' => ['mazowieckie'], 'Wołomin' => ['mazowieckie'],
		'Błonie' => ['mazowieckie'], 'Raszyn' => ['mazowieckie'], 'Janki' => ['mazowieckie'], 'Nadarzyn' => ['mazowieckie'], 'Sochaczew' => ['mazowieckie'],
		'Kraków' => ['malopolskie', ['Krakow', 'Cracow']],
		'Tarnów' => ['malopolskie'], 'Nowy Sącz' => ['malopolskie'], 'Oświęcim' => ['malopolskie', ['Auschwitz']], 'Wieliczka' => ['malopolskie'], 'Niepołomice' => ['malopolskie'], 'Skawina' => ['malopolskie'], 'Chrzanów' => ['malopolskie'],
		'Wrocław' => ['dolnoslaskie', ['Wroclaw', 'Breslau']],
		'Wałbrzych' => ['dolnoslaskie'], 'Legnica' => ['dolnoslaskie'], 'Jelenia Góra' => ['dolnoslaskie'], 'Lubin' => ['dolnoslaskie'], 'Głogów' => ['dolnoslaskie'], 'Świdnica' => ['dolnoslaskie'], 'Bolesławiec' => ['dolnoslaskie'], 'Oleśnica' => ['dolnoslaskie'], 'Kobierzyce' => ['dolnoslaskie'], 'Dzierżoniów' => ['dolnoslaskie'],
		'Poznań' => ['wielkopolskie', ['Poznan', 'Posen']],
		'Kalisz' => ['wielkopolskie'], 'Konin' => ['wielkopolskie'], 'Piła' => ['wielkopolskie'], 'Ostrów Wielkopolski' => ['wielkopolskie'], 'Gniezno' => ['wielkopolskie'], 'Leszno' => ['wielkopolskie'], 'Swarzędz' => ['wielkopolskie'], 'Września' => ['wielkopolskie'], 'Tarnowo Podgórne' => ['wielkopolskie'], 'Komorniki' => ['wielkopolskie'], 'Luboń' => ['wielkopolskie'],
		'Gdańsk' => ['pomorskie', ['Gdansk', 'Danzig']],
		'Gdynia' => ['pomorskie'], 'Sopot' => ['pomorskie'], 'Słupsk' => ['pomorskie'], 'Tczew' => ['pomorskie'], 'Starogard Gdański' => ['pomorskie'], 'Wejherowo' => ['pomorskie'], 'Pruszcz Gdański' => ['pomorskie'], 'Chojnice' => ['pomorskie'], 'Kwidzyn' => ['pomorskie'], 'Malbork' => ['pomorskie'],
		'Łódź' => ['lodzkie', ['Lodz']],
		'Piotrków Trybunalski' => ['lodzkie'], 'Pabianice' => ['lodzkie'], 'Tomaszów Mazowiecki' => ['lodzkie'], 'Bełchatów' => ['lodzkie'], 'Zgierz' => ['lodzkie'], 'Skierniewice' => ['lodzkie'], 'Radomsko' => ['lodzkie'], 'Kutno' => ['lodzkie'], 'Sieradz' => ['lodzkie'], 'Zduńska Wola' => ['lodzkie'], 'Stryków' => ['lodzkie'],
		'Katowice' => ['slaskie', ['Kattowitz']],
		'Częstochowa' => ['slaskie', ['Czestochowa']], 'Sosnowiec' => ['slaskie'], 'Gliwice' => ['slaskie'], 'Zabrze' => ['slaskie'], 'Bielsko-Biała' => ['slaskie', ['Bielsko-Biala', 'Bielsko Biala']],
		'Bytom' => ['slaskie'], 'Rybnik' => ['slaskie'], 'Ruda Śląska' => ['slaskie'], 'Tychy' => ['slaskie'], 'Dąbrowa Górnicza' => ['slaskie'], 'Chorzów' => ['slaskie'], 'Jaworzno' => ['slaskie'], 'Jastrzębie-Zdrój' => ['slaskie'],
		'Mysłowice' => ['slaskie'], 'Siemianowice Śląskie' => ['slaskie'], 'Żory' => ['slaskie'], 'Piekary Śląskie' => ['slaskie'], 'Świętochłowice' => ['slaskie'], 'Racibórz' => ['slaskie'], 'Mikołów' => ['slaskie'], 'Knurów' => ['slaskie'], 'Cieszyn' => ['slaskie'], 'Będzin' => ['slaskie'], 'Czechowice-Dziedzice' => ['slaskie'], 'Pszczyna' => ['slaskie'], 'Żywiec' => ['slaskie'], 'Zawiercie' => ['slaskie'], 'Wodzisław Śląski' => ['slaskie'], 'Tarnowskie Góry' => ['slaskie'],
		'Lublin' => ['lubelskie'], 'Chełm' => ['lubelskie'], 'Zamość' => ['lubelskie'], 'Biała Podlaska' => ['lubelskie'], 'Puławy' => ['lubelskie'], 'Świdnik' => ['lubelskie'], 'Kraśnik' => ['lubelskie'],
		'Białystok' => ['podlaskie', ['Bialystok']], 'Suwałki' => ['podlaskie'], 'Łomża' => ['podlaskie'],
		'Rzeszów' => ['podkarpackie', ['Rzeszow']], 'Przemyśl' => ['podkarpackie'], 'Stalowa Wola' => ['podkarpackie'], 'Mielec' => ['podkarpackie'], 'Krosno' => ['podkarpackie'], 'Tarnobrzeg' => ['podkarpackie'], 'Dębica' => ['podkarpackie'], 'Jasło' => ['podkarpackie'], 'Sanok' => ['podkarpackie'], 'Jarosław' => ['podkarpackie'],
		'Kielce' => ['swietokrzyskie'], 'Ostrowiec Świętokrzyski' => ['swietokrzyskie'], 'Starachowice' => ['swietokrzyskie'], 'Skarżysko-Kamienna' => ['swietokrzyskie'], 'Końskie' => ['swietokrzyskie'],
		'Szczecin' => ['zachodniopomorskie', ['Stettin']], 'Koszalin' => ['zachodniopomorskie'], 'Stargard' => ['zachodniopomorskie'], 'Kołobrzeg' => ['zachodniopomorskie'], 'Świnoujście' => ['zachodniopomorskie'], 'Police' => ['zachodniopomorskie'], 'Szczecinek' => ['zachodniopomorskie'],
		'Bydgoszcz' => ['kujawsko-pomorskie'], 'Toruń' => ['kujawsko-pomorskie', ['Torun']], 'Włocławek' => ['kujawsko-pomorskie'], 'Grudziądz' => ['kujawsko-pomorskie'], 'Inowrocław' => ['kujawsko-pomorskie'], 'Brodnica' => ['kujawsko-pomorskie'], 'Świecie' => ['kujawsko-pomorskie'],
		'Olsztyn' => ['warminsko-mazurskie'], 'Elbląg' => ['warminsko-mazurskie', ['Elblag']], 'Ełk' => ['warminsko-mazurskie'], 'Ostróda' => ['warminsko-mazurskie'], 'Iława' => ['warminsko-mazurskie'], 'Giżycko' => ['warminsko-mazurskie'],
		'Opole' => ['opolskie'], 'Kędzierzyn-Koźle' => ['opolskie'], 'Nysa' => ['opolskie'], 'Brzeg' => ['opolskie'], 'Krapkowice' => ['opolskie'],
		'Zielona Góra' => ['lubuskie', ['Zielona Gora']], 'Gorzów Wielkopolski' => ['lubuskie', ['Gorzow Wielkopolski']], 'Nowa Sól' => ['lubuskie'], 'Żary' => ['lubuskie'], 'Żagań' => ['lubuskie'], 'Świebodzin' => ['lubuskie'], 'Kostrzyn nad Odrą' => ['lubuskie'],
	];

	/**
	 * Klucz porównania: małe litery, bez znaków diakrytycznych i interpunkcji.
	 */
	public static function key(?string $name): string
	{
		$name = trim((string) $name);

		if ($name === '') {
			return '';
		}

		return trim(preg_replace('/[^a-z0-9]+/', ' ', N::foldAscii($name)) ?? '');
	}

	/**
	 * @return array<string, array{name: string, region: string}>
	 */
	private static function index(): array
	{
		static $index = null;

		if ($index !== null) {
			return $index;
		}

		$index = [];

		foreach (self::CITIES as $name => $def) {
			$entry = ['name' => $name, 'region' => $def[0]];
			$index[self::key($name)] = $entry;

			foreach ($def[1] ?? [] as $alias) {
				$index[self::key($alias)] ??= $entry;
			}
		}

		return $index;
	}

	/**
	 * @return array{name: string, region: string}|null
	 */
	public static function find(?string $name): ?array
	{
		$key = self::key($name);

		return $key !== '' ? (self::index()[$key] ?? null) : null;
	}

	/**
	 * Województwo dla miasta (null = miasto spoza katalogu — nie zgadujemy).
	 */
	public static function regionFor(?string $city): ?string
	{
		return self::find($city)['region'] ?? null;
	}

	/**
	 * Kanoniczna polska nazwa miasta (z katalogu) albo nazwa wpisana przez użytkownika.
	 */
	public static function canonical(?string $city): ?string
	{
		$city = trim((string) $city);

		return $city === '' ? null : (self::find($city)['name'] ?? $city);
	}

	/**
	 * Warianty nazwy do filtra Apollo: polska, bez diakrytyków, angielska (bez duplikatów).
	 *
	 * @return list<string>
	 */
	public static function variants(?string $city): array
	{
		$city = trim((string) $city);

		if ($city === '') {
			return [];
		}

		$canonical = self::canonical($city);
		$variants = [$canonical];
		$ascii = N::foldAscii($canonical);
		// foldAscii zwraca małe litery — przywróć wielkie litery na początku słów.
		$variants[] = implode('-', array_map(fn ($p) => mb_convert_case($p, MB_CASE_TITLE), explode('-', $ascii)));

		foreach (self::CITIES[$canonical][1] ?? [] as $alias) {
			$variants[] = $alias;
		}

		$out = [];

		foreach ($variants as $v) {
			$out[mb_strtolower($v)] ??= $v;
		}

		return array_values($out);
	}

	/**
	 * Czy dwie nazwy oznaczają to samo miasto (niezależnie od diakrytyków i wariantów EN).
	 */
	public static function same(?string $a, ?string $b): bool
	{
		$ka = self::key($a);
		$kb = self::key($b);

		if ($ka === '' || $kb === '') {
			return false;
		}

		if ($ka === $kb) {
			return true;
		}

		$fa = self::find($a);
		$fb = self::find($b);

		return $fa && $fb && $fa['name'] === $fb['name'];
	}
}
