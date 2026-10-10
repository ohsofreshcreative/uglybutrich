<?php

namespace App\Ubr\Infrastructure\Database;

use RuntimeException;

class DatabaseException extends RuntimeException
{
	public function __construct(string $message, public readonly bool $duplicate = false)
	{
		parent::__construct($message);
	}
}
