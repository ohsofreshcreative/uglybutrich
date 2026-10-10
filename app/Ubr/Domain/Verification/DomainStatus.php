<?php

namespace App\Ubr\Domain\Verification;

/**
 * Status domeny (czy strona WWW należy do tej firmy) — niezależny od aktywności przedsiębiorstwa.
 *
 * Samo podobieństwo nazwy domeny do nazwy firmy NIGDY nie daje statusu CONFIRMED.
 */
final class DomainStatus
{
	public const UNCHECKED = 'unchecked';

	public const CONFIRMED = 'confirmed';

	public const UNCERTAIN = 'uncertain';

	public const MISMATCH = 'mismatch';

	public const UNAVAILABLE = 'unavailable';

	public const ALL = [self::UNCHECKED, self::CONFIRMED, self::UNCERTAIN, self::MISMATCH, self::UNAVAILABLE];

	public const LABELS = [
		self::UNCHECKED => 'Niesprawdzona',
		self::CONFIRMED => 'Potwierdzona',
		self::UNCERTAIN => 'Niepewna',
		self::MISMATCH => 'Niezgodna',
		self::UNAVAILABLE => 'Niedostępna',
	];

	public static function label(?string $status): string
	{
		return self::LABELS[$status ?? self::UNCHECKED] ?? self::LABELS[self::UNCHECKED];
	}
}
