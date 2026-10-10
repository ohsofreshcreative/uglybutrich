<?php

namespace App\Ubr\Domain\Analysis;

/**
 * Wykryty problem (finding). Zawsze z dowodem — bez dowodu nie tworzymy findingu.
 *
 * source: automated (obiektywny pomiar/reguła), ai (ocena modelu — tylko gdy analiza AI faktycznie się odbyła),
 *         manual_review (sugestia do ręcznego sprawdzenia — nie jest stwierdzonym problemem).
 */
final class Finding
{
	public const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

	public const CATEGORIES = [
		'design' => 'Design', 'ux' => 'UX', 'mobile' => 'Mobile', 'conversion' => 'Konwersja', 'content' => 'Treść',
		'performance' => 'Wydajność', 'seo' => 'SEO', 'accessibility' => 'Dostępność', 'security' => 'Bezpieczeństwo / jakość',
		'business' => 'Potencjał biznesowy', 'company' => 'Wiarygodność firmy',
	];

	/**
	 * @param string|list<string> $evidence
	 * @return array{category: string, severity: string, title: string, description: string, source: string, evidence: list<string>, recommendation: string, key: string}
	 */
	public static function make(string $key, string $category, string $severity, string $title, string $description, string|array $evidence, string $recommendation, string $source = 'automated'): array
	{
		return [
			'key' => $key,
			'category' => $category,
			'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
			'title' => $title,
			'description' => $description,
			'source' => $source,
			'evidence' => array_values(array_map(fn ($e) => mb_substr((string) $e, 0, 300), (array) $evidence)),
			'recommendation' => $recommendation,
		];
	}

	public static function rank(string $severity): int
	{
		return (int) array_search($severity, self::SEVERITIES, true);
	}

	/**
	 * @param list<array<string, mixed>> $findings
	 * @return list<array<string, mixed>>
	 */
	public static function sort(array $findings): array
	{
		usort($findings, fn ($a, $b) => [self::rank($b['severity']), $a['source'] === 'manual_review' ? 1 : 0] <=> [self::rank($a['severity']), $b['source'] === 'manual_review' ? 1 : 0]);

		return $findings;
	}
}
