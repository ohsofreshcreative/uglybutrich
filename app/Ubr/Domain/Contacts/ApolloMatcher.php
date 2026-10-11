<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Prompt 10.3 — dopasowanie kandydata z Apollo People Search do kontaktów firmy w Ugly but Rich.
 *
 * Poziomy:
 * - EXACT — ten sam identyfikator osoby Apollo albo pełne imię i nazwisko + ta sama firma, bez sprzecznych danych;
 * - STRONG — kilka niezależnych zgodności (firma, imię, nazwisko zgodne z maską Apollo, stanowisko z tego samego obszaru)
 *   albo pełne nazwisko przy innym obszarze stanowiska — wymaga zatwierdzenia przed przypisaniem identyfikatora;
 * - POSSIBLE — prawdopodobne, ale dowodów za mało (maska bez stanowiska, kilka osób pasujących do maski);
 * - NO_MATCH — brak wiarygodnego kandydata;
 * - CONFLICT — dane wskazują na inną osobę / firmę (inna firma w Apollo, inny identyfikator Apollo przy kontakcie,
 *   identyfikator przypisany w organizacji do innego kontaktu).
 *
 * Zamaskowane nazwisko (np. „Dr***z”) NIGDY nie daje EXACT — pomaga wskazać kandydata, nie potwierdza tożsamości.
 * Nie zgadujemy nazwisk i nie odtwarzamy maski — sprawdzamy tylko zgodność nazwiska ZNANEGO z oficjalnego źródła.
 * Czysta logika (bez SQL / HTTP).
 */
final class ApolloMatcher
{
	public const EXACT = 'EXACT';

	public const STRONG = 'STRONG';

	public const POSSIBLE = 'POSSIBLE';

	public const NO_MATCH = 'NO_MATCH';

	public const CONFLICT = 'CONFLICT';

	public const LEVELS = [self::EXACT, self::STRONG, self::POSSIBLE, self::NO_MATCH, self::CONFLICT];

	public const LABELS = [
		self::EXACT => 'Dopasowanie pewne',
		self::STRONG => 'Silne dopasowanie — do zatwierdzenia',
		self::POSSIBLE => 'Możliwe dopasowanie — niewystarczające dowody',
		self::NO_MATCH => 'Brak dopasowania',
		self::CONFLICT => 'Konflikt danych',
	];

