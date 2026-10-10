<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Technika: wydajność (czas odpowiedzi i rozmiar HTML — pomiar serwera; czasy ładowania, transfer, liczba żądań,
 * błędy zasobów — jeden pomiar LABORATORYJNY w przeglądarce, to NIE są Core Web Vitals ani dane użytkowników),
 * bezpieczeństwo i jakość (HTTPS, przekierowanie http→https, mixed content, przestarzałe biblioteki, błędy HTTP).
 */
final class TechnicalAnalyzer
{
	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		$criteria = [];
		$findings = [];
		$f = $ctx->homeFetch;
		$checks = ['response_ms' => $f['ms'] ?? null, 'html_bytes' => $f['bytes'] ?? null, 'status' => $f['status'] ?? null, 'final_url' => $f['final_url'] ?? null, 'redirects' => count($f['redirects'] ?? [])];

		if (! $ctx->siteReachable()) {
			return ['criteria' => [
				'b_perf_static' => Criterion::notEvaluated(2, 'Strona nie odpowiada.'),
				'b_perf_render' => Criterion::notEvaluated(5, 'Strona nie odpowiada.'),
				'b_security' => Criterion::notEvaluated(3, 'Strona nie odpowiada.'),
			], 'checks' => $checks, 'findings' => []];
		}

		// Wydajność — serwer.
		$points = 0;
		$evidence = [];

		if (($f['ms'] ?? 0) > 1500) {
			$points++;
			$evidence[] = 'Czas odpowiedzi HTML: ' . $f['ms'] . ' ms (pomiar z serwera aplikacji)';
		}

		if (($f['bytes'] ?? 0) > 400 * 1024) {
			$points++;
			$evidence[] = 'Rozmiar HTML: ' . round($f['bytes'] / 1024) . ' KB';
		}

		$criteria['b_perf_static'] = Criterion::measured($points, 2, 'Czas odpowiedzi i rozmiar dokumentu HTML.', $evidence);

		// Wydajność — przeglądarka (laboratoryjnie).
		$cap = $ctx->capture;

		if ($ctx->rendered() && ! empty($cap['timing'])) {
			$points = 0;
			$evidence = [];
			$t = $cap['timing'];
			$n = $cap['network'] ?? [];
			$checks['lab'] = ['load_ms' => $t['load_ms'] ?? null, 'dom_content_loaded_ms' => $t['dom_content_loaded_ms'] ?? null, 'ttfb_ms' => $t['ttfb_ms'] ?? null, 'requests' => $n['requests'] ?? null, 'bytes' => $n['bytes'] ?? null, 'failed' => $n['failed'] ?? null, 'http_errors' => $n['http_errors'] ?? null, 'console_errors' => $cap['console_errors'] ?? null, 'third_party_hosts' => $n['third_party_hosts'] ?? null];

			$load = (int) ($t['load_ms'] ?? 0);

			if ($load > 6000) {
				$points += 2;
			} elseif ($load > 3500) {
				$points += 1;
			}

			if ($load > 3500) {
				$evidence[] = 'Zdarzenie load: ' . $load . ' ms (1 pomiar laboratoryjny, desktop)';
			}

			$bytes = (int) ($n['bytes'] ?? 0);

			if ($bytes > 5 * 1024 * 1024) {
				$points += 2;
			} elseif ($bytes > 2.5 * 1024 * 1024) {
				$points += 1;
			}

			if ($bytes > 2.5 * 1024 * 1024) {
				$evidence[] = 'Transfer strony głównej: ' . round($bytes / 1048576, 1) . ' MB';
				$findings[] = Finding::make('perf_heavy', 'performance', $bytes > 5 * 1048576 ? 'medium' : 'low', 'Ciężka strona główna', sprintf('Strona główna pobiera ok. %.1f MB danych (%d żądań).', $bytes / 1048576, (int) ($n['requests'] ?? 0)), $evidence[array_key_last($evidence)], 'Zoptymalizować obrazy (WebP/AVIF, właściwe wymiary, lazy loading) i ograniczyć skrypty.');
			}

			if (($n['requests'] ?? 0) > 150) {
				$points++;
				$evidence[] = 'Żądań: ' . $n['requests'];
			}

			$broken = (int) ($n['failed'] ?? 0) + (int) ($n['http_errors'] ?? 0);

			if ($broken > 3) {
				$points++;
				$evidence[] = 'Błędne zasoby (4xx/5xx/nieudane): ' . $broken;
				$findings[] = Finding::make('perf_broken_resources', 'performance', 'low', 'Niedziałające zasoby na stronie', sprintf('%d zasobów strony głównej zwraca błąd lub nie ładuje się.', $broken), $evidence[array_key_last($evidence)], 'Usunąć lub naprawić odwołania do brakujących plików.');
			}

			if ($load > 6000) {
				$findings[] = Finding::make('perf_slow', 'performance', 'medium', 'Strona ładuje się wolno', sprintf('Pełne załadowanie strony głównej trwało %.1f s w pomiarze laboratoryjnym (jeden pomiar, nie dane użytkowników).', $load / 1000), 'load: ' . $load . ' ms', 'Przeprowadzić audyt wydajności (obrazy, skrypty, hosting, cache).');
			}

			$criteria['b_perf_render'] = Criterion::measured($points, 5, 'Pomiar laboratoryjny w Chromium (1 pomiar, desktop) — nie Core Web Vitals.', $evidence);
		} else {
			$criteria['b_perf_render'] = Criterion::notEvaluated(5, 'Brak pomiaru w przeglądarce (Lighthouse/CrUX nie są używane).');
		}

