<?php

namespace App\Ubr\Domain\Discovery;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;

/**
 * Predefiniowane branże — wspólny słownik dla wszystkich źródeł i dla oceny trafności.
 *
 * Dla każdej branży:
 * - terms:   rdzenie słów PL + EN do dopasowania w nazwie/opisie (początek słowa),
 * - phrases: polskie zapytania, które użytkownik może wpisać we frazie („obróbka metali”) → rozpoznanie branży,
 * - apollo:  tagi słów kluczowych Apollo (`q_organization_keyword_tags[]`, angielskie, możliwie wąskie),
 * - sic / naics: prefiksy kodów, które Apollo zwraca w wynikach (deklaracja dostawcy),
 * - pkd:     prefiksy PKD 2007 (przeważająca działalność w KRS/CEIDG/GUS — potwierdzenie w rejestrze),
 * - osm:     tagi OpenStreetMap.
 *
 * Użytkownik zawsze może wpisać własną frazę — katalog to skróty i słownik dopasowań.
 */
final class IndustryCatalog
{
	/**
	 * @return array<string, array{label: string, terms: list<string>, phrases: list<string>, apollo: list<string>, sic: list<string>, naics: list<string>, pkd: list<string>, osm: list<array{0: string, 1: string}>}>
	 */
	public static function all(): array
	{
		return apply_filters('ubr/discovery/industries', [
			'steel_structures' => [
				'label' => 'Konstrukcje stalowe',
				'terms' => ['konstrukcj', 'stalow', 'stal ', 'hal stalow', 'spawal', 'steel structur', 'structural steel', 'steel fabricat'],
				'phrases' => ['konstrukcje stalowe', 'konstrukcji stalowych', 'konstrukcja stalowa', 'hale stalowe', 'wytwórnia konstrukcji', 'steel structures'],
				'apollo' => ['steel structures', 'structural steel', 'steel fabrication', 'steel construction'],
				'sic' => ['3441', '3448', '3449', '1791'],
				'naics' => ['332311', '332312', '332313', '238120'],
				'pkd' => ['25.11', '25.12', '43.99'],
				'osm' => [['craft', 'metal_construction'], ['industrial', 'steelworks']],
			],
			'metalworking' => [
				'label' => 'Obróbka metali (CNC, spawanie, blacharstwo)',
				'terms' => ['obróbk', 'obrobk', 'metal', 'cnc', 'ślusar', 'slusar', 'blach', 'spawal', 'frezow', 'toczen', 'machining', 'metalwork', 'sheet metal'],
				'phrases' => ['obróbka metali', 'obróbka metalu', 'obróbka skrawaniem', 'obróbka cnc', 'cięcie laserem', 'gięcie blach', 'spawalnictwo', 'ślusarstwo'],
				'apollo' => ['metalworking', 'cnc machining', 'metal fabrication', 'sheet metal fabrication', 'precision machining', 'laser cutting'],
				'sic' => ['34', '3599'],
				'naics' => ['332'],
				'pkd' => ['25.5', '25.6', '25.9', '24.5'],
				'osm' => [['craft', 'metal_construction'], ['craft', 'blacksmith'], ['craft', 'welder'], ['industrial', 'metal_processing']],
			],
			'machinery' => [
				'label' => 'Producenci maszyn i urządzeń',
				'terms' => ['maszyn', 'urządze', 'urzadze', 'machinery', 'machine build'],
				'phrases' => ['produkcja maszyn', 'producent maszyn', 'producenci maszyn', 'budowa maszyn', 'maszyny przemysłowe', 'urządzenia przemysłowe'],
				'apollo' => ['industrial machinery', 'machinery manufacturing', 'machine building', 'industrial equipment manufacturing'],
				'sic' => ['35'],
				'naics' => ['333'],
				'pkd' => ['28.', '33.12', '33.20'],
				'osm' => [['industrial', 'machinery'], ['industrial', 'machine_building']],
			],
			'automation' => [
				'label' => 'Automatyka przemysłowa i robotyka',
				'terms' => ['automatyk', 'automatyz', 'robot', 'sterowni', 'plc', 'industrial automation', 'robotics'],
				'phrases' => ['automatyka przemysłowa', 'automatyzacja produkcji', 'automatyzacja procesów', 'robotyzacja', 'robotyka przemysłowa', 'integrator systemów'],
				'apollo' => ['industrial automation', 'factory automation', 'robotics', 'plc programming', 'process automation', 'machine vision'],
				'sic' => ['3569', '3625', '3823', '3829', '8711'],
				'naics' => ['333249', '333922', '335314', '334513', '541330'],
				'pkd' => ['26.51', '27.12', '28.99', '33.20', '71.12', '62.01'],
				'osm' => [],
			],
			'industrial_distribution' => [
				'label' => 'Dystrybucja przemysłowa (komponenty, napędy, łożyska)',
				'terms' => ['łożysk', 'lozysk', 'napęd', 'naped', 'pneumaty', 'hydrauli', 'komponent', 'dystrybu', 'hurtowni', 'bearing', 'industrial supply', 'distribut'],
				'phrases' => ['dystrybucja przemysłowa', 'dystrybucja komponentów', 'hurtownia przemysłowa', 'zaopatrzenie przemysłu', 'części przemysłowe', 'łożyska', 'napędy'],
				'apollo' => ['industrial distribution', 'industrial supplies', 'bearings', 'power transmission', 'pneumatics', 'hydraulics', 'mro supplies'],
				'sic' => ['5084', '5085', '5063', '5051', '5072'],
				'naics' => ['4238', '4235', '4236', '4237'],
				'pkd' => ['46.6', '46.7', '46.14', '46.18', '46.19'],
				'osm' => [['shop', 'trade'], ['shop', 'wholesale'], ['office', 'distributor']],
			],
			'plastics' => [
				'label' => 'Tworzywa sztuczne',
				'terms' => ['tworzyw', 'plastik', 'wtrysk', 'polimer', 'plastic', 'injection mold'],
				'phrases' => ['tworzywa sztuczne', 'przetwórstwo tworzyw', 'wtryskownia', 'produkcja wyrobów z tworzyw', 'formy wtryskowe'],
				'apollo' => ['plastics manufacturing', 'plastic injection molding', 'injection molding', 'plastics processing', 'thermoforming'],
				'sic' => ['30', '2821'],
				'naics' => ['326', '325211'],
				'pkd' => ['22.2', '20.16'],
				'osm' => [['industrial', 'plastics']],
			],
			'packaging' => [
				'label' => 'Produkcja opakowań',
				'terms' => ['opakowa', 'kartonow', 'tektur', 'folii', 'folie', 'packaging', 'corrugated'],
				'phrases' => ['produkcja opakowań', 'producent opakowań', 'opakowania kartonowe', 'opakowania z tektury', 'opakowania foliowe', 'opakowania'],
				'apollo' => ['packaging manufacturing', 'packaging', 'corrugated packaging', 'flexible packaging', 'cardboard boxes'],
				'sic' => ['265', '267', '3081', '3089', '3411', '3221'],
				'naics' => ['3222', '326111', '326112', '326160', '332431', '327213'],
				'pkd' => ['17.21', '22.22', '25.92', '16.24', '23.13'],
				'osm' => [['industrial', 'packaging']],
			],
			'food' => [
				'label' => 'Przemysł spożywczy',
				'terms' => ['spożyw', 'spozyw', 'żywnoś', 'zywnos', 'mleczar', 'piekar', 'przetwór', 'przetwor', 'masarni', 'mięsn', 'miesn', 'cukier', 'food', 'dairy', 'bakery'],
				'phrases' => ['przemysł spożywczy', 'produkcja żywności', 'przetwórstwo spożywcze', 'zakład mięsny', 'mleczarnia', 'producent żywności'],
				'apollo' => ['food production', 'food manufacturing', 'food processing', 'dairy', 'meat processing', 'bakery'],
				'sic' => ['20'],
				'naics' => ['311', '3121'],
				'pkd' => ['10.', '11.'],
				'osm' => [['industrial', 'food'], ['industrial', 'food_industry'], ['craft', 'bakery']],
			],
			'industrial_electronics' => [
				'label' => 'Elektronika przemysłowa',
				'terms' => ['elektroni', 'pcb', 'smt', 'zasilacz', 'sterownik', 'czujnik', 'electronics', 'embedded'],
				'phrases' => ['elektronika przemysłowa', 'produkcja elektroniki', 'montaż elektroniki', 'montaż smt', 'projektowanie elektroniki', 'systemy wbudowane'],
				'apollo' => ['industrial electronics', 'electronics manufacturing', 'electronic manufacturing services', 'pcb assembly', 'embedded systems', 'power electronics'],
				'sic' => ['367', '3825', '3829', '3613', '3621'],
				'naics' => ['3344', '3345', '335311', '335313', '334418'],
				'pkd' => ['26.1', '26.3', '26.5', '27.1', '27.3', '27.9'],
				'osm' => [['industrial', 'electronics']],
			],
			'logistics' => [
				'label' => 'Transport i logistyka',
				'terms' => ['transport', 'logisty', 'spedycj', 'magazyn', 'logistics', 'freight', 'warehous'],
				'phrases' => ['logistyka', 'transport i logistyka', 'firma transportowa', 'spedycja', 'magazynowanie', 'usługi logistyczne', 'transport drogowy'],
				'apollo' => ['logistics', 'freight forwarding', 'road transport', 'warehousing', 'third party logistics', 'trucking'],
				'sic' => ['42', '4731', '4783'],
				'naics' => ['484', '488', '493'],
				'pkd' => ['49.41', '52.1', '52.2', '53.'],
				'osm' => [['office', 'logistics'], ['industrial', 'logistics'], ['office', 'moving_company']],
			],
			'furniture' => [
				'label' => 'Producenci mebli',
				'terms' => ['mebl', 'furniture'],
				'phrases' => ['producent mebli', 'produkcja mebli', 'meble', 'fabryka mebli'],
				'apollo' => ['furniture manufacturing', 'furniture', 'office furniture', 'upholstered furniture'],
				'sic' => ['25'],
				'naics' => ['337'],
				'pkd' => ['31.'],
				'osm' => [['craft', 'cabinet_maker'], ['industrial', 'furniture']],
			],
			'woodworking' => [
				'label' => 'Przemysł drzewny i stolarka',
				'terms' => ['drzewn', 'tartak', 'stolar', 'drewn', 'timber', 'sawmill', 'joinery'],
				'phrases' => ['przemysł drzewny', 'tartak', 'stolarka', 'stolarka okienna', 'wyroby z drewna'],
				'apollo' => ['woodworking', 'wood products', 'sawmill', 'timber', 'joinery'],
				'sic' => ['24'],
				'naics' => ['321'],
				'pkd' => ['16.'],
				'osm' => [['craft', 'carpenter'], ['craft', 'sawmill'], ['industrial', 'sawmill'], ['man_made', 'sawmill']],
			],
			'construction' => [
				'label' => 'Budownictwo i wykonawstwo',
				'terms' => ['budowl', 'budownict', 'construction', 'builder', 'general contractor'],
				'phrases' => ['budownictwo', 'firma budowlana', 'generalny wykonawca', 'wykonawstwo budowlane', 'budownictwo przemysłowe'],
				'apollo' => ['construction', 'general contractor', 'civil engineering', 'industrial construction'],
				'sic' => ['15', '16', '17'],
				'naics' => ['236', '237', '238'],
				'pkd' => ['41.', '42.', '43.'],
				'osm' => [['office', 'construction_company'], ['craft', 'builder']],
			],
			'electrical' => [
				'label' => 'Elektrotechnika i instalacje elektryczne',
				'terms' => ['elektrotech', 'elektryk', 'instalacj elektr', 'rozdzielni', 'electrical'],
				'phrases' => ['elektrotechnika', 'instalacje elektryczne', 'rozdzielnice', 'aparatura elektryczna'],
				'apollo' => ['electrical equipment', 'electrical engineering', 'switchgear', 'electrical contractor'],
				'sic' => ['36', '1731'],
				'naics' => ['335', '238210'],
				'pkd' => ['27.', '43.21'],
				'osm' => [['craft', 'electrician']],
			],
			'printing_packaging' => [
				'label' => 'Poligrafia i drukarnie',
				'terms' => ['drukarn', 'poligraf', 'druk ', 'printing'],
				'phrases' => ['poligrafia', 'drukarnia', 'druk cyfrowy', 'druk offsetowy'],
				'apollo' => ['printing', 'commercial printing', 'offset printing', 'label printing'],
				'sic' => ['27'],
				'naics' => ['323'],
				'pkd' => ['18.'],
				'osm' => [['craft', 'printer'], ['industrial', 'printing']],
			],
			'chemicals' => [
				'label' => 'Chemia, farby i lakiery',
				'terms' => ['chemi', 'farb', 'lakier', 'chemical', 'coatings'],
				'phrases' => ['przemysł chemiczny', 'farby i lakiery', 'chemia przemysłowa', 'chemia budowlana'],
				'apollo' => ['chemicals', 'chemical manufacturing', 'paints', 'coatings', 'industrial chemicals'],
				'sic' => ['28'],
				'naics' => ['325'],
				'pkd' => ['20.'],
				'osm' => [['industrial', 'chemical']],
			],
			'automotive' => [
				'label' => 'Motoryzacja i części',
				'terms' => ['motoryz', 'samochod', 'automotive', 'autoczęś', 'autoczes'],
				'phrases' => ['motoryzacja', 'części samochodowe', 'branża motoryzacyjna', 'dostawca motoryzacyjny'],
				'apollo' => ['automotive parts', 'automotive', 'auto parts manufacturing'],
				'sic' => ['3714', '371'],
				'naics' => ['3363', '3361'],
				'pkd' => ['29.'],
				'osm' => [['industrial', 'automotive']],
			],
		]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get(?string $key): ?array
	{
		return $key ? (self::all()[$key] ?? null) : null;
	}

	/**
	 * Rozpoznaje branżę z polskiej (lub angielskiej) frazy, np. „obróbka metali” → metalworking.
	 * Dopasowanie po frazach katalogu (bez diakrytyków); wygrywa najdłuższa pasująca fraza.
	 */
	public static function detect(?string $phrase): ?string
	{
		$text = ' ' . self::fold($phrase) . ' ';

		if (trim($text) === '') {
			return null;
		}

		$best = null;
		$bestLen = 0;

		foreach (self::all() as $key => $industry) {
			foreach (array_merge($industry['phrases'] ?? [], [$industry['label']]) as $p) {
				$p = self::fold($p);

				if ($p !== '' && str_contains($text, ' ' . $p) && mb_strlen($p) > $bestLen) {
					$best = $key;
					$bestLen = mb_strlen($p);
				}
			}
		}

		return $best;
	}

	/**
	 * Czy kod (SIC/NAICS/PKD) pasuje do prefiksów branży.
	 *
	 * @param list<string> $codes
	 * @param 'sic'|'naics'|'pkd' $type
	 */
	public static function codesMatch(?string $key, array $codes, string $type): ?string
	{
		$prefixes = self::get($key)[$type] ?? [];

		foreach ($codes as $code) {
			$normalized = $type === 'pkd' ? self::pkd((string) $code) : preg_replace('/\D/', '', (string) $code);

			foreach ($prefixes as $prefix) {
				if ($normalized !== '' && $normalized !== null && str_starts_with($normalized, $prefix)) {
					return (string) $code;
				}
			}
		}

		return null;
	}

	/**
	 * Normalizacja PKD: „2511Z” / „25.11.Z” / „25.11” → „25.11.Z” (prefiksowo porównywalne z „25.1”).
	 */
	public static function pkd(?string $code): ?string
	{
		$raw = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $code) ?? '');

		if (! preg_match('/^(\d{2})(\d)?(\d)?([A-Z])?$/', $raw, $m)) {
			return null;
		}

		$out = $m[1] . '.';

		if (($m[2] ?? '') !== '') {
			$out .= $m[2] . ($m[3] ?? '');
		}

		if (($m[4] ?? '') !== '') {
			$out .= '.' . $m[4];
		}

		return $out;
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	public static function options(): array
	{
		$out = [];

		foreach (self::all() as $key => $industry) {
			$out[] = ['key' => $key, 'label' => $industry['label']];
		}

		return $out;
	}

	private static function fold(?string $value): string
	{
		return trim(preg_replace('/[^a-z0-9]+/', ' ', N::foldAscii((string) $value)) ?? '');
	}
}
