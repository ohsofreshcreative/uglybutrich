<?php

namespace App\Ubr\Infrastructure\Mail;

use PHPMailer\PHPMailer\SMTP;

if (! class_exists(SMTP::class)) {
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
}

/**
 * Klient SMTP z PHPMailera zapamiętujący ostatnie polecenie (DATA vs DATA END) — podstawa klasyfikacji
 * „na pewno nie wysłano” vs „wynik nieznany”.
 */
class TrackingSmtp extends SMTP
{
	public string $lastCommand = '';

	protected function sendCommand($command, $commandstring, $expect)
	{
		$this->lastCommand = (string) $command;

		return parent::sendCommand($command, $commandstring, $expect);
	}
}
