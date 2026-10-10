<?php

/**
 * Testy: Lead Intelligence (Prompt 5). Dołączane z tests/run.php (wspólne: $uA, $uB, $uM, $uX, $orgA, $orgB, $sB, $jobId,
 * $mkVisible, $db, $wpdb). Logika domenowa testowana na syntetycznych danych wejściowych (fikcyjne firmy), integracja
 * na tymczasowych firmach „Ubrtest” — bez zapytań zewnętrznych i bez nowych analiz.
 */

use App\Ubr\Domain\Lead\LeadPotentialCalculator;
use App\Ubr\Domain\Lead\LeadRecommender;
use App\Ubr\Domain\Lead\LeadResearchSummaryBuilder;
use App\Ubr\Domain\Lead\RedesignAssessor;
use App\Ubr\Domain\Lead\WebsiteInsightGenerator;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Services\LeadIntelligenceRunner;
use App\Ubr\Services\LeadIntelligenceService;

if (! defined('ABSPATH')) {
	exit(1);
}

$li = app(LeadIntelligenceService::class);
$liRepo = app(LeadIntelligenceRepository::class);
$liF = fn (string $key, string $cat, string $sev, string $src = 'automated', array $ev = ['pomiar: 1']) => ['key' => $key, 'category' => $cat, 'severity' => $sev, 'source' => $src, 'title' => 'T ' . $key, 'description' => 'D', 'evidence' => $ev, 'recommendation' => 'R'];
$liAn = fn (array $findings, int $points = 25, int $measured = 50, bool $rendered = true, bool $fresh = true, string $status = 'completed') => [
	'id' => 9001, 'status' => $status, 'finished_at' => gmdate('c'), 'fresh' => $fresh, 'rendered' => $rendered, 'ai_evaluated' => false, 'algorithm_version' => 'test',
	'criteria' => ['a_visual_ai' => ['points' => null, 'max' => 15], 'a_ux_structure' => ['points' => $points, 'max' => $measured]],
	'findings' => $findings,
];
$liStrong = [$liF('mobile_tap_targets', 'mobile', 'medium'), $liF('conv_no_cta', 'conversion', 'high'), $liF('ux_no_nav', 'ux', 'high'), $liF('a11y_labels', 'accessibility', 'medium')];
$liSnap = function (array $o = []) use ($liAn, $liStrong): array {
	$s = [
		'company' => ['id' => 1, 'name' => 'Fikcyjna Sp. z o.o.', 'domain' => 'fikcyjna.test', 'verification_status' => 'verified', 'domain_status' => 'confirmed', 'pkd_main' => '25.11.Z', 'sic' => null, 'employees' => 120, 'fit_flags' => [], 'discrepancies' => 0],
		'relevance' => 80,
		'analysis' => $liAn($liStrong),
		'contacts' => ['researched' => true, 'status' => 'completed', 'people' => [
			['name' => 'Jan Fikcyjny', 'title' => 'Prezes Zarządu', 'status' => 'verified', 'confidence' => 'high', 'score' => 75, 'email' => false, 'phone' => false],
			['name' => 'Anna Fikcyjna', 'title' => 'Dyrektor Marketingu', 'status' => 'verified', 'confidence' => 'medium', 'score' => 60, 'email' => false, 'phone' => false],
		], 'channels' => 1],
		'prospect' => null,
	];

	foreach ($o as $k => $v) {
		if (in_array($k, ['company', 'contacts'], true) && is_array($v)) {
			$s[$k] = array_merge($s[$k], $v);
		} else {
			$s[$k] = $v;
		}
	}

	return $s;
};

/* ======================================================================================
 * SCORING (1–15)
 * ==================================================================================== */
UbrT::group('[lead] Lead Potential Score — scoring, brak danych, blokery');

$full = $li->compute($liSnap());
UbrT::ok($full['lead']['status'] === 'SCORED' && $full['lead']['score'] >= 85 && $full['lead']['score'] <= 100 && $full['lead']['coverage']['percent'] === 100, '1. pełne dane: wynik SCORED (' . $full['lead']['score'] . '), kompletność 100%');
UbrT::ok(array_sum(array_map(fn ($c) => $c['max'], $full['lead']['components'])) === 100 && ! in_array(false, array_column($full['lead']['components'], 'measured'), true), '1. rozbicie: 4 składowe = 100 pkt, wszystkie zmierzone');

