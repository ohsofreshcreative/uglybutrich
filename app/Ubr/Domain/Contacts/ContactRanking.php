<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Ranking Top 1 / Top 2 / Top 3 (ContactRanking) — powiązany z Contact Priority Score, ale nie identyczny.
 *
 * Kolejność kryteriów: potwierdzona aktualna funkcja → decyzyjność → związek ze stroną WWW → wielkość firmy →
 * Contact Priority Score → dostępność kontaktu. Preferencja: Top 1 = właściciel / prezes / osoba zarządzająca
 * (w dużej firmie potwierdzony dyrektor marketingu może wygrać), Top 2 = dyrektor marketingu, Top 3 = marketing.
 *
 * Zasady twarde:
 * - OUTDATED / REJECTED / wykluczone / odrzucone przez organizację — poza rankingiem,
 * - Top 1 tylko VERIFIED lub PROBABLE (albo zatwierdzony przez organizację), z dopasowaną firmą i aktualną funkcją,
 *   nigdy przy niepewnym dopasowaniu firmy ani w firmie nieaktywnej,
 * - brak kandydata = puste miejsce (nie uzupełniamy rankingu),
 * - deterministyczne remisy (wynik, pewność, liczba źródeł, klucz osoby).
 */
final class ContactRanking
{
	/**
	 * @param list<array<string, mixed>> $contacts ocenione kontakty (score, confidence, status…)
	 * @param array<int|string, array{status?: ?string, preferred?: bool}> $overrides prywatne decyzje organizacji wg id lub person_key
	 * @return array{top: list<array<string, mixed>>, others: list<array<string, mixed>>, hidden: list<array<string, mixed>>, top1_note: ?string}
	 */
	public function rank(array $contacts, ?string $sizeBand, bool $companyInactive = false, array $overrides = []): array
	{
		$eligible = [];
		$hidden = [];

		foreach ($contacts as $c) {
			$o = $overrides[$c['id'] ?? $c['person_key']] ?? ($overrides[$c['person_key']] ?? []);
			$c['review_status'] = $o['status'] ?? null;
			$c['preferred'] = (bool) ($o['preferred'] ?? false);

			if (in_array($c['review_status'], ['rejected', 'outdated', 'excluded'], true)
				|| in_array($c['verification_status'], [ContactVerifier::OUTDATED, ContactVerifier::REJECTED], true)
				|| ($c['score_status'] ?? null) === ContactPriorityCalculator::INSUFFICIENT) {
				$c['rank_position'] = null;
				$hidden[] = $c;
				continue;
			}

			$c['rank_value'] = $this->value($c, $sizeBand);
			$eligible[] = $c;
		}

		usort($eligible, [$this, 'compare']);

		$top = [];
		$note = null;
		$top1 = null;

		if (! $companyInactive) {
			foreach ($eligible as $i => $c) {
				if ($this->canBeTop1($c)) {
					$top1 = $i;
					break;
				}
			}
		}

		$remaining = $eligible;

		if ($top1 !== null) {
			$top[] = $eligible[$top1];
			unset($remaining[$top1]);
		} else {
			$note = $companyInactive
				? 'Firma nieaktywna / zawieszona w rejestrze — kontakty nie są promowane jako aktywni decydenci.'
				: ($eligible ? 'Brak potwierdzonego decydenta do pozycji Top 1 (pozostałe kontakty wymagają weryfikacji).' : null);
		}

		// Top 2 / Top 3: premia za inną grupę ról niż już wybrane (prezes + marketing zamiast dwóch członków zarządu).
		while (count($top) < 3 && $remaining) {
			$usedGroups = array_map(fn ($c) => RoleCatalog::group($c['normalized_role']), $top);
			$best = null;
			$bestValue = null;

			foreach ($remaining as $i => $c) {
				$v = $c['rank_value'] + (in_array(RoleCatalog::group($c['normalized_role']), $usedGroups, true) ? 0 : 8);

				if ($bestValue === null || $v > $bestValue || ($v === $bestValue && $this->compare($c, $remaining[$best]) < 0)) {
					$best = $i;
					$bestValue = $v;
				}
			}

			$top[] = $remaining[$best];
			unset($remaining[$best]);
		}

		if ($top1 === null && $top) {
			// Bez Top 1 — kontakty zajmują pozycje 2 i 3 (pozycja 1 pozostaje pusta).
			$top = array_slice($top, 0, 2);
		}

		$offset = $top1 === null ? 2 : 1;

		foreach ($top as $i => &$c) {
			$c['rank_position'] = $i + $offset;
			$c['rank_reason'] = $this->reason($c, $i + $offset, $sizeBand, $top, $eligible);
		}

		unset($c);

		$others = array_values(array_map(function ($c) {
			$c['rank_position'] = null;
			$c['rank_reason'] = null;

			return $c;
		}, $remaining));

		return ['top' => $top, 'others' => $others, 'hidden' => $hidden, 'top1_note' => $note];
	}

	/**
	 * @param array<string, mixed> $c
	 */
	public function canBeTop1(array $c): bool
	{
		if ($c['preferred'] ?? false) {
			return true;
		}

		$status = ($c['review_status'] ?? null) === 'approved' ? ContactVerifier::VERIFIED : $c['verification_status'];

		return in_array($status, [ContactVerifier::VERIFIED, ContactVerifier::PROBABLE], true)
			&& $c['company_match_status'] !== ContactVerifier::MATCH_UNCERTAIN
			&& ($c['employment_status'] ?? 'current') === 'current';
	}

