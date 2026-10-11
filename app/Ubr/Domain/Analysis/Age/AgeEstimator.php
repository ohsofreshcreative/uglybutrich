<?php

namespace App\Ubr\Domain\Analysis\Age;

/**
 * Prompt 10.4.3 — oszacowanie wieku OBECNEGO projektu strony (Website Age Intelligence). Funkcja czysta (bez zapytań).
 *
 * Zasady:
 * - podstawą daty jest wyłącznie archiwum (Wayback Machine): granica między ostatnią archiwalną wersją innego projektu
 *   a pierwszą wersją obecnego daje PRZEDZIAŁ wdrożenia (np. 2018–2020); rok podajemy tylko, gdy przedział mieści się
 *   w jednym roku — nie wybieramy arbitralnie roku ze środka,
 * - brak wcześniejszego projektu w archiwum → „co najmniej od <rok najstarszej kopii>” (archiwum nie zawiera wszystkiego),
 * - poszlaki techniczne, metadane i wiek domeny są tylko dowodami pomocniczymi i mogą obniżyć pewność (sprzeczności),
 *   nigdy nie wyznaczają roku wdrożenia,
 * - wiek strony i jakość projektu są rozdzielone (nowoczesność techniczna osobno).
 */
final class AgeEstimator
{
	public const STATUSES = ['NEW', 'CURRENT', 'AGING', 'OUTDATED', 'UNKNOWN'];

