<?php

namespace App\Ubr\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Prompt 10.5 — wiadomość do wysłania (tekst, UTF-8). MIME budowane przez PHPMailer z WordPressa — bez użycia
 * `wp_mail()` i jego filtrów (poczta systemowa WordPressa i wtyczki SMTP pozostają nietknięte).
 */
final class OutgoingMessage
{
	/**
	 * @param array<string, string> $headers dodatkowe nagłówki (np. List-Unsubscribe)
	 */
	public function __construct(
		public readonly string $fromEmail,
		public readonly string $fromName,
		public readonly string $toEmail,
		public readonly string $toName,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $messageId,
		public readonly array $headers = [],
	) {
	}

	/** Pełna wiadomość RFC 5322 (nagłówki + treść). */
	public function mime(): string
	{
		if (! class_exists(PHPMailer::class)) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}

		$mail = new PHPMailer(true);
		$mail->Mailer = 'smtp';
		$mail->CharSet = PHPMailer::CHARSET_UTF8;
		$mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
		$mail->XMailer = ' ';
		$mail->isHTML(false);
		$mail->setFrom($this->fromEmail, $this->fromName, false);
		$mail->addAddress($this->toEmail, $this->toName);
		$mail->Subject = $this->subject;
		$mail->Body = $this->body;
		$mail->MessageID = $this->messageId;

		foreach ($this->headers as $name => $value) {
			$mail->addCustomHeader($name, $value);
		}

		$mail->preSend();

		return $mail->getSentMIMEMessage();
	}
}