$noAn = $li->compute($liSnap(['analysis' => null]));
UbrT::ok($noAn['lead']['score'] !== null && $noAn['lead']['components']['website']['points'] === null && $noAn['lead']['status'] === 'PROVISIONAL', '2. brak analizy: składowa strony NIEZMIERZONA (nie 0), wynik PROVISIONAL');
UbrT::ok($noAn['lead']['score'] <= LeadPotentialCalculator::CAP_WITHOUT_WEBSITE && $noAn['lead']['score'] < $full['lead']['score'] && $li->compute($liSnap(['analysis' => $liAn([$liF('mobile_tap_targets', 'mobile', 'medium'), $liF('a11y_labels', 'accessibility', 'medium')], 15, 50)]))['lead']['score'] > $noAn['lead']['score'], '2. regresja (walidacja biznesowa): bez analizy maks. ' . LeadPotentialCalculator::CAP_WITHOUT_WEBSITE . ' — nie wyprzedza firmy z potwierdzonymi umiarkowanymi problemami');
UbrT::ok($noAn['recommendation']['key'] === 'research_further' && in_array('no_analysis', array_column($noAn['warnings'], 'key'), true) && $noAn['assessment']['key'] === 'insufficient_data', '2. brak analizy: RESEARCH_FURTHER + ostrzeżenie + redesign INSUFFICIENT_DATA');

$noDm = $li->compute($liSnap(['contacts' => ['people' => [], 'channels' => 0]]));
UbrT::ok($noDm['lead']['score'] >= 75 && $noDm['lead']['components']['contacts']['points'] === 0.0, '3. brak decydenta: wysoki potencjał zachowany (' . $noDm['lead']['score'] . '), Contact Readiness 0/10');
UbrT::eq('find_decision_maker', $noDm['recommendation']['key'], '3. brak osoby i kanału → FIND_DECISION_MAKER (nie LOW_PRIORITY)');

$noSize = $li->compute($liSnap(['company' => ['employees' => 0]]));
$sizeItem = array_values(array_filter($noSize['lead']['components']['business']['items'], fn ($i) => $i['key'] === 'size'))[0];
UbrT::ok($sizeItem['points'] === null && abs($noSize['lead']['score'] - $full['lead']['score']) <= 3, '4. nieznana wielkość = niezmierzona, nie „mała firma” (wynik ' . $noSize['lead']['score'] . ' vs ' . $full['lead']['score'] . ')');

$low = $li->compute($liSnap(['analysis' => null, 'contacts' => ['researched' => false, 'people' => [], 'channels' => 0], 'company' => ['pkd_main' => null, 'employees' => 0, 'domain_status' => 'unchecked'], 'relevance' => 70]));
UbrT::ok($low['lead']['score'] === null && $low['lead']['status'] === 'INSUFFICIENT_DATA' && $low['lead']['coverage']['percent'] < 40, '5. bardzo niska kompletność: INSUFFICIENT_DATA (bez fikcyjnego wyniku)');

$hiLow = $li->compute($liSnap(['company' => ['verification_status' => 'uncertain', 'domain_status' => 'unchecked', 'pkd_main' => null, 'employees' => 0], 'analysis' => $liAn($liStrong, 30, 50, false, false), 'contacts' => ['researched' => false, 'people' => [], 'channels' => 0]]));
UbrT::ok($hiLow['lead']['score'] >= 60 && $hiLow['confidence'] === 'low' && $hiLow['lead']['status'] === 'PROVISIONAL', '6. wysoki potencjał (' . $hiLow['lead']['score'] . ') przy niskiej pewności (LOW) — wartości rozdzielone');

$loHi = $li->compute($liSnap(['analysis' => $liAn([$liF('ux_nav_cluttered', 'ux', 'low')], 4, 50), 'relevance' => 30, 'company' => ['pkd_main' => '47.11.Z', 'employees' => 5]]));
UbrT::ok($loHi['lead']['score'] < 55 && $loHi['confidence'] === 'high', '7. niski potencjał (' . $loHi['lead']['score'] . ') przy wysokiej pewności (HIGH)');

$inactive = $li->compute($liSnap(['company' => ['verification_status' => 'inactive']]));
UbrT::ok(in_array('inactive', array_column($inactive['blockers'], 'key'), true) && $inactive['recommendation']['key'] === 'do_not_contact' && $inactive['rank']['score'] === 0.0, '8. INACTIVE: twarda przeszkoda, DO_NOT_CONTACT, koniec rankingu');

$mismatch = $li->compute($liSnap(['company' => ['domain_status' => 'mismatch']]));
UbrT::ok(in_array('domain_mismatch', array_column($mismatch['blockers'], 'key'), true) && $mismatch['recommendation']['key'] !== 'contact_now' && $mismatch['recommendation']['key'] === 'verify_company', '9. domena MISMATCH: przeszkoda, VERIFY_COMPANY (nigdy CONTACT_NOW)');

