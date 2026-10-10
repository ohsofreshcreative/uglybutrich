<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Contact Priority Score 0–100 — jak wartościowy jest kontakt dla sprzedaży redesignu strony WWW
 * (to NIE jest Opportunity Score ani Confidence).
 *
 * A. Decision authority   35 — czy osoba może zatwierdzić wydatek,
 * B. Role relevance       30 — związek stanowiska z zakupem i wdrożeniem strony,
 * C. Company size fit     15 — dopasowanie stanowiska do wielkości firmy (nieznana wielkość = nie mierzone, 0 pkt),
 * D. Contactability       10 — wiarygodna, publiczna droga kontaktu (adresów nie zgadujemy),
 * E. Data confidence      10 — z poziomu Confidence.
 *
 * Deterministyczny; brak danych = 0 pkt (nie maksimum) i status PROVISIONAL; za mało danych = INSUFFICIENT_DATA.
 * Firma nieaktywna w rejestrze: wynik ograniczony do 10.
 */
final class ContactPriorityCalculator
{
	public const VERSION = 'cps-2026-10-v1';

	public const SCORED = 'SCORED';

	public const PROVISIONAL = 'PROVISIONAL';

	public const INSUFFICIENT = 'INSUFFICIENT_DATA';

	private const AUTHORITY = ['owner' => 35, 'founder' => 33, 'ceo' => 35, 'managing_director' => 32, 'vice_president' => 28, 'board_member' => 22, 'proxy' => 12, 'marketing_director' => 26, 'digital_head' => 22, 'marketing_manager' => 16, 'sales_director' => 15, 'other_director' => 12, 'marketing_specialist' => 6];

	private const RELEVANCE = ['marketing_director' => 30, 'digital_head' => 30, 'marketing_manager' => 27, 'marketing_specialist' => 15, 'owner' => 22, 'founder' => 22, 'ceo' => 20, 'managing_director' => 20, 'vice_president' => 14, 'board_member' => 12, 'sales_director' => 14, 'proxy' => 5, 'other_director' => 6];

	/** Dopasowanie do wielkości: [mała < 50, średnia 50–249, duża 250+]. */
	private const SIZE_FIT = [
		'leadership' => [15, 11, 5],
		'board' => [12, 10, 6],
		'marketing_lead' => [10, 14, 15],
		'marketing_manager' => [10, 13, 13],
		'marketing_specialist' => [6, 7, 8],
		'other' => [6, 7, 7],
	];

