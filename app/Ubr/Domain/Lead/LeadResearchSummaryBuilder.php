<?php

namespace App\Ubr\Domain\Lead;

/**
 * Lead Research Summary + Sales Talking Points — deterministyczne szablony po polsku, wyłącznie z zapisanych danych.
 * Bez płatnego AI, bez wymyślonych przychodów, bez twierdzeń o utracie klientów, bez oceny wyglądu (AI Vision wyłączone).
 * Każdy argument sprzedażowy wskazuje wniosek (insight) i jego dowód — bez dowodu argument nie powstaje.
 */
final class LeadResearchSummaryBuilder
{
	private const VERIFICATION = [
		'verified' => 'zweryfikowana w rejestrze publicznym',
		'uncertain' => 'z niejednoznaczną weryfikacją w rejestrze',
		'inactive' => 'oznaczona w rejestrze jako NIEAKTYWNA',
		'suspended' => 'z ZAWIESZONĄ działalnością (rejestr)',
	];

	private const DOMAIN = [
		'confirmed' => 'Domena została potwierdzona jako strona firmy.',
		'uncertain' => 'Zgodność domeny z firmą jest niepotwierdzona.',
		'mismatch' => 'Domena prawdopodobnie należy do innego podmiotu.',
		'unavailable' => 'Strona była niedostępna przy weryfikacji domeny.',
	];

	/**
	 * @param array<string, mixed> $s
	 * @param array<string, mixed> $lead
	 * @param list<array<string, mixed>> $insights
	 * @param array<string, mixed> $assessment
	 * @param array<string, mixed> $recommendation
	 * @param list<array<string, mixed>> $blockers
	 * @param list<array<string, mixed>> $warnings
	 * @return list<array{key: string, title: string, text: string}>
	 */
	public static function summary(array $s, array $lead, array $insights, array $assessment, array $recommendation, array $blockers, array $warnings): array
	{
		$c = $s['company'];
		$sections = [];

		// 1. Company overview.
		$segment = self::segmentLabel($lead);
		$parts = [];
		$parts[] = 'Firma ' . (self::VERIFICATION[$c['verification_status'] ?? ''] ?? 'jeszcze niezweryfikowana w rejestrze') . ($segment ? ', segment: ' . $segment : '') . '.';
		$emp = (int) ($c['employees'] ?? 0);
		$parts[] = $emp > 0 ? sprintf('Ok. %d pracowników (wg źródła danych).', $emp) : 'Liczba pracowników nieznana.';

		if (! empty($c['domain'])) {
			$parts[] = self::DOMAIN[$c['domain_status'] ?? ''] ?? 'Domena nie była jeszcze sprawdzana.';
		} else {
			$parts[] = 'Firma nie ma przypisanej strony WWW.';
		}

		$sections[] = ['key' => 'overview', 'title' => 'Company overview', 'text' => implode(' ', $parts)];

		// 2. Website condition.
		$a = $s['analysis'] ?? null;

		if (! $a) {
			$text = 'Strona nie była analizowana — brak danych o jej stanie (to nie znaczy, że jest dobra).';
		} elseif (($a['status'] ?? '') === 'failed') {
			$text = 'Ostatnia analiza nie powiodła się — strona nie odpowiadała podczas pomiaru.';
		} else {
			$top = array_slice(array_filter($insights, fn ($i) => in_array($i['severity'], ['medium', 'high', 'critical'], true)), 0, 3);
			$text = $top
				? 'Analiza strony (' . self::date((string) ($a['finished_at'] ?? '')) . ') wykazała: ' . implode('; ', array_map(fn ($i) => mb_strtolower(mb_substr($i['title'], 0, 1)) . mb_substr($i['title'], 1), $top)) . '.'
				: 'Analiza strony (' . self::date((string) ($a['finished_at'] ?? '')) . ') nie wykazała problemów o znaczeniu ≥ średnim.';

			if (empty($a['rendered'])) {
				$text .= ' Analiza bez renderu — brak pomiarów mobile i wydajności w przeglądarce.';
			}

			if (empty($a['fresh'])) {
				$text .= sprintf(' Analiza jest starsza niż %d dni.', LeadPotentialCalculator::FRESH_DAYS);
			}
		}

		$sections[] = ['key' => 'website', 'title' => 'Website condition', 'text' => $text];

		// 3. Redesign opportunity.
		$text = match ($assessment['key']) {
			'strong' => 'Silne przesłanki do redesignu: kilka niezależnych obszarów z istotnymi problemami.',
			'moderate' => 'Umiarkowane przesłanki do redesignu — wykryte problemy wymagają ręcznej oceny strony.',
			'weak' => 'Automatyczne pomiary nie wskazują istotnych problemów. Nie oznacza to, że strona jest nowoczesna — wygląd nie był oceniany.',
			default => 'Za mało danych, by ocenić szansę na redesign.',
		};
		$sections[] = ['key' => 'redesign', 'title' => 'Redesign opportunity', 'text' => $text . ' Warstwa wizualna nie była oceniana automatycznie (AI Vision wyłączone).'];

		// 4. Business fit.
		$rel = $s['relevance'] ?? null;
		$fit = $lead['components']['business'];
		$text = ($rel !== null ? sprintf('Trafność względem wyszukiwań organizacji: %d/100. ', (int) $rel) : 'Brak wyniku trafności. ')
			. ($fit['measured'] ? sprintf('Business Fit: %s z %d pkt możliwych do oceny.', self::num((float) $fit['points']), (int) $fit['measured_max']) : 'Business Fit nieoceniony (brak danych).')
			. ' Budżetu firmy nie szacujemy.';
		$sections[] = ['key' => 'fit', 'title' => 'Business fit', 'text' => $text];

		// 5. Decision makers.
		$ct = $s['contacts'] ?? [];

		if (empty($ct['researched'])) {
			$text = 'Decydentów jeszcze nie wyszukiwano.';
		} else {
			$people = array_values(array_filter((array) ($ct['people'] ?? []), fn ($p) => in_array($p['status'] ?? '', ['verified', 'probable'], true)));
			$verified = count(array_filter($people, fn ($p) => ($p['status'] ?? '') === 'verified'));
			$text = $people
				? sprintf('Znaleziono %d %s (zweryfikowanych: %d). Top 1: %s%s.', count($people), count($people) === 1 ? 'osobę decyzyjną' : 'osoby decyzyjne', $verified, $people[0]['name'], ! empty($people[0]['title']) ? ' — ' . $people[0]['title'] : '')
				: 'Nie znaleziono wiarygodnej osoby decyzyjnej' . (((int) ($ct['channels'] ?? 0)) > 0 ? ', jest natomiast oficjalny kanał kontaktu firmy.' : '.');
		}

		$sections[] = ['key' => 'contacts', 'title' => 'Decision makers', 'text' => $text];

		// 6. Risks & missing data.
		$risks = array_merge(array_column($blockers, 'label'), array_column($warnings, 'label'));
		$missing = $lead['coverage']['missing'];
		$text = ($risks ? implode(' ', array_slice($risks, 0, 5)) : 'Brak istotnych ryzyk w danych.')
			. ($missing ? ' Brakujące dane: ' . implode(', ', $missing) . '.' : '');
		$sections[] = ['key' => 'risks', 'title' => 'Risks & missing data', 'text' => $text];

		// 7. Recommended next action.
		$sections[] = ['key' => 'action', 'title' => 'Recommended next action', 'text' => 'Rekomendacja: ' . strtoupper($recommendation['label']) . ' — ' . $recommendation['reason']];

		return $sections;
	}

