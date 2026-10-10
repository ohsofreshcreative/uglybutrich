<?php

namespace App\Ubr\Domain\Analysis;

/**
 * Opportunity Score 0–100: szansa sprzedaży redesignu. WYSOKI = słaba strona + duży potencjał firmy (nie: dobra strona).
 *
 * Kategorie (wagi z promptu, doprecyzowane podkryteriami):
 *   A. Słabość strony 45 = design AI 15 + nawigacja 5 + struktura 3 + viewport 3 + mobile render 7 + CTA/kontakt 4 + CTA 1. ekran 3 + treść 5
 *   B. Słabość techniczna 20 = wydajność serwer 2 + wydajność przeglądarka 5 + SEO 5 + dostępność statyczna 2 + axe 3 + bezpieczeństwo 3
 *   C. Potencjał biznesowy 25 = wielkość 10 + branża 8 + B2B 4 + dane finansowe 3
 *   D. Wiarygodność firmy 10 = status w rejestrze 5 + domena 3 + aktualność 2
 *
 * Zasady:
 * - kryterium bez pomiaru (NOT_EVALUATED) = 0 pkt; NIE przeskalowujemy wyniku do brakujących kategorii,
 * - coverage = suma wag zmierzonych kryteriów (%); confidence z coverage (high ≥ 90, medium ≥ 65, low),
 * - INSUFFICIENT_DATA (score = null): strona niedostępna / brak HTML albo coverage < 50%,
 * - firma nieaktywna/zawieszona w rejestrze → score ≤ 10,
 * - wynik pokazujemy z „maksimum możliwym” = score + nieocenione punkty (przedział, nie pozorna precyzja).
 */
final class OpportunityScoreCalculator
{
	public const VERSION = 'opp-2026-10-v1';

	public const CATEGORIES = [
		'website_weakness' => ['label' => 'Słabość strony', 'max' => 45, 'keys' => ['a_visual_ai', 'a_ux_navigation', 'a_ux_structure', 'a_mobile_viewport', 'a_mobile_render', 'a_conversion_static', 'a_conversion_fold', 'a_content']],
		'technical_weakness' => ['label' => 'Słabość techniczna', 'max' => 20, 'keys' => ['b_perf_static', 'b_perf_render', 'b_seo', 'b_a11y_static', 'b_a11y_axe', 'b_security']],
		'business_potential' => ['label' => 'Potencjał biznesowy', 'max' => 25, 'keys' => ['c_size', 'c_industry', 'c_b2b', 'c_financial']],
		'company_confidence' => ['label' => 'Wiarygodność firmy', 'max' => 10, 'keys' => ['d_verification', 'd_domain', 'd_freshness']],
	];

	public const CRITERIA_LABELS = [
		'a_visual_ai' => 'Design (analiza wizualna AI)', 'a_ux_navigation' => 'Nawigacja', 'a_ux_structure' => 'Struktura strony głównej',
		'a_mobile_viewport' => 'Mobile: viewport', 'a_mobile_render' => 'Mobile: układ, cele dotykowe, tekst', 'a_conversion_static' => 'Kontakt i CTA',
		'a_conversion_fold' => 'CTA w pierwszym ekranie', 'a_content' => 'Treść: aktualność, oferta, zaufanie',
		'b_perf_static' => 'Wydajność: serwer', 'b_perf_render' => 'Wydajność: przeglądarka (lab)', 'b_seo' => 'SEO', 'b_a11y_static' => 'Dostępność: HTML',
		'b_a11y_axe' => 'Dostępność: axe-core', 'b_security' => 'Bezpieczeństwo / jakość',
		'c_size' => 'Wielkość firmy', 'c_industry' => 'Branża', 'c_b2b' => 'Charakter B2B', 'c_financial' => 'Dane finansowe',
		'd_verification' => 'Status w rejestrze', 'd_domain' => 'Zgodność domeny', 'd_freshness' => 'Aktualność weryfikacji',
	];

	/**
	 * Przedziały interpretacji (konfigurowalne filtrem `ubr/opportunity/levels`).
	 *
	 * @return list<array{min: int, key: string, label: string}>
	 */
	public static function levels(): array
	{
		return (array) apply_filters('ubr/opportunity/levels', [
			['min' => 80, 'key' => 'very_high', 'label' => 'Very High Opportunity'],
			['min' => 60, 'key' => 'high', 'label' => 'High Opportunity'],
			['min' => 40, 'key' => 'moderate', 'label' => 'Moderate Opportunity'],
			['min' => 0, 'key' => 'low', 'label' => 'Low Opportunity'],
		]);
	}

	/**
	 * @param array<string, array<string, mixed>> $criteria wszystkie kryteria z analizatorów
	 * @return array<string, mixed>
	 */
	public static function calculate(array $criteria, bool $siteReachable, string $verificationStatus): array
	{
		$categories = [];
		$score = 0;
		$measuredMax = 0;
		$unmeasured = 0;

		foreach (self::CATEGORIES as $key => $def) {
			$cat = ['label' => $def['label'], 'max' => $def['max'], 'points' => 0, 'measured_max' => 0, 'criteria' => []];

			foreach ($def['keys'] as $ck) {
				$c = $criteria[$ck] ?? ['points' => null, 'max' => 0, 'state' => 'not_evaluated', 'detail' => 'Brak kryterium.', 'evidence' => [], 'source' => 'none'];
				$cat['criteria'][$ck] = $c + ['label' => self::CRITERIA_LABELS[$ck] ?? $ck];

				if ($c['points'] !== null) {
					$cat['points'] += $c['points'];
					$cat['measured_max'] += $c['max'];
				} else {
					$unmeasured += $c['max'];
				}
			}

			$cat['coverage'] = $def['max'] > 0 ? (int) round($cat['measured_max'] / $def['max'] * 100) : 0;
			$categories[$key] = $cat;
			$score += $cat['points'];
			$measuredMax += $cat['measured_max'];
		}

		$coverage = (int) round($measuredMax);
		$confidence = $coverage >= 90 ? 'high' : ($coverage >= 65 ? 'medium' : 'low');
		$caps = [];

		if (in_array($verificationStatus, ['inactive', 'suspended'], true) && $score > 10) {
			$caps[] = 'Firma ' . ($verificationStatus === 'inactive' ? 'nieaktywna' : 'zawieszona') . ' w rejestrze — wynik ograniczony do 10.';
			$score = 10;
		}

		$insufficient = null;

		if (! $siteReachable) {
			$insufficient = 'Strona niedostępna lub nie udało się jej pobrać — brak podstaw do oceny szansy redesignu.';
		} elseif ($coverage < 50) {
			$insufficient = sprintf('Za mało danych: zmierzono %d%% kryteriów (minimum 50%%).', $coverage);
		}

		return [
			'version' => self::VERSION,
			'score' => $insufficient ? null : $score,
			'partial_points' => $score,
			'max_possible' => $insufficient ? null : min(100, $score + $unmeasured),
			'status' => $insufficient ? 'INSUFFICIENT_DATA' : 'SCORED',
			'insufficient_reason' => $insufficient,
			'coverage' => $coverage,
			'confidence' => $insufficient ? 'low' : $confidence,
			'level' => $insufficient ? null : self::level($score),
			'categories' => $categories,
			'caps' => $caps,
		];
	}

	/**
	 * @return array{key: string, label: string}|null
	 */
	public static function level(?int $score): ?array
	{
		if ($score === null) {
			return null;
		}

		foreach (self::levels() as $level) {
			if ($score >= $level['min']) {
				return ['key' => $level['key'], 'label' => $level['label']];
			}
		}

		return null;
	}
}
