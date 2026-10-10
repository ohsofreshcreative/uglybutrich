<?php

namespace App\Ubr\Domain\Verification;

/**
 * Status przedsiębiorstwa (aktywność w rejestrach) — niezależny od statusu domeny.
 *
 * VERIFIED wymaga rekordu w rejestrze publicznym (KRS / biała lista VAT / GUS / CEIDG), którego nazwa
 * odpowiada firmie, i statusu „aktywny”. Dane Apollo (ani żadnego innego dostawcy Discovery) NIGDY nie wystarczają.
 */
final class VerificationStatus
{
	public const PENDING = 'pending';

	public const VERIFIED = 'verified';

	public const UNCERTAIN = 'uncertain';

	public const INACTIVE = 'inactive';

	public const SUSPENDED = 'suspended';

	public const ALL = [self::PENDING, self::VERIFIED, self::UNCERTAIN, self::INACTIVE, self::SUSPENDED];

	public const LABELS = [
		self::PENDING => 'Do weryfikacji',
		self::VERIFIED => 'Zweryfikowana',
		self::UNCERTAIN => 'Niepewna',
		self::INACTIVE => 'Nieaktywna',
		self::SUSPENDED => 'Zawieszona',
	];

	public static function label(?string $status): string
	{
		return self::LABELS[$status ?? self::PENDING] ?? self::LABELS[self::PENDING];
	}
}
