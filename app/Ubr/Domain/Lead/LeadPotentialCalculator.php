<?php

namespace App\Ubr\Domain\Lead;

use App\Ubr\Domain\Analysis\Analyzers\BusinessPotentialAnalyzer;

/**
 * Lead Potential Score 0–100: jak wartościowym potencjalnym klientem NA REDESIGN STRONY jest firma.
 * Nie zastępuje Relevance / Opportunity / Contact Priority — korzysta z ich danych wejściowych bez zmiany ich algorytmów.
 *
 * Wagi (po audycie; zmiana względem propozycji 40/25/15/10/10 uzasadniona w docs/ARCHITECTURE.md §13):
 *   A. Website Redesign Opportunity 45  — słabość strony (kryteria analizy bez AI) + liczba niezależnych kategorii problemów,
 *      UŚREDNIANE (0,5 / 0,5), nie sumowane — to dwie miary tej samej warstwy (kontrola podwójnego liczenia).
 *      Celowo NIE używamy łącznego Opportunity Score: zawiera on potencjał biznesowy i wiarygodność firmy, które są osobno w B i C.
 *   B. Business Fit 30                  — Relevance (15) + branża / segment (9) + wielkość (6, nie jako dowód budżetu).
 *   C. Company Trust 15                 — status w rejestrze (9) + zgodność domeny (6).
 *   D. Contact Readiness 10             — wiarygodne osoby (5) + Contact Priority Top 1 (3) + oficjalny kanał (2).
 *   Kompletność danych NIE jest częścią wyniku (pkt E propozycji) — to osobna wartość Data Coverage + Confidence,
 *   żeby nie mieszać wartości biznesowej z ilością danych (§6 promptu).
 *
 * Brak danych: podkryterium bez danych = NIEZMIERZONE (nie 0 pkt) i wypada z mianownika. Wynik = zdobyte / zmierzone × 100,
 * ograniczony limitem zależnym od udziału zmierzonych wag (cap = 55 + 45 × w), więc jeden pozytywny sygnał nie daje 100/100;
 * bez danych o stronie limit wynosi maks. 65 (strona to główne pytanie biznesowe).
 * Udział zmierzonych wag < 35% albo brak zarówno A jak i B = INSUFFICIENT_DATA (wynik null).
 * Stan PROVISIONAL: Data Coverage < 70% albo brak A albo zmierzone wagi < 85%.
 */
final class LeadPotentialCalculator
{
	public const VERSION = 'lead-2026-10-v2';

	public const COMPONENTS = [
		'website' => ['label' => 'Website Redesign Opportunity', 'max' => 45],
		'business' => ['label' => 'Business Fit', 'max' => 30],
		'trust' => ['label' => 'Company Trust', 'max' => 15],
		'contacts' => ['label' => 'Contact Readiness', 'max' => 10],
	];

	public const FRESH_DAYS = 180;

	public const CAP_WITHOUT_WEBSITE = 65;

	/** Punkty segmentu (Business Fit) — OhSoFresh robi strony B2B, ale też B2C / WooCommerce, dlatego segment konsumencki nie jest zerowany. */
	public const SEGMENT_POINTS = [
		'manufacturing' => [9, 'produkcja / przemysł'],
		'wholesale' => [8, 'handel hurtowy / dystrybucja'],
		'logistics' => [8, 'transport i logistyka'],
		'construction' => [7, 'budownictwo'],
		'tech_services' => [7, 'usługi techniczne / IT / inżynieria'],
		'energy' => [6, 'energetyka'],
		'agriculture' => [5, 'rolnictwo'],
		'consumer' => [5, 'handel detaliczny / usługi konsumenckie'],
		'other' => [4, 'inna działalność'],
		'public' => [0, 'administracja / organizacja / media / katalog'],
	];

	public const NON_COMMERCIAL_FLAGS = ['public_institution', 'association', 'media', 'directory'];

