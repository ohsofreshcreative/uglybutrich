<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Typy źródeł kontaktów i ich etykiety w UI.
 */
final class ContactSources
{
	public const KRS = 'KRS';

	public const WEBSITE = 'COMPANY_WEBSITE';

	public const APOLLO = 'APOLLO';

	public const USER = 'USER_PROVIDED';

	public const LABELS = [
		self::KRS => 'KRS',
		self::WEBSITE => 'Oficjalna strona firmy',
		self::APOLLO => 'Apollo',
		self::USER => 'Dodane ręcznie',
		'OFFICIAL_PUBLICATION' => 'Oficjalny komunikat',
	];

	public static function label(string $type): string
	{
		return self::LABELS[$type] ?? $type;
	}
}
