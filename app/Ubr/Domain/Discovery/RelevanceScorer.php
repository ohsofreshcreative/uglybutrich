<?php

namespace App\Ubr\Domain\Discovery;

use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Companies\FitFlags;

/**
 * Relevance Score (0–100): jak bardzo firma odpowiada KRYTERIOM wyszukiwania. Deterministyczny, bez AI.
 * To NIE jest Opportunity Score (potencjał sprzedażowy dla OhSoFresh) — ten będzie w kolejnym etapie.
 *
 * Kryteria i wagi:
 * - branża (40):            PKD z rejestru > kody SIC/NAICS dostawcy > słowa branżowe w danych > tylko filtr dostawcy,
 * - słowa kluczowe (20):    dopasowane rdzenie frazy/branży w nazwie, opisie, branży, tytule strony,
 * - lokalizacja (25):       potwierdzona (rejestr/strona) > deklarowana (rekord dostawcy) > tylko filtr dostawcy > brak danych,
 * - wielkość (15):          deklarowana/potwierdzona liczba pracowników > tylko filtr dostawcy > brak danych.
 * Kryterium, którego nie podano w wyszukiwaniu, dostaje pełną pulę („nie dotyczy”).
 *
 * Stany kryterium: confirmed | declared | filtered (tylko filtr po stronie dostawcy) | partial | unknown | mismatch | not_required.
 * Kary: rekord nie jest przedsiębiorstwem-prospektem (urząd, stowarzyszenie, media, katalog) −30; nieaktywna ≤ 10; zawieszona ≤ 20.
 */
final class RelevanceScorer
{
	public const WEIGHTS = ['industry' => 40, 'keywords' => 20, 'location' => 25, 'size' => 15];

	public const STATE_LABELS = [
		'confirmed' => 'potwierdzone',
		'declared' => 'wg danych źródła',
		'filtered' => 'tylko filtr źródła',
		'partial' => 'częściowo',
		'unknown' => 'brak danych',
		'mismatch' => 'niezgodne',
		'not_required' => 'nie dotyczy',
	];

	/**
	 * @param array<string, mixed> $company wiersz ubr_companies
	 * @param string $provider źródło, przez które firma trafiła do wyników
	 * @param list<string>|string|null $matchedTerms rdzenie dopasowane przez dostawcę
	 * @return array{score: int, location_match: string, criteria: array<string, array{state: string, points: int, max: int, detail: string}>, penalties: list<array{key: string, points: int, label: string}>, flags: list<string>, incomplete: list<string>}
	 */
	public static function score(array $company, DiscoveryCriteria $criteria, string $provider, array|string|null $matchedTerms = null): array
	{
		$text = self::companyText($company);
		$termHits = $criteria->matchedTerms($text);

		$criteriaOut = [
			'industry' => self::industry($company, $criteria, $provider, $termHits),
			'keywords' => self::keywords($company, $criteria, $termHits),
			'location' => self::location($company, $criteria, $provider),
			'size' => self::size($company, $criteria, $provider),
		];

		$score = array_sum(array_column($criteriaOut, 'points'));
		$flags = FitFlags::detect($company);
		$penalties = [];

		$blocking = array_values(array_intersect($flags, FitFlags::BLOCKING));

		if ($blocking !== []) {
			$penalties[] = ['key' => 'not_a_prospect', 'points' => 30, 'label' => 'Rekord nie wygląda na przedsiębiorstwo-prospekt: ' . implode(', ', array_map(fn ($f) => FitFlags::LABELS[$f], $blocking))];
			$score -= 30;
		}

		if (in_array('inactive', $flags, true) && $score > 10) {
			$penalties[] = ['key' => 'inactive', 'points' => $score - 10, 'label' => 'Firma nieaktywna w rejestrze (maks. 10 pkt)'];
			$score = 10;
		} elseif (in_array('suspended', $flags, true) && $score > 20) {
			$penalties[] = ['key' => 'suspended', 'points' => $score - 20, 'label' => 'Działalność zawieszona (maks. 20 pkt)'];
			$score = 20;
		}

		$incomplete = [];

		foreach (['industry', 'location', 'size'] as $key) {
			if (in_array($criteriaOut[$key]['state'], ['unknown', 'filtered'], true)) {
				$incomplete[] = $key;
			}
		}

		if (trim((string) ($company['description'] ?? '')) === '' && trim((string) ($company['website_title'] ?? '')) === '') {
			$incomplete[] = 'description';
		}

		return [
			'score' => max(0, min(100, (int) round($score))),
			'location_match' => $criteriaOut['location']['state'],
			'criteria' => $criteriaOut,
			'penalties' => $penalties,
			'flags' => $flags,
			'incomplete' => $incomplete,
		];
	}