	/**
	 * @param array<string, mixed> $s snapshot danych (LeadIntelligenceService::snapshot)
	 * @param list<array<string, mixed>> $insights
	 * @return array<string, mixed>
	 */
	public static function calculate(array $s, array $insights): array
	{
		$components = [
			'website' => self::website($s, $insights),
			'business' => self::business($s),
			'trust' => self::trust($s),
			'contacts' => self::contacts($s),
		];

		$points = 0.0;
		$measuredMax = 0;

		foreach ($components as $key => &$comp) {
			$comp['label'] = self::COMPONENTS[$key]['label'];
			$comp['max'] = self::COMPONENTS[$key]['max'];
			$comp['points'] = null;
			$comp['measured_max'] = 0;

			foreach ($comp['items'] as $item) {
				if ($item['points'] !== null) {
					$comp['points'] = ($comp['points'] ?? 0) + $item['points'];
					$comp['measured_max'] += $item['max'];
				}
			}

			if ($comp['points'] !== null) {
				$comp['points'] = round($comp['points'], 1);
				$points += $comp['points'];
				$measuredMax += $comp['measured_max'];
			}

			$comp['measured'] = $comp['points'] !== null;
		}

		unset($comp);

		$w = $measuredMax / 100;
		$coverage = self::coverage($s);
		$normalized = $measuredMax > 0 ? $points / $measuredMax * 100 : 0.0;
		$cap = 55 + 45 * $w;

		// Bez danych o stronie (główne pytanie: szansa na redesign) potencjał nie może być „wysoki” — maks. 65 (walidacja biznesowa:
		// firmy bez analizy nie mogą wyprzedzać firm z potwierdzonymi problemami strony tylko dzięki normalizacji).
		if (! $components['website']['measured']) {
			$cap = min($cap, self::CAP_WITHOUT_WEBSITE);
		}
		$insufficient = $w < 0.35 || (! $components['website']['measured'] && ! $components['business']['measured']);
		$score = $insufficient ? null : (int) round(min($normalized, $cap));
		$capped = ! $insufficient && $normalized > $cap;

		$status = match (true) {
			$insufficient => 'INSUFFICIENT_DATA',
			$coverage['percent'] < 70 || ! $components['website']['measured'] || $w < 0.85 => 'PROVISIONAL',
			default => 'SCORED',
		};

		return [
			'version' => self::VERSION,
			'score' => $score,
			'status' => $status,
			'components' => $components,
			'points' => round($points, 1),
			'measured_max' => $measuredMax,
			'measured_weight' => round($w, 2),
			'normalized' => round($normalized, 1),
			'cap' => round($cap, 1),
			'capped' => $capped,
			'coverage' => $coverage,
			'explanation' => self::explanation($score, $points, $measuredMax, $cap, $capped, $insufficient),
		];
	}

	/**
	 * A. Website Redesign Opportunity (45).
	 *
	 * @param array<string, mixed> $s
	 * @param list<array<string, mixed>> $insights
	 * @return array{items: list<array<string, mixed>>}
	 */
	private static function website(array $s, array $insights): array
	{
		$a = $s['analysis'] ?? null;
		$weak = RedesignAssessor::weakness($a);
		$ok = $a && in_array($a['status'] ?? '', ['completed', 'partial'], true) && $weak['measured'] >= RedesignAssessor::MIN_MEASURED;

		if (! $ok) {
			$why = ! $a ? 'Brak analizy strony — nie oznacza, że strona jest dobra.' : (($a['status'] ?? '') === 'failed' ? 'Analiza nie powiodła się (strona nie odpowiadała).' : 'Za mało zmierzonych kryteriów strony.');

			return ['items' => [
				self::unmeasured('weakness', 'Słabość strony i techniki (pomiary)', 22.5, $why),
				self::unmeasured('signals', 'Niezależne kategorie problemów', 22.5, $why),
			]];
		}

		$rNorm = min(1.0, (float) $weak['ratio'] / 0.6);
		$cats = WebsiteInsightGenerator::significantCategories($insights);
		$sNorm = [0, 0.35, 0.6, 0.8, 1.0][min(4, count($cats))];

		return ['items' => [
			self::item('weakness', 'Słabość strony i techniki (pomiary)', round(22.5 * $rNorm, 1), 22.5, sprintf('%d z %d pkt zmierzonych kryteriów strony i techniki (%d%%; pełna pula od 60%%). Kryterium AI pominięte.', $weak['points'], $weak['measured'], (int) round($weak['ratio'] * 100))),
			self::item('signals', 'Niezależne kategorie problemów', round(22.5 * $sNorm, 1), 22.5, $cats ? sprintf('%d kategorie z problemami ≥ MEDIUM: %s.', count($cats), implode(', ', array_map(fn ($c) => InsightRuleRegistry::CATEGORIES[$c] ?? $c, $cats))) : 'Brak problemów o znaczeniu ≥ MEDIUM w dostępnych pomiarach.'),
		]];
	}

