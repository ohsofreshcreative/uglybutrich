<?php

namespace App\Ubr\Domain\Discovery;

use RuntimeException;

final class ProviderException extends RuntimeException
{
	public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $retryAfter = null)
	{
		parent::__construct($message);
	}
}
