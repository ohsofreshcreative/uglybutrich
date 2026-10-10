<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Confidence = jak pewne są dane o osobie i jej funkcji (oddzielnie od Contact Priority Score).
 *
 * Składowe 0–100: tożsamość, aktualność stanowiska, dopasowanie firmy, dane kontaktowe.
 * Poziom ogólny (HIGH ≥ 80, MEDIUM ≥ 55, LOW) liczony z trzech pierwszych — brak e-maila NIE obniża pewności
 * co do osoby i funkcji. Jedno źródło prasowe nie daje HIGH; konflikt stanowisk ogranicza do MEDIUM.
 */
final class ContactConfidence
{
	/**
	 * @param array<string, mixed> $contact zweryfikowany kontakt (ContactVerifier)
	 * @param array<string, mixed>|null $board
	 * @param bool $companyHasGeneralChannel firma ma ogólny publiczny kontakt (e-mail / telefon)
	 * @return array{level: string, score: int, identity: int, role_currency: int, company_match: int, contact_data: int, notes: list<string>}
	 */
	public function assess(array $contact, ?array $board, bool $companyHasGeneralChannel = false): array
	{
		$types = array_column($contact['sources'], 'source_type');
		$kinds = array_filter(array_column($contact['sources'], 'page_kind'));
		$notes = [];

		// Tożsamość.
		$identity = match (true) {
			$contact['obfuscated'] => 20,
			$contact['krs_match'] === 'matched' && in_array('COMPANY_WEBSITE', $types, true) => 95,
			in_array('COMPANY_WEBSITE', $types, true) => 80,
			in_array('USER_PROVIDED', $types, true) => 50,
			in_array('APOLLO', $types, true) => 60,
			default => 30,
		};

		if (in_array('COMPANY_WEBSITE', $types, true) && in_array('APOLLO', $types, true) && $identity < 95) {
			$identity += 5;
		}

		// Aktualność stanowiska.
		$krsFresh = $board && ! empty($board['state_date']) && strtotime($board['state_date'] . ' UTC') > time() - 365 * DAY_IN_SECONDS;
		$siteKind = in_array('management', $kinds, true) ? 'management' : (in_array('team', $kinds, true) || in_array('about', $kinds, true) || in_array('contact', $kinds, true) ? 'team' : (in_array('press', $kinds, true) ? 'press' : 'other'));
		$role = match (true) {
			$contact['employment_status'] === 'former' => 10,
			$contact['krs_match'] === 'matched' && ! $contact['krs_suspended'] => $krsFresh ? 95 : 75,
			$contact['krs_match'] === 'length_mismatch', $contact['krs_match'] === 'ambiguous' => 50,
			in_array('COMPANY_WEBSITE', $types, true) => match ($siteKind) {
				'management' => 75,
				'team' => 70,
				'press' => 50,
				default => 60,
			},
			in_array('USER_PROVIDED', $types, true) => 50,
			in_array('APOLLO', $types, true) => 50,
			default => 30,
		};

		if ($siteKind === 'press' && $contact['krs_match'] !== 'matched') {
			$notes[] = 'Stanowisko tylko z publikacji prasowej / aktualności — aktualność niepewna.';
		}

		// Dopasowanie firmy.
		$match = match ($contact['company_match_status']) {
			ContactVerifier::MATCH_CONFIRMED => 95,
			ContactVerifier::MATCH_PROBABLE => 70,
			ContactVerifier::MATCH_UNCERTAIN => 35,
			default => 0,
		};

		// Dane kontaktowe (nie wpływają na poziom ogólny).
		$data = match (true) {
			! empty($contact['email']) => 90,
			! empty($contact['phone']) => 70,
			! empty($contact['profile']) => 60,
			! empty($contact['role_email']) => 40,
			$companyHasGeneralChannel => 25,
			default => 0,
		};

		$overall = (int) round(0.35 * $identity + 0.35 * $role + 0.30 * $match);
		$level = $overall >= 80 ? 'high' : ($overall >= 55 ? 'medium' : 'low');

		if (min($identity, $role, $match) < 40) {
			$level = 'low';
		}

		if ($contact['conflict'] && $level === 'high') {
			$level = 'medium';
			$notes[] = 'Konflikt stanowisk w źródłach — pewność ograniczona do średniej.';
		}

		if ($siteKind === 'press' && $level === 'high' && $contact['krs_match'] !== 'matched') {
			$level = 'medium';
		}

		return ['level' => $level, 'score' => $overall, 'identity' => $identity, 'role_currency' => $role, 'company_match' => $match, 'contact_data' => $data, 'notes' => $notes];
	}

	public static function label(?string $level): string
	{
		return ['high' => 'Wysoka', 'medium' => 'Średnia', 'low' => 'Niska'][$level] ?? '—';
	}
}
