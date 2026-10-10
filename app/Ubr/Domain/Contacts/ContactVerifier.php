<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Weryfikacja kontaktu (ContactVerification): dopasowanie do firmy, potwierdzenie w KRS, aktualność, status.
 *
 * Statusy: VERIFIED / PROBABLE / UNCERTAIN / OUTDATED / REJECTED (każdy z uzasadnieniem).
 * - VERIFIED: funkcja w zarządzie / prokura potwierdzona aktualnym KRS spółki (zgodne inicjały i długość imienia
 *   i nazwiska z zanonimizowanym składem) + osoba opublikowana na oficjalnej stronie firmy.
 * - PROBABLE: oficjalna strona firmy (aktualnie opublikowana), firma dopasowana; brak twardego potwierdzenia aktualności.
 * - UNCERTAIN: niepewne dopasowanie firmy, konflikt stanowisk, tylko Apollo, niejednoznaczne dane w KRS.
 * - OUTDATED: wzmianka o dawnej funkcji albo funkcja zarządu, której nie ma w aktualnym składzie w KRS.
 * - REJECTED: wykryta niezgodność (np. osoba z innej firmy) — automatycznie tylko przy domenie niezgodnej.
 *
 * Członek zarządu nie staje się „prezesem” ani „CEO” — funkcja z KRS jest zachowywana dosłownie (registry_role).
 */
final class ContactVerifier
{
	public const VERIFIED = 'verified';

	public const PROBABLE = 'probable';

	public const UNCERTAIN = 'uncertain';

	public const OUTDATED = 'outdated';

	public const REJECTED = 'rejected';

	public const LABELS = [
		self::VERIFIED => 'Zweryfikowany',
		self::PROBABLE => 'Prawdopodobny',
		self::UNCERTAIN => 'Niepewny',
		self::OUTDATED => 'Nieaktualny',
		self::REJECTED => 'Odrzucony',
	];

	public const MATCH_CONFIRMED = 'confirmed';

	public const MATCH_PROBABLE = 'probable';

	public const MATCH_UNCERTAIN = 'company_match_uncertain';

	public const MATCH_MISMATCH = 'mismatch';

