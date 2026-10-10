<?php

namespace App\Ubr\Domain\Tenancy;

/**
 * Kontekst żądania: zalogowany użytkownik + aktywna organizacja, w której JEST członkiem.
 * Tworzony wyłącznie przez TenantResolver na podstawie danych z bazy — nigdy z parametrów żądania.
 */
final class TenantContext
{
	/**
	 * @param array<string, mixed> $organization
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $organizationId,
		public readonly Role $role,
		public readonly array $organization,
	) {
	}

	public function can(string $ability): bool
	{
		return $this->role->can($ability);
	}
}