		// Bezpieczeństwo / jakość.
		$points = 0;
		$evidence = [];
		$final = (string) ($f['final_url'] ?? '');
		$https = str_starts_with(strtolower($final), 'https://');
		$checks['https'] = $https;
		$checks['http_to_https_redirect'] = $ctx->httpsRedirect;
		$checks['hsts'] = ! empty($f['headers']['strict-transport-security']);

		if (! $https) {
			$points += 2;
			$evidence[] = 'Strona działa bez HTTPS: ' . $final;
			$findings[] = Finding::make('sec_no_https', 'security', 'high', 'Strona bez HTTPS', 'Strona jest serwowana przez niezaszyfrowane HTTP — przeglądarki oznaczają ją jako „niezabezpieczoną”.', $final, 'Wdrożyć certyfikat SSL i przekierowanie na HTTPS.');
		} elseif ($ctx->httpsRedirect === false) {
			$points++;
			$evidence[] = 'http:// nie przekierowuje na https://';
		}

		$mixed = $ctx->home ? $ctx->home->insecureResources() : [];
		$checks['mixed_content'] = $mixed;

		if ($https && $mixed) {
			$points++;
			$evidence[] = 'Zasoby po http:// na stronie https: ' . count($mixed);
			$findings[] = Finding::make('sec_mixed_content', 'security', 'low', 'Mieszana zawartość (mixed content)', 'Strona HTTPS ładuje zasoby przez niezaszyfrowane HTTP.', array_slice($mixed, 0, 3), 'Zmienić adresy zasobów na https://.');
		}

		$jquery = null;

		foreach ($ctx->home ? $ctx->home->scriptSources() : [] as $src) {
			if (preg_match('/jquery[.-]?(\d+\.\d+(?:\.\d+)?)(?:\.min)?\.js/i', $src, $m)) {
				$jquery = $m[1];
				break;
			}
		}

		$checks['jquery'] = $jquery;

		if ($jquery !== null && version_compare($jquery, '1.12.0', '<')) {
			$points++;
			$evidence[] = 'jQuery ' . $jquery;
			$findings[] = Finding::make('sec_old_jquery', 'security', 'medium', 'Przestarzała biblioteka jQuery', 'Strona ładuje jQuery ' . $jquery . ' (wersja sprzed 2016 r., znane podatności) — sygnał długo nieaktualizowanej strony.', 'jQuery ' . $jquery, 'Zaktualizować bibliotekę lub przebudować front-end.');
		}

		if ($ctx->capture && ! empty($ctx->capture['console_errors']) && $ctx->capture['console_errors'] > 5) {
			$evidence[] = 'Błędy JavaScript w konsoli: ' . $ctx->capture['console_errors'];
		}

		$criteria['b_security'] = Criterion::measured($points, 3, 'HTTPS, przekierowanie, mixed content, przestarzałe biblioteki.', $evidence);

		return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
	}
}
