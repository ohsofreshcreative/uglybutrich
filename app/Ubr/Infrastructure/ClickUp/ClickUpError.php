<?php

namespace App\Ubr\Infrastructure\ClickUp;

use RuntimeException;

/**
 * Błąd ClickUp z rodzajem decydującym o obsłudze:
 * - AUTH → połączenie wymaga ponownej autoryzacji (bez ponowień),
 * - FORBIDDEN / NOT_FOUND / INVALID → trwałe (bez ponowień, komunikat dla użytkownika),
 * - RATE_LIMITED / SERVER / NETWORK → tymczasowe (ponowienie z backoffem / Retry-After, z limitem prób),
 * - AMBIGUOUS → POST tworzący bez jednoznacznej odpowiedzi: stan UNKNOWN, BEZ automatycznego ponowienia (ryzyko duplikatu).
 */
final class ClickUpError extends RuntimeException
{
	public const AUTH = 'auth';

	public const FORBIDDEN = 'forbidden';

	public const NOT_FOUND = 'not_found';

	public const RATE_LIMITED = 'rate_limited';

	public const SERVER = 'server';

	public const NETWORK = 'network';

	public const AMBIGUOUS = 'ambiguous';

	public const INVALID = 'invalid';

	public const CONFIG = 'config';

	public function __construct(
		public readonly string $kind,
		string $message,
		public readonly ?int $httpStatus = null,
		public readonly string $apiCode = '',
		public readonly int $retryAfter = 0,
	) {
		parent::__construct($message);
	}

	public function temporary(): bool
	{
		return in_array($this->kind, [self::RATE_LIMITED, self::SERVER, self::NETWORK], true);
	}

	public function needsReconnect(): bool
	{
		return $this->kind === self::AUTH;
	}
}