	/**
	 * @param array<string, mixed> $c
	 */
	private function value(array $c, ?string $sizeBand): int
	{
		$group = RoleCatalog::group((string) $c['normalized_role']);
		$bonus = match ($group) {
			// Preferencja biznesowa: Top 1 = właściciel / prezes; w dużej firmie potwierdzony dyrektor marketingu może wygrać.
			'leadership' => match ($sizeBand) {
				'large' => 0,
				'medium' => 12,
				default => 15,
			},
			'marketing_lead' => match ($sizeBand) {
				'large' => 8,
				'medium' => 2,
				default => 0,
			},
			'marketing' => $sizeBand === 'small' ? 0 : 1,
			'other' => -4,
			default => 0,
		};

		$status = ($c['review_status'] ?? null) === 'approved' ? ContactVerifier::VERIFIED : $c['verification_status'];
		$bonus += match ($status) {
			ContactVerifier::VERIFIED => 6,
			ContactVerifier::PROBABLE => 2,
			default => -10,
		};

		if ($c['company_match_status'] === ContactVerifier::MATCH_UNCERTAIN) {
			$bonus -= 10;
		}

		if ($c['preferred'] ?? false) {
			$bonus += 1000;
		}

		return (int) $c['contact_priority_score'] + $bonus;
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private function compare(array $a, array $b): int
	{
		return [$b['rank_value'], $b['contact_priority_score'], $b['confidence']['score'] ?? 0, count($b['sources'] ?? []), $a['person_key']]
			<=> [$a['rank_value'], $a['contact_priority_score'], $a['confidence']['score'] ?? 0, count($a['sources'] ?? []), $b['person_key']];
	}

	/**
	 * Uzasadnienie pozycji — wyłącznie z danych kontaktu.
	 *
	 * @param array<string, mixed> $c
	 * @param list<array<string, mixed>> $top
	 * @param list<array<string, mixed>> $eligible
	 */
	private function reason(array $c, int $position, ?string $sizeBand, array $top, array $eligible): string
	{
		$parts = [];
		$types = array_unique(array_column($c['sources'], 'source_type'));
		$sourceLabel = implode(' + ', array_map(fn ($t) => ContactSources::label($t), $types));
		$title = $c['registry_role'] ? mb_convert_case(mb_strtolower((string) $c['registry_role']), MB_CASE_TITLE) : $c['job_title'];

		$parts[] = match ($c['verification_status']) {
			ContactVerifier::VERIFIED => sprintf('%s — potwierdzone (%s).', $title, $sourceLabel),
			ContactVerifier::PROBABLE => sprintf('%s według: %s.', $title, $sourceLabel),
			default => sprintf('%s według: %s — niepotwierdzone, wymaga weryfikacji.', $title, $sourceLabel),
		};

		if (($c['review_status'] ?? null) === 'approved') {
			$parts[] = 'Zatwierdzony przez Twoją organizację.';
		}

		if ($c['preferred'] ?? false) {
			$parts[] = 'Wskazany przez Twoją organizację jako preferowany kontakt.';
		}

		$a = (int) ($c['score_breakdown']['decision_authority']['points'] ?? 0);
		$group = RoleCatalog::group((string) $c['normalized_role']);
		$parts[] = match (true) {
			$a >= 30 => 'Wysoka możliwość zatwierdzenia wydatku.',
			$a >= 20 && $group === 'board' => 'Członek organu zarządzającego — współdecyduje o wydatkach (zakres odpowiedzialności za marketing niepotwierdzony' . (! empty($c['marketing_focus']) ? ', ale funkcja obejmuje handel / marketing' : '') . ').',
			$a >= 20 => 'Kieruje obszarem marketingu / digital — zwykle przygotowuje lub rekomenduje zakup strony.',
			default => 'Ograniczona decyzyjność zakupowa — raczej osoba operacyjna lub wpływająca na decyzję.',
		};

		if (($c['score_breakdown']['role_relevance']['points'] ?? 0) >= 24) {
			$parts[] = 'Stanowisko bezpośrednio związane z marketingiem / stroną WWW.';
		}

		if ($position === 1 && RoleCatalog::group((string) $c['normalized_role']) !== 'leadership' && ! ($c['preferred'] ?? false)) {
			$leaders = array_filter($eligible, fn ($e) => RoleCatalog::group((string) $e['normalized_role']) === 'leadership' && $e['person_key'] !== $c['person_key']);
			$parts[] = match (true) {
				$leaders !== [] && $sizeBand === 'large' => 'Duża firma (250+ os.) — potwierdzona osoba odpowiedzialna za marketing jest praktyczniejszym kontaktem niż prezes całej organizacji.',
				$leaders !== [] => 'Właściciel / prezes nie spełnia warunków Top 1 (niższa pewność danych lub wynik).',
				default => 'Nie znaleziono potwierdzonego właściciela ani prezesa — najwyżej oceniona osoba decyzyjna.',
			};
		}

		$parts[] = ! empty($c['email'])
			? 'Publiczny służbowy e-mail.'
			: (! empty($c['role_email']) ? 'Brak publicznego adresu e-mail osoby — dostępny adres działu.' : 'Brak publicznego bezpośredniego adresu e-mail.');

		return implode(' ', $parts);
	}
}
