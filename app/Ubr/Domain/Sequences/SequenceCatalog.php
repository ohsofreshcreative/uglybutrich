<?php

namespace App\Ubr\Domain\Sequences;

/**
 * Prompt 10.5 — statusy kampanii, sekwencji, wiadomości i skrzynek (polskie etykiety w UI).
 */
final class SequenceCatalog
{
	public const CAMPAIGN_STATUSES = ['draft' => 'Szkic', 'active' => 'Aktywna', 'archived' => 'Zarchiwizowana'];

	public const SEQUENCE_STATUSES = [
		'draft' => 'Szkic',
		'scheduled' => 'Zaplanowana',
		'active' => 'Aktywna',
		'paused' => 'Wstrzymana',
		'completed' => 'Zakończona',
		'cancelled' => 'Anulowana',
		'error' => 'Błąd — wymaga uwagi',
	];

	/** Sekwencje blokujące adres odbiorcy (jedna na adres w organizacji). */
	public const LIVE = ['scheduled', 'active', 'paused', 'error'];

	/** Sekwencje, w których można edytować niewysłane kroki. */
	public const EDITABLE = ['draft', 'scheduled', 'active', 'paused', 'error'];

	public const MESSAGE_STATUSES = [
		'pending' => 'Oczekuje',
		'scheduled' => 'Zaplanowana',
		'sending' => 'Wysyłanie',
		'sent' => 'Przekazana do serwera poczty',
		'failed' => 'Błąd wysyłki',
		'cancelled' => 'Anulowana',
		'unknown_result' => 'Wynik nieznany — sprawdź folder Wysłane',
	];

	/** Wiadomości, których treść jest już zamknięta (historia). */
	public const FINAL_MESSAGES = ['sending', 'sent', 'failed', 'cancelled', 'unknown_result'];

	public const MAILBOX_STATUSES = [
		'untested' => 'Nie przetestowano',
		'connected' => 'Połączona',
		'auth_failed' => 'Błędne dane logowania',
		'error' => 'Błąd połączenia',
		'reconnect' => 'Wymaga ponownego podania hasła',
		'disabled' => 'Wyłączona',
	];

	public const SUPPRESSION_REASONS = [
		'unsubscribed' => 'Rezygnacja z wiadomości',
		'bounced' => 'Trwały błąd dostarczenia',
		'manual' => 'Wykluczony ręcznie',
	];

	public const RECIPIENT_SOURCES = ['company_general' => 'Ogólny e-mail firmy', 'contact' => 'E-mail decydenta', 'manual' => 'Wpisany ręcznie'];

	public const ERROR_LABELS = [
		'unknown_result' => 'Nie wiadomo, czy wiadomość wyszła (przerwane połączenie po przekazaniu treści). Sprawdź folder Wysłane i potwierdź wynik.',
		'missing_variables' => 'Brakuje danych do personalizacji — uzupełnij wartości i wznów sekwencję.',
		'mailbox' => 'Skrzynka nadawcza wymaga uwagi (dane logowania / połączenie).',
		'failed' => 'Wysyłka nie powiodła się po kilku próbach.',
		'bounced' => 'Serwer odbiorcy odrzucił adres (trwały błąd) — adres zablokowany.',
	];

	public static function label(array $map, ?string $key): string
	{
		return $key !== null && isset($map[$key]) ? $map[$key] : (string) $key;
	}
}
