<?php

namespace App\Ubr\Domain\Discovery;

/**
 * Województwa. Nazwa `name` jest identyczna z nazwą obszaru w OSM i etykietą PL w Wikidata.
 */
final class RegionCatalog
{
	public const REGIONS = [
		'dolnoslaskie' => 'dolnośląskie',
		'kujawsko-pomorskie' => 'kujawsko-pomorskie',
		'lubelskie' => 'lubelskie',
		'lubuskie' => 'lubuskie',
		'lodzkie' => 'łódzkie',
		'malopolskie' => 'małopolskie',
		'mazowieckie' => 'mazowieckie',
		'opolskie' => 'opolskie',
		'podkarpackie' => 'podkarpackie',
		'podlaskie' => 'podlaskie',
		'pomorskie' => 'pomorskie',
		'slaskie' => 'śląskie',
		'swietokrzyskie' => 'świętokrzyskie',
		'warminsko-mazurskie' => 'warmińsko-mazurskie',
		'wielkopolskie' => 'wielkopolskie',
		'zachodniopomorskie' => 'zachodniopomorskie',
	];

	/**
	 * Nazwy angielskie/warianty używane przez dostawców (np. Apollo `state`: "Masovian Voivodeship").
	 */
	public const ALIASES = [
		'dolnoslaskie' => ['lower silesian', 'lower silesia', 'dolnośląskie', 'dolnoslaskie'],
		'kujawsko-pomorskie' => ['kuyavian-pomeranian', 'kuyavia-pomerania', 'kujawsko-pomorskie'],
		'lubelskie' => ['lublin', 'lubelskie'],
		'lubuskie' => ['lubusz', 'lubuskie'],
		'lodzkie' => ['łódź voivodeship', 'lodz voivodeship', 'łódzkie', 'lodzkie', 'lodz province'],
		'malopolskie' => ['lesser poland', 'małopolskie', 'malopolskie'],
		'mazowieckie' => ['masovian', 'mazovia', 'mazowieckie', 'masovia'],
		'opolskie' => ['opole', 'opolskie'],
		'podkarpackie' => ['subcarpathian', 'podkarpackie', 'subcarpathia'],
		'podlaskie' => ['podlaskie', 'podlachian', 'podlasie'],
		'pomorskie' => ['pomeranian voivodeship', 'pomorskie', 'pomerania'],
		'slaskie' => ['silesian', 'śląskie', 'slaskie', 'silesia'],
		'swietokrzyskie' => ['świętokrzyskie', 'swietokrzyskie', 'holy cross'],
		'warminsko-mazurskie' => ['warmian-masurian', 'warmia-masuria', 'warmińsko-mazurskie', 'warminsko-mazurskie'],
		'wielkopolskie' => ['greater poland', 'wielkopolskie'],
		'zachodniopomorskie' => ['west pomeranian', 'zachodniopomorskie', 'west pomerania'],
	];

	/**
	 * Nazwy województw w formacie lokalizacji Apollo (`organization_locations[]`, siedziba firmy).
	 */
	public const APOLLO_LOCATIONS = [
		'dolnoslaskie' => 'Lower Silesian Voivodeship, Poland',
		'kujawsko-pomorskie' => 'Kuyavian-Pomeranian Voivodeship, Poland',
		'lubelskie' => 'Lublin Voivodeship, Poland',
		'lubuskie' => 'Lubusz Voivodeship, Poland',
		'lodzkie' => 'Łódź Voivodeship, Poland',
		'malopolskie' => 'Lesser Poland Voivodeship, Poland',
		'mazowieckie' => 'Masovian Voivodeship, Poland',
		'opolskie' => 'Opole Voivodeship, Poland',
		'podkarpackie' => 'Subcarpathian Voivodeship, Poland',
		'podlaskie' => 'Podlaskie Voivodeship, Poland',
		'pomorskie' => 'Pomeranian Voivodeship, Poland',
		'slaskie' => 'Silesian Voivodeship, Poland',
		'swietokrzyskie' => 'Świętokrzyskie Voivodeship, Poland',
		'warminsko-mazurskie' => 'Warmian-Masurian Voivodeship, Poland',
		'wielkopolskie' => 'Greater Poland Voivodeship, Poland',
		'zachodniopomorskie' => 'West Pomeranian Voivodeship, Poland',
	];

	/**
	 * Rozpoznaje województwo z nazwy regionu podanej przez dostawcę (PL lub EN). Null = nie rozpoznano.
	 */
	public static function keyFromName(?string $name): ?string
	{
		$name = mb_strtolower(trim((string) $name));

		if ($name === '') {
			return null;
		}

		$name = trim(str_replace(['województwo', 'voivodeship', 'province'], '', $name));

		// "pomorskie" jest zawarte w "zachodniopomorskie"/"kujawsko-pomorskie" — sprawdzamy najdłuższe aliasy najpierw.
		$best = null;
		$bestLen = 0;

		foreach (self::ALIASES as $key => $aliases) {
			foreach ($aliases as $alias) {
				$alias = trim(str_replace(['voivodeship', 'province'], '', $alias));

				if ($alias !== '' && ($name === $alias || str_contains($name, $alias)) && mb_strlen($alias) > $bestLen) {
					$best = $key;
					$bestLen = mb_strlen($alias);
				}
			}
		}

		return $best;
	}

	public static function label(?string $key): ?string
	{
		return $key ? (self::REGIONS[$key] ?? null) : null;
	}

	/**
	 * Pełna nazwa obszaru: "województwo mazowieckie".
	 */
	public static function areaName(?string $key): ?string
	{
		$label = self::label($key);

		return $label ? 'województwo ' . $label : null;
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	public static function options(): array
	{
		$out = [];

		foreach (self::REGIONS as $key => $label) {
			$out[] = ['key' => $key, 'label' => $label];
		}

		return $out;
	}
}
