<?php

namespace App\Ubr\Domain\Analysis\Age;

/**
 * Prompt 10.4.3 — porównanie projektu dwóch wersji strony (odciski z FingerprintExtractor).
 *
 * Werdykty:
 * - `same`  — ten sam projekt (różnice tylko w treści: artykuły, zdjęcia, teksty, numery) → aktualizacja treści,
 * - `minor` — drobne zmiany wizualne / techniczne (część zasobów lub klas, ten sam układ),
 * - `major` — istotna przebudowa (inny motyw / platforma albo większość struktury, zasobów i klas inna),
 * - `uncertain` — za mało cech do wiarygodnego porównania (np. strona generowana skryptem, archiwum bez CSS).
 */
final class FingerprintComparator
{
	public const WEIGHTS = ['assets' => 0.3, 'classes' => 0.3, 'structure' => 0.25, 'nav' => 0.15];

	public const SAME_FROM = 0.8;

	public const MINOR_FROM = 0.45;

	/** Minimalna liczba cech każdej wersji (zasoby + klasy + struktura + menu). */
	public const MIN_FEATURES = 8;

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @return array{verdict: string, similarity: ?float, parts: array<string, ?float>, reason: string}
	 */
	public static function compare(array $a, array $b): array
	{
		if ((int) ($a['features'] ?? 0) < self::MIN_FEATURES || (int) ($b['features'] ?? 0) < self::MIN_FEATURES) {
			return ['verdict' => 'uncertain', 'similarity' => null, 'parts' => [], 'reason' => 'Za mało cech projektu do porównania (np. strona generowana skryptem lub niepełna kopia w archiwum).'];
		}

		$parts = [];
		$total = 0.0;
		$weights = 0.0;

		foreach (self::WEIGHTS as $key => $weight) {
			$x = (array) ($a[$key] ?? []);
			$y = (array) ($b[$key] ?? []);

			if ($x === [] && $y === []) {
				$parts[$key] = null;
				continue;
			}

			$parts[$key] = self::jaccard($x, $y);
			$total += $parts[$key] * $weight;
			$weights += $weight;
		}

		$similarity = $weights > 0 ? round($total / $weights, 3) : null;

		if ($similarity === null) {
			return ['verdict' => 'uncertain', 'similarity' => null, 'parts' => $parts, 'reason' => 'Brak porównywalnych cech.'];
		}

		$themeChanged = ! empty($a['theme']) && ! empty($b['theme']) && $a['theme'] !== $b['theme'];
		$platformChanged = ! empty($a['platform']) && ! empty($b['platform']) && $a['platform'] !== $b['platform'];

		if ($themeChanged || $platformChanged) {
			return ['verdict' => 'major', 'similarity' => $similarity, 'parts' => $parts, 'reason' => $themeChanged ? sprintf('Zmiana motywu strony (%s → %s).', $b['theme'], $a['theme']) : sprintf('Zmiana platformy (%s → %s).', $b['platform'], $a['platform'])];
		}

		return match (true) {
			$similarity >= self::SAME_FROM => ['verdict' => 'same', 'similarity' => $similarity, 'parts' => $parts, 'reason' => 'Ten sam projekt — różnice dotyczą treści.'],
			$similarity >= self::MINOR_FROM => ['verdict' => 'minor', 'similarity' => $similarity, 'parts' => $parts, 'reason' => 'Drobne zmiany wizualne lub techniczne przy tym samym układzie.'],
			default => ['verdict' => 'major', 'similarity' => $similarity, 'parts' => $parts, 'reason' => 'Istotnie inna struktura, zasoby i klasy — przebudowa strony.'],
		};
	}

	/**
	 * @param list<string> $x
	 * @param list<string> $y
	 */
	public static function jaccard(array $x, array $y): float
	{
		$x = array_unique($x);
		$y = array_unique($y);
		$union = count(array_unique(array_merge($x, $y)));

		return $union === 0 ? 1.0 : count(array_intersect($x, $y)) / $union;
	}
}
