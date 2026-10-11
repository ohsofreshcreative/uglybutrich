<?php

namespace App\Ubr\Domain\ActionCenter;

/**
 * Action Priority Engine (Prompt 6) — CO operator powinien zrobić TERAZ. Odrębny od Lead Potential (JAK atrakcyjna jest firma).
 *
 * Zasady (deterministyczne, bez wag i „pozornie precyzyjnego” wyniku):
 * 1. Kategorie (tier) w stałej kolejności — zobowiązania handlowe przed wszystkim innym:
 *    1 FOLLOW_UP_OVERDUE → 2 FOLLOW_UP_DUE → 3 CONTACT_READY → 4 FIND_DECISION_MAKER → 5 REVIEW_WEBSITE → 6 VERIFY_COMPANY
 *    → 7 ANALYSIS_MISSING → 8 RESEARCH_FURTHER. Wysoki Lead Potential nigdy nie wypiera zaległego follow-upu.
 * 2. Typ działania dla firmy = pierwsza pasująca reguła `classify()` (ta sama logika w SQL: `sqlType()`; test porównuje obie).
 *    Rekomendacja pochodzi z Lead Intelligence (bez zmiany jej algorytmu); silnik tylko ją filtruje i porządkuje.
 * 3. Remisy w kategorii: priorytet prospekta (urgent → high → pozostałe), Smart Ranking (rank_score), Lead Potential,
 *    Data Coverage, prospekt przed nie-prospektem, ID firmy (stabilnie). Follow-upy: termin rosnąco, potem ID.
 * 4. Deduplikacja: firma występuje w „Today's Priorities” najwyżej raz — follow-upy grupowane per firma (najwcześniejszy
 *    termin + liczba pozostałych), a firma z otwartym follow-upem nie dostaje dodatkowo działania z rekomendacji.
 */
final class ActionPriorityEngine
{
	public const FOLLOW_UP_OVERDUE = 'follow_up_overdue';
	public const FOLLOW_UP_DUE = 'follow_up_due';
	public const CONTACT_READY = 'contact_ready';
	public const FIND_DECISION_MAKER = 'find_decision_maker';
	public const REVIEW_WEBSITE = 'review_website';
	public const VERIFY_COMPANY = 'verify_company';
	public const ANALYSIS_MISSING = 'analysis_missing';
	public const RESEARCH_FURTHER = 'research_further';

	/** Kategorie: kolejność = tier; priorytet to etykieta pochodna od kategorii (nie liczba). */
	public const TYPES = [
		self::FOLLOW_UP_OVERDUE => ['tier' => 1, 'priority' => 'critical', 'label' => 'Follow-up overdue', 'label_pl' => 'Zaległy follow-up', 'cta' => 'Otwórz sprzedaż'],
		self::FOLLOW_UP_DUE => ['tier' => 2, 'priority' => 'high', 'label' => 'Follow-up due today', 'label_pl' => 'Follow-up na dziś', 'cta' => 'Otwórz sprzedaż'],
		self::CONTACT_READY => ['tier' => 3, 'priority' => 'high', 'label' => 'Contact ready', 'label_pl' => 'Gotowa do kontaktu', 'cta' => 'Przygotuj kontakt'],
		self::FIND_DECISION_MAKER => ['tier' => 4, 'priority' => 'medium', 'label' => 'Find decision maker', 'label_pl' => 'Znajdź decydenta', 'cta' => 'Znajdź decydentów'],
		self::REVIEW_WEBSITE => ['tier' => 5, 'priority' => 'medium', 'label' => 'Review website', 'label_pl' => 'Oceń stronę ręcznie', 'cta' => 'Podgląd strony'],
		self::VERIFY_COMPANY => ['tier' => 6, 'priority' => 'medium', 'label' => 'Verify company', 'label_pl' => 'Zweryfikuj firmę / domenę', 'cta' => 'Zweryfikuj'],
		self::ANALYSIS_MISSING => ['tier' => 7, 'priority' => 'low', 'label' => 'Analysis missing', 'label_pl' => 'Brak analizy strony', 'cta' => 'Analizuj stronę'],
		self::RESEARCH_FURTHER => ['tier' => 8, 'priority' => 'low', 'label' => 'Research further', 'label_pl' => 'Uzupełnij dane', 'cta' => 'Karta firmy'],
	];

	/** Działania wynikające z rekomendacji (mogą być odłożone ręcznie); follow-upy przekłada się zmianą terminu. */
	public const SNOOZABLE = [self::CONTACT_READY, self::FIND_DECISION_MAKER, self::REVIEW_WEBSITE, self::VERIFY_COMPANY, self::ANALYSIS_MISSING, self::RESEARCH_FURTHER];

