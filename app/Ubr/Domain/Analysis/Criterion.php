<?php

namespace App\Ubr\Domain\Analysis;

/**
 * Kryterium Opportunity Score. `points = null` oznacza NOT_EVALUATED (brak danych / brak pomiaru) — wtedy
 * kryterium NIE dostaje punktów i obniża pokrycie (coverage). Punkty = słabość strony / potencjał (więcej = większa szansa sprzedaży).
 */
final class Criterion
{
	/**
	 * @param list<string> $evidence
	 * @return array{points: ?int, max: int, state: string, detail: string, evidence: list<string>, source: string}
	 */
	public static function measured(int $points, int $max, string $detail, array $evidence = [], string $source = 'automated'): array
	{
		return ['points' => max(0, min($max, $points)), 'max' => $max, 'state' => 'measured', 'detail' => $detail, 'evidence' => array_values(array_slice($evidence, 0, 8)), 'source' => $source];
	}

	/**
	 * @return array{points: null, max: int, state: string, detail: string, evidence: list<string>, source: string}
	 */
	public static function notEvaluated(int $max, string $reason): array
	{
		return ['points' => null, 'max' => $max, 'state' => 'not_evaluated', 'detail' => $reason, 'evidence' => [], 'source' => 'none'];
	}
}