	/**
	 * @param array<string, mixed> $contact zweryfikowany kontakt
	 * @param array{level: string, score: int, contact_data: int} $confidence
	 * @param array<string, mixed> $company
	 * @return array{score: int, status: string, breakdown: array<string, array<string, mixed>>, version: string}
	 */
	public function score(array $contact, array $confidence, array $company, bool $companyHasGeneralChannel = false): array
	{
		$role = (string) $contact['normalized_role'];
		$focus = (bool) $contact['marketing_focus'];
		$size = self::sizeBand($company);
		$b = [];

		// A. Decyzyjność.
		$a = self::AUTHORITY[$role] ?? 3;

		if (in_array($role, ['board_member', 'vice_president'], true) && $focus) {
			$a = min(35, $a + 4);
		}

		$b['decision_authority'] = ['points' => $a, 'max' => 35, 'state' => 'measured', 'note' => RoleCatalog::label($role) . ($focus && RoleCatalog::isBoard($role) ? ' (obszar: marketing / sprzedaż)' : '')];

		// B. Związek stanowiska ze stroną WWW.
		$r = self::RELEVANCE[$role] ?? 2;

		if (in_array($role, ['board_member', 'vice_president', 'proxy'], true) && $focus) {
			$r = max($r, 24);
		}

		$b['role_relevance'] = ['points' => $r, 'max' => 30, 'state' => 'measured', 'note' => match (true) {
			$r >= 27 => 'Stanowisko bezpośrednio związane z marketingiem / stroną WWW.',
			$r >= 20 => 'Zarządza firmą — może zlecić stronę, ale nie odpowiada za nią operacyjnie.',
			$r >= 12 => 'Pośredni związek z zakupem strony.',
			default => 'Słaby związek z zakupem strony.',
		}];

		// C. Wielkość firmy (nie zgadujemy — brak danych = nie mierzone).
		if ($size === null) {
			$b['company_size_fit'] = ['points' => 0, 'max' => 15, 'state' => 'not_measured', 'note' => 'Wielkość firmy nieznana — kryterium nie oceniane (0 pkt).'];
		} else {
			$group = match (true) {
				in_array($role, ['owner', 'founder', 'ceo', 'managing_director'], true) => 'leadership',
				in_array($role, ['vice_president', 'board_member', 'proxy'], true) => 'board',
				in_array($role, ['marketing_director', 'digital_head'], true) => 'marketing_lead',
				$role === 'marketing_manager' => 'marketing_manager',
				$role === 'marketing_specialist' => 'marketing_specialist',
				default => 'other',
			};
			$fit = self::SIZE_FIT[$group][['small' => 0, 'medium' => 1, 'large' => 2][$size]];

			if ($group === 'board' && $focus) {
				$fit = min(15, $fit + 2);
			}

			$b['company_size_fit'] = ['points' => $fit, 'max' => 15, 'state' => 'measured', 'note' => ['small' => 'Mała firma (< 50 os.)', 'medium' => 'Średnia firma (50–249 os.)', 'large' => 'Duża firma (250+ os.)'][$size]];
		}

		// D. Dostępność kontaktu.
		[$d, $dNote] = match (true) {
			! empty($contact['email']) => [10, 'Publiczny służbowy e-mail opublikowany przy osobie.'],
			! empty($contact['phone']) => [7, 'Publiczny służbowy telefon przy osobie.'],
			! empty($contact['profile']) => [5, 'Profil zawodowy opublikowany przez firmę.'],
			! empty($contact['role_email']) => [4, 'E-mail działu przy osobie (nie osobisty).'],
			$companyHasGeneralChannel => [2, 'Tylko ogólny kontakt firmy.'],
			default => [0, 'Brak publicznej drogi kontaktu.'],
		};
		$b['contactability'] = ['points' => $d, 'max' => 10, 'state' => 'measured', 'note' => $dNote];

		// E. Pewność danych.
		$e = (int) round($confidence['score'] / 10);
		$b['data_confidence'] = ['points' => $e, 'max' => 10, 'state' => 'measured', 'note' => 'Confidence: ' . ContactConfidence::label($confidence['level']) . ' (' . $confidence['score'] . '/100).'];

		$score = $a + $r + ($b['company_size_fit']['points']) + $d + $e;

		if (in_array($company['verification_status'] ?? '', ['inactive', 'suspended'], true)) {
			$score = min($score, 10);
			$b['cap'] = ['points' => 0, 'max' => 0, 'state' => 'cap', 'note' => 'Firma nieaktywna / zawieszona w rejestrze — wynik ograniczony do 10.'];
		}

		$status = match (true) {
			$confidence['score'] < 40 || $contact['obfuscated'] => self::INSUFFICIENT,
			$size === null || $confidence['level'] === 'low' => self::PROVISIONAL,
			default => self::SCORED,
		};

		return ['score' => max(0, min(100, $score)), 'status' => $status, 'breakdown' => $b, 'version' => self::VERSION];
	}

	/**
	 * @param array<string, mixed> $company
	 */
	public static function sizeBand(array $company): ?string
	{
		if (($company['employees_confidence'] ?? 'unavailable') === 'unavailable') {
			return null;
		}

		$n = $company['employees_max'] ?? $company['employees_min'] ?? null;

		if ($n === null || $n === '') {
			return null;
		}

		$n = (int) $n;

		return $n < 50 ? 'small' : ($n < 250 ? 'medium' : 'large');
	}
}