$unc = $li->compute($liSnap(['company' => ['verification_status' => 'uncertain']]));
UbrT::ok(! $unc['blockers'] && in_array('company_uncertain', array_column($unc['warnings'], 'key'), true) && $unc['lead']['components']['trust']['items'][0]['points'] === 4.0 && $unc['recommendation']['key'] === 'verify_company', '10. UNCERTAIN: bez blokady (to nie dowód nieistnienia), ostrzeżenie, VERIFY_COMPANY');

$r20 = $li->compute($liSnap(['relevance' => 20]))['lead']['score'];
$r90 = $li->compute($liSnap(['relevance' => 90]))['lead']['score'];
UbrT::ok($r20 < $r90 && $r90 - $r20 <= 15, '11. Relevance 20 → ' . $r20 . ', 90 → ' . $r90 . ' (monotonicznie, maks. 15 pkt wpływu)');

$w20 = $li->compute($liSnap(['analysis' => $liAn($liStrong, 10, 50)]))['lead']['components']['website']['points'];
$w60 = $li->compute($liSnap(['analysis' => $liAn($liStrong, 30, 50)]))['lead']['components']['website']['points'];
UbrT::ok($w60 > $w20, '12. semantyka: więcej punktów słabości strony (Opportunity: wysoki = słaba strona) → więcej pkt redesignu (' . $w20 . ' → ' . $w60 . ')');

$onlyRatio = $li->compute($liSnap(['analysis' => $liAn([], 50, 50)]))['lead']['components']['website'];
$dupes = WebsiteInsightGenerator::generate($liAn([$liF('a11y_labels', 'accessibility', 'medium'), $liF('axe_label', 'accessibility', 'medium'), $liF('a11y_alt', 'accessibility', 'medium'), $liF('axe_image-alt', 'accessibility', 'medium')]));
UbrT::ok($onlyRatio['points'] <= 22.5 && $full['lead']['components']['website']['points'] <= 45 && count($dupes) === 1 && $dupes[0]['causes'] === 2, '13. brak podwójnego liczenia: pomiar i liczba kategorii uśredniane (maks. 45), ta sama przyczyna z 2 metod = 1');

$inRange = true;

foreach ([0, 30, 60, 100] as $rel) {
	foreach ([0, 25, 50] as $pts) {
		foreach (['verified', 'uncertain', 'inactive'] as $v) {
			$x = $li->compute($liSnap(['relevance' => $rel, 'analysis' => $liAn($liStrong, $pts, 50), 'company' => ['verification_status' => $v]]))['lead']['score'];
			$inRange = $inRange && ($x === null || ($x >= 0 && $x <= 100));
		}
	}
}

$single = $li->compute($liSnap(['analysis' => $liAn($liStrong, 50, 50), 'relevance' => null, 'contacts' => ['researched' => false, 'people' => [], 'channels' => 0], 'company' => ['verification_status' => 'pending', 'domain_status' => 'unchecked', 'pkd_main' => null, 'employees' => 0]]));
UbrT::ok($inRange && ($single['lead']['score'] === null || $single['lead']['score'] < 100), '14. zakres 0–100 dla 36 kombinacji; jeden pozytywny sygnał nie daje 100/100 (' . var_export($single['lead']['score'], true) . ')');

UbrT::ok(wp_json_encode($li->compute($liSnap())) === wp_json_encode($li->compute($liSnap())) && LeadIntelligenceService::hash($liSnap()) === LeadIntelligenceService::hash($liSnap()), '15. determinizm: te same dane → identyczny wynik i hash');

/* ======================================================================================
 * INSIGHTS (16–24)
 * ==================================================================================== */
UbrT::group('[lead] Website Opportunity Insights');

$ins = WebsiteInsightGenerator::generate($liAn([$liF('perf_slow', 'performance', 'medium', 'automated', ['load: 7200 ms'])]));
UbrT::ok(count($ins) === 1 && $ins[0]['category'] === 'performance' && str_contains($ins[0]['evidence'][0]['detail'], '7200') && ! str_contains($ins[0]['description'], 'traci'), '16. wydajność: wniosek z pomiarem (load 7200 ms), ostrożny język');

$ins = WebsiteInsightGenerator::generate($liAn([$liF('mobile_overflow', 'mobile', 'high'), $liF('mobile_no_viewport', 'mobile', 'high')]));
UbrT::ok(count($ins) === 1 && $ins[0]['key'] === 'mobile_responsive' && $ins[0]['severity'] === 'high' && count($ins[0]['evidence']) === 2, '17. mobile: dwa objawy jednej przyczyny → jeden wniosek HIGH z dwoma dowodami');

