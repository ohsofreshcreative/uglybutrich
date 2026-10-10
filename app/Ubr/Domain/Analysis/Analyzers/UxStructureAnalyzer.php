<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Sygnały STRUKTURALNE UX (nawigacja, nagłówki, organizacja treści) z HTML i — jeśli dostępne — z renderu.
 * To nie jest ocena wizualna: hierarchii wizualnej, estetyki i czytelności układu nie oceniamy bez analizy obrazu.
 */
final class UxStructureAnalyzer
{
	public const OFFER_RE = '/(oferta|ofert|offer|produkt|product|uslug|usług|service|rozwiazan|rozwiązan|solution|realizacj|katalog)/iu';

	public const CONTACT_RE = '/(kontakt|contact)/iu';

	public const ABOUT_RE = '/(o-nas|o nas|o-firmie|o firmie|about|firma)/iu';

	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		$criteria = [];
		$findings = [];
		$checks = [];

		if (! $ctx->home) {
			return ['criteria' => ['a_ux_navigation' => Criterion::notEvaluated(5, 'Brak HTML strony głównej.'), 'a_ux_structure' => Criterion::notEvaluated(3, 'Brak HTML strony głównej.')], 'checks' => [], 'findings' => []];
		}

		$doc = $ctx->home;
		$links = $doc->links();
		$navLinks = array_values(array_filter($links, fn ($l) => $l['internal'] && ($l['in_nav'] || $l['in_header'])));
		$navText = implode(' | ', array_map(fn ($l) => $l['text'] . ' ' . $l['href'], $navLinks));
		$keySections = [
			'oferta' => (bool) preg_match(self::OFFER_RE, $navText),
			'kontakt' => (bool) preg_match(self::CONTACT_RE, $navText),
			'o firmie' => (bool) preg_match(self::ABOUT_RE, $navText),
		];
		$checks['nav_links'] = count($navLinks);
		$checks['nav_key_sections'] = $keySections;

		$points = 0;
		$evidence = [];

		if (count($navLinks) < 2) {
			$points += 2;
			$evidence[] = 'Brak rozpoznawalnego menu (linki w <nav>/<header>): ' . count($navLinks);
			$findings[] = Finding::make('ux_no_nav', 'ux', 'high', 'Brak czytelnego menu nawigacyjnego', 'W kodzie strony głównej nie znaleziono menu (elementy <nav> lub linki w nagłówku). Użytkownik może mieć trudność ze znalezieniem oferty i kontaktu.', $evidence[array_key_last($evidence)], 'Dodać główne menu w nagłówku z kluczowymi sekcjami (Oferta, Realizacje, O firmie, Kontakt).');
		} elseif (count($navLinks) > 14) {
			$points += 1;
			$evidence[] = 'Linków w menu/nagłówku: ' . count($navLinks);
			$findings[] = Finding::make('ux_nav_cluttered', 'ux', 'low', 'Rozbudowane menu', 'Menu i nagłówek zawierają bardzo dużo linków, co utrudnia orientację (wymaga ręcznej weryfikacji układu).', $evidence[array_key_last($evidence)], 'Uporządkować nawigację: 5–7 głównych pozycji, reszta w podmenu lub stopce.');
		}

		$missing = array_keys(array_filter($keySections, fn ($v) => ! $v));

		if (count($navLinks) >= 2 && count($missing) >= 2) {
			$points += 2;
			$evidence[] = 'W menu brak: ' . implode(', ', $missing);
			$findings[] = Finding::make('ux_nav_missing_sections', 'ux', 'medium', 'Menu nie prowadzi do kluczowych sekcji', 'W nawigacji nie znaleziono linków do: ' . implode(', ', $missing) . '.', $evidence[array_key_last($evidence)], 'Zapewnić bezpośredni dostęp z menu do oferty i kontaktu.');
		} elseif (count($navLinks) >= 2 && ! $keySections['kontakt']) {
			$points += 1;
			$evidence[] = 'W menu brak linku do kontaktu';
		}

		$criteria['a_ux_navigation'] = Criterion::measured($points, 5, 'Nawigacja (struktura HTML strony głównej).', $evidence);

		// Struktura strony głównej.
		$h1 = $doc->headings('h1');
		$h2 = $doc->headings('h2');
		$words = $doc->wordCount();
		$checks += ['h1' => $h1, 'h2_count' => count($h2), 'words' => $words];
		$points = 0;
		$evidence = [];

		if ($h1 === []) {
			$points++;
			$evidence[] = 'Brak nagłówka H1 na stronie głównej';
			$findings[] = Finding::make('ux_no_h1', 'content', 'medium', 'Brak głównego nagłówka (H1)', 'Strona główna nie ma nagłówka H1 — brakuje jasnego komunikatu, czym zajmuje się firma (także sygnał SEO).', 'H1: 0', 'Dodać jeden H1 z jasną propozycją wartości (co firma robi i dla kogo).');
		} elseif (count($h1) > 2) {
			$points++;
			$evidence[] = 'H1 na stronie głównej: ' . count($h1);
		}

		if ($words < 120) {
			$points++;
			$evidence[] = 'Mało treści na stronie głównej: ' . $words . ' słów';
		} elseif ($words > 600 && count($h2) === 0) {
			$points++;
			$evidence[] = $words . ' słów bez śródtytułów H2';
			$findings[] = Finding::make('ux_wall_of_text', 'content', 'low', 'Treść bez struktury śródtytułów', 'Długa treść strony głównej nie jest podzielona nagłówkami H2, co utrudnia skanowanie.', $evidence[array_key_last($evidence)], 'Podzielić treść na sekcje z czytelnymi śródtytułami.');
		}

		$criteria['a_ux_structure'] = Criterion::measured($points, 3, 'Struktura treści strony głównej (HTML).', $evidence);

		return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
	}
}
