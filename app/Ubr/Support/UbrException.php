<?php

namespace App\Ubr\Support;

use RuntimeException;
use WP_Error;

/**
 * Błąd domenowy z kodem HTTP — kontrolery zamieniają go na WP_Error.
 */
class UbrException extends RuntimeException
{
	public function __construct(string $message, public readonly string $errorCode = 'ubr_error', public readonly int $status = 400)
	{
		parent::__construct($message);
	}

	public static function forbidden(string $message = 'Brak uprawnień do tej operacji.'): self
	{
		return new self($message, 'ubr_forbidden', 403);
	}

	public static function notFound(string $message = 'Nie znaleziono zasobu.'): self
	{
		return new self($message, 'ubr_not_found', 404);
	}

	public static function invalid(string $message): self
	{
		return new self($message, 'ubr_invalid', 422);
	}

	public static function limit(string $message): self
	{
		return new self($message, 'ubr_limit', 429);
	}

	public function toWpError(): WP_Error
	{
		return new WP_Error($this->errorCode, $this->getMessage(), ['status' => $this->status]);
	}
}