$ins = WebsiteInsightGenerator::generate($liAn([$liF('axe_color-contrast', 'accessibility', 'low'), $liF('a11y_labels', 'accessibility', 'medium')]));
UbrT::ok(count($ins) === 1 && $ins[0]['category'] === 'accessibility' && $ins[0]['severity'] === 'medium', '18. dostępność: kontrast + etykiety → wniosek MEDIUM');

$ins = WebsiteInsightGenerator::generate($liAn([$liF('ux_no_nav', 'ux', 'high')]));
UbrT::ok($ins[0]['category'] === 'ux' && $ins[0]['severity'] === 'high', '19. UX: brak nawigacji → wniosek UX HIGH');

UbrT::ok(WebsiteInsightGenerator::generate(null) === [] && WebsiteInsightGenerator::generate($liAn([$liF('ux_no_nav', 'ux', 'high', 'automated', [])])) === [] && WebsiteInsightGenerator::generate($liAn([$liF('manual_visual_review', 'design', 'info', 'manual_review')])) === [] && WebsiteInsightGenerator::generate($liAn([], 25, 50, true, true, 'failed')) === [], '20. brak pomiaru / dowodu / analizy nieudanej / sugestii ręcznej → brak wniosku');

$three = WebsiteInsightGenerator::generate($liAn([$liF('a11y_labels', 'accessibility', 'medium'), $liF('axe_image-alt', 'accessibility', 'medium'), $liF('axe_color-contrast', 'accessibility', 'medium')]));
UbrT::ok($dupes[0]['severity'] === 'medium' && $three[0]['severity'] === 'high', '21. kilka objawów jednej przyczyny NIE podnosi severity; 3 różne przyczyny ≥ MEDIUM → HIGH');

$crit = WebsiteInsightGenerator::generate($liAn([$liF('seo_noindex', 'seo', 'critical'), $liF('sec_old_jquery', 'security', 'medium')]));
$lows = WebsiteInsightGenerator::generate($liAn([$liF('perf_heavy', 'performance', 'low'), $liF('perf_broken_resources', 'performance', 'low')]));
UbrT::ok($crit[0]['severity'] === 'critical' && WebsiteInsightGenerator::severity([$liF('a', 'ux', 'high'), $liF('b', 'ux', 'high'), $liF('c', 'ux', 'high'), $liF('d', 'ux', 'high')]) === 'high' && ! array_filter($lows, fn ($i) => $i['severity'] !== 'low'), '22. severity z progów: CRITICAL tylko z krytycznego findingu, eskalacja maks. do HIGH, same LOW = LOW');

$allEv = true;

foreach (WebsiteInsightGenerator::generate($liAn($liStrong)) as $i) {
	foreach ($i['evidence'] as $e) {
		$allEv = $allEv && $e['measure'] !== '' && $e['source'] !== '' && $e['date'] !== '' && array_key_exists('detail', $e);
	}

	$allEv = $allEv && $i['evidence'] && isset($i['category'], $i['severity'], $i['title'], $i['description'], $i['source'], $i['recommendation'], $i['confidence'], $i['algorithm_version']);
}

UbrT::ok($allEv, '23. każdy wniosek: kategoria, severity, opis, dowody (pomiar, wykryty problem, źródło, data), rekomendacja, pewność, wersja');

$fullIns = WebsiteInsightGenerator::generate($liAn($liStrong));
$blob = mb_strtolower(wp_json_encode([$fullIns, $full['summary'], $full['talking_points']], JSON_UNESCAPED_UNICODE));
UbrT::ok(count($fullIns) === 4 && ! array_filter($fullIns, fn ($i) => $i['category'] === 'design') && ! str_contains($blob, 'przestarzały wygląd') && ! str_contains($blob, 'tracą') && ! str_contains($blob, 'traci klient') && str_contains($blob, 'nie była oceniana'), '24. brak halucynacji: tylko wykryte problemy, bez ocen wyglądu i bez „utraty klientów”');

/* ======================================================================================
 * RECOMMENDATIONS (25–34)
 * ==================================================================================== */
UbrT::group('[lead] Recommended Next Action, blokery, ostrzeżenia');

