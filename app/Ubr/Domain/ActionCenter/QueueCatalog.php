<?php

namespace App\Ubr\Domain\ActionCenter;

/**
 * Smart Queues i KPI (Prompt 6) — definicje w jednym miejscu (UI, API, dokumentacja i testy korzystają z tych samych opisów).
 *
 * Zakres danych (tenant-private): „pula organizacji” = firmy z wyników wyszukiwań TEJ organizacji + jej aktywni prospekci
 * (to samo, co ocenia Lead Intelligence). Nigdy cała globalna baza firm. Zarchiwizowani prospekci są pomijani (decyzja
 * operatora). Filtr `scope`: all (pula) | prospects (tylko aktywni prospekci) | not_prospects (jeszcze nie w Prospects).
 * Kolejka Follow-ups zawiera wyłącznie prywatne follow-upy prospektów organizacji.
 *
 * Warunki SQL operują na kolumnach widoku bazowego (alias x) z ActionCenterRepository — wyłącznie stałe, bez danych wejściowych.
 */
final class QueueCatalog
{
	public const QUEUES = [
		'top_opportunities' => [
			'label' => 'Top Opportunities',
			'label_pl' => 'Najlepsze okazje',
			'definition' => 'Smart Lead Ranking z Lead Intelligence (Lead Potential × kompletność danych, kara za nieaktualną analizę), bez firm z hard blockers i zamkniętych. Kolejność rankingu bez zmian — nie sortujemy wyłącznie po Lead Potential.',
			'scope' => 'pool',
		],
		'needs_attention' => [
			'label' => 'Needs Attention',
			'label_pl' => 'Wymaga uwagi',
			'definition' => 'Firmy wymagające działania przed kontaktem — jedna główna kategoria na firmę (Find decision maker / Review website / Verify company / Missing website analysis / Research further, wg Recommended Next Action); pozostałe braki jako informacje pomocnicze.',
			'scope' => 'pool',
		],
		'ready_to_contact' => [
			'label' => 'Ready to Contact',
			'label_pl' => 'Gotowe do kontaktu',
			'definition' => 'Lead Intelligence rekomenduje kontakt (Contact now), analiza strony zakończona, brak hard blockers, firma nie jest w trakcie rozmowy ani zamknięta i nie ma już zaplanowanego follow-upu.',
			'scope' => 'pool',
		],
		'review_website' => [
			'label' => 'Review Website',
			'label_pl' => 'Ocena strony',
			'definition' => 'Rekomendacja „Review website”: potwierdzone problemy strony wymagają ręcznej oceny wyglądu (AI Vision wyłączone) przed kontaktem.',
			'scope' => 'pool',
		],
		'needs_research' => [
			'label' => 'Needs Research',
			'label_pl' => 'Do uzupełnienia',
			'definition' => 'Rekomendacja „Research further”, a analiza strony istnieje lub firma nie ma domeny — brakuje innych danych (np. weryfikacji, pomiarów).',
			'scope' => 'pool',
		],
		'verify_company' => [
			'label' => 'Verify Company',
			'label_pl' => 'Weryfikacja firmy',
			'definition' => 'Rekomendacja „Verify company”: firma lub domena niepotwierdzona w rejestrach albo dane sprzeczne.',
			'scope' => 'pool',
		],
		'find_decision_maker' => [
			'label' => 'Find Decision Maker',
			'label_pl' => 'Znajdź decydenta',
			'definition' => 'Rekomendacja „Find decision maker” ALBO aktywny prospekt przed kontaktem (Nowy / Zakwalifikowany / Do kontaktu) bez wyszukanych decydentów, bez blokera i bez rekomendacji niskiego priorytetu. Brak decydenta nie obniża potencjału.',
			'scope' => 'pool',
		],
		'missing_analysis' => [
			'label' => 'Missing Website Analysis',
			'label_pl' => 'Brak analizy strony',
			'definition' => 'Firma ma domenę, ale nie ma zakończonej analizy strony (brak, w kolejce, w toku lub błąd); bez blokera i poza statusami zamkniętymi. Analizę uruchamia operator — nic nie startuje automatycznie.',
			'scope' => 'pool',
		],
		'high_potential' => [
			'label' => 'High Potential',
			'label_pl' => 'Wysoki potencjał',
			'definition' => 'Lead Potential ≥ 70 (wymaga pomiarów strony — bez nich limit to 65), bez hard blockers, poza statusami zamkniętymi.',
			'scope' => 'pool',
		],
		'blocked' => [
			'label' => 'Blocked',
			'label_pl' => 'Wykluczone (hard blockers)',
			'definition' => 'Firmy z hard blocker (nieaktywna / zawieszona w rejestrze, domena innego podmiotu, oznaczona przez organizację jako „nie pasuje”) — wyłączone z rekomendacji kontaktu.',
			'scope' => 'pool',
		],
		'followups' => [
			'label' => 'Follow-ups',
			'label_pl' => 'Follow-upy',
			'definition' => 'Otwarte follow-upy aktywnych prospektów organizacji, od najstarszego terminu.',
			'scope' => 'prospects',
		],
	];