	/**
	 * B. Business Fit (30).
	 *
	 * @param array<string, mixed> $s
	 * @return array{items: list<array<string, mixed>>}
	 */
	private static function business(array $s): array
	{
		$c = $s['company'];
		$items = [];
		$rel = $s['relevance'] ?? null;
		$items[] = $rel === null
			? self::unmeasured('relevance', 'Relevance Score (zgodność z wyszukiwaniem)', 15, 'Firma nie ma wyniku trafności w wyszukiwaniach organizacji.')
			: self::item('relevance', 'Relevance Score (zgodność z wyszukiwaniem)', round(15 * max(0, min(100, (int) $rel)) / 100, 1), 15, 'Najwyższa trafność w wyszukiwaniach organizacji: ' . (int) $rel . '/100.');

		$flags = array_values(array_intersect((array) ($c['fit_flags'] ?? []), self::NON_COMMERCIAL_FLAGS));
		$segment = null;
		$source = null;

		if ($flags) {
			$segment = 'public';
			$source = 'flagi: ' . implode(', ', $flags);
		} elseif (! empty($c['pkd_main'])) {
			$segment = BusinessPotentialAnalyzer::segmentFromPkd(substr(preg_replace('/\D/', '', (string) $c['pkd_main']) ?? '', 0, 2));
			$source = 'PKD ' . $c['pkd_main'] . ' (rejestr)';
		} elseif (! empty($c['sic'])) {
			$segment = BusinessPotentialAnalyzer::segmentFromSic(substr(preg_replace('/\D/', '', (string) $c['sic']) ?? '', 0, 2));
			$source = 'SIC ' . $c['sic'] . ' (deklaracja źródła)';
		}

		if ($segment) {
			[$pts, $label] = self::SEGMENT_POINTS[$segment];
			$items[] = self::item('segment', 'Branża / segment', (float) $pts, 9, 'Segment: ' . $label . ' — ' . $source . '.');
		} else {
			$items[] = self::unmeasured('segment', 'Branża / segment', 9, 'Brak kodu PKD/SIC — branży nie zgadujemy.');
		}

		$emp = (int) ($c['employees'] ?? 0);

		if ($emp > 0) {
			$pts = match (true) {
				$emp <= 9 => 3,
				$emp <= 49 => 5,
				$emp <= 249 => 6,
				$emp <= 999 => 5,
				default => 4,
			};
			$items[] = self::item('size', 'Wielkość firmy', (float) $pts, 6, sprintf('Ok. %d pracowników (wielkość = skala serwisu i zasobów, nie dowód budżetu).', $emp));
		} else {
			$items[] = self::unmeasured('size', 'Wielkość firmy', 6, 'Liczba pracowników nieznana — nie zakładamy, że firma jest mała.');
		}

		return ['items' => $items];
	}

	/**
	 * C. Company Trust (15).
	 *
	 * @param array<string, mixed> $s
	 * @return array{items: list<array<string, mixed>>}
	 */
	private static function trust(array $s): array
	{
		$c = $s['company'];
		$v = (string) ($c['verification_status'] ?? 'pending');
		$items = [];
		$items[] = match ($v) {
			'verified' => self::item('verification', 'Status w rejestrze', 9.0, 9, 'Firma zweryfikowana w rejestrze publicznym (VERIFIED).'),
			'uncertain' => self::item('verification', 'Status w rejestrze', 4.0, 9, 'Weryfikacja niejednoznaczna (UNCERTAIN) — to nie dowód, że firma nie istnieje.'),
			'inactive', 'suspended' => self::item('verification', 'Status w rejestrze', 0.0, 9, $v === 'inactive' ? 'Firma nieaktywna w rejestrze (INACTIVE).' : 'Działalność zawieszona (SUSPENDED).'),
			default => self::unmeasured('verification', 'Status w rejestrze', 9, 'Firma nie była jeszcze weryfikowana.'),
		};

		$d = (string) ($c['domain_status'] ?? 'unchecked');

		if (empty($c['domain'])) {
			$items[] = self::unmeasured('domain', 'Zgodność domeny', 6, 'Firma nie ma przypisanej domeny.');
		} else {
			$items[] = match ($d) {
				'confirmed' => self::item('domain', 'Zgodność domeny', 6.0, 6, 'Domena potwierdzona jako strona firmy (CONFIRMED).'),
				'uncertain' => self::item('domain', 'Zgodność domeny', 3.0, 6, 'Zgodność domeny niepotwierdzona (UNCERTAIN).'),
				'unavailable' => self::item('domain', 'Zgodność domeny', 1.0, 6, 'Strona była niedostępna przy weryfikacji domeny.'),
				'mismatch' => self::item('domain', 'Zgodność domeny', 0.0, 6, 'Domena należy do innego podmiotu (MISMATCH).'),
				default => self::unmeasured('domain', 'Zgodność domeny', 6, 'Domena nie była jeszcze sprawdzana.'),
			};
		}

		return ['items' => $items];
	}