UbrT::eq('contact_now', $full['recommendation']['key'], '25. CONTACT_NOW: wiarygodna firma, potwierdzona domena, STRONG, dane kompletne');
UbrT::eq('research_further', $noAn['recommendation']['key'], '26. RESEARCH_FURTHER: obiecująca firma bez analizy strony');
$mod = $li->compute($liSnap(['analysis' => $liAn([$liF('mobile_tap_targets', 'mobile', 'medium'), $liF('a11y_labels', 'accessibility', 'medium')], 15, 50)]));
UbrT::ok($mod['assessment']['key'] === 'moderate' && $mod['recommendation']['key'] === 'review_website', '27. REVIEW_WEBSITE: problemy wymagające ręcznej oceny (MODERATE)');
UbrT::eq('verify_company', $li->compute($liSnap(['company' => ['verification_status' => 'pending']]))['recommendation']['key'], '28. VERIFY_COMPANY: firma niezweryfikowana');
UbrT::eq('find_decision_maker', $li->compute($liSnap(['contacts' => ['researched' => false, 'people' => [], 'channels' => 0]]))['recommendation']['key'], '29. FIND_DECISION_MAKER: dobry kandydat, decydentów nie wyszukano');
$lp = $li->compute($liSnap(['analysis' => $liAn([], 2, 50), 'relevance' => 25, 'company' => ['pkd_main' => '47.11.Z']]));
UbrT::ok($lp['assessment']['key'] === 'weak' && $lp['recommendation']['key'] === 'low_priority', '30. LOW_PRIORITY: brak przesłanek (WEAK) i słabe dopasowanie');
$goodSite = $li->compute($liSnap(['analysis' => $liAn([], 2, 50)]));
UbrT::ok($goodSite['assessment']['key'] === 'weak' && $goodSite['recommendation']['key'] === 'review_website' && str_contains(implode(' ', array_column($goodSite['summary'], 'text')), 'Nie oznacza to, że strona jest nowoczesna'), '30. dobre wyniki pomiarów ≠ dobra strona: WEAK przy dobrym dopasowaniu → REVIEW_WEBSITE (wygląd nieoceniony)');
$excl = $li->compute($liSnap(['prospect' => ['status' => 'not_a_fit', 'qualification' => null, 'archived' => false]]));
UbrT::ok($inactive['recommendation']['key'] === 'do_not_contact' && $excl['recommendation']['key'] === 'do_not_contact' && in_array('excluded', array_column($excl['blockers'], 'key'), true), '31. DO_NOT_CONTACT: nieaktywna albo wykluczona przez organizację');
$conf = $li->compute($liSnap(['company' => ['domain_status' => 'uncertain']]));
UbrT::ok($conf['assessment']['key'] === 'strong' && $conf['recommendation']['key'] === 'verify_company' && in_array('contact_now', $conf['recommendation']['also'], true), '32. konflikt: STRONG + niepotwierdzona domena → VERIFY_COMPANY, „Contact now” jawnie jako alternatywa');
UbrT::ok($mismatch['assessment']['key'] === 'strong' && $mismatch['rank']['score'] === 0.0 && $mismatch['lead']['score'] !== null, '33. hard blocker: nawet przy STRONG brak CONTACT_NOW i koniec rankingu (potencjał nadal widoczny)');
$stale = $li->compute($liSnap(['analysis' => $liAn($liStrong, 25, 50, true, false)]));
UbrT::ok(in_array('analysis_stale', array_column($stale['warnings'], 'key'), true) && $stale['lead']['components']['website']['points'] === $full['lead']['components']['website']['points'] && $stale['rank']['score'] < $full['rank']['score'] && $stale['confidence'] !== 'high', '34. soft warning (stara analiza): potencjał bez zmian, niższa pewność i ranking');

/* ======================================================================================
 * SUMMARY (35–39)
 * ==================================================================================== */
UbrT::group('[lead] Lead Research Summary + Sales Talking Points');

UbrT::eq(['overview', 'website', 'redesign', 'fit', 'contacts', 'risks', 'action'], array_column($full['summary'], 'key'), '35. podsumowanie: 7 sekcji w ustalonej kolejności');
$sumNo = implode(' ', array_column($low['summary'], 'text'));
UbrT::ok(str_contains($sumNo, 'nie była analizowana') && str_contains($sumNo, 'Brakujące dane') && str_contains($sumNo, 'Liczba pracowników nieznana'), '36. brak danych: jawne „brak analizy”, lista braków, nieznana wielkość');
$sumFull = implode(' ', array_column($full['summary'], 'text'));
UbrT::ok(! preg_match('/PLN|przych|budżet firmy wynosi|tracą|traci klient/u', $sumFull) && str_contains($sumFull, 'Budżetu firmy nie szacujemy') && str_contains($sumFull, 'Ok. 120 pracowników'), '37. brak wymyślonych informacji (przychody, budżety, straty); tylko dane z rekordu');
UbrT::ok(count($full['talking_points']) >= 3 && count($full['talking_points']) <= 5 && $low['talking_points'] === [] && ! array_filter($full['talking_points'], fn ($t) => preg_match('/tracicie|%\s*klient/u', $t['angle'])), '38. talking points: 3–5 argumentów bez agresywnych obietnic; brak przy braku danych');
$keys = array_column($fullIns, 'key');
UbrT::ok(! array_filter($full['talking_points'], fn ($t) => ! in_array($t['insight_key'], $keys, true) || $t['evidence'] === ''), '39. każdy argument powiązany z wnioskiem i jego dowodem');

