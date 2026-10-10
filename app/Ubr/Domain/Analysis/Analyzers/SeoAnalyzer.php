<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Podstawowe SEO strony głównej: title, meta description, H1, struktura nagłówków, canonical, robots/indeksowalność,
 * dane strukturalne. Bez narzędzi zewnętrznych i bez szacowania pozycji w Google.
 */
final class SeoAnalyzer
{
	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		if (! $ctx->home) {
			return ['criteria' => ['b_seo' => Criterion::notEvaluated(5, 'Brak HTML strony głównej.')], 'checks' => [], 'findings' => []];
		}

		$doc = $ctx->home;
		$title = $doc->title();
		$description = $doc->meta('description');
		$robots = strtolower((string) $doc->meta('robots')) . ' ' . strtolower((string) ($ctx->homeFetch['headers']['x-robots-tag'] ?? ''));
		$h1 = $doc->headings('h1');
		$canonical = $doc->canonical();
		$jsonLd = $doc->jsonLdTypes();
		$checks = [
			'title' => $title,
			'title_length' => $title ? mb_strlen($title) : 0,
			'meta_description' => $description ? mb_substr($description, 0, 200) : null,
			'h1_count' => count($h1),
			'h2_count' => count($doc->headings('h2')),
			'canonical' => $canonical,
			'robots' => trim($robots) ?: null,
			'structured_data' => $jsonLd,
			'lang' => $doc->lang(),
		];
		$points = 0;
		$evidence = [];
		$findings = [];

		if (! $title) {
			$points += 2;
			$evidence[] = 'Brak <title>';
			$findings[] = Finding::make('seo_no_title', 'seo', 'high', 'Brak tytułu strony', 'Strona główna nie ma znacznika <title>.', '<title>: brak', 'Dodać unikalny tytuł z nazwą firmy i główną usługą (50–60 znaków).');
		} elseif (mb_strlen($title) < 15 || mb_strlen($title) > 70) {
			$points++;
			$evidence[] = sprintf('Title (%d znaków): %s', mb_strlen($title), mb_substr($title, 0, 80));
			$findings[] = Finding::make('seo_title_length', 'seo', 'low', mb_strlen($title) < 15 ? 'Zbyt krótki tytuł strony' : 'Zbyt długi tytuł strony', 'Tytuł strony głównej ma ' . mb_strlen($title) . ' znaków — w wynikach wyszukiwania słabo opisuje działalność.', $title, 'Ustawić tytuł 30–60 znaków: nazwa firmy + główna usługa/produkt.');
		}

		if (! $description) {
			$points++;
			$evidence[] = 'Brak meta description';
			$findings[] = Finding::make('seo_no_description', 'seo', 'medium', 'Brakuje meta description', 'Strona główna nie ma opisu meta — Google sam wybierze fragment tekstu do wyników wyszukiwania.', 'meta description: brak', 'Dodać opis 120–160 znaków z ofertą i wezwaniem do kontaktu.');
		}

		if ($h1 === []) {
			$points++;
			$evidence[] = 'Brak H1';
		}

		if (preg_match('/noindex/', $robots)) {
			$points += 2;
			$evidence[] = 'robots: noindex';
			$findings[] = Finding::make('seo_noindex', 'seo', 'critical', 'Strona wyłączona z indeksowania', 'Strona główna ma dyrektywę noindex — nie pojawi się w Google.', 'robots: ' . trim($robots), 'Usunąć noindex (o ile nie jest celowy).');
		}

		if (! $canonical && $jsonLd === []) {
			$points++;
			$evidence[] = 'Brak canonical i danych strukturalnych';
		}

		return ['criteria' => ['b_seo' => Criterion::measured($points, 5, 'SEO strony głównej (HTML).', $evidence)], 'checks' => $checks, 'findings' => $findings];
	}
}
