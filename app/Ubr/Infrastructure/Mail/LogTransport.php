<?php

namespace App\Ubr\Infrastructure\Mail;

/**
 * Prompt 10.5 — transport TESTOWY: wiadomość jest budowana i zapisywana w historii, ale NIGDY nie wychodzi
 * do serwera poczty. Używany przez sekwencje w trybie testowym i skrzynki typu „test” (środowiska nieprodukcyjne).
 */
class LogTransport implements MailTransport
{
	public function send(OutgoingMessage $message, array $mailbox, ?string $secret): array
	{
		try {
			$message->mime();
		} catch (\Throwable $e) {
			return ['status' => 'failed', 'response' => null, 'error' => 'Nie udało się zbudować wiadomości: ' . $e->getMessage(), 'bounce' => false];
		}

		return ['status' => 'sent', 'response' => 'TRYB TESTOWY — wiadomość nie została wysłana do serwera poczty.', 'error' => null, 'bounce' => false];
	}

	public function test(array $mailbox, ?string $secret): array
	{
		return ['ok' => true, 'status' => 'connected', 'message' => 'Skrzynka testowa — wiadomości nie wychodzą poza aplikację.'];
	}
}