/* ======================================================================================
 * DATA & PERFORMANCE (40–50) — integracja
 * ==================================================================================== */
UbrT::group('[lead] Historia, przeliczanie, cache, batch, filtry, sortowanie, multi-tenancy');

$analysesRepo = app(AnalysisRepository::class);
$mkAnalysis = function (int $companyId, array $findings, int $points = 25) use ($analysesRepo, $orgA, $uA): int {
	$id = $analysesRepo->create(['company_id' => $companyId, 'organization_id' => $orgA, 'user_id' => $uA, 'trigger_type' => 'manual', 'url' => 'https://x.ubrtest.pl/', 'algorithm_version' => 'test']);
	$analysesRepo->update($id, [
		'status' => 'completed', 'finished_at' => Db::now(), 'opportunity_score' => 40, 'findings' => $findings,
		'results' => ['opportunity' => ['categories' => [
			'website_weakness' => ['criteria' => ['a_visual_ai' => ['points' => null, 'max' => 15], 'a_ux_structure' => ['points' => $points, 'max' => 30]]],
			'technical_weakness' => ['criteria' => ['b_seo' => ['points' => 0, 'max' => 20]]],
			'business_potential' => ['criteria' => ['c_size' => ['points' => 10, 'max' => 10]]],
		]], 'render' => ['available' => true], 'visual' => ['status' => 'NOT_EVALUATED']],
	]);

	return $id;
};
$liGood = $mkVisible('li-good', 'Ubrtest Lead Mocna', 'https://li-good.ubrtest.pl/');
$liWeak = $mkVisible('li-weak', 'Ubrtest Lead Slaba', 'https://li-weak.ubrtest.pl/');
$liDead = $mkVisible('li-dead', 'Ubrtest Lead Nieaktywna', 'https://li-dead.ubrtest.pl/');
app(CompanyRepository::class)->update($liGood, ['verification_status' => 'verified', 'domain_status' => 'confirmed', 'pkd_main' => '25.11.Z', 'employees_min' => 80, 'employees_max' => 80, 'contacts_status' => 'completed', 'company_channels' => wp_json_encode([['type' => 'COMPANY_GENERAL_EMAIL', 'value' => 'biuro@li-good.ubrtest.pl', 'department' => null]])]);
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::RESULTS)} SET relevance_score = 85 WHERE company_id = %d AND organization_id = %d", $liGood, $orgA));
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::RESULTS)} SET relevance_score = 30 WHERE company_id IN (%d, %d) AND organization_id = %d", $liWeak, $liDead, $orgA));
app(CompanyRepository::class)->update($liWeak, ['verification_status' => 'verified', 'domain_status' => 'confirmed', 'pkd_main' => '47.11.Z']);
app(CompanyRepository::class)->update($liDead, ['verification_status' => 'inactive', 'domain_status' => 'confirmed']);
$mkAnalysis($liGood, $liStrong, 25);
$mkAnalysis($liWeak, [$liF('ux_nav_cluttered', 'ux', 'low')], 2);
$mkAnalysis($liDead, $liStrong, 25);
$before = ['opp' => app(CompanyRepository::class)->find($liGood)['opportunity_score'], 'analyses' => (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}"), 'api' => (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::API_REQUESTS)}")];

// 45. Batch: firmy widoczne bez oceny przeliczane w tle jednym krokiem.
LeadIntelligenceRunner::resetKick();
$tick = app(LeadIntelligenceRunner::class)->tick(500, false, $orgA);
UbrT::ok($tick['processed'] >= 3 && $tick['remaining'] === 0 && $liRepo->current($orgA, $liGood) && $liRepo->current($orgA, $liDead), '45. batch: ' . $tick['processed'] . ' ocen w jednym kroku, kolejka pusta');

