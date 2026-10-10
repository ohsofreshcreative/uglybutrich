<?php

namespace App\Ubr\Domain\Lead;

/**
 * Website Opportunity Insights — deterministyczne wnioski z zapisanej analizy strony (bez nowych pomiarów, bez AI).
 *
 * Wejście: snapshot analizy (findingi z dowodami, data, render). Wyjście: lista wniosków, każdy z kategorią,
 * severity (z progów), opisem, dowodami (pomiar + wykryty problem + źródło + data), rekomendacją i wiarygodnością.
 *
 * Severity (progi):
 * - bazowo = najwyższa severity findingów grupy, przy czym CRITICAL tylko wtedy, gdy analizator oznaczył finding jako
 *   krytyczny (np. blokada indeksowania); grupa złożona wyłącznie z findingów LOW = LOW;
 * - podniesienie o jeden poziom (maks. HIGH) dopiero przy ≥ 3 RÓŻNYCH przyczynach o severity ≥ MEDIUM w grupie
 *   (duplikaty tej samej przyczyny z dwóch metod pomiaru liczą się raz).
 */
final class WebsiteInsightGenerator
{
	public const VERSION = 'insights-2026-10-v1';

	private const LEVELS = ['info', 'low', 'medium', 'high', 'critical'];

	private const FALLBACK_CATEGORY = [
		'performance' => 'performance', 'mobile' => 'mobile', 'accessibility' => 'accessibility', 'ux' => 'ux',
		'content' => 'content_structure', 'seo' => 'content_structure', 'security' => 'technical', 'conversion' => 'conversion',
	];

	/**
	 * @param array<string, mixed>|null $analysis snapshot: id, status, finished_at, rendered, findings[]
	 * @return list<array<string, mixed>>
	 */
	public static function generate(?array $analysis): array
	{
		if (! $analysis || ! in_array($analysis['status'] ?? '', ['completed', 'partial'], true)) {
			return [];
		}

		$date = (string) ($analysis['finished_at'] ?? '');
		$groups = [];

		foreach ((array) ($analysis['findings'] ?? []) as $f) {
			$key = (string) ($f['key'] ?? '');

			if ($key === '' || in_array($f['source'] ?? '', InsightRuleRegistry::IGNORED_SOURCES, true) || in_array($f['category'] ?? '', InsightRuleRegistry::IGNORED_CATEGORIES, true)) {
				continue;
			}

			// Finding bez dowodu nie jest podstawą wniosku.
			if (empty($f['evidence'])) {
				continue;
			}

			$group = InsightRuleRegistry::groupFor($key);

			if ($group === null) {
				$cat = self::FALLBACK_CATEGORY[$f['category'] ?? ''] ?? null;

				if ($cat === null) {
					continue;
				}

				$group = 'other_' . $cat;
			}

			$groups[$group][] = $f;
		}

		$insights = [];

		foreach ($groups as $group => $findings) {
			$def = InsightRuleRegistry::GROUPS[$group] ?? null;
			$category = $def['category'] ?? substr($group, 6);
			$severity = self::severity($findings);
			$causes = [];
			$evidence = [];

			foreach ($findings as $f) {
				$causes[InsightRuleRegistry::cause((string) $f['key'])] = true;
				$evidence[] = [
					'measure' => (string) ($f['title'] ?? $f['key']),
					'detail' => implode(' · ', array_slice(array_map('strval', (array) $f['evidence']), 0, 2)),
					'source' => self::sourceLabel((string) $f['key'], (string) ($f['source'] ?? 'automated'), (bool) ($analysis['rendered'] ?? false)),
					'date' => $date,
					'severity' => (string) ($f['severity'] ?? 'info'),
				];
			}

			usort($evidence, fn ($a, $b) => [array_search($b['severity'], self::LEVELS, true), $a['measure']] <=> [array_search($a['severity'], self::LEVELS, true), $b['measure']]);

			$insights[] = [
				'key' => $group,
				'category' => $category,
				'category_label' => InsightRuleRegistry::CATEGORIES[$category] ?? $category,
				'severity' => $severity,
				'title' => $def['title'] ?? (string) ($findings[0]['title'] ?? 'Wykryty problem'),
				'description' => $def['description'] ?? (string) ($findings[0]['description'] ?? ''),
				'evidence' => array_slice($evidence, 0, 6),
				'source' => 'Website Analyzer (analiza #' . (int) ($analysis['id'] ?? 0) . ')',
				'recommendation' => $def['recommendation'] ?? (string) ($findings[0]['recommendation'] ?? ''),
				'confidence' => self::confidence($def['confidence'] ?? 'medium', $findings),
				'causes' => count($causes),
				'finding_keys' => array_values(array_unique(array_map(fn ($f) => (string) $f['key'], $findings))),
				'angle' => $def['angle'] ?? null,
				'generated_from' => $date,
				'algorithm_version' => self::VERSION,
			];
		}

		usort($insights, fn ($a, $b) => [array_search($b['severity'], self::LEVELS, true), $b['causes'], $a['key']] <=> [array_search($a['severity'], self::LEVELS, true), $a['causes'], $b['key']]);

		return $insights;
	}

	/**
	 * @param list<array<string, mixed>> $findings
	 */
	public static function severity(array $findings): string
	{
		$max = 0;
		$significant = [];

		foreach ($findings as $f) {
			$level = (int) array_search((string) ($f['severity'] ?? 'info'), self::LEVELS, true);
			$max = max($max, $level);

			if ($level >= 2) {
				$significant[InsightRuleRegistry::cause((string) $f['key'])] = true;
			}
		}

		if ($max < 4 && count($significant) >= 3) {
			$max = min(3, $max + 1);
		}

		return self::LEVELS[$max];
	}

	/**
	 * @param list<array<string, mixed>> $findings
	 */
	private static function confidence(string $base, array $findings): string
	{
		// Wniosek oparty na ocenie AI (gdyby była włączona) lub wyłącznie na heurystyce o niskiej severity — niższa pewność.
		if (array_filter($findings, fn ($f) => ($f['source'] ?? '') === 'ai')) {
			return 'low';
		}

		return $base;
	}

	private static function sourceLabel(string $key, string $source, bool $rendered): string
	{
		if ($source === 'ai') {
			return 'ocena AI';
		}

		return match (true) {
			str_starts_with($key, 'axe_') => 'axe-core (render Chromium)',
			in_array($key, ['perf_slow', 'perf_heavy', 'perf_broken_resources', 'mobile_overflow', 'mobile_scaled_layout', 'mobile_small_text', 'mobile_tap_targets', 'conv_no_cta_fold'], true) => 'render Chromium (1 pomiar laboratoryjny)',
			default => 'analiza HTML' . ($rendered ? '' : ' (bez renderu)'),
		};
	}

	/**
	 * Liczba niezależnych kategorii z wnioskami o severity ≥ MEDIUM.
	 *
	 * @param list<array<string, mixed>> $insights
	 * @return list<string>
	 */
	public static function significantCategories(array $insights): array
	{
		$cats = [];

		foreach ($insights as $i) {
			if (in_array($i['severity'], ['medium', 'high', 'critical'], true)) {
				$cats[$i['category']] = true;
			}
		}

		$cats = array_keys($cats);
		sort($cats);

		return $cats;
	}
}
