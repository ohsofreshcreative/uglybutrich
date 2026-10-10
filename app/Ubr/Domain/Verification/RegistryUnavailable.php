<?php

namespace App\Ubr\Domain\Verification;

use RuntimeException;

/**
 * Rejestr chwilowo niedostępny (timeout, 5xx, limit dzienny) — weryfikacja zostanie ponowiona później.
 * Nigdy nie przerywa Discovery.
 */
final class RegistryUnavailable extends RuntimeException
{
	public function __construct(string $message, public readonly string $source, public readonly int $retryAfter = 600)
	{
		parent::__construct($message);
	}
}