// 40–42. Historia, wersjonowanie, brak duplikatów.
$h1 = $liRepo->history($orgA, $liGood);
UbrT::ok(count($h1) === 1 && $h1[0]['reason'] === 'initial' && $h1[0]['lead_score'] !== null, '40. historia: pierwsza ocena zapisana (data, wynik, przyczyna)');
$cur = $liRepo->current($orgA, $liGood);
UbrT::ok($cur['algorithm_version'] === LeadIntelligenceService::VERSION && $h1[0]['algorithm_version'] === LeadIntelligenceService::VERSION && $liRepo->insights($liGood)[0]['algorithm_version'] === WebsiteInsightGenerator::VERSION && strlen($cur['input_hash']) === 64 && ! empty($cur['input_snapshot']['company']), '41. wersjonowanie: wersja algorytmu, input_snapshot i hash przy ocenie, historii i wnioskach');
UbrT::ok(array_keys($cur['input_snapshot']['analysis']['criteria']) === ['a_ux_structure', 'a_visual_ai', 'b_seo'] && ! isset($cur['input_snapshot']['analysis']['opportunity_score']), '12. wejście = tylko kryteria strony i techniki; łączny Opportunity Score (z potencjałem biznesowym i wiarygodnością) NIE jest używany');
$li->evaluate($orgA, $liGood, 'manual');
$li->evaluate($orgA, $liGood, 'manual');
UbrT::eq(1, count($liRepo->history($orgA, $liGood)), '42. identyczne dane → brak nowego wpisu historii');

// 43. Przeliczenie po zmianie istotnych danych.
app(CompanyRepository::class)->update($liGood, ['domain_status' => 'uncertain']);
do_action('ubr/company/changed', $liGood, 'verification_done', null);
UbrT::ok((int) $liRepo->current($orgA, $liGood)['stale'] === 1, '43. zdarzenie zmiany danych → ocena oznaczona do przeliczenia');
app(LeadIntelligenceRunner::class)->tick(50, false, $orgA);
$h2 = $liRepo->history($orgA, $liGood);
UbrT::ok((int) $liRepo->current($orgA, $liGood)['stale'] === 0 && count($h2) === 2 && $h2[0]['reason'] === 'verification_done' && $h2[0]['previous_score'] === $h1[0]['lead_score'] && $h2[0]['recommendation'] === 'verify_company', '43. przeliczenie w tle: nowy wpis z przyczyną, poprzednim wynikiem i nową rekomendacją');
app(CompanyRepository::class)->update($liGood, ['domain_status' => 'confirmed']);
do_action('ubr/company/changed', $liGood, 'verification_done', null);

// 44. Cache invalidation: profil liczy od razu nieaktualną ocenę; unieważnienie dla jednej organizacji nie dotyka innych.
$prof = ubrt_rest($uA, 'GET', '/companies/' . $liGood . '/lead-intelligence');
UbrT::ok($prof['status'] === 200 && $prof['data']['recommendation']['key'] === 'contact_now' && (int) $liRepo->current($orgA, $liGood)['stale'] === 0, '44. profil: nieaktualna ocena przeliczona przy odczycie (cache unieważniony zdarzeniem)');
app(\App\Ubr\Infrastructure\Repositories\ResultRepository::class)->add($sB, $orgB, $liGood, 'ubrtest', 10, []);
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::RESULTS)} SET relevance_score = 40 WHERE company_id = %d AND organization_id = %d", $liGood, $orgB));
$li->evaluate($orgB, $liGood, 'initial');
$li->invalidate($liGood, 'pipeline_changed', $orgB);
UbrT::ok((int) $liRepo->current($orgB, $liGood)['stale'] === 1 && (int) $liRepo->current($orgA, $liGood)['stale'] === 0, '44. unieważnienie dla organizacji B nie dotyka oceny organizacji A');