	/**
	 * @param array<string, mixed> $contact scalony kontakt (ContactMerger)
	 * @param array<string, mixed> $company rekord firmy
	 * @param array<string, mixed>|null $board skład zarządu z KRS (KrsClient::board) lub null, gdy niedostępny
	 * @return array<string, mixed> kontakt uzupełniony o: krs_match, registry_role, company_match_status, employment_status, verification_status, verification_reason, needs_review
	 */
	public function verify(array $contact, array $company, ?array $board): array
	{
		$reasons = [];
		$types = array_column($contact['sources'], 'source_type');
		$fromSite = in_array('COMPANY_WEBSITE', $types, true);
		$fromUser = in_array('USER_PROVIDED', $types, true);
		$apolloOnly = $types !== [] && array_unique($types) === ['APOLLO'];
		$role = (string) $contact['normalized_role'];
		$boardRole = RoleCatalog::isBoard($role);

		// 1) Potwierdzenie w KRS (maski imienia i nazwiska + funkcja).
		$contact['krs_match'] = null;
		$contact['registry_role'] = null;
		$contact['krs_suspended'] = false;

		if ($board && ($board['members'] || $board['proxies']) && ! $contact['obfuscated']) {
			$name = new PersonName((string) $contact['first_name'], (string) $contact['last_name']);
			$pool = array_merge($board['members'], $board['proxies']);
			$matches = array_values(array_filter($pool, fn ($p) => $name->matchesMask((string) $p['first_mask'], (string) $p['last_mask'])));
			$sameInitials = array_values(array_filter($pool, fn ($p) => PersonName::maskMatch(mb_substr($name->first, 0, 1), mb_substr((string) $p['first_mask'], 0, 1)) && PersonName::maskMatch(mb_substr($name->last, 0, 1), mb_substr((string) $p['last_mask'], 0, 1))));

			if (count($matches) === 1) {
				$contact['krs_match'] = 'matched';
				$contact['registry_role'] = $matches[0]['function'];
				$contact['krs_suspended'] = ! empty($matches[0]['suspended']);
				$krsRole = RoleCatalog::fromRegistry((string) $matches[0]['function']);

				// Stanowisko ze strony vs funkcja w KRS: różne funkcje zarządu = konflikt (zachowujemy oficjalną funkcję z KRS).
				if (RoleCatalog::isBoard($role) && ContactMerger::conflicting($role, $krsRole)) {
					$contact['conflict'] = true;
					$reasons[] = sprintf('Strona firmy podaje „%s”, a KRS „%s” — przyjęto funkcję z KRS, wymaga ponownej weryfikacji.', $contact['job_title'], mb_convert_case(mb_strtolower((string) $matches[0]['function']), MB_CASE_TITLE));
					$role = $krsRole;
				} elseif (! RoleCatalog::isBoard($role)) {
					// Np. dyrektor marketingu będący jednocześnie członkiem zarządu — decyzyjność wg wyższej funkcji.
					$role = self::authorityRank($krsRole) > self::authorityRank($role) ? $krsRole : $role;
					$contact['marketing_focus'] = $contact['marketing_focus'] || in_array($contact['normalized_role'], ['marketing_director', 'digital_head', 'marketing_manager'], true);
				}

				$contact['normalized_role'] = $role;
			} elseif (count($matches) > 1) {
				$contact['krs_match'] = 'ambiguous';
			} elseif ($sameInitials) {
				$contact['krs_match'] = 'length_mismatch';
			} elseif ($boardRole) {
				$contact['krs_match'] = 'not_in_board';
			}
		}

		// 2) Dopasowanie do firmy.
		$domainStatus = (string) ($company['domain_status'] ?? 'unchecked');
		$companyStatus = (string) ($company['verification_status'] ?? 'pending');

		if ($contact['other_entity']) {
			$match = self::MATCH_UNCERTAIN;
			$reasons[] = sprintf('Funkcja opisana w innym podmiocie („%s”) — możliwa spółka matka, córka lub inna spółka grupy.', mb_substr((string) $contact['other_entity'], 0, 80));
		} elseif ($contact['krs_match'] === 'matched') {
			$match = self::MATCH_CONFIRMED;
		} elseif ($domainStatus === 'mismatch') {
			$match = self::MATCH_MISMATCH;
		} elseif ($fromSite && $domainStatus === 'confirmed') {
			$match = $companyStatus === 'uncertain' ? self::MATCH_PROBABLE : self::MATCH_CONFIRMED;
		} elseif ($fromSite || $fromUser) {
			$match = self::MATCH_PROBABLE;
		} else {
			$match = $domainStatus === 'confirmed' ? self::MATCH_PROBABLE : self::MATCH_UNCERTAIN;
		}

		if ($companyStatus === 'uncertain' && $match !== self::MATCH_CONFIRMED) {
			$reasons[] = 'Firma niepotwierdzona w rejestrze — ograniczona pewność powiązania osoby z firmą.';
		}

		// 3) Aktualność.
		$employment = 'current';

		if ($contact['former']) {
			$employment = 'former';
			$reasons[] = 'Źródło opisuje dawną funkcję (np. „były”, „do 20xx”).';
		} elseif ($contact['krs_match'] === 'not_in_board') {
			$employment = 'former';
			$reasons[] = sprintf('Strona podaje funkcję „%s”, ale osoby nie ma w aktualnym składzie zarządu ani wśród prokurentów w KRS (stan z %s).', $contact['job_title'], $board['state_date'] ?? '—');
		} elseif ($apolloOnly) {
			$employment = 'unknown';
		}

		// 4) Status.
		if ($match === self::MATCH_MISMATCH) {
			$status = self::REJECTED;
			$reasons[] = 'Domena niezgodna z firmą — osoba nie jest przypisana do tej firmy.';
		} elseif ($employment === 'former') {
			$status = self::OUTDATED;
		} elseif ($contact['krs_match'] === 'matched' && $contact['krs_suspended']) {
			$status = self::UNCERTAIN;
			$reasons[] = 'W KRS funkcja oznaczona jako zawieszona.';
		} elseif ($match === self::MATCH_UNCERTAIN || $apolloOnly || $contact['obfuscated']) {
			$status = self::UNCERTAIN;

			if ($apolloOnly) {
				$reasons[] = 'Tylko Apollo — brak potwierdzenia w oficjalnym źródle.';
			}
		} elseif (in_array($contact['krs_match'], ['ambiguous', 'length_mismatch'], true)) {
			$status = self::UNCERTAIN;
			$reasons[] = $contact['krs_match'] === 'ambiguous'
				? 'W KRS kilka osób o tych samych inicjałach i długości imienia i nazwiska — nie można jednoznacznie potwierdzić.'
				: 'W KRS jest osoba o tych samych inicjałach, ale innej długości imienia lub nazwiska (np. nazwisko dwuczłonowe) — wymaga sprawdzenia.';
		} elseif ($contact['conflict']) {
			$status = $contact['krs_match'] === 'matched' ? self::PROBABLE : self::UNCERTAIN;

			if ($contact['krs_match'] !== 'matched') {
				$reasons[] = 'Źródła podają różne stanowiska (' . implode(', ', array_map([RoleCatalog::class, 'label'], $contact['observed_roles'])) . ') — wymaga ponownej weryfikacji.';
			}
		} elseif ($contact['krs_match'] === 'matched' && $match === self::MATCH_CONFIRMED) {
			$status = self::VERIFIED;
			array_unshift($reasons, sprintf('Funkcja „%s” potwierdzona w aktualnym KRS (stan z %s; dane osobowe w API zanonimizowane — zgodne inicjały i długość imienia i nazwiska).', mb_convert_case(mb_strtolower((string) $contact['registry_role']), MB_CASE_TITLE), $board['state_date'] ?? '—'));
		} elseif ($fromSite || $fromUser) {
			$status = self::PROBABLE;
			array_unshift($reasons, $fromSite
				? 'Osoba i stanowisko opublikowane na oficjalnej stronie firmy' . ($boardRole && ! $board ? ' (brak danych KRS do potwierdzenia funkcji).' : ' — brak niezależnego potwierdzenia aktualności.')
				: 'Kontakt dodany ręcznie (USER_PROVIDED) — nie jest automatycznie zweryfikowany.');
		} else {
			$status = self::UNCERTAIN;
		}

		if ($fromUser && $status === self::VERIFIED) {
			$status = self::PROBABLE;
		}

		$contact['company_match_status'] = $match;
		$contact['employment_status'] = $employment;
		$contact['verification_status'] = $status;
		$contact['verification_reason'] = implode(' ', array_unique($reasons)) ?: self::LABELS[$status] . '.';
		$contact['needs_review'] = $contact['conflict'] || in_array($contact['krs_match'], ['ambiguous', 'length_mismatch'], true) || $match === self::MATCH_UNCERTAIN;

		return $contact;
	}

	public static function authorityRank(string $role): int
	{
		return ['owner' => 10, 'founder' => 9, 'ceo' => 10, 'managing_director' => 9, 'vice_president' => 8, 'board_member' => 7, 'marketing_director' => 6, 'digital_head' => 5, 'proxy' => 4, 'sales_director' => 4, 'marketing_manager' => 3, 'other_director' => 3, 'marketing_specialist' => 1][$role] ?? 0;
	}
}