	/**
	 * @param array<string, mixed> $company
	 */
	public static function companyText(array $company): string
	{
		return implode(' ', array_filter([
			$company['name'] ?? null,
			$company['registry_name'] ?? null,
			$company['industry'] ?? null,
			$company['description'] ?? null,
			$company['website_title'] ?? null,
			$company['pkd_description'] ?? null,
			$company['domain'] ?? null,
		]));
	}

	/**
	 * @param array<string, mixed> $company
	 * @param list<string> $termHits
	 * @return array{state: string, points: int, max: int, detail: string}
	 */
	private static function industry(array $company, DiscoveryCriteria $criteria, string $provider, array $termHits): array
	{
		$max = self::WEIGHTS['industry'];
		$key = $criteria->industryKey();

		if ($key === null) {
			// Brak branży z katalogu (własna fraza) — branżę oceniamy dopasowaniem frazy.
			$points = $termHits === [] ? 8 : (count($termHits) >= 2 ? $max : 28);

			return self::c($termHits === [] ? 'unknown' : 'declared', $points, $max, $termHits === [] ? 'Brak branży z katalogu i brak dopasowania frazy w danych firmy.' : 'Fraza dopasowana w danych firmy: ' . implode(', ', array_slice($termHits, 0, 4)) . '.');
		}

		$label = IndustryCatalog::get($key)['label'] ?? $key;
		$pkd = IndustryCatalog::pkd($company['pkd_main'] ?? null);
		$codes = json_decode((string) ($company['industry_codes'] ?? ''), true) ?: [];
		$sicMatch = IndustryCatalog::codesMatch($key, (array) ($codes['sic'] ?? []), 'sic');
		$naicsMatch = IndustryCatalog::codesMatch($key, (array) ($codes['naics'] ?? []), 'naics');
		$industryTerms = array_values(array_intersect($termHits, $criteria->industryTerms()));

		if ($pkd && IndustryCatalog::codesMatch($key, [$pkd], 'pkd')) {
			return self::c('confirmed', $max, $max, sprintf('PKD przeważające %s (%s) w rejestrze pasuje do branży „%s”.', $pkd, (string) ($company['pkd_description'] ?? ''), $label));
		}

		if ($sicMatch || $naicsMatch) {
			$detail = sprintf('Kod %s od dostawcy pasuje do branży „%s”.', $sicMatch ? 'SIC ' . $sicMatch : 'NAICS ' . $naicsMatch, $label);

			return self::c('declared', 30, $max, $pkd ? $detail . ' Uwaga: PKD przeważające w rejestrze (' . $pkd . ') jest inne.' : $detail);
		}

		if ($industryTerms !== []) {
			return self::c('declared', $pkd ? 22 : 26, $max, 'Słowa branżowe w danych firmy: ' . implode(', ', array_slice($industryTerms, 0, 4)) . ($pkd ? '. PKD przeważające w rejestrze (' . $pkd . ') jest inne.' : '.'));
		}

		if ($pkd) {
			return self::c('mismatch', 4, $max, sprintf('PKD przeważające %s (%s) nie pasuje do branży „%s”, brak innych potwierdzeń.', $pkd, (string) ($company['pkd_description'] ?? ''), $label));
		}

		if ($provider === 'apollo' && $criteria->apolloKeywords() !== []) {
			return self::c('filtered', 16, $max, 'Firma zwrócona przez Apollo dla tagów branżowych, ale rekord nie zawiera branży — niepotwierdzone.');
		}

		if ($provider === 'osm' || $provider === 'wikidata') {
			return self::c('filtered', 14, $max, 'Firma znaleziona po słowach branżowych w źródle, brak kodów branżowych.');
		}

		return self::c('unknown', 8, $max, 'Brak danych o branży.');
	}

	/**
	 * @param array<string, mixed> $company
	 * @param list<string> $termHits
	 * @return array{state: string, points: int, max: int, detail: string}
	 */
	private static function keywords(array $company, DiscoveryCriteria $criteria, array $termHits): array
	{
		$max = self::WEIGHTS['keywords'];
		$hasText = trim((string) ($company['description'] ?? '') . (string) ($company['website_title'] ?? '') . (string) ($company['pkd_description'] ?? '')) !== '';

		if ($termHits === []) {
			return $hasText
				? self::c('mismatch', 0, $max, 'Opis/tytuł strony nie zawiera słów z wyszukiwania.')
				: self::c('unknown', 4, $max, 'Brak opisu firmy — nie da się ocenić słów kluczowych.');
		}

		return count($termHits) >= 2
			? self::c('confirmed', $max, $max, 'Dopasowane: ' . implode(', ', array_slice($termHits, 0, 5)) . '.')
			: self::c('partial', 12, $max, 'Dopasowane: ' . $termHits[0] . '.');
	}

