<?php

namespace App\Ubr\Domain\Lead;

/**
 * Hard blockers, soft warnings, Data Confidence, Recommended Next Action i Smart Ranking.
 * Rekomendacja to SUGESTIA — nie zmienia pipeline, nie wysyła wiadomości, nie eksportuje, nie uruchamia analiz.
 */
final class LeadRecommender
{
	public const ACTIONS = [
		'contact_now' => 'Contact now',
		'research_further' => 'Research further',
		'review_website' => 'Review website',
		'verify_company' => 'Verify company',
		'find_decision_maker' => 'Find decision maker',
		'low_priority' => 'Low priority',
		'do_not_contact' => 'Do not contact',
	];

	public const ACTION_LABELS_PL = [
		'contact_now' => 'Skontaktuj się',
		'research_further' => 'Uzupełnij dane',
		'review_website' => 'Oceń stronę ręcznie',
		'verify_company' => 'Zweryfikuj firmę / domenę',
		'find_decision_maker' => 'Znajdź osobę decyzyjną',
		'low_priority' => 'Niski priorytet',
		'do_not_contact' => 'Nie kontaktuj',
	];

	public const CONFIDENCE = ['high' => 'HIGH', 'medium' => 'MEDIUM', 'low' => 'LOW'];

	/**
	 * @param array<string, mixed> $s snapshot
	 * @return list<array{key: string, label: string}>
	 */
	public static function blockers(array $s): array
	{
		$c = $s['company'];
		$out = [];

		if (($c['verification_status'] ?? '') === 'inactive') {
			$out[] = ['key' => 'inactive', 'label' => 'Firma potwierdzona w rejestrze jako nieaktywna.'];
		}

		if (($c['verification_status'] ?? '') === 'suspended') {
			$out[] = ['key' => 'suspended', 'label' => 'Działalność firmy jest zawieszona (rejestr).'];
		}

		if (! empty($c['domain']) && ($c['domain_status'] ?? '') === 'mismatch') {
			$out[] = ['key' => 'domain_mismatch', 'label' => 'Domena należy do innego podmiotu (MISMATCH) — sprzeczność identyfikacji firmy.'];
		}

		$p = $s['prospect'] ?? null;

		if ($p && empty($p['archived']) && (($p['status'] ?? '') === 'not_a_fit' || ($p['qualification'] ?? '') === 'not_a_fit')) {
			$out[] = ['key' => 'excluded', 'label' => 'Organizacja oznaczyła firmę jako „nie pasuje” (decyzja prywatna organizacji).'];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $s
	 * @param array<string, mixed> $lead wynik LeadPotentialCalculator
	 * @return list<array{key: string, label: string}>
	 */
	public static function warnings(array $s, array $lead): array
	{
		$c = $s['company'];
		$a = $s['analysis'] ?? null;
		$out = [];
		$v = (string) ($c['verification_status'] ?? 'pending');

		if ($v === 'uncertain') {
			$out[] = ['key' => 'company_uncertain', 'label' => 'Weryfikacja firmy niejednoznaczna (UNCERTAIN).'];
		} elseif (in_array($v, ['pending', ''], true)) {
			$out[] = ['key' => 'company_unverified', 'label' => 'Firma nie była jeszcze weryfikowana w rejestrze.'];
		}

		if (empty($c['domain'])) {
			$out[] = ['key' => 'no_domain', 'label' => 'Brak przypisanej domeny / strony.'];
		} elseif (! in_array($c['domain_status'] ?? 'unchecked', ['confirmed', 'mismatch'], true)) {
			$out[] = ['key' => 'domain_unconfirmed', 'label' => 'Domena niepotwierdzona.'];
		}

		if (! $a) {
			$out[] = ['key' => 'no_analysis', 'label' => 'Brak analizy strony (nie oznacza dobrej strony).'];
		} elseif (($a['status'] ?? '') === 'failed') {
			$out[] = ['key' => 'analysis_failed', 'label' => 'Ostatnia analiza strony nie powiodła się.'];
		} else {
			if (empty($a['fresh'])) {
				$out[] = ['key' => 'analysis_stale', 'label' => sprintf('Analiza strony starsza niż %d dni.', LeadPotentialCalculator::FRESH_DAYS)];
			}

			if (empty($a['rendered'])) {
				$out[] = ['key' => 'analysis_partial', 'label' => 'Analiza bez renderu (bez pomiarów mobile i wydajności w przeglądarce).'];
			}
		}

		if ($lead['coverage']['percent'] < 60) {
			$out[] = ['key' => 'low_coverage', 'label' => sprintf('Niska kompletność danych (%d%%).', $lead['coverage']['percent'])];
		}

		$ct = $s['contacts'] ?? [];

		if (empty($ct['researched'])) {
			$out[] = ['key' => 'contacts_not_researched', 'label' => 'Decydentów jeszcze nie wyszukiwano.'];
		} elseif (! array_filter((array) ($ct['people'] ?? []), fn ($p) => in_array($p['status'] ?? '', ['verified', 'probable'], true))) {
			$out[] = ['key' => 'no_decision_makers', 'label' => 'Nie znaleziono wiarygodnej osoby decyzyjnej.'];
		}

		if (array_intersect((array) ($c['fit_flags'] ?? []), LeadPotentialCalculator::NON_COMMERCIAL_FLAGS)) {
			$out[] = ['key' => 'non_commercial', 'label' => 'Rekord wygląda na instytucję / organizację / media / katalog, nie typowe przedsiębiorstwo.'];
		}

		if ((int) ($c['discrepancies'] ?? 0) > 0) {
			$out[] = ['key' => 'data_conflicts', 'label' => sprintf('Rozbieżności danych przy weryfikacji: %d.', (int) $c['discrepancies'])];
		}

		return $out;
	}

	/**
	 * Data Confidence — pewność oceny (osobno od potencjału i od coverage).
	 *
	 * @param array<string, mixed> $s
	 * @param array<string, mixed> $lead
	 */
	public static function confidence(array $s, array $lead): string
	{
		$c = $s['company'];
		$a = $s['analysis'] ?? null;
		$cov = (int) $lead['coverage']['percent'];
		$fresh = $a && in_array($a['status'] ?? '', ['completed', 'partial'], true) && ! empty($a['fresh']);

		if ($cov >= 80 && ($c['verification_status'] ?? '') === 'verified' && ($c['domain_status'] ?? '') === 'confirmed' && $fresh) {
			return 'high';
		}

		if ($cov >= 55 && ! in_array($c['verification_status'] ?? 'pending', ['pending', ''], true)) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Recommended Next Action — reguły w ustalonej kolejności (pierwsza pasująca wygrywa; pozostałe trafione = „również”).
	 *
	 * @param array<string, mixed> $s
	 * @param array<string, mixed> $lead
	 * @param array<string, mixed> $assessment
	 * @param list<array<string, mixed>> $blockers
	 * @return array{key: string, label: string, label_pl: string, reason: string, also: list<string>}
	 */
	public static function recommend(array $s, array $lead, array $assessment, array $blockers, string $confidence): array
	{
		$c = $s['company'];
		$score = $lead['score'];
		$cov = (int) $lead['coverage']['percent'];
		$keys = array_column($blockers, 'key');
		$ct = $s['contacts'] ?? [];
		$people = array_filter((array) ($ct['people'] ?? []), fn ($p) => in_array($p['status'] ?? '', ['verified', 'probable'], true));
		$channel = ((int) ($ct['channels'] ?? 0)) > 0;
		$fit = $lead['components']['business'];
		$fitRatio = $fit['measured'] && $fit['measured_max'] > 0 ? $fit['points'] / $fit['measured_max'] : null;
		$v = (string) ($c['verification_status'] ?? 'pending');
		$d = (string) ($c['domain_status'] ?? 'unchecked');
		$hits = [];

		$rules = [
			['do_not_contact', (bool) array_intersect($keys, ['inactive', 'suspended', 'excluded']), 'Twarda przeszkoda: ' . implode(' ', array_column(array_filter($blockers, fn ($b) => in_array($b['key'], ['inactive', 'suspended', 'excluded'], true)), 'label'))],
			['verify_company', in_array('domain_mismatch', $keys, true), 'Domena nie należy do tej firmy — najpierw ustal właściwą stronę.'],
			['low_priority', (bool) array_intersect((array) ($c['fit_flags'] ?? []), LeadPotentialCalculator::NON_COMMERCIAL_FLAGS), 'Rekord nie wygląda na przedsiębiorstwo, które kupuje stronę komercyjną.'],
			['research_further', $lead['status'] === 'INSUFFICIENT_DATA', 'Za mało danych, by ocenić potencjał — uzupełnij weryfikację lub analizę strony.'],
			['verify_company', in_array($v, ['pending', 'uncertain', ''], true) || empty($c['domain']) || in_array($d, ['unchecked', 'uncertain', 'unavailable', ''], true), 'Tożsamość firmy lub domena nie jest potwierdzona.'],
			['research_further', ! $lead['components']['website']['measured'], 'Brak (udanej) analizy strony — uruchom ją ręcznie, jeśli firma Cię interesuje.'],
			['research_further', $assessment['key'] === 'insufficient_data', 'Analiza strony ma za mało pomiarów do oceny redesignu.'],
			['low_priority', $score !== null && $score < 40, 'Niski potencjał przy obecnych danych.'],
			['review_website', $assessment['key'] === 'weak' && $fitRatio !== null && $fitRatio >= 0.6, 'Pomiary automatyczne nie pokazują istotnych problemów, ale firma dobrze pasuje — oceń wygląd strony ręcznie (brak oceny wizualnej).'],
			['low_priority', $assessment['key'] === 'weak', 'Pomiary nie wskazują istotnych przesłanek do redesignu.'],
			['review_website', $assessment['key'] === 'moderate', 'Wykryto problemy, ale ich znaczenie wymaga ręcznej oceny strony.'],
			['research_further', $assessment['key'] === 'strong' && ($cov < 60 || $confidence === 'low'), 'Silne przesłanki, ale niska kompletność / pewność danych.'],
			['find_decision_maker', $assessment['key'] === 'strong' && (empty($ct['researched']) || (! $people && ! $channel)), 'Dobry kandydat — brakuje osoby lub kanału do kontaktu.'],
			['contact_now', $assessment['key'] === 'strong' && $score !== null && $score >= 55, 'Wiarygodna firma, potwierdzona domena, silne przesłanki do redesignu i wystarczające dane.'],
			['review_website', true, 'Wynik wymaga ręcznej oceny.'],
		];

		foreach ($rules as [$key, $cond, $reason]) {
			if ($cond) {
				$hits[] = [$key, $reason];
			}
		}

		[$key, $reason] = $hits[0];
		// Pozostałe trafione reguły (bez reguły domyślnej) — pokazywane jako „również do rozważenia” (konflikt rekomendacji jawny).
		array_pop($hits);
		$also = array_values(array_diff(array_unique(array_map(fn ($h) => $h[0], array_slice($hits, 1))), [$key]));

		return ['key' => $key, 'label' => self::ACTIONS[$key], 'label_pl' => self::ACTION_LABELS_PL[$key], 'reason' => $reason, 'also' => array_slice($also, 0, 3)];
	}

	/**
	 * Smart Lead Ranking — ranking score uwzględnia pewność danych, kompletność, blokery i aktualność.
	 *
	 * @param array<string, mixed> $s
	 * @param array<string, mixed> $lead
	 * @param list<array<string, mixed>> $blockers
	 * @return array{score: ?float, confidence: string, explanation: list<string>}
	 */
	public static function rank(array $s, array $lead, array $blockers, string $confidence): array
	{
		if ($blockers) {
			return ['score' => 0.0, 'confidence' => $confidence, 'explanation' => ['Twarda przeszkoda — firma na końcu rankingu: ' . $blockers[0]['label']]];
		}

		if ($lead['score'] === null) {
			return ['score' => null, 'confidence' => 'low', 'explanation' => ['Brak wyniku (za mało danych) — poza rankingiem do czasu uzupełnienia danych.']];
		}

		$cov = (int) $lead['coverage']['percent'];
		$factor = 0.6 + 0.4 * $cov / 100;
		$a = $s['analysis'] ?? null;
		$stale = $a && in_array($a['status'] ?? '', ['completed', 'partial'], true) && empty($a['fresh']);
		$score = $lead['score'] * $factor * ($stale ? 0.9 : 1);
		$exp = [
			sprintf('Lead Potential %d/100.', $lead['score']),
			sprintf('Kompletność danych %d%% → mnożnik %.2f (wynik przy niepełnych danych jest mniej pewny).', $cov, $factor),
		];

		if ($stale) {
			$exp[] = 'Analiza strony nieaktualna → mnożnik 0,90.';
		}

		$exp[] = 'Ranking score: ' . number_format($score, 1, ',', '') . ' (remis: Lead Potential, kompletność, ID firmy).';

		return ['score' => round($score, 1), 'confidence' => $confidence, 'explanation' => $exp];
	}
}
