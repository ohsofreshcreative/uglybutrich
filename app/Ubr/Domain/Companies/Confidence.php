<?php

namespace App\Ubr\Domain\Companies;

/**
 * Poziom pewności informacji o firmie.
 *
 * - confirmed:   potwierdzone w rejestrze publicznym (KRS, biała lista VAT, GUS, CEIDG) albo weryfikacją
 *                (np. strona odpowiada i zawiera dane firmy, oficjalna strona w Wikidata).
 * - declared:    podane wprost przez dostawcę danych (pole w rekordzie Apollo/Wikidata/OSM), bez potwierdzenia.
 * - uncertain:   pośrednie / niepewne (np. strona WWW z bazy społecznościowej).
 * - unavailable: brak danych — NIGDY nie zgadujemy.
 *
 * Wartość wynikająca WYŁĄCZNIE z filtra wyszukiwania (np. „Apollo zwróciło firmę dla filtra województwo
 * mazowieckie”) nie jest daną firmy — nie zapisujemy jej w polach firmy (RelevanceScorer: stan „filtered”).
 */
final class Confidence
{
	public const CONFIRMED = 'confirmed';

	public const DECLARED = 'declared';

	public const UNCERTAIN = 'uncertain';

	public const UNAVAILABLE = 'unavailable';

	public static function rank(string $value): int
	{
		return match ($value) {
			self::CONFIRMED => 3,
			self::DECLARED => 2,
			self::UNCERTAIN => 1,
			default => 0,
		};
	}
}
