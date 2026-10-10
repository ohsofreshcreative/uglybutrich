<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Potencjał biznesowy (25 pkt): wielkość, branża (dopasowanie do segmentów agencji), charakter B2B, dane finansowe.
 *
 * Zasady: brak danych = NOT_EVALUATED (0 pkt). Nie zgadujemy wielkości ani przychodów. Liczba pracowników i przychód
 * to POTENCJAŁ zakupowy, nie dowód budżetu ani gotowości do zakupu. Dane finansowe: tylko deklaracja źródła (Apollo);
 * miejsce na przyszłe publiczne sprawozdania (eKRS) — niezaimplementowane.
 */
final class BusinessPotentialAnalyzer
{
	/** PKD 2007 (2 cyfry) → punkty branży (preferowane segmenty: produkcja, przemysł, dystrybucja B2B, logistyka, firmy techniczne). */
	private const PKD_INDUSTRY = [
		'manufacturing' => [8, 'produkcja / przemysł'],
		'wholesale' => [7, 'handel hurtowy / dystrybucja B2B'],
		'logistics' => [7, 'transport i logistyka'],
		'construction' => [6, 'budownictwo'],
		'tech_services' => [6, 'usługi techniczne / IT / inżynieria'],
		'energy' => [5, 'energetyka'],
		'agriculture' => [4, 'rolnictwo'],
		'consumer' => [2, 'handel detaliczny / usługi konsumenckie'],
		'public' => [0, 'administracja / edukacja / organizacje'],
		'other' => [3, 'inna działalność'],
	];

	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		$c = $ctx->company;
		$criteria = [];
		$checks = ['available' => [], 'missing' => []];

		// Wielkość.
		$employees = (int) ($c['employees_min'] ?? 0) ?: (int) ($c['employees_max'] ?? 0);

		if ($employees > 0) {
			$points = match (true) {
				$employees <= 9 => 2,
				$employees <= 49 => 6,
				$employees <= 249 => 10,
				$employees <= 999 => 8,
				default => 4,
			};
			$label = match (true) {
				$employees <= 9 => 'mikro (budżet zwykle ograniczony)',
				$employees <= 49 => 'mała',
				$employees <= 249 => 'średnia (segment docelowy)',
				$employees <= 999 => 'duża',
				default => 'bardzo duża (marketing często centralny / w grupie)',
			};
			$criteria['c_size'] = Criterion::measured($points, 10, sprintf('Ok. %d pracowników — %s; źródło: %s (%s).', $employees, $label, $c['employees_source'] ?? '?', $c['employees_confidence'] ?? '?'), ['employees: ' . $employees]);
			$checks['available'][] = 'liczba pracowników';
		} else {
			$criteria['c_size'] = Criterion::notEvaluated(10, 'Brak danych o liczbie pracowników — wielkości nie zgadujemy.');
			$checks['missing'][] = 'liczba pracowników';
		}

		// Branża i B2B — PKD z rejestru (potwierdzone) > SIC od dostawcy (deklaracja).
		$segment = null;
		$source = null;
		$pkd = (string) ($c['pkd_main'] ?? '');

		if ($pkd !== '') {
			$segment = self::segmentFromPkd(substr(preg_replace('/\D/', '', $pkd) ?? '', 0, 2));
			$source = 'PKD ' . $pkd . ' (rejestr)';
		} else {
			$codes = json_decode((string) ($c['industry_codes'] ?? ''), true) ?: [];
			$sic = (string) (($codes['sic'] ?? [])[0] ?? '');

			if ($sic !== '') {
				$segment = self::segmentFromSic(substr(preg_replace('/\D/', '', $sic) ?? '', 0, 2));
				$source = 'SIC ' . $sic . ' (deklaracja źródła)';
			}
		}

		$flags = json_decode((string) ($c['fit_flags'] ?? ''), true) ?: [];

		if (array_intersect($flags, ['public_institution', 'association', 'media', 'directory'])) {
			$segment = 'public';
			$source = ($source ? $source . '; ' : '') . 'flagi: ' . implode(', ', array_intersect($flags, ['public_institution', 'association', 'media', 'directory']));
		}