	public const CONFIDENCE = ['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];

	public const STATUS_LABELS = ['NEW' => 'Nowy projekt', 'CURRENT' => 'Względnie aktualny', 'AGING' => 'Warto sprawdzić', 'OUTDATED' => 'Potencjalnie przestarzały', 'UNKNOWN' => 'Wiek nieznany'];

	public const CONFIDENCE_LABELS = ['HIGH' => 'Wysoka', 'MEDIUM' => 'Średnia', 'LOW' => 'Niska', 'UNKNOWN' => 'Brak'];

	public const MODERNITY_LABELS = ['modern' => 'Nowoczesna technicznie', 'mixed' => 'Mieszana', 'dated' => 'Przestarzała technicznie', 'unknown' => 'Nie oceniono'];

	/**
	 * @param list<array{ts: string, verdict: string, similarity?: ?float, reason?: string}> $samples porównania archiwalnych wersji z obecną
	 * @param list<array{newer: string, older: string, verdict: string}> $pairs porównania kolejnych wersji archiwalnych (wcześniejsze redesigny)
	 * @param array{signals?: list<array<string, mixed>>, modernity?: string, copyright_year?: ?int, dates?: list<array<string, string>>} $tech
	 * @param string $archive ok | empty | unavailable | site_unreachable
	 * @return array<string, mixed>
	 */
	public static function estimate(array $samples, array $pairs, ?string $firstCaptureTs, int $captureCount, array $tech, string $archive, int $nowYear, ?string $nowTs = null): array
	{
		$nowTs ??= gmdate('YmdHis');
		$evidence = [];
		$modernity = (string) ($tech['modernity'] ?? 'unknown');
		$firstSeen = $firstCaptureTs ? self::year($firstCaptureTs) : null;
		$usable = array_values(array_filter($samples, fn ($s) => in_array($s['verdict'], ['same', 'minor', 'major'], true)));
		usort($usable, fn ($a, $b) => strcmp($b['ts'], $a['ts']));

		foreach ((array) ($tech['signals'] ?? []) as $signal) {
			if ($signal['key'] !== 'viewport') {
				$evidence[] = ['source' => 'html', 'text' => (string) $signal['label']];
			}
		}

		foreach ((array) ($tech['dates'] ?? []) as $date) {
			$evidence[] = ['source' => 'meta', 'text' => $date['source'] . ': ' . $date['value'] . ' (data treści — nie data wdrożenia projektu)'];
		}

		if ($firstSeen) {
			$evidence[] = ['source' => 'domain', 'text' => sprintf('Najstarsza kopia strony w archiwum: %d (%d kopii miesięcznych) — to nie jest data rejestracji domeny ani wdrożenia projektu.', $firstSeen, $captureCount)];
		}

		$base = [
			'status' => 'UNKNOWN', 'confidence' => 'UNKNOWN', 'launch_year' => null, 'launch_from' => null, 'launch_to' => null,
			'age_years' => null, 'age_max' => null, 'last_redesign_year' => null, 'modernity' => $modernity,
			'first_seen_year' => $firstSeen, 'earlier_redesigns' => 0, 'archive' => $archive,
			'samples' => array_map(fn ($s) => ['date' => self::date($s['ts']), 'verdict' => $s['verdict'], 'similarity' => $s['similarity'] ?? null, 'reason' => $s['reason'] ?? ''], $samples),
		];

		if ($archive !== 'ok' || $usable === []) {
			$evidence[] = ['source' => 'wayback', 'text' => match ($archive) {
				'empty' => 'Brak kopii strony w Wayback Machine — wieku projektu nie da się ustalić.',
				'unavailable' => 'Archiwum (Wayback Machine) nie odpowiadało — analiza zakończona bez daty.',
				'site_unreachable' => 'Strona firmy nie odpowiadała — brak obecnej wersji do porównania.',
				default => 'Archiwalnych wersji nie udało się wiarygodnie porównać z obecną stroną.',
			}];

			return $base + ['evidence' => $evidence, 'summary' => 'Wiek obecnego projektu nieznany.'];
		}

		$k = null;

		foreach ($usable as $i => $s) {
			if ($s['verdict'] === 'major') {
				$k = $i;
				break;
			}
		}

		if ($k === null) {
			$oldest = $usable[count($usable) - 1];
			$to = self::year($oldest['ts']);
			$ageMin = max(0, $nowYear - $to);
			$sameCount = count($usable);
			$confidence = ($ageMin >= 3 && $sameCount >= 2) ? 'MEDIUM' : 'LOW';
			$evidence[] = ['source' => 'wayback', 'text' => sprintf('Obecny projekt widoczny już w kopii z %s; we wszystkich sprawdzonych kopiach (%d) ten sam projekt — wcześniejszego projektu nie znaleziono w archiwum.', self::date($oldest['ts']), $sameCount)];
			$result = ['launch_to' => $to, 'age_years' => $ageMin, 'confidence' => $confidence, 'summary' => sprintf('Obecny projekt co najmniej od %d.', $to)];
		} else {
			$old = $usable[$k];
			$newer = $k > 0 ? $usable[$k - 1] : null;
			$from = self::year($old['ts']);
			$to = $newer ? self::year($newer['ts']) : $nowYear;
			$gap = self::months($old['ts'], $newer ? $newer['ts'] : $nowTs);
			$decisive = (($newer['verdict'] ?? 'same') === 'same') && (($old['similarity'] ?? 0) <= 0.35 || str_contains((string) ($old['reason'] ?? ''), 'Zmiana'));
			// HIGH tylko, gdy rok wdrożenia jest jednoznaczny (granica w jednym roku kalendarzowym).
			$confidence = match (true) {
				$gap <= 13 && $decisive && $from === $to => 'HIGH',
				$gap <= 36 => 'MEDIUM',
				default => 'LOW',
			};
			$evidence[] = ['source' => 'wayback', 'text' => sprintf('Inny projekt w kopii z %s (%s), obecny projekt %s — wdrożenie oszacowane na %s.', self::date($old['ts']), rtrim((string) ($old['reason'] ?? ''), '.'), $newer ? 'od kopii z ' . self::date($newer['ts']) : 'dopiero na obecnej stronie', $from === $to ? (string) $from : $from . '–' . $to)];
			$earlier = 0;

			foreach ($pairs as $pair) {
				if ($pair['verdict'] === 'major' && strcmp($pair['newer'], $old['ts']) <= 0) {
					$earlier++;
				}
			}

			if ($earlier > 0) {
				$evidence[] = ['source' => 'wayback', 'text' => sprintf('Wcześniejsze przebudowy w archiwum: %d.', $earlier)];
			}

			$result = [
				'launch_from' => $from,
				'launch_to' => $to,
				'launch_year' => $from === $to ? $to : null,
				'age_years' => max(0, $nowYear - $to),
				'age_max' => max(0, $nowYear - $from),
				'last_redesign_year' => $to,
				'earlier_redesigns' => $earlier,
				'confidence' => $confidence,
				'summary' => sprintf('Szacowane wdrożenie obecnego projektu: %s.', $from === $to ? (string) $to : $from . '–' . $to),
			];
		}

		// Sprzeczne dane: nowoczesne technologie młodsze niż projekt (aktualizacja na starym projekcie albo nietrafione
		// porównanie) lub przestarzałe technologie przy „nowym” projekcie → pewność niższa o jeden poziom.
		$modernYears = array_filter(array_map(fn ($s) => $s['kind'] === 'modern' ? $s['year'] : null, (array) ($tech['signals'] ?? [])));
		$datedCount = count(array_filter((array) ($tech['signals'] ?? []), fn ($s) => $s['kind'] === 'dated'));
		$contradiction = ($modernYears && max($modernYears) > (int) $result['launch_to'] + 1 && ($result['launch_from'] ?? null) === null)
			|| ($datedCount >= 2 && (int) ($result['age_max'] ?? $result['age_years']) <= 3);

		if ($contradiction) {
			$result['confidence'] = ['HIGH' => 'MEDIUM', 'MEDIUM' => 'LOW', 'LOW' => 'LOW'][$result['confidence']];
			$evidence[] = ['source' => 'html', 'text' => 'Sprzeczne sygnały: technologie strony nie pasują do wieku projektu z archiwum — wynik wymaga weryfikacji.'];
		}

		$age = (int) $result['age_years'];
		$result['status'] = match (true) {
			$age <= 2 => 'NEW',
			$age <= 4 => 'CURRENT',
			$age <= 6 => 'AGING',
			default => 'OUTDATED',
		};

		return array_merge($base, $result, ['evidence' => $evidence]);
	}

	/** Krótka etykieta na listę Discover: „~2018”, „~2018–20”, „≥8 lat”, „nowa”, „wiek nieznany”. */
	public static function short(?string $status, ?int $launchYear, ?int $from, ?int $to, ?int $ageYears): string
	{
		if ($status === null || $status === 'UNKNOWN') {
			return 'wiek nieznany';
		}

		if ($status === 'NEW') {
			return 'nowa';
		}

		if ($launchYear) {
			return '~' . $launchYear;
		}

		if ($from && $to) {
			return '~' . $from . '–' . substr((string) $to, -2);
		}

		return $ageYears !== null ? '≥' . $ageYears . ' ' . self::years($ageYears) : 'wiek nieznany';
	}

	public static function years(int $n): string
	{
		return $n === 1 ? 'rok' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 'lata' : 'lat');
	}

	public static function year(string $ts): int
	{
		return (int) substr($ts, 0, 4);
	}

	public static function date(string $ts): string
	{
		return substr($ts, 0, 4) . '-' . substr($ts, 4, 2);
	}

	public static function months(string $a, string $b): int
	{
		return abs(((int) substr($b, 0, 4) * 12 + (int) substr($b, 4, 2)) - ((int) substr($a, 0, 4) * 12 + (int) substr($a, 4, 2)));
	}
}
