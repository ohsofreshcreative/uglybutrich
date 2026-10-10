<?php

namespace App\Ubr\Domain\Verification;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;

/**
 * Porównanie nazwy firmy z nazwą w rejestrze (deterministyczne).
 *
 * 1.0  identyczne po normalizacji (bez formy prawnej, diakrytyków, interpunkcji),
 * 0.9  wszystkie istotne słowa krótszej nazwy występują w dłuższej („Famur” ↔ „FAMUR SPÓŁKA AKCYJNA”),
 * 0.85 jedna nazwa zawiera drugą po usunięciu spacji („Intercars” ↔ „Inter Cars”),
 * <0.85 częściowa zgodność słów (Jaccard) — NIE wystarcza do przypisania identyfikatora.
 * Podobne nazwy z różnymi słowami wyróżniającymi („Stalbud Kowalski” ↔ „Stalbud Nowak”) dają < 0.5.
 */
final class NameMatcher
{
	public const STRONG = 0.85;

	public const WEAK = 0.5;

	public static function score(?string $a, ?string $b): float
	{
		$a = trim((string) $a);
		$b = trim((string) $b);

		if ($a === '' || $b === '') {
			return 0.0;
		}

		$na = N::normalizeName($a);
		$nb = N::normalizeName($b);

		if ($na === $nb) {
			return 1.0;
		}

		$ta = N::nameTokens($a) ?: array_values(array_filter(explode(' ', $na)));
		$tb = N::nameTokens($b) ?: array_values(array_filter(explode(' ', $nb)));

		if ($ta === [] || $tb === []) {
			return 0.0;
		}

		[$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];

		if (array_diff($short, $long) === []) {
			return 0.9;
		}

		$ca = str_replace(' ', '', $na);
		$cb = str_replace(' ', '', $nb);

		if (min(strlen($ca), strlen($cb)) >= 4 && (str_contains($ca, $cb) || str_contains($cb, $ca))) {
			return 0.85;
		}

		$intersection = count(array_intersect($ta, $tb));
		$union = count(array_unique(array_merge($ta, $tb)));

		return $union > 0 ? round($intersection / $union, 2) : 0.0;
	}

	public static function matches(?string $a, ?string $b): bool
	{
		return self::score($a, $b) >= self::STRONG;
	}
}
