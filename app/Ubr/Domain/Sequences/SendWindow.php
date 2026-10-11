<?php

namespace App\Ubr\Domain\Sequences;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Prompt 10.5 — okno wysyłki sekwencji: dozwolone dni tygodnia, godziny i strefa czasowa.
 *
 * Deterministyczne: ten sam termin wejściowy i te same ustawienia dają zawsze ten sam wynik. Termin poza oknem
 * jest przesuwany na NAJBLIŻSZY dozwolony moment (początek okna tego samego dnia albo kolejnego dozwolonego dnia),
 * z uwzględnieniem strefy czasowej (także zmian czasu letni / zimowy). Wszystkie terminy w bazie — UTC.
 */
final class SendWindow
{
	public const DEFAULT = ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '16:00', 'timezone' => 'Europe/Warsaw'];

	public const DAY_LABELS = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'So', 7 => 'Nd'];

	/**
	 * Ustawienia po walidacji (nieznane wartości → domyślne).
	 *
	 * @param array<string, mixed> $s
	 * @return array{days: list<int>, from: string, to: string, timezone: string}
	 */
	public static function normalize(array $s): array
	{
		$days = array_values(array_unique(array_filter(array_map('intval', (array) ($s['days'] ?? self::DEFAULT['days'])), fn ($d) => $d >= 1 && $d <= 7)));
		sort($days);
		$time = fn ($v, $def) => is_string($v) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) ? $v : $def;
		$from = $time($s['from'] ?? null, self::DEFAULT['from']);
		$to = $time($s['to'] ?? null, self::DEFAULT['to']);

		if ($to <= $from) {
			[$from, $to] = [self::DEFAULT['from'], self::DEFAULT['to']];
		}

		$tz = is_string($s['timezone'] ?? null) && in_array($s['timezone'], timezone_identifiers_list(), true) ? $s['timezone'] : self::DEFAULT['timezone'];

		return ['days' => $days ?: self::DEFAULT['days'], 'from' => $from, 'to' => $to, 'timezone' => $tz];
	}

	/** Czy moment (UTC) mieści się w oknie wysyłki. */
	public static function allows(DateTimeImmutable $utc, array $settings): bool
	{
		$s = self::normalize($settings);
		$local = $utc->setTimezone(new DateTimeZone($s['timezone']));
		$hm = $local->format('H:i');

		return in_array((int) $local->format('N'), $s['days'], true) && $hm >= $s['from'] && $hm < $s['to'];
	}

	/**
	 * Najbliższy dozwolony moment ≥ $utc (UTC).
	 */
	public static function next(DateTimeImmutable $utc, array $settings): DateTimeImmutable
	{
		$s = self::normalize($settings);
		$zone = new DateTimeZone($s['timezone']);
		$utcZone = new DateTimeZone('UTC');
		$local = $utc->setTimezone($zone);

		for ($i = 0; $i < 15; $i++) {
			$day = $local->setTime(0, 0)->modify('+' . $i . ' day');

			if (! in_array((int) $day->format('N'), $s['days'], true)) {
				continue;
			}

			[$fh, $fm] = array_map('intval', explode(':', $s['from']));
			[$th, $tm] = array_map('intval', explode(':', $s['to']));
			$start = $day->setTime($fh, $fm);
			$end = $day->setTime($th, $tm);

			if ($i === 0) {
				if ($local < $start) {
					return $start->setTimezone($utcZone);
				}

				if ($local < $end) {
					return $local->setTimezone($utcZone);
				}

				continue;
			}

			return $start->setTimezone($utcZone);
		}

		return $utc;
	}

	/**
	 * Termin kolejnego kroku: poprzednia wysyłka (lub start) + odstęp w dniach, przesunięty do okna.
	 */
	public static function due(DateTimeImmutable $afterUtc, int $delayDays, array $settings): DateTimeImmutable
	{
		$base = $delayDays > 0 ? $afterUtc->modify('+' . $delayDays . ' day') : $afterUtc;

		return self::next($base, $settings);
	}

	public static function utc(string $mysql): DateTimeImmutable
	{
		return new DateTimeImmutable($mysql, new DateTimeZone('UTC'));
	}

	public static function mysql(DateTimeImmutable $d): string
	{
		return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/** Czytelny opis okna, np. „Pn–Pt 09:00–16:00 (Europe/Warsaw)”. */
	public static function describe(array $settings): string
	{
		$s = self::normalize($settings);
		$days = $s['days'] === [1, 2, 3, 4, 5] ? 'Pn–Pt' : implode(', ', array_map(fn ($d) => self::DAY_LABELS[$d], $s['days']));

		return sprintf('%s %s–%s (%s)', $days, $s['from'], $s['to'], $s['timezone']);
	}
}