		if ($segment) {
			[$points, $label] = self::PKD_INDUSTRY[$segment];
			$criteria['c_industry'] = Criterion::measured($points, 8, 'Branża: ' . $label . ' — ' . $source . '.', [$source]);
			$b2b = match ($segment) {
				'manufacturing', 'wholesale', 'logistics', 'tech_services', 'construction', 'energy' => 4,
				'consumer', 'public' => 0,
				default => 2,
			};
			$criteria['c_b2b'] = Criterion::measured($b2b, 4, $b2b >= 4 ? 'Działalność typowo B2B (wg kodu działalności).' : ($b2b === 0 ? 'Działalność konsumencka / niekomercyjna.' : 'Charakter B2B niejednoznaczny.'), [$source]);
			$checks['available'][] = 'branża (' . $source . ')';
		} else {
			$criteria['c_industry'] = Criterion::notEvaluated(8, 'Brak kodu PKD/SIC — branży nie przypisujemy z domysłu.');
			$checks['missing'][] = 'kod branży (PKD/SIC)';
			$siteText = $ctx->home ? mb_strtolower($ctx->home->text()) : '';

			if ($siteText !== '' && preg_match('/\b(b2b|dla przemysłu|dla firm|hurtow|dystrybut|producent|zakład produkcyjny|dla zakładów)/u', $siteText, $m)) {
				$criteria['c_b2b'] = Criterion::measured(3, 4, 'Sygnał B2B w treści strony: „' . $m[1] . '” (deklaracja firmy).', ['strona: ' . $m[1]]);
			} else {
				$criteria['c_b2b'] = Criterion::notEvaluated(4, 'Brak danych o charakterze działalności.');
				$checks['missing'][] = 'charakter B2B';
			}
		}

		// Dane finansowe — tylko jeśli źródło je podało (Apollo: szacunek przychodu w USD).
		$revenue = (int) ($c['revenue_estimate'] ?? 0);

		if ($revenue > 0) {
			$points = $revenue >= 10_000_000 ? 3 : ($revenue >= 2_000_000 ? 2 : 1);
			$criteria['c_financial'] = Criterion::measured($points, 3, sprintf('Szacowany przychód wg %s: ok. %s USD (deklaracja źródła, nie sprawozdanie).', $c['revenue_source'] ?? 'źródła', number_format($revenue, 0, ',', ' ')), ['revenue_estimate: ' . $revenue]);
			$checks['available'][] = 'szacunek przychodu';
		} else {
			$criteria['c_financial'] = Criterion::notEvaluated(3, 'Brak publicznych danych finansowych (sprawozdania eKRS — integracja przygotowana na przyszłość, niezaimplementowana).');
			$checks['missing'][] = 'dane finansowe';
		}

		$findings = [];

		if (count($checks['missing']) >= 3) {
			$findings[] = Finding::make('business_missing_data', 'business', 'info', 'Mało danych o potencjale biznesowym', 'Brakuje: ' . implode(', ', $checks['missing']) . '. Potencjał zakupowy jest niepewny (punkty nie są przyznawane za brakujące dane).', $checks['missing'], 'Uzupełnić dane (weryfikacja w rejestrze, ręczna ocena wielkości firmy).', 'manual_review');
		}

		return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
	}

	public static function segmentFromPkd(string $two): string
	{
		$n = (int) $two;

		return match (true) {
			$n >= 10 && $n <= 33 => 'manufacturing',
			$n === 46 || $n === 45 => 'wholesale',
			$n >= 49 && $n <= 53 => 'logistics',
			$n >= 41 && $n <= 43 => 'construction',
			in_array($n, [62, 63, 71, 72, 74], true) => 'tech_services',
			$n === 35 || $n === 36 || $n === 38 => 'energy',
			$n >= 1 && $n <= 3 => 'agriculture',
			in_array($n, [47, 55, 56, 93, 96], true) => 'consumer',
			in_array($n, [84, 85, 94, 99], true) => 'public',
			default => 'other',
		};
	}

	public static function segmentFromSic(string $two): string
	{
		$n = (int) $two;

		return match (true) {
			$n >= 20 && $n <= 39 => 'manufacturing',
			$n === 50 || $n === 51 => 'wholesale',
			$n >= 40 && $n <= 47 => 'logistics',
			$n >= 15 && $n <= 17 => 'construction',
			in_array($n, [73, 87], true) => 'tech_services',
			$n === 49 => 'energy',
			$n >= 1 && $n <= 9 => 'agriculture',
			$n >= 52 && $n <= 59 || in_array($n, [70, 72, 79], true) => 'consumer',
			$n >= 91 && $n <= 97 || in_array($n, [82, 86], true) => 'public',
			default => 'other',
		};
	}
}