	/**
	 * Potential Sales Angles — tylko z wniosków ≥ MEDIUM, każdy z odwołaniem do wniosku i dowodu.
	 *
	 * @param list<array<string, mixed>> $insights
	 * @param array<string, mixed> $assessment
	 * @return list<array{insight_key: string, angle: string, evidence: string, severity: string}>
	 */
	public static function talkingPoints(array $insights, array $assessment): array
	{
		if ($assessment['key'] === 'insufficient_data') {
			return [];
		}

		$out = [];

		foreach ($insights as $i) {
			if (! in_array($i['severity'], ['medium', 'high', 'critical'], true) || empty($i['angle']) || empty($i['evidence'])) {
				continue;
			}

			$e = $i['evidence'][0];
			$out[] = ['insight_key' => $i['key'], 'angle' => $i['angle'], 'evidence' => trim($e['measure'] . ($e['detail'] !== '' ? ': ' . $e['detail'] : '')), 'severity' => $i['severity']];
		}

		return array_slice($out, 0, 5);
	}

	/**
	 * „Why this company?” — 2–4 konkretne powody z danych (lub informacja, że ich brak).
	 *
	 * @param array<string, mixed> $s
	 * @param array<string, mixed> $lead
	 * @param array<string, mixed> $assessment
	 * @return list<string>
	 */
	public static function why(array $s, array $lead, array $assessment): array
	{
		$out = [];
		$c = $s['company'];

		if (in_array($assessment['key'], ['strong', 'moderate'], true)) {
			$out[] = ($assessment['key'] === 'strong' ? 'Silne' : 'Umiarkowane') . ' przesłanki do redesignu w pomiarach strony (' . implode(', ', array_map(fn ($k) => InsightRuleRegistry::CATEGORIES[$k] ?? $k, $assessment['categories'])) . ').';
		}

		if (($s['relevance'] ?? null) !== null && (int) $s['relevance'] >= 60) {
			$out[] = 'Wysoka zgodność z kryteriami wyszukiwania (Relevance ' . (int) $s['relevance'] . ').';
		}

		if (($c['verification_status'] ?? '') === 'verified' && ($c['domain_status'] ?? '') === 'confirmed') {
			$out[] = 'Firma zweryfikowana w rejestrze, domena potwierdzona.';
		}

		$segment = self::segmentLabel($lead);

		if ($segment && ($lead['components']['business']['items'][1]['points'] ?? 0) >= 7) {
			$out[] = 'Segment pasujący do oferty: ' . $segment . '.';
		}

		if (! $out) {
			$out[] = 'Brak wyraźnych powodów w obecnych danych — sprawdź braki danych poniżej.';
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $lead
	 */
	private static function segmentLabel(array $lead): ?string
	{
		foreach ($lead['components']['business']['items'] as $item) {
			if ($item['key'] === 'segment' && $item['points'] !== null && preg_match('/^Segment: ([^—]+) —/u', $item['detail'], $m)) {
				return trim($m[1]);
			}
		}

		return null;
	}

	private static function date(string $iso): string
	{
		$ts = strtotime($iso);

		return $ts ? wp_date('d.m.Y', $ts) : '—';
	}

	private static function num(float $v): string
	{
		return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
	}
}