	/**
	 * @param array<string, mixed> $company
	 * @return array{state: string, points: int, max: int, detail: string}
	 */
	private static function location(array $company, DiscoveryCriteria $criteria, string $provider): array
	{
		$max = self::WEIGHTS['location'];

		if (! $criteria->hasLocation()) {
			return self::c('not_required', $max, $max, 'Wyszukiwanie bez kryterium lokalizacji.');
		}

		$wantedRegion = $criteria->regionKey();
		$city = $company['city'] ?? null;
		$regionKey = RegionCatalog::keyFromName($company['region'] ?? null) ?? CityCatalog::regionFor($city);
		$confirmed = ($company['location_confidence'] ?? '') === Confidence::CONFIRMED;
		$source = $confirmed ? 'rejestr/strona' : 'dane źródła';
		$where = trim(implode(', ', array_filter([$city, RegionCatalog::label($regionKey)])));

		if ($regionKey !== null) {
			if ($wantedRegion && $regionKey !== $wantedRegion) {
				return self::c('mismatch', 0, $max, sprintf('Siedziba: %s (%s) — poza woj. %s.', $where, $source, RegionCatalog::label($wantedRegion)));
			}

			if ($criteria->city && $city && ! CityCatalog::same($city, $criteria->city)) {
				return self::c('partial', 10, $max, sprintf('Siedziba: %s (%s) — to samo województwo, inne miasto niż %s.', $where, $source, $criteria->city));
			}

			if ($criteria->city && ! $city) {
				return self::c('partial', $confirmed ? 14 : 10, $max, sprintf('Województwo zgodne (%s), miasto nieznane.', $source));
			}

			return $confirmed
				? self::c('confirmed', $max, $max, 'Siedziba potwierdzona: ' . $where . '.')
				: self::c('declared', 20, $max, 'Siedziba wg danych źródła: ' . $where . ' (niepotwierdzona w rejestrze).');
		}

		if ($provider === 'apollo') {
			return self::c('filtered', 12, $max, 'Lokalizacja tylko z filtra Apollo (siedziba wg Apollo) — rekord nie zawiera adresu, nie potwierdzono.');
		}

		if ($provider === 'osm') {
			return self::c('filtered', 8, $max, 'Obiekt w przybliżonym obszarze mapy (OSM), brak adresu.');
		}

		return self::c('unknown', 5, $max, 'Brak danych o siedzibie.');
	}

	/**
	 * @param array<string, mixed> $company
	 * @return array{state: string, points: int, max: int, detail: string}
	 */
	private static function size(array $company, DiscoveryCriteria $criteria, string $provider): array
	{
		$max = self::WEIGHTS['size'];

		if (! $criteria->hasSize()) {
			return self::c('not_required', $max, $max, 'Wyszukiwanie bez kryterium wielkości.');
		}

		$min = isset($company['employees_min']) ? (int) $company['employees_min'] : null;
		$maxEmp = isset($company['employees_max']) ? (int) $company['employees_max'] : null;
		$label = DiscoveryCriteria::SIZES[$criteria->size][2];

		if ($min || $maxEmp) {
			$value = $min ?: $maxEmp;
			[$from, $to] = $criteria->sizeRange();
			$ok = ($from === null || $value >= $from) && ($to === null || $value <= $to);
			$state = ($company['employees_confidence'] ?? '') === Confidence::CONFIRMED ? 'confirmed' : 'declared';

			return $ok
				? self::c($state, $max, $max, sprintf('Ok. %d os. (%s) — w przedziale %s.', $value, $state === 'confirmed' ? 'potwierdzone' : 'wg źródła', $label))
				: self::c('mismatch', 0, $max, sprintf('Ok. %d os. (wg źródła) — poza przedziałem %s.', $value, $label));
		}

		if ($provider === 'apollo') {
			return self::c('filtered', 8, $max, 'Wielkość tylko z filtra Apollo — rekord nie zawiera liczby pracowników.');
		}

		return self::c('unknown', 5, $max, 'Brak danych o zatrudnieniu.');
	}

	/**
	 * @return array{state: string, points: int, max: int, detail: string}
	 */
	private static function c(string $state, int $points, int $max, string $detail): array
	{
		return ['state' => $state, 'points' => $points, 'max' => $max, 'detail' => $detail];
	}
}
