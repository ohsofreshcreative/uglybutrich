<?php

namespace App\Ubr\Domain\Sequences;

/**
 * Prompt 10.5 — personalizacja wiadomości: zmienne {{nazwa}} w temacie i treści.
 *
 * - Wiadomości są wysyłane jako TEKST (text/plain) — podstawiane wartości nie mogą wstrzyknąć HTML; dodatkowo wartości
 *   są oczyszczane ze znaczników i znaków sterujących, a w temacie także z nowych linii (ochrona nagłówków e-mail).
 * - Brak wartości → zmienna na liście braków; wiadomość z brakami NIE jest wysyłana (nigdy surowy placeholder).
 *   Nieznana zmienna (literówka) też jest brakiem.
 * - Wartości ręczne dla konkretnej sekwencji mają pierwszeństwo przed danymi z Ugly but Rich.
 */
final class TemplateRenderer
{
	public const VARIABLES = [
		'company_name' => 'Nazwa firmy',
		'company_website' => 'Strona WWW firmy',
		'company_domain' => 'Domena firmy',
		'company_city' => 'Miasto firmy',
		'contact_first_name' => 'Imię odbiorcy',
		'contact_last_name' => 'Nazwisko odbiorcy',
		'contact_full_name' => 'Imię i nazwisko odbiorcy',
		'contact_position' => 'Stanowisko odbiorcy',
		'sender_first_name' => 'Imię nadawcy',
		'sender_last_name' => 'Nazwisko nadawcy',
		'sender_name' => 'Nazwa nadawcy',
		'sender_email' => 'E-mail nadawcy',
		'unsubscribe_link' => 'Link rezygnacji z kolejnych wiadomości',
	];

	/** Zmienne wypełniane automatycznie przy wysyłce (nie wymagają danych firmy). */
	public const SYSTEM = ['unsubscribe_link'];

	private const PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

	/**
	 * @return list<string> nazwy zmiennych użytych w tekście
	 */
	public static function placeholders(string $text): array
	{
		preg_match_all(self::PATTERN, $text, $m);

		return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
	}

	/**
	 * @param array<string, string|null> $values
	 * @param list<string> $missing uzupełniane brakującymi zmiennymi
	 */
	public static function render(string $text, array $values, array &$missing = [], bool $subject = false): string
	{
		return (string) preg_replace_callback(self::PATTERN, function ($m) use ($values, &$missing, $subject) {
			$key = strtolower($m[1]);
			$value = isset(self::VARIABLES[$key]) ? self::clean((string) ($values[$key] ?? ''), $subject || $key !== 'unsubscribe_link') : '';

			if ($value === '') {
				$missing[] = $key;

				return $m[0];
			}

			return $value;
		}, $subject ? self::oneLine($text) : $text);
	}

	/**
	 * Braki dla zestawu tekstów (bez renderowania).
	 *
	 * @param list<string> $texts
	 * @param array<string, string|null> $values
	 * @return list<string>
	 */
	public static function missing(array $texts, array $values): array
	{
		$missing = [];

		foreach ($texts as $text) {
			foreach (self::placeholders($text) as $key) {
				if (in_array($key, self::SYSTEM, true)) {
					continue;
				}

				if (! isset(self::VARIABLES[$key]) || self::clean((string) ($values[$key] ?? ''), true) === '') {
					$missing[] = $key;
				}
			}
		}

		return array_values(array_unique($missing));
	}

	/** Wartość bezpieczna dla treści tekstowej: bez znaczników, znaków sterujących, maks. 300 znaków. */
	public static function clean(string $value, bool $singleLine = true): string
	{
		$value = wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

		if ($singleLine) {
			$value = self::oneLine($value);
		}

		return mb_substr(trim($value), 0, 300);
	}

	public static function oneLine(string $value): string
	{
		return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
	}
}
