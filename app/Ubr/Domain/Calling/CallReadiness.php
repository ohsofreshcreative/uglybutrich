<?php

namespace App\Ubr\Domain\Calling;

/**
 * Cold Call Readiness — gotowość firmy do kontaktu telefonicznego. ODRĘBNA od Lead Potential, Opportunity Score
 * i Contact Priority Score (nie zmienia żadnego z nich; znaleziony telefon nie podnosi Lead Potential).
 *
 * Reguła (pierwsza pasująca): BLOCKED (hard blocker / zakaz kontaktu / „nie pasuje”) → READY_DIRECT (odpowiedni decydent
 * z POTWIERDZONYM bezpośrednim numerem — powiązanie VERIFIED albo Apollo, Prompt 10.2) → CALL_COMPANY (ogólny numer firmy)
 * → RESEARCH (brak numeru, a dane firmy niepotwierdzone albo numer decydenta tylko „prawdopodobny”) → FIND_PHONE (brak
 * użytecznego numeru, firma potwierdzona). Brak decydenta nie wyklucza cold calla, jeśli jest numer firmy.
 * Ta sama reguła jest w SQL (CallFilters::readiness) — test porównuje obie.
 */
final class CallReadiness
{
	public const READY_DIRECT = 'READY_DIRECT';

	public const CALL_COMPANY = 'CALL_COMPANY';

	public const FIND_PHONE = 'FIND_PHONE';

	public const RESEARCH = 'RESEARCH';

	public const BLOCKED = 'BLOCKED';

	public const STATES = [self::READY_DIRECT, self::CALL_COMPANY, self::FIND_PHONE, self::RESEARCH, self::BLOCKED];

	public const LABELS = [
		self::READY_DIRECT => ['label' => 'Ready Direct', 'label_pl' => 'Telefon do decydenta', 'tone' => 'money', 'hint' => 'Mamy odpowiedniego decydenta i jego potwierdzony bezpośredni numer służbowy.'],
		self::CALL_COMPANY => ['label' => 'Call Company', 'label_pl' => 'Zadzwoń do firmy', 'tone' => 'confirmed', 'hint' => 'Mamy numer firmy, bez potwierdzonego numeru decydenta.'],
		self::FIND_PHONE => ['label' => 'Find Phone', 'label_pl' => 'Znajdź telefon', 'tone' => 'uncertain', 'hint' => 'Firma potwierdzona, ale nie mamy żadnego numeru.'],
		self::RESEARCH => ['label' => 'Research', 'label_pl' => 'Do sprawdzenia', 'tone' => 'uncertain', 'hint' => 'Brak numeru firmy, a dane wymagają sprawdzenia (weryfikacja / domena albo numer decydenta tylko prawdopodobny).'],
		self::BLOCKED => ['label' => 'Blocked', 'label_pl' => 'Nie kontaktować', 'tone' => 'danger', 'hint' => 'Aktywna blokada (np. firma nieaktywna, domena innego podmiotu) lub zakaz kontaktu w organizacji.'],
	];

	/** Role uznawane za decydentów w sprawie strony WWW (Dyrektor produkcji, serwis, IT, magazyn — nie). */
	public const DECISION_ROLES = ['owner', 'founder', 'ceo', 'managing_director', 'vice_president', 'board_member', 'marketing_director', 'digital_head', 'marketing_manager'];

	/**
	 * @param array{blocked?: bool, direct_phone?: bool, company_phone?: bool, likely_phone?: bool, verification_status?: ?string, domain_status?: ?string} $s
	 */
	public static function classify(array $s): string
	{
		if (! empty($s['blocked'])) {
			return self::BLOCKED;
		}

		if (! empty($s['direct_phone'])) {
			return self::READY_DIRECT;
		}

		if (! empty($s['company_phone'])) {
			return self::CALL_COMPANY;
		}

		$uncertain = ($s['verification_status'] ?? 'pending') !== 'verified' || in_array($s['domain_status'] ?? '', ['mismatch', 'uncertain'], true) || ! empty($s['likely_phone']);

		return $uncertain ? self::RESEARCH : self::FIND_PHONE;
	}

	/**
	 * @return array{key: string, label: string, label_pl: string, tone: string, hint: string}
	 */
	public static function present(?string $state): array
	{
		$state = in_array($state, self::STATES, true) ? $state : self::RESEARCH;

		return ['key' => $state] + self::LABELS[$state];
	}
}