// 46–47. Filtrowanie i sortowanie po stronie backendu (Discover + Prospects).
$res = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['recommendation' => 'contact_now', 'per_page' => 100]);
$ids = array_column($res['data']['items'], 'id');
UbrT::ok(in_array($liGood, $ids, true) && ! in_array($liWeak, $ids, true) && ! array_filter($res['data']['items'], fn ($c) => ($c['lead']['recommendation'] ?? '') !== 'contact_now'), '46. Discover: filtr rekomendacji w SQL');
$res = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['no_blockers' => 1, 'min_lead' => 1, 'per_page' => 100]);
UbrT::ok(! in_array($liDead, array_column($res['data']['items'], 'id'), true) && ! array_filter($res['data']['items'], fn ($c) => ($c['lead']['score'] ?? 0) < 1), '46. Discover: „bez twardych przeszkód” + minimalny Lead Potential');
$res = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['sort' => 'rank', 'per_page' => 100]);
$order = array_values(array_filter(array_column($res['data']['items'], 'id'), fn ($id) => in_array($id, [$liGood, $liWeak, $liDead], true)));
$ranks = array_map(fn ($c) => $c['lead']['rank'] ?? -1, array_values(array_filter($res['data']['items'], fn ($c) => ! empty($c['lead']['available']) && empty($c['lead']['blocker']) && $c['lead']['rank'] !== null)));
$sorted = $ranks;
rsort($sorted);
UbrT::ok($order === [$liGood, $liWeak, $liDead] && $ranks === $sorted, '47. Discover: Smart Ranking malejąco, firma z przeszkodą na końcu');
ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $liGood]);
ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $liWeak]);
LeadIntelligenceRunner::resetKick();
app(LeadIntelligenceRunner::class)->tick(50, false, $orgA);
$pr = ubrt_rest($uA, 'GET', '/prospects', ['sort' => 'lead', 'recommendation' => '', 'per_page' => 100]);
$pIds = array_values(array_filter(array_column($pr['data']['items'], 'id'), fn ($id) => in_array($id, [$liGood, $liWeak], true)));
$pf = ubrt_rest($uA, 'GET', '/prospects', ['recommendation' => 'contact_now', 'per_page' => 100]);
UbrT::ok($pIds === [$liGood, $liWeak] && in_array($liGood, array_column($pf['data']['items'], 'id'), true) && ! in_array($liWeak, array_column($pf['data']['items'], 'id'), true), '47. Prospects: sortowanie po Lead Potential i filtr rekomendacji w backendzie');

// 48. Multi-tenancy: prywatne decyzje organizacji B nie wpływają na A.
ubrt_rest($uB, 'POST', '/prospects', ['company_id' => $liGood]);
$pB = app(PipelineRepository::class)->prospectForCompany($orgB, $liGood);
ubrt_rest($uB, 'POST', '/prospects/' . $pB['id'], ['status' => 'not_a_fit']);
app(LeadIntelligenceRunner::class)->tick(50, false);
UbrT::ok($liRepo->current($orgB, $liGood)['recommendation'] === 'do_not_contact' && $liRepo->current($orgA, $liGood)['recommendation'] === 'contact_now', '48. wykluczenie w organizacji B → DO_NOT_CONTACT tylko w B; A bez zmian');
UbrT::ok((int) $liRepo->current($orgA, $liGood)['input_snapshot']['relevance'] !== (int) $liRepo->current($orgB, $liGood)['input_snapshot']['relevance'], '48. ocena per organizacja (Relevance organizacji, osobne rekordy)');

// 49. IDOR.
UbrT::eq(404, ubrt_rest($uB, 'GET', '/companies/' . $liWeak . '/lead-intelligence')['status'], '49. org B nie odczyta oceny firmy znanej tylko org A');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/companies/' . $liWeak . '/lead-intelligence/recalculate')['status'], '49. org B nie przeliczy oceny firmy org A');
UbrT::ok(in_array(ubrt_rest($uX, 'GET', '/companies/' . $liGood . '/lead-intelligence')['status'], [403, 404], true), '49. użytkownik spoza organizacji — brak dostępu');
$topB = ubrt_rest($uB, 'GET', '/lead-intelligence/top');
UbrT::ok(! in_array($liWeak, array_map(fn ($t) => $t['company']['id'], $topB['data']['items']), true), '49. ranking organizacji B bez firm organizacji A');

// 50. Regresja: istniejące scoringi i decyzje użytkownika nietknięte, brak nowych analiz i zapytań zewnętrznych.
$pA = app(PipelineRepository::class)->prospectForCompany($orgA, $liGood);
UbrT::ok(app(CompanyRepository::class)->find($liGood)['opportunity_score'] === $before['opp'] && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}") === $before['analyses'] && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::API_REQUESTS)}") === $before['api'], '50. Opportunity Score bez zmian, brak nowych analiz i zapytań zewnętrznych');
UbrT::ok($pA['status'] === 'new' && $pA['priority'] === 'medium' && $pA['qualification'] === null, '50. rekomendacja nie zmienia statusu, priorytetu ani kwalifikacji prospektu');
$dash = ubrt_rest($uA, 'GET', '/dashboard');
UbrT::ok($dash['status'] === 200 && ($dash['data']['top_leads'][0]['company']['id'] ?? null) !== $liDead && isset($dash['data']['pipeline']), '50. Dashboard: Smart Lead Ranking + dotychczasowe statystyki działają');