	/**
	 * @param array<string, mixed> $cand apollo_person_id, first_name, last_name (pełne lub null), last_name_obfuscated, normalized_role, belongs_to_company
	 * @param list<array<string, mixed>> $contacts id, first_name, last_name, full_name, normalized_role, external_provider, external_id, approved_apollo_id
	 * @param array<string, int> $approvedByPerson identyfikator Apollo → kontakt zatwierdzony w organizacji
	 * @return list<array{contact_id: int, level: string, evidence: list<string>}>
	 */
	public static function match(array $cand, array $contacts, array $approvedByPerson = []): array
	{
		$pid = (string) ($cand['apollo_person_id'] ?? '');

		if (empty($cand['belongs_to_company'])) {
			return [['contact_id' => 0, 'level' => self::CONFLICT, 'evidence' => ['Apollo przypisuje tę osobę do innej firmy (inna domena / identyfikator organizacji).']]];
		}

		$first = PersonName::fold((string) ($cand['first_name'] ?? ''));
		$fullLast = trim((string) ($cand['last_name'] ?? ''));
		$masked = trim((string) ($cand['last_name_obfuscated'] ?? ''));
		$role = $cand['normalized_role'] ?? null;
		$out = [];

		foreach ($contacts as $c) {
			$cid = (int) $c['id'];
			$cApollo = ($c['external_provider'] ?? '') === 'apollo' ? (string) ($c['external_id'] ?? '') : '';
			$approved = (string) ($c['approved_apollo_id'] ?? '');
			$evidence = ['Ta sama firma (domena / identyfikator organizacji w Apollo).'];

			// Identyfikator osoby Apollo przy kontakcie (np. z Decision Maker Findera) — najsilniejszy dowód albo konflikt.
			if ($pid !== '' && ($cApollo === $pid || $approved === $pid)) {
				$out[] = ['contact_id' => $cid, 'level' => self::EXACT, 'evidence' => array_merge($evidence, ['Ten sam identyfikator osoby Apollo.'])];
				continue;
			}

			[$cFirst, $cLast] = self::names($c);

			if ($first === '' || $cFirst !== $first) {
				continue;
			}

			$evidence[] = 'Zgodne imię.';

			if ($fullLast !== '') {
				if (PersonName::fold($fullLast) !== $cLast) {
					continue;
				}

				$evidence[] = 'Zgodne pełne nazwisko.';
				$nameLevel = self::EXACT;
			} elseif ($masked !== '' && self::maskMatches($masked, $cLast)) {
				$evidence[] = 'Nazwisko zgodne z maską Apollo („' . $masked . '”) — maska nie potwierdza tożsamości.';
				$nameLevel = self::STRONG;
			} else {
				continue;
			}

			$roleFit = self::roleFit($role, $c['normalized_role'] ?? null);
			$evidence[] = match ($roleFit) {
				'same' => 'To samo stanowisko (rola).',
				'department' => 'Stanowisko z tego samego obszaru.',
				'different' => 'Stanowisko z innego obszaru — możliwa zmiana roli lub inna osoba.',
				default => 'Brak porównywalnego stanowiska.',
			};

			$level = match (true) {
				$nameLevel === self::EXACT && $roleFit !== 'different' => self::EXACT,
				$nameLevel === self::EXACT => self::STRONG,
				in_array($roleFit, ['same', 'department'], true) => self::STRONG,
				default => self::POSSIBLE,
			};

			// Sprzeczne identyfikatory: kontakt ma inny identyfikator Apollo albo ten identyfikator jest już przypisany innej osobie.
			if (($cApollo !== '' && $cApollo !== $pid) || ($approved !== '' && $approved !== $pid)) {
				$level = self::CONFLICT;
				$evidence[] = 'Kontakt ma już inny identyfikator Apollo.';
			} elseif (isset($approvedByPerson[$pid]) && (int) $approvedByPerson[$pid] !== $cid) {
				$level = self::CONFLICT;
				$evidence[] = 'Ten identyfikator Apollo jest w organizacji przypisany do innego kontaktu.';
			}

			$out[] = ['contact_id' => $cid, 'level' => $level, 'evidence' => $evidence];
		}

		// Kilka osób pasuje do tego samego kandydata — żadne dopasowanie nie jest silne.
		$strong = array_filter($out, fn ($m) => in_array($m['level'], [self::EXACT, self::STRONG], true));

		if (count($strong) > 1) {
			foreach ($out as &$m) {
				if (in_array($m['level'], [self::EXACT, self::STRONG], true) && ! in_array('Ten sam identyfikator osoby Apollo.', $m['evidence'], true)) {
					$m['level'] = self::POSSIBLE;
					$m['evidence'][] = 'Kilka kontaktów pasuje do tego kandydata — wymagana ręczna weryfikacja.';
				}
			}

			unset($m);
		}

		return $out !== [] ? $out : [['contact_id' => 0, 'level' => self::NO_MATCH, 'evidence' => ['Brak kontaktu z tym imieniem i nazwiskiem zgodnym z danymi Apollo.']]];
	}

	/**
	 * Maska Apollo: początek + gwiazdki + koniec („Dr***z”). Nazwisko musi zaczynać się i kończyć tymi literami
	 * i być dłuższe od widocznych liter. Długość ukrytej części nie jest znana — maska NIE identyfikuje osoby.
	 */
	public static function maskMatches(string $mask, string $foldedLast): bool
	{
		if (! str_contains($mask, '*') || $foldedLast === '') {
			return false;
		}

		$prefix = PersonName::fold((string) strstr($mask, '*', true));
		$suffix = PersonName::fold((string) substr($mask, strrpos($mask, '*') + 1));

		if ($prefix === '' && $suffix === '') {
			return false;
		}

		return str_starts_with($foldedLast, $prefix) && ($suffix === '' || str_ends_with($foldedLast, $suffix)) && strlen($foldedLast) > strlen($prefix) + strlen($suffix);
	}

	private static function roleFit(?string $a, ?string $b): string
	{
		if (! $a || ! $b || $b === 'other') {
			return 'unknown';
		}

		if ($a === $b) {
			return 'same';
		}

		$da = RoleCatalog::ROLES[$a]['department'] ?? null;
		$db = RoleCatalog::ROLES[$b]['department'] ?? null;

		return $da !== null && $da === $db ? 'department' : 'different';
	}

	/**
	 * @param array<string, mixed> $c
	 * @return array{0: string, 1: string}
	 */
	private static function names(array $c): array
	{
		$first = PersonName::fold((string) ($c['first_name'] ?? ''));
		$last = PersonName::fold((string) ($c['last_name'] ?? ''));

		if (($first === '' || $last === '') && ($p = PersonName::parse((string) ($c['full_name'] ?? '')))) {
			[$first, $last] = [PersonName::fold($p->first), PersonName::fold($p->last)];
		}

		return [$first, $last];
	}
}
