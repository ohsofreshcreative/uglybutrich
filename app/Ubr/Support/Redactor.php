<?php

namespace App\Ubr\Support;

/**
 * Redakcja sekretów w logach i komunikatach (tokeny Bearer, access_token, client_secret, code, klucze pk_).
 */
final class Redactor
{
	public static function redact(string $text, array $secrets = []): string
	{
		foreach ($secrets as $secret) {
			if (is_string($secret) && strlen($secret) >= 6) {
				$text = str_replace($secret, '[REDACTED]', $text);
			}
		}

		$patterns = [
			'/(Bearer\s+)[A-Za-z0-9._\-]+/i' => '$1[REDACTED]',
			'/("?(?:access_token|refresh_token|client_secret|token|secret|code|authorization)"?\s*[:=]\s*"?)[^"&\s,}]+/i' => '$1[REDACTED]',
			'/\bpk_[A-Za-z0-9_]{6,}/' => 'pk_[REDACTED]',
		];

		return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
	}

	public static function log(string $message, array $secrets = []): void
	{
		error_log('[ubr] ' . self::redact($message, $secrets));
	}
}