	/** Statusy zamknięte — brak działań z rekomendacji. */
	public const CLOSED_STATUSES = ['won', 'lost', 'not_a_fit'];

	/** Trwająca rozmowa handlowa — pracą sterują follow-upy, nie rekomendacja. */
	public const CONVERSATION_STATUSES = ['contacted', 'follow_up', 'in_discussion', 'proposal_sent'];

	/** Prospekt przed kontaktem (kolejka „Find Decision Maker” obejmuje takie firmy bez wyszukanych decydentów). */
	public const PRE_CONTACT_STATUSES = ['new', 'qualified', 'to_contact'];

	/** Analiza strony z danymi (PARTIAL = częściowe pomiary, nadal dowód). */
	public const ANALYZED = ['completed', 'partial'];

	/**
	 * Próg „High Potential”: Lead Potential ≥ 70. Uzasadnienie: bez danych o stronie wynik jest ograniczony do 65
	 * (LeadPotentialCalculator::CAP_WITHOUT_WEBSITE), więc ≥ 70 oznacza wysoki potencjał POTWIERDZONY pomiarami strony.
	 */
	public const HIGH_POTENTIAL = 70;

	/**
	 * Typ działania z rekomendacji dla firmy albo null (brak działania). Sygnały:
	 * has_li, has_blocker, do_not_contact, recommendation, analysis_status, domain, prospect_status, prospect_archived, has_open_followup.
	 *
	 * @param array<string, mixed> $s
	 * @return array{type: string, rule: string}|null
	 */
	public static function classify(array $s): ?array
	{
		if (empty($s['has_li'])) {
			return null; // ocena Lead Intelligence jeszcze nieobliczona — firma „czeka na ocenę”, bez zgadywania
		}

		$status = $s['prospect_status'] ?? null;

		if (! empty($s['prospect_archived'])) {
			return null; // prospekt zarchiwizowany — decyzja operatora
		}

		if (! empty($s['has_blocker'])) {
			return null; // hard blocker → nigdy rekomendacja kontaktu (kolejka „Blocked”)
		}

		if (! empty($s['do_not_contact'])) {
			return null; // zakaz kontaktu w organizacji (Prompt 10) — nigdy w priorytetach ani kolejkach kontaktu
		}

		if ($status !== null && in_array($status, self::CLOSED_STATUSES, true)) {
			return null;
		}

		if ($status !== null && in_array($status, self::CONVERSATION_STATUSES, true)) {
			return null;
		}

		if (! empty($s['has_open_followup'])) {
			return null; // dedup: zaplanowane działanie już istnieje (follow-up)
		}

		// „Przeanalizowana” = istnieje zakończona analiza (analyzed_at), a nie status OSTATNIEGO zlecenia — ponowna
		// analiza w kolejce lub nieudana nie może cofać firmy do „Brak analizy” ani wyjmować z „Gotowe do kontaktu”.
		$analyzed = ! empty($s['analyzed_at']) || in_array((string) ($s['analysis_status'] ?? ''), self::ANALYZED, true);
		$hasDomain = trim((string) ($s['domain'] ?? '')) !== '';

		return match ((string) ($s['recommendation'] ?? '')) {
			'contact_now' => $analyzed
				? ['type' => self::CONTACT_READY, 'rule' => 'contact_now']
				: ['type' => self::ANALYSIS_MISSING, 'rule' => 'contact_now_without_analysis'], // bez analizy nigdy „gotowa do kontaktu”
			'find_decision_maker' => ['type' => self::FIND_DECISION_MAKER, 'rule' => 'find_decision_maker'],
			'review_website' => ['type' => self::REVIEW_WEBSITE, 'rule' => 'review_website'],
			'verify_company' => ['type' => self::VERIFY_COMPANY, 'rule' => 'verify_company'],
			'research_further' => $hasDomain && ! $analyzed
				? ['type' => self::ANALYSIS_MISSING, 'rule' => 'research_missing_analysis']
				: ['type' => self::RESEARCH_FURTHER, 'rule' => 'research_further'],
			default => null, // low_priority / do_not_contact
		};
	}

