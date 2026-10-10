<?php

namespace App\Ubr\Domain\Lead;

/**
 * Redesign Opportunity Assessment: STRONG / MODERATE / WEAK / INSUFFICIENT_DATA.
 *
 * Wejście: wnioski (Insight Engine) + udział zmierzonej słabości strony (website + technical, bez kryterium AI).
 * - INSUFFICIENT_DATA: brak zakończonej analizy albo zmierzono < 20 z 50 pkt kryteriów strony/techniki dostępnych bez AI.
 * - STRONG: ≥ 3 niezależne kategorie z wnioskami ≥ MEDIUM, w tym ≥ 2 z „rdzenia” (mobile, UX, struktura treści, konwersja),
 *   oraz (wniosek HIGH+ w rdzeniu albo zmierzona słabość ≥ 45%). Sama wydajność / technika nigdy nie daje STRONG.
 * - MODERATE: ≥ 2 kategorie ≥ MEDIUM albo wniosek HIGH+ w rdzeniu albo słabość ≥ 25%.
 * - WEAK: dostępne pomiary nie wskazują istotnych problemów (warstwa wizualna NIE jest oceniana — AI Vision wyłączone).
 */
final class RedesignAssessor
{
	public const LABELS = [
		'strong' => 'Silne przesłanki do redesignu',
		'moderate' => 'Umiarkowane przesłanki — wymaga oceny',
		'weak' => 'Słabe przesłanki w pomiarach automatycznych',
		'insufficient_data' => 'Za mało danych',
	];

	public const MIN_MEASURED = 20;

	/**
	 * @param array<string, mixed>|null $analysis
	 * @param list<array<string, mixed>> $insights
	 * @return array{key: string, label: string, reasons: list<string>, weakness_ratio: ?float, measured: int, categories: list<string>, core: list<string>}
	 */
	public static function assess(?array $analysis, array $insights): array
	{
		$weak = self::weakness($analysis);
		$cats = WebsiteInsightGenerator::significantCategories($insights);
		$core = array_values(array_intersect($cats, InsightRuleRegistry::CORE));
		$highCore = array_filter($insights, fn ($i) => in_array($i['category'], InsightRuleRegistry::CORE, true) && in_array($i['severity'], ['high', 'critical'], true));
		$base = ['weakness_ratio' => $weak['ratio'], 'measured' => $weak['measured'], 'categories' => $cats, 'core' => $core];

		if (! $analysis || ! in_array($analysis['status'] ?? '', ['completed', 'partial'], true)) {
			return ['key' => 'insufficient_data', 'label' => self::LABELS['insufficient_data'], 'reasons' => [$analysis && ($analysis['status'] ?? '') === 'failed' ? 'Ostatnia analiza strony nie powiodła się (strona nie odpowiadała).' : 'Strona nie została jeszcze przeanalizowana.']] + $base;
		}

		if ($weak['measured'] < self::MIN_MEASURED) {
			return ['key' => 'insufficient_data', 'label' => self::LABELS['insufficient_data'], 'reasons' => [sprintf('Analiza zmierzyła tylko %d z 50 pkt kryteriów strony i techniki (minimum %d).', $weak['measured'], self::MIN_MEASURED)]] + $base;
		}

		$pct = (int) round(($weak['ratio'] ?? 0) * 100);
		$reasons = [sprintf('Niezależne kategorie z istotnymi problemami: %d (%s).', count($cats), $cats ? implode(', ', array_map(fn ($c) => InsightRuleRegistry::CATEGORIES[$c] ?? $c, $cats)) : 'brak'), sprintf('Zmierzona słabość strony i techniki: %d%% punktów możliwych do zmierzenia bez AI.', $pct)];

		if (count($cats) >= 3 && count($core) >= 2 && ($highCore || ($weak['ratio'] ?? 0) >= 0.45)) {
			$key = 'strong';
		} elseif (count($cats) >= 2 || $highCore || ($weak['ratio'] ?? 0) >= 0.25) {
			$key = 'moderate';
		} else {
			$key = 'weak';
		}

		if ($key !== 'strong' && $cats && ! $core) {
			$reasons[] = 'Wykryte problemy dotyczą wydajności / techniki / dostępności — same w sobie nie dowodzą potrzeby pełnego redesignu.';
		}

		$reasons[] = 'Warstwa wizualna (design, nowoczesność) nie jest oceniana automatycznie — AI Vision wyłączone.';

		return ['key' => $key, 'label' => self::LABELS[$key], 'reasons' => $reasons] + $base;
	}

	/**
	 * Udział zdobytych punktów słabości w kryteriach strony i techniki (bez kryterium AI i bez nieocenionych).
	 *
	 * @param array<string, mixed>|null $analysis
	 * @return array{ratio: ?float, measured: int, points: int}
	 */
	public static function weakness(?array $analysis): array
	{
		$points = 0;
		$measured = 0;

		foreach ((array) ($analysis['criteria'] ?? []) as $key => $c) {
			if ($key === 'a_visual_ai' || ($c['points'] ?? null) === null) {
				continue;
			}

			$points += (int) $c['points'];
			$measured += (int) $c['max'];
		}

		return ['ratio' => $measured > 0 ? round($points / $measured, 3) : null, 'measured' => $measured, 'points' => $points];
	}
}
