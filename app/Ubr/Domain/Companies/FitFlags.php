<?php

namespace App\Ubr\Domain\Companies;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;

/**
 * Flagi jakości wyniku: czy rekord jest w ogóle przedsiębiorstwem, które może być klientem (prospektem) dla OhSoFresh.
 * Deterministyczne reguły na nazwie, domenie, opisie i formie prawnej. Nie usuwamy firm — tylko je oznaczamy.
 *
 * Flagi „blokujące” (urząd, stowarzyszenie, media, katalog, nieaktywna) obniżają Relevance Score;
 * flagi informacyjne (duże przedsiębiorstwo, możliwy oddział grupy, brak strony) są tylko pokazywane.
 */
final class FitFlags
{
	public const LABELS = [
		'public_institution' => 'Instytucja publiczna / uczelnia',
		'association' => 'Stowarzyszenie / fundacja / izba',
		'media' => 'Portal / media / wydawnictwo',
		'directory' => 'Katalog / agregator, nie firma',
		'inactive' => 'Nieaktywna w rejestrze',
		'suspended' => 'Działalność zawieszona',
		'large_enterprise' => 'Duże przedsiębiorstwo (1000+ os. lub przychód ≥ 250 mln wg źródła)',
		'group_member' => 'Możliwy oddział / spółka grupy',
		'no_website' => 'Brak strony WWW',
	];

	/** Flagi, które oznaczają, że rekord prawdopodobnie nie jest prospektem (kara w Relevance Score). */
	public const BLOCKING = ['public_institution', 'association', 'media', 'directory'];

	/**
	 * @param array<string, mixed> $company wiersz ubr_companies
	 * @return list<string>
	 */
	public static function detect(array $company): array
	{
		$name = ' ' . preg_replace('/[^a-z0-9]+/', ' ', N::foldAscii((string) ($company['name'] ?? '') . ' ' . (string) ($company['registry_name'] ?? ''))) . ' ';
		$description = N::foldAscii((string) ($company['description'] ?? ''));
		$legalForm = N::foldAscii((string) ($company['legal_form'] ?? ''));
		$domain = (string) ($company['domain'] ?? '');
		$flags = [];

		$public = '/ (urzad|ministerstwo|inspektorat|inspekcja|starostwo|gmina|powiat|wojewodztwo|dozor techniczny|dozoru technicznego|uniwersytet|politechnika|akademia|szkola|zespol szkol|instytut|siec badawcza|panstwow|policja|wojsko|sad rejonowy|prokuratura|szpital|zus|nfz) /';

		if (preg_match($public, $name) || preg_match('/\.(gov|edu|mil)\.pl$/', $domain) || preg_match('/(panstwowa osoba prawna|jednostka budzetowa|instytut badawczy|uczelnia)/', $legalForm)) {
			$flags[] = 'public_institution';
		}

		if (preg_match('/ (stowarzyszenie|fundacja|zwiazek|izba gospodarcza|izba przemyslowo|izba rzemiosla|izba handlowa|klaster|federacja|zrzeszenie|cech|towarzystwo|samorzad) /', $name) || preg_match('/(stowarzyszenie|fundacja|zwiazek zawodowy|izba gospodarcza|zwiazek pracodawcow|cech rzemiosl)/', $legalForm)) {
			$flags[] = 'association';
		}

		if (preg_match('/ (portal|magazyn|miesiecznik|kwartalnik|wydawnictwo|media|news|gazeta|tygodnik|radio|telewizja|tv) /', $name)
			|| preg_match('/\b(portal|magazine|news portal|trade journal|serwis informacyjny|czasopismo|branzowy portal|publisher)\b/', $description)
			|| preg_match('/[a-z0-9-]+\.(pl|com|eu|net|info)\b/i', (string) ($company['name'] ?? '')) // nazwa w formie domeny, np. „Przemysl40.pl”
		) {
			$flags[] = 'media';
		}

		if (N::classifyDomain($domain ?: null) === 'directory' || preg_match('/ (katalog firm|baza firm|spis firm|ranking firm|directory|factories) /', $name)) {
			$flags[] = 'directory';
		}

		// PKD przeważające z rejestru (gdy firma zweryfikowana): administracja, edukacja, organizacje członkowskie, wydawcy/portale.
		$pkd = preg_replace('/[^0-9.]/', '', (string) ($company['pkd_main'] ?? '')) ?? '';

		if ($pkd !== '') {
			$prefix2 = substr($pkd, 0, 2);

			if (in_array($prefix2, ['84', '85'], true)) {
				$flags[] = 'public_institution';
			} elseif ($prefix2 === '94') {
				$flags[] = 'association';
			} elseif (in_array($prefix2, ['58', '59', '60'], true) || str_starts_with($pkd, '63.12') || str_starts_with($pkd, '63.9')) {
				$flags[] = 'media';
			}
		}

		$status = (string) ($company['verification_status'] ?? '');

		if ($status === 'inactive') {
			$flags[] = 'inactive';
		} elseif ($status === 'suspended') {
			$flags[] = 'suspended';
		}

		$employees = (int) ($company['employees_min'] ?? 0);

		if ($employees >= 1000 || (int) ($company['revenue_estimate'] ?? 0) >= 250000000) {
			$flags[] = 'large_enterprise';
		}

		if (preg_match('/ (polska|poland|polen|pl) (sp z o o|spolka z ograniczona|sa|s a)? ?$/', rtrim($name) . ' ') || preg_match('/ (oddzial w polsce|branch) /', $name)) {
			$flags[] = 'group_member';
		}

		if (empty($company['domain'])) {
			$flags[] = 'no_website';
		}

		return array_values(array_unique($flags));
	}

	/**
	 * @param list<string> $flags
	 * @return list<array{key: string, label: string, blocking: bool}>
	 */
	public static function present(array $flags): array
	{
		return array_map(fn ($f) => ['key' => $f, 'label' => self::LABELS[$f] ?? $f, 'blocking' => in_array($f, self::BLOCKING, true) || in_array($f, ['inactive', 'suspended'], true)], $flags);
	}
}