	/**
	 * To samo co classify() jako wyrażenie SQL (aliasy: li, c, p). Stałe z whitelist — bez danych użytkownika.
	 */
	public static function sqlType(): string
	{
		$in = fn (array $v) => "'" . implode("','", $v) . "'";
		$analyzed = "(c.analyzed_at IS NOT NULL OR COALESCE(c.analysis_status, '') IN (" . $in(self::ANALYZED) . '))';

		return "CASE
			WHEN li.id IS NULL THEN NULL
			WHEN p.id IS NOT NULL AND p.archived_at IS NOT NULL THEN NULL
			WHEN li.has_blocker = 1 THEN NULL
			WHEN p.do_not_contact = 1 THEN NULL
			WHEN p.status IN (" . $in(self::CLOSED_STATUSES) . ") THEN NULL
			WHEN p.status IN (" . $in(self::CONVERSATION_STATUSES) . ") THEN NULL
			WHEN p.next_action_at IS NOT NULL THEN NULL
			WHEN li.recommendation = 'contact_now' AND {$analyzed} THEN '" . self::CONTACT_READY . "'
			WHEN li.recommendation = 'contact_now' THEN '" . self::ANALYSIS_MISSING . "'
			WHEN li.recommendation = 'find_decision_maker' THEN '" . self::FIND_DECISION_MAKER . "'
			WHEN li.recommendation = 'review_website' THEN '" . self::REVIEW_WEBSITE . "'
			WHEN li.recommendation = 'verify_company' THEN '" . self::VERIFY_COMPANY . "'
			WHEN li.recommendation = 'research_further' AND COALESCE(c.domain, '') <> '' AND NOT ({$analyzed}) THEN '" . self::ANALYSIS_MISSING . "'
			WHEN li.recommendation = 'research_further' THEN '" . self::RESEARCH_FURTHER . "'
			ELSE NULL END";
	}

	/**
	 * Kolejność działań z rekomendacji (kategoria + stabilne remisy). Kolumny: x.action_type, x.p_priority, x.prospect_id,
	 * x.rank_score, x.lead_score, x.coverage, x.company_id.
	 */
	public static function sqlOrder(): string
	{
		$types = "'" . implode("','", array_keys(self::TYPES)) . "'";

		return "FIELD(x.action_type, {$types}) ASC,
			CASE x.p_priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END ASC,
			(x.rank_score IS NULL) ASC, x.rank_score DESC, COALESCE(x.lead_score, -1) DESC, x.coverage DESC,
			(x.prospect_id IS NULL) ASC, x.company_id ASC";
	}

	/**
	 * Porównanie w PHP (testy stabilności; ta sama kolejność co sqlOrder()).
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function compare(array $a, array $b): int
	{
		$pri = fn ($r) => match ($r['p_priority'] ?? null) {
			'urgent' => 0,
			'high' => 1,
			default => 2,
		};
		$keyA = [self::TYPES[$a['action_type']]['tier'], $pri($a), $a['rank_score'] === null ? 1 : 0, -(float) ($a['rank_score'] ?? 0), -($a['lead_score'] ?? -1), -(int) $a['coverage'], empty($a['prospect_id']) ? 1 : 0, (int) $a['company_id']];
		$keyB = [self::TYPES[$b['action_type']]['tier'], $pri($b), $b['rank_score'] === null ? 1 : 0, -(float) ($b['rank_score'] ?? 0), -($b['lead_score'] ?? -1), -(int) $b['coverage'], empty($b['prospect_id']) ? 1 : 0, (int) $b['company_id']];

		return $keyA <=> $keyB;
	}

	/**
	 * Przyczyna działania (czytelne uzasadnienie dla operatora).
	 *
	 * @param array<string, mixed> $row
	 */
	public static function reason(string $type, array $row): string
	{
		$li = trim((string) ($row['li_reason'] ?? ''));

		return match ($type) {
			self::FOLLOW_UP_OVERDUE => 'Termin follow-upu minął — zobowiązanie handlowe ma pierwszeństwo przed nowymi szansami.',
			self::FOLLOW_UP_DUE => 'Follow-up zaplanowany na dziś.',
			self::CONTACT_READY => $li !== '' ? $li : 'Lead Intelligence rekomenduje kontakt (strona przeanalizowana, brak przeszkód).',
			self::ANALYSIS_MISSING => ($row['rule'] ?? '') === 'contact_now_without_analysis'
				? 'Brak zakończonej analizy strony — przed kontaktem potrzebny jest dowód słabości strony.'
				: 'Brak analizy strony — Lead Potential jest wstępny (limit 65). Brak analizy nie oznacza słabej firmy.',
			default => $li !== '' ? $li : (self::TYPES[$type]['label_pl'] ?? $type),
		};
	}
}