	/**
	 * D. Contact Readiness (10). Brak osoby ≠ słaby lead — waga celowo niska; brak researchu = niezmierzone.
	 *
	 * @param array<string, mixed> $s
	 * @return array{items: list<array<string, mixed>>}
	 */
	private static function contacts(array $s): array
	{
		$ct = $s['contacts'] ?? [];

		if (empty($ct['researched'])) {
			$why = 'Decydentów jeszcze nie wyszukiwano.';

			return ['items' => [
				self::unmeasured('people', 'Wiarygodne osoby decyzyjne', 5, $why),
				self::unmeasured('top_score', 'Contact Priority Top 1', 3, $why),
				self::unmeasured('channel', 'Oficjalny kanał kontaktu', 2, $why),
			]];
		}

		$people = array_values(array_filter((array) ($ct['people'] ?? []), fn ($p) => in_array($p['status'] ?? '', ['verified', 'probable'], true)));
		$n = count($people);
		$top = $people ? max(array_map(fn ($p) => (int) ($p['score'] ?? 0), $people)) : 0;
		$channel = ((int) ($ct['channels'] ?? 0)) > 0 || (bool) array_filter((array) ($ct['people'] ?? []), fn ($p) => ! empty($p['email']) || ! empty($p['phone']));

		return ['items' => [
			self::item('people', 'Wiarygodne osoby decyzyjne', (float) ($n >= 2 ? 5 : ($n === 1 ? 3 : 0)), 5, $n ? sprintf('%d osób zweryfikowanych / prawdopodobnych.', $n) : 'Nie znaleziono wiarygodnej osoby decyzyjnej (firma nadal może być dobrym kandydatem).'),
			self::item('top_score', 'Contact Priority Top 1', (float) ($top >= 70 ? 3 : ($top >= 50 ? 2 : ($top > 0 ? 1 : 0))), 3, $top ? 'Najwyższy Contact Priority: ' . $top . '/100.' : 'Brak wyniku Contact Priority.'),
			self::item('channel', 'Oficjalny kanał kontaktu', $channel ? 2.0 : 0.0, 2, $channel ? 'Jest oficjalny kanał (adres firmy / działu lub dane służbowe osoby).' : 'Nie znaleziono oficjalnego kanału w danych researchu (brak e-maila ≠ brak możliwości kontaktu).'),
		]];
	}

	/**
	 * Data Coverage (osobno od wyniku): jakie dane wejściowe są dostępne.
	 *
	 * @param array<string, mixed> $s
	 * @return array{percent: int, available: list<string>, missing: list<string>}
	 */
	public static function coverage(array $s): array
	{
		$c = $s['company'];
		$a = $s['analysis'] ?? null;
		$finished = $a && in_array($a['status'] ?? '', ['completed', 'partial'], true);
		$checks = [
			['Weryfikacja firmy w rejestrze', 15, ! in_array($c['verification_status'] ?? 'pending', ['pending', ''], true)],
			['Weryfikacja domeny', 15, ! empty($c['domain']) && ! in_array($c['domain_status'] ?? 'unchecked', ['unchecked', ''], true)],
			['Analiza strony', 25, $finished],
			['Aktualna analiza (≤ ' . self::FRESH_DAYS . ' dni)', 10, $finished && ! empty($a['fresh'])],
			['Render strony (desktop + mobile)', 5, $finished && ! empty($a['rendered'])],
			['Relevance Score', 10, ($s['relevance'] ?? null) !== null],
			['Branża (PKD/SIC)', 7, ! empty($c['pkd_main']) || ! empty($c['sic'])],
			['Wielkość firmy', 5, (int) ($c['employees'] ?? 0) > 0],
			['Wyszukiwanie decydentów', 8, ! empty($s['contacts']['researched'])],
		];
		$pct = 0;
		$available = [];
		$missing = [];

		foreach ($checks as [$label, $weight, $ok]) {
			if ($ok) {
				$pct += $weight;
				$available[] = $label;
			} else {
				$missing[] = $label;
			}
		}

		return ['percent' => $pct, 'available' => $available, 'missing' => $missing];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function item(string $key, string $label, float $points, float $max, string $detail): array
	{
		return ['key' => $key, 'label' => $label, 'points' => round($points, 1), 'max' => $max, 'detail' => $detail];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function unmeasured(string $key, string $label, float $max, string $why): array
	{
		return ['key' => $key, 'label' => $label, 'points' => null, 'max' => $max, 'detail' => $why];
	}

	private static function explanation(?int $score, float $points, int $measuredMax, float $cap, bool $capped, bool $insufficient): string
	{
		if ($insufficient) {
			return sprintf('Zmierzono tylko %d z 100 pkt wag — za mało, by ocenić potencjał (brak danych to nie zero).', $measuredMax);
		}

		return sprintf('%s pkt z %d pkt możliwych do oceny na podstawie dostępnych danych → %d/100%s. Niezmierzone elementy nie dostają zera, ale ograniczają maksymalny wynik do %d.', rtrim(rtrim(number_format($points, 1, ',', ''), '0'), ','), $measuredMax, (int) $score, $capped ? ' (ograniczony limitem kompletności)' : '', (int) round($cap));
	}
}
