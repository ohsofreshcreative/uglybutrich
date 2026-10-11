<?php

namespace App\Ubr\Infrastructure\Mail;

/**
 * Prompt 10.5 — transport poczty dla skrzynki pracownika (SMTP; w przyszłości np. Gmail API / Microsoft Graph).
 *
 * Wynik wysyłki (`send`):
 * - `sent`    — serwer poczty PRZYJĄŁ wiadomość (250 po DATA). To nie jest potwierdzenie dostarczenia do skrzynki odbiorcy.
 * - `retry`   — wiadomość na pewno NIE została przyjęta (błąd przed przekazaniem treści albo jawne odrzucenie 4xx) — można ponowić.
 * - `failed`  — trwałe odrzucenie (5xx); `bounce` = serwer odrzucił adres odbiorcy.
 * - `auth`    — błędne dane logowania / wygasły dostęp (wiadomość nie wyszła; skrzynka wymaga uwagi).
 * - `unknown` — treść została przekazana, ale brak odpowiedzi serwera (timeout / zerwane połączenie) — NIE ponawiać automatycznie.
 */
interface MailTransport
{
	/**
	 * @param array<string, mixed> $mailbox wiersz `ubr_seq_mailboxes`
	 * @return array{status: string, response: ?string, error: ?string, bounce: bool}
	 */
	public function send(OutgoingMessage $message, array $mailbox, ?string $secret): array;

	/**
	 * Test połączenia i logowania (bez wysyłania wiadomości).
	 *
	 * @param array<string, mixed> $mailbox
	 * @return array{ok: bool, status: string, message: string}
	 */
	public function test(array $mailbox, ?string $secret): array;
}