	/** KPI na Dashboardzie (kolejność wyświetlania) → kolejka do drill-down. */
	public const KPIS = [
		'total_prospects' => ['label' => 'Total Prospects', 'queue' => null, 'definition' => 'Firmy zapisane do Prospects przez organizację (aktywne, bez zarchiwizowanych). Firmy odkryte w wyszukiwaniach liczone osobno.'],
		'followups_due' => ['label' => 'Follow-ups Due', 'queue' => 'followups', 'bucket' => 'due', 'definition' => 'Otwarte follow-upy zaległe (termin minął) + na dziś (do końca dnia w strefie czasowej WordPressa).'],
		'ready_to_contact' => ['label' => 'Ready to Contact', 'queue' => 'ready_to_contact'],
		'high_potential' => ['label' => 'High Potential', 'queue' => 'high_potential'],
		'needs_review' => ['label' => 'Needs Review', 'queue' => 'review_website', 'definition' => 'Firmy z rekomendacją Review website (ręczna ocena strony przed kontaktem).'],
		'missing_analysis' => ['label' => 'Missing Analysis', 'queue' => 'missing_analysis'],
	];

	public const SCOPES = ['all', 'prospects', 'not_prospects'];

	public const OWNERS = ['all', 'mine', 'unassigned'];

	public const SORTS = ['priority', 'rank', 'lead', 'coverage', 'name'];

	/** Domyślne sortowanie kolejki (Top Opportunities = Smart Ranking). */
	public const DEFAULT_SORT = ['top_opportunities' => 'rank'];

	public static function exists(string $key): bool
	{
		return isset(self::QUEUES[$key]);
	}

	/**
	 * Warunek SQL kolejki firm (na kolumnach x.* widoku bazowego).
	 */
	public static function where(string $key): string
	{
		$in = fn (array $v) => "'" . implode("','", $v) . "'";
		$open = "(x.prospect_status IS NULL OR x.prospect_status NOT IN (" . $in(ActionPriorityEngine::CLOSED_STATUSES) . '))';
		$analyzed = "(x.analyzed_at IS NOT NULL OR COALESCE(x.analysis_status, '') IN (" . $in(ActionPriorityEngine::ANALYZED) . '))';

		$attention = "'" . implode("','", [ActionPriorityEngine::FIND_DECISION_MAKER, ActionPriorityEngine::REVIEW_WEBSITE, ActionPriorityEngine::VERIFY_COMPANY, ActionPriorityEngine::ANALYSIS_MISSING, ActionPriorityEngine::RESEARCH_FURTHER]) . "'";

		return match ($key) {
			'top_opportunities' => "x.rank_score IS NOT NULL AND x.has_blocker = 0 AND {$open}",
			'needs_attention' => "x.action_type IN ({$attention})",
			'ready_to_contact' => "x.action_type = '" . ActionPriorityEngine::CONTACT_READY . "'",
			'review_website' => "x.action_type = '" . ActionPriorityEngine::REVIEW_WEBSITE . "'",
			'needs_research' => "x.action_type = '" . ActionPriorityEngine::RESEARCH_FURTHER . "'",
			'verify_company' => "x.action_type = '" . ActionPriorityEngine::VERIFY_COMPANY . "'",
			'find_decision_maker' => "(x.action_type = '" . ActionPriorityEngine::FIND_DECISION_MAKER . "' OR (x.prospect_id IS NOT NULL AND x.prospect_status IN (" . $in(ActionPriorityEngine::PRE_CONTACT_STATUSES) . ") AND x.has_blocker = 0 AND x.contacts_status IS NULL AND x.recommendation NOT IN ('low_priority', 'do_not_contact')))",
			'missing_analysis' => "COALESCE(x.domain, '') <> '' AND NOT ({$analyzed}) AND x.has_blocker = 0 AND {$open}",
			'high_potential' => 'x.lead_score >= ' . ActionPriorityEngine::HIGH_POTENTIAL . " AND x.has_blocker = 0 AND {$open}",
			'blocked' => 'x.has_blocker = 1',
			default => '1 = 0',
		};
	}
}
