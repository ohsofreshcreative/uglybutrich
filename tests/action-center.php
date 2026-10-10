<?php

/**
 * Testy: Prospecting Dashboard & Action Center (Prompt 6). Dołączane z tests/run.php (wspólne: $mk, $created, $ingest,
 * $searches, $uA, $uB, $uX, $orgA, $orgB, $db, $wpdb). Osobna organizacja testowa C z kontrolowanym zestawem firm
 * „Ubrtest AC …” — liczniki są dokładne. Oceny Lead Intelligence zapisywane jako dane testowe (bez zmiany algorytmu),
 * bez zapytań zewnętrznych, analiz i integracji.
 */

use App\Ubr\Domain\ActionCenter\ActionPriorityEngine as APE;
use App\Ubr\Domain\ActionCenter\QueueCatalog;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\ActionCenterRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Services\ActionCenterService;
use App\Ubr\Services\LeadIntelligenceService;
use App\Ubr\Services\OrganizationService;

if (! defined('ABSPATH')) {
	exit(1);
}

/* ------------------------------------------------------------------ */
UbrT::group('Action Priority Engine — reguły (jednostkowo)');

$apS = fn (array $o = []) => $o + ['has_li' => true, 'has_blocker' => false, 'recommendation' => 'contact_now', 'analysis_status' => 'completed', 'domain' => 'firma.test', 'prospect_status' => null, 'prospect_archived' => false, 'has_open_followup' => false];
$apT = fn (array $o = []) => APE::classify($apS($o))['type'] ?? null;

UbrT::eq(APE::CONTACT_READY, $apT(), '1. Contact now + zakończona analiza → CONTACT_READY');
UbrT::eq(APE::CONTACT_READY, $apT(['analysis_status' => 'partial']), '2. analiza PARTIAL (częściowe pomiary) wystarcza do CONTACT_READY');
UbrT::eq(APE::ANALYSIS_MISSING, $apT(['analysis_status' => null]), '3. Contact now BEZ analizy → nigdy „gotowa do kontaktu” (ANALYSIS_MISSING)');
UbrT::eq(APE::ANALYSIS_MISSING, $apT(['analysis_status' => 'failed']), '4. analiza z błędem nie jest dowodem → ANALYSIS_MISSING');
UbrT::eq(null, $apT(['has_blocker' => true]), '5. hard blocker → brak działania (nawet przy Contact now)');
UbrT::eq(null, $apT(['prospect_status' => 'won']), '6. status zamknięty (won) → brak działania z rekomendacji');
UbrT::eq(null, $apT(['prospect_status' => 'not_a_fit']), '7. status „nie pasuje” → brak działania');
UbrT::eq(null, $apT(['prospect_status' => 'in_discussion']), '8. trwająca rozmowa → pracą sterują follow-upy, nie rekomendacja');
UbrT::eq(null, $apT(['has_open_followup' => true]), '9. dedup: firma z otwartym follow-upem nie dostaje drugiego działania');
UbrT::eq(null, $apT(['prospect_archived' => true]), '10. prospekt zarchiwizowany → brak działania');
UbrT::eq(null, $apT(['has_li' => false]), '11. brak oceny Lead Intelligence → brak działania (bez zgadywania)');
UbrT::eq(APE::FIND_DECISION_MAKER, $apT(['recommendation' => 'find_decision_maker']), '12. Find decision maker → FIND_DECISION_MAKER');
UbrT::eq(APE::REVIEW_WEBSITE, $apT(['recommendation' => 'review_website']), '13. Review website → REVIEW_WEBSITE');
UbrT::eq(APE::VERIFY_COMPANY, $apT(['recommendation' => 'verify_company']), '14. Verify company → VERIFY_COMPANY');
UbrT::eq(APE::ANALYSIS_MISSING, $apT(['recommendation' => 'research_further', 'analysis_status' => null]), '15. Research further + domena bez analizy → ANALYSIS_MISSING (konkretne działanie)');
UbrT::eq(APE::RESEARCH_FURTHER, $apT(['recommendation' => 'research_further', 'analysis_status' => null, 'domain' => '']), '16. Research further bez domeny → RESEARCH_FURTHER');
UbrT::eq(APE::RESEARCH_FURTHER, $apT(['recommendation' => 'research_further']), '17. Research further z analizą → RESEARCH_FURTHER');
UbrT::eq(null, $apT(['recommendation' => 'low_priority']), '18. Low priority → brak działania na liście priorytetów');
UbrT::eq(null, $apT(['recommendation' => 'do_not_contact']), '19. Do not contact → brak działania');
UbrT::eq(APE::REVIEW_WEBSITE, $apT(['recommendation' => 'review_website', 'prospect_status' => 'qualified']), '20. prospekt przed kontaktem zachowuje rekomendację');

$tiers = array_column(APE::TYPES, 'tier');
UbrT::eq(range(1, 8), $tiers, '21. kategorie mają stałą kolejność 1–8 (follow-upy zaległe → dziś → kontakt → … → uzupełnienie)');
UbrT::ok(APE::TYPES[APE::FOLLOW_UP_OVERDUE]['tier'] < APE::TYPES[APE::CONTACT_READY]['tier'], '22. zaległy follow-up zawsze przed „gotową do kontaktu” (wysoki Lead Potential nie wypiera zobowiązań)');
UbrT::ok(! in_array(APE::FOLLOW_UP_OVERDUE, APE::SNOOZABLE, true) && ! in_array(APE::FOLLOW_UP_DUE, APE::SNOOZABLE, true), '23. follow-upów nie odkłada się (przekłada się termin)');
UbrT::eq(70, APE::HIGH_POTENTIAL, '24. próg High Potential = 70 (powyżej limitu 65 dla firm bez danych o stronie)');

$row = fn (string $t, $rank, $lead, $cov, $cid, $pri = null, $pid = null) => ['action_type' => $t, 'rank_score' => $rank, 'lead_score' => $lead, 'coverage' => $cov, 'company_id' => $cid, 'p_priority' => $pri, 'prospect_id' => $pid];
$set = [$row(APE::REVIEW_WEBSITE, 90.0, 90, 90, 1), $row(APE::CONTACT_READY, 40.0, 50, 60, 9), $row(APE::CONTACT_READY, 60.0, 70, 80, 7), $row(APE::CONTACT_READY, 60.0, 70, 80, 3), $row(APE::CONTACT_READY, 10.0, 20, 30, 5, 'urgent', 50)];
usort($set, [APE::class, 'compare']);
UbrT::eq([5, 3, 7, 9, 1], array_column($set, 'company_id'), '25. remisy: pilny prospekt → rank → Lead → coverage → ID firmy; kategoria ważniejsza niż wynik (Review 90 po Contact 40)');
$set2 = $set;
shuffle($set2);
usort($set2, [APE::class, 'compare']);
UbrT::eq(array_column($set, 'company_id'), array_column($set2, 'company_id'), '26. sortowanie stabilne i deterministyczne (niezależne od kolejności wejścia)');

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — dane organizacji testowej C');

$uC = $mk('ownerC');
$uC2 = $mk('memberC');
$orgC = app(OrganizationService::class)->create('ubrtest Org C', $uC);
$created['orgs'][] = $orgC;
app(MemberRepository::class)->add($orgC, $uC2, Role::Member);
$sC = $searches->create(['organization_id' => $orgC, 'user_id' => $uC, 'label' => 'ubrtest AC', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
$acCompanies = app(CompanyRepository::class);
$acMk = function (string $id, string $name, string $site, array $fields = []) use ($ingest, $orgC, $sC, $acCompanies) {
	$cid = $ingest->ingest(ubrt_candidate(['id' => 'ac-' . $id, 'name' => 'Ubrtest AC ' . $name, 'website' => $site]))['company_id'];
	app(ResultRepository::class)->add($sC, $orgC, $cid, 'ubrtest', 50, []);
	$acCompanies->update($cid, $fields + ['analysis_status' => null, 'contacts_status' => null]);

	return $cid;
};
$an = ['analysis_status' => 'completed'];
$c = [
	'ready' => $acMk('ready', 'Gotowa', 'https://ac-ready.ubrtest.pl/', $an),
	'twin' => $acMk('twin', 'Gotowa Bliźniak', 'https://ac-twin.ubrtest.pl/', $an),
	'readyNoAn' => $acMk('ready-noan', 'Kontakt Bez Analizy', 'https://ac-noan.ubrtest.pl/'),
	'review' => $acMk('review', 'Do Oceny', 'https://ac-review.ubrtest.pl/', $an),
	'blocked' => $acMk('blocked', 'Nieaktywna', 'https://ac-blocked.ubrtest.pl/', $an),
	'research' => $acMk('research', 'Bez Analizy', 'https://ac-research.ubrtest.pl/'),
	'noDomain' => $acMk('nodomain', 'Bez Domeny', ''),
	'verify' => $acMk('verify', 'Do Weryfikacji', 'https://ac-verify.ubrtest.pl/', $an),
	'follow' => $acMk('follow', 'Zalegly Followup', 'https://ac-follow.ubrtest.pl/', $an),
	'follow2' => $acMk('follow2', 'Dwa Followupy', 'https://ac-follow2.ubrtest.pl/', $an),
	'today' => $acMk('today', 'Na Dzis', 'https://ac-today.ubrtest.pl/', $an),
	'talk' => $acMk('talk', 'W Rozmowach', 'https://ac-talk.ubrtest.pl/', $an),
	'won' => $acMk('won', 'Wygrana', 'https://ac-won.ubrtest.pl/', $an),
	'dmProspect' => $acMk('dm', 'Prospekt Bez Decydenta', 'https://ac-dm.ubrtest.pl/', $an),
	'low' => $acMk('low', 'Niski Priorytet', 'https://ac-low.ubrtest.pl/', $an),
	'planned' => $acMk('planned', 'Zaplanowana', 'https://ac-planned.ubrtest.pl/', $an),
	'noLi' => $acMk('noli', 'Bez Oceny', 'https://ac-noli.ubrtest.pl/'),
];

// Pipeline (przed zapisem ocen — zdarzenia pipeline oznaczają oceny do przeliczenia).
$acPid = [];
foreach (['follow', 'follow2', 'today', 'talk', 'won', 'dmProspect', 'planned'] as $k) {
	$acPid[$k] = (int) ubrt_rest($uC, 'POST', '/prospects', ['company_id' => $c[$k], 'search_id' => $sC])['data']['prospect_id'];
}
UbrT::ok(count(array_filter($acPid)) === 7, 'prospekci organizacji C utworzeni istniejącym endpointem');
ubrt_rest($uC, 'POST', '/prospects/' . $acPid['follow'], ['owner_user_id' => $uC]);
ubrt_rest($uC, 'POST', '/prospects/' . $acPid['today'], ['owner_user_id' => $uC2]);
ubrt_rest($uC, 'POST', '/prospects/' . $acPid['follow2'], ['owner_user_id' => null]);
ubrt_rest($uC, 'POST', '/prospects/' . $acPid['talk'], ['status' => 'contacted']);
ubrt_rest($uC, 'POST', '/prospects/' . $acPid['won'], ['status' => 'won']);
ubrt_rest($uC, 'POST', '/prospects/' . $acPid['dmProspect'], ['status' => 'qualified']);
$fu = fn (string $k, string $title, int $offset, ?int $user = null) => ubrt_rest($uC, 'POST', '/prospects/' . $acPid[$k] . '/followups', ['title' => $title, 'due_at' => gmdate('c', time() + $offset)] + ($user ? ['assigned_user_id' => $user] : []));
$fu('follow', 'AC zaległy', -2 * DAY_IN_SECONDS, $uC);
$fu('follow2', 'AC zaległy 2', -3600, $uC);
$fu('follow2', 'AC dziś 2', 60, $uC);
$fu('today', 'AC dziś', 60, $uC2);
$fu('planned', 'AC za 3 dni', 3 * DAY_IN_SECONDS, $uC);
$fu('talk', 'AC rozmowa za 10 dni', 10 * DAY_IN_SECONDS, $uC);

$acCompanies->update($c['review'], ['region' => 'podkarpackie', 'industry' => 'Produkcja maszyn testowa']);
$acCompanies->update($c['ready'], ['top_contact_name' => 'Jan Testowy', 'top_contact_title' => 'Prezes Zarządu', 'top_contact_confidence' => 'high', 'company_channels' => wp_json_encode([['type' => 'COMPANY_GENERAL_EMAIL', 'department' => null, 'email' => 'biuro@ac-ready.ubrtest.pl']])]);
$liRepo = app(LeadIntelligenceRepository::class);
$acLi = function (int $cid, string $rec, ?int $score, ?float $rank, int $cov = 80, bool $blocker = false, string $conf = 'high', array $warnings = []) use ($liRepo, $orgC) {
	$liRepo->save($orgC, $cid, [
		'lead_score' => $score, 'score_status' => 'scored', 'confidence' => $conf, 'coverage' => $cov, 'redesign' => 'moderate', 'recommendation' => $rec,
		'rank_score' => $rank, 'has_blocker' => $blocker ? 1 : 0, 'analysis_id' => null, 'input_hash' => str_repeat('a', 64), 'input_snapshot' => ['test' => true],
		'result' => ['recommendation' => ['key' => $rec, 'reason' => 'Powód testowy: ' . $rec], 'blockers' => $blocker ? [['key' => 'inactive', 'label' => 'Firma nieaktywna (test)']] : [], 'warnings' => $warnings],
		'algorithm_version' => LeadIntelligenceService::VERSION, 'calculated_at' => Db::now(),
	]);
};
$acLi($c['ready'], 'contact_now', 80, 75.0);
$acLi($c['twin'], 'contact_now', 80, 75.0);
$acLi($c['readyNoAn'], 'contact_now', 72, 60.0);
$acLi($c['review'], 'review_website', 74, 70.0);
$acLi($c['blocked'], 'do_not_contact', 78, 0.0, 80, true);
$acLi($c['research'], 'research_further', 65, 40.0, 50, false, 'medium', [['key' => 'no_analysis', 'label' => 'Brak analizy strony (test).'], ['key' => 'low_coverage', 'label' => 'Niska kompletność danych (test).']]);
$acLi($c['noDomain'], 'research_further', 40, 20.0, 30, false, 'low');
$acLi($c['verify'], 'verify_company', 60, 45.0);
$acLi($c['follow'], 'contact_now', 30, 25.0);
$acLi($c['follow2'], 'review_website', 55, 40.0);
$acLi($c['today'], 'review_website', 50, 40.0);
$acLi($c['talk'], 'contact_now', 85, 80.0);
$acLi($c['won'], 'contact_now', 90, 85.0);
$acLi($c['dmProspect'], 'review_website', 62, 50.0);
$acLi($c['low'], 'low_priority', 35, 20.0);
$acLi($c['planned'], 'contact_now', 76, 70.0);
$liBefore = $wpdb->get_results($wpdb->prepare("SELECT company_id, lead_score, recommendation, rank_score FROM {$db->table(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d ORDER BY company_id", $orgC), ARRAY_A);
$sideBefore = [(int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}"), (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_RUNS)}"), (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::API_REQUESTS)}"), (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CLICKUP_JOBS)}")];

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — Today\'s Priorities, dedup i kolejność');

$ov = ubrt_rest($uC, 'GET', '/action-center');
UbrT::eq(200, $ov['status'], 'GET /action-center: 200');
$items = $ov['data']['priorities']['items'];
$byCo = [];
foreach ($items as $it) {
	$byCo[$it['company']['id']][] = $it['type'];
}
$pAll = ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 50])['data'];
$allTypes = array_map(fn ($i) => $i['type'] . '#' . $i['company']['id'], $pAll['items']);
$seq = array_map(fn ($i) => $i['company']['id'], $pAll['items']);
UbrT::eq(count($seq), count(array_unique($seq)), '27. każda firma najwyżej raz w Today\'s Priorities (deduplikacja)');
UbrT::eq(APE::FOLLOW_UP_OVERDUE, $pAll['items'][0]['type'], '28. pierwsza pozycja: zaległy follow-up');
UbrT::ok(in_array($pAll['items'][0]['company']['id'], [$c['follow'], $c['follow2']], true) && in_array($pAll['items'][1]['company']['id'], [$c['follow'], $c['follow2']], true), '29. zaległe follow-upy (także firmy z Lead 30) przed Contact Ready z Lead 80');
$f2 = current(array_filter($pAll['items'], fn ($i) => $i['company']['id'] === $c['follow2']));
UbrT::ok($f2 && $f2['type'] === APE::FOLLOW_UP_OVERDUE && $f2['followup']['more'] === 1, '30. dwa follow-upy jednej firmy → jedna pozycja (najwcześniejszy termin + „i jeszcze 1”)');
UbrT::eq($c['follow'], $pAll['items'][0]['company']['id'], '31. zaległe sortowane od najstarszego terminu');
$todayIt = current(array_filter($pAll['items'], fn ($i) => $i['company']['id'] === $c['today']));
UbrT::ok($todayIt && $todayIt['type'] === APE::FOLLOW_UP_DUE && $todayIt['due_at'] !== null, '32. follow-up na dziś → FOLLOW_UP_DUE z terminem');
UbrT::ok(! in_array(APE::CONTACT_READY . '#' . $c['follow'], $allTypes, true), '33. firma z follow-upem nie występuje dodatkowo jako Contact Ready');
$ready = array_values(array_filter($pAll['items'], fn ($i) => $i['type'] === APE::CONTACT_READY));
UbrT::eq([min($c['ready'], $c['twin']), max($c['ready'], $c['twin'])], array_map(fn ($i) => $i['company']['id'], $ready), '34. Contact Ready: tylko firmy z analizą; remis (ten sam rank/Lead/coverage) rozstrzyga ID firmy');
UbrT::ok(isset($ready[0]) && array_key_exists('due_at', $ready[0]) && $ready[0]['due_at'] === null, '35. działania z rekomendacji nie mają wymyślonego terminu');
UbrT::eq([APE::ANALYSIS_MISSING], array_values(array_map(fn ($i) => $i['type'], array_filter($pAll['items'], fn ($i) => $i['company']['id'] === $c['readyNoAn']))), '36. Contact now bez analizy → „Brak analizy strony”, nie kontakt');
UbrT::ok(! in_array($c['blocked'], $seq, true), '37. hard blocker wyłączony z priorytetów');
foreach (['talk' => 'rozmowa', 'won' => 'zamknięty', 'low' => 'low priority', 'planned' => 'zaplanowany follow-up', 'noLi' => 'bez oceny'] as $k => $why) {
	UbrT::ok(! in_array($c[$k], $seq, true), "38. brak działania dla firmy: {$why}");
}
$tierSeq = array_map(fn ($i) => $i['tier'], $pAll['items']);
$sorted = $tierSeq;
sort($sorted);
UbrT::eq($sorted, $tierSeq, '39. pozycje uporządkowane według kategorii (tier rosnąco)');
UbrT::ok(count(array_filter($pAll['items'], fn ($i) => $i['reason'] !== '' && $i['cta']['label'] !== '')) === count($pAll['items']), '40. każda pozycja ma przyczynę i jedno główne CTA');
$rev = current(array_filter($pAll['items'], fn ($i) => $i['company']['id'] === $c['review']));
UbrT::ok($rev && $rev['lead']['score'] === 74 && $rev['lead']['confidence'] === 'high' && str_contains($rev['reason'], 'review_website'), '41. pozycja pokazuje Lead Potential, Confidence i uzasadnienie z Lead Intelligence');
UbrT::eq(count($pAll['items']), $pAll['total'], '42. total = liczba wszystkich pozycji');

// Zgodność SQL ↔ PHP (ta sama reguła w obu miejscach).
$acRepo = app(ActionCenterRepository::class);
$sqlTypes = [];
foreach ($wpdb->get_results($wpdb->prepare(
	"SELECT li.id AS li_id, li.company_id, li.has_blocker, li.recommendation, c.analysis_status, c.domain, p.status AS prospect_status, p.next_action_at, " . APE::sqlType() . " AS t
	FROM {$db->table(Tables::LEAD_INTELLIGENCE)} li INNER JOIN {$db->table(Tables::COMPANIES)} c ON c.id = li.company_id
	LEFT JOIN {$db->table(Tables::PROSPECTS)} p ON p.organization_id = li.organization_id AND p.company_id = li.company_id WHERE li.organization_id = %d",
	$orgC
), ARRAY_A) as $r) {
	$sqlTypes[] = ($r['t'] ?? '-') === (APE::classify(app(ActionCenterService::class)->signals($r))['type'] ?? '-');
}
UbrT::ok(count($sqlTypes) === 16 && ! in_array(false, $sqlTypes, true), '43. typ działania w SQL = classify() w PHP dla wszystkich 16 ocen');

$page1 = ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 4, 'page' => 1])['data'];
$page2 = ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 4, 'page' => 2])['data'];
$page3 = ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 4, 'page' => 3])['data'];
UbrT::eq(array_slice($seq, 0, 12), array_map(fn ($i) => $i['company']['id'], array_merge($page1['items'], $page2['items'], $page3['items'])), '44. paginacja priorytetów: strony sklejają się w tę samą kolejność (follow-upy + rekomendacje)');

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — Smart Queues, KPI i liczniki');

$q = fn (string $key, array $p = []) => ubrt_rest($uC, 'GET', '/action-center/queues/' . $key, $p + ['per_page' => 50]);
$ids = fn (array $res) => array_map(fn ($i) => $i['company']['id'], $res['data']['items']);
$exp = [
	'ready_to_contact' => [$c['ready'], $c['twin']],
	'review_website' => [$c['review'], $c['dmProspect']],
	'needs_research' => [$c['noDomain']],
	'verify_company' => [$c['verify']],
	'missing_analysis' => [$c['readyNoAn'], $c['research']], // firma bez oceny jest w „czeka na ocenę”, nie w kolejkach
	'high_potential' => [$c['ready'], $c['twin'], $c['readyNoAn'], $c['review'], $c['talk'], $c['planned']],
	'blocked' => [$c['blocked']],
	'find_decision_maker' => [$c['dmProspect'], $c['planned'], $c['follow'], $c['follow2'], $c['today']], // prospekci przed kontaktem bez wyszukanych decydentów
	'needs_attention' => [$c['review'], $c['dmProspect'], $c['verify'], $c['readyNoAn'], $c['research'], $c['noDomain']],
	'top_opportunities' => array_values(array_diff(array_values($c), [$c['blocked'], $c['won'], $c['noLi']])),
];
foreach ($exp as $key => $want) {
	$res = $q($key);
	$got = $ids($res);
	sort($got);
	sort($want);
	UbrT::eq($want, $got, "45. kolejka {$key}: dokładnie oczekiwane firmy");
	UbrT::eq(count($want), $res['data']['total'], "46. kolejka {$key}: licznik = liczba firm");
}
$kpis = array_column($ov['data']['kpis'], 'value', 'key');
$queues = array_column($ov['data']['queues'], 'count', 'key');
UbrT::eq(['total_prospects' => 7, 'followups_due' => 4, 'ready_to_contact' => 2, 'high_potential' => 6, 'needs_review' => 2, 'missing_analysis' => 2], $kpis, '47. KPI zgodne z rzeczywistymi danymi (7 zapisanych ≠ 17 odkrytych; follow-upy: 2 zaległe + 2 na dziś)');
UbrT::ok(count(array_filter($ov['data']['kpis'], fn ($k) => trim((string) $k['definition']) !== '' && (($k['queue'] && QueueCatalog::exists($k['queue'])) || $k['link'] === 'prospects'))) === 6, '48. każdy KPI ma definicję i drill-down (lista lub Prospects)');
UbrT::eq('Odkryte w wyszukiwaniach: 17', current(array_filter($ov['data']['kpis'], fn ($k) => $k['key'] === 'total_prospects'))['detail'], '48b. Total Prospects odróżnia firmy zapisane od odkrytych');
UbrT::eq('w tym zaległe: 2 · na dziś: 2', current(array_filter($ov['data']['kpis'], fn ($k) => $k['key'] === 'followups_due'))['detail'], '48c. Follow-ups Due rozbija zaległe i dzisiejsze');
foreach ($exp as $key => $want) {
	UbrT::eq(count($want), $queues[$key], "49. licznik kolejki {$key} w widoku głównym = total kolejki");
}
UbrT::eq(6, $queues['followups'], '50. kolejka Follow-ups: wszystkie otwarte follow-upy (6)');
UbrT::ok(! in_array($c['noLi'], $ids($q('missing_analysis')), true) && ! in_array($c['blocked'], $ids($q('high_potential')), true), '51. High Potential bez blokera i bez firm nieocenionych');
UbrT::ok(in_array($c['dmProspect'], $ids($q('find_decision_maker')), true), '52. brak decydenta nie odrzuca firmy — prospekt bez wyszukanych decydentów trafia do „Find Decision Maker”');
UbrT::eq(1, $ov['data']['coverage']['awaiting_evaluation'], '53. firma bez oceny liczona jako „czeka na ocenę”');

$fq = $q('followups', ['bucket' => 'overdue']);
UbrT::eq([$c['follow'], $c['follow2']], $ids($fq), '54. follow-upy zaległe: najstarszy termin pierwszy');
UbrT::eq(2, $q('followups', ['bucket' => 'today'])['data']['total'], '55. follow-upy na dziś: 2');
UbrT::eq(1, $q('followups', ['bucket' => 'upcoming'])['data']['total'], '56. nadchodzące 7 dni: 1 (follow-up za 10 dni poza zakresem)');

// Paginacja, filtry, sortowanie.
$hp1 = $q('high_potential', ['per_page' => 2, 'page' => 1]);
$hp2 = $q('high_potential', ['per_page' => 2, 'page' => 2]);
UbrT::ok(count($hp1['data']['items']) === 2 && $hp1['data']['total'] === 6 && ! array_intersect($ids($hp1), $ids($hp2)), '57. paginacja kolejki bez powtórzeń');
$pros = $ids($q('high_potential', ['scope' => 'prospects']));
sort($pros);
$wantPros = [$c['talk'], $c['planned']];
sort($wantPros);
UbrT::eq($wantPros, $pros, '58. zakres „tylko Prospects”');
UbrT::eq(4, $q('high_potential', ['scope' => 'not_prospects'])['data']['total'], '59. zakres „jeszcze nie w Prospects”');
UbrT::eq(4, $q('high_potential', ['min_lead' => 76])['data']['total'], '60. filtr minimalnego Lead Potential (≥ 76: 80, 80, 85, 76)');
UbrT::eq(1, $q('high_potential', ['search' => 'Do Oceny'])['data']['total'], '61. wyszukiwanie po nazwie');
$byLead = array_map(fn ($i) => $i['lead']['score'], $q('high_potential', ['sort' => 'lead'])['data']['items']);
$sortedLead = $byLead;
rsort($sortedLead);
UbrT::eq($sortedLead, $byLead, '62. sortowanie po Lead Potential malejąco');
UbrT::eq(404, $q('nieznana')['status'], '63. nieznana kolejka → 404');
UbrT::eq(1, $q('needs_research', ['confidence' => 'low'])['data']['total'], '64. filtr Confidence');


/* ------------------------------------------------------------------ */
UbrT::group('Action Center — Top Opportunities, Needs Attention, Ready to Contact');

$top = $q('top_opportunities');
$smart = array_values(array_filter(array_map(fn ($r) => (int) $r['company_id'], $liRepo->top($orgC, 50)), fn ($id) => $id !== $c['won']));
UbrT::eq($smart, $ids($top), '98. Top Opportunities = istniejący Smart Lead Ranking (ta sama kolejność, bez nowego scoringu; bez blokerów i zamkniętych)');
UbrT::ok($ids($top)[0] === $c['talk'] && ! in_array($c['blocked'], $ids($top), true), '99. ranking nie sortuje wyłącznie po Lead Potential (Won 90 wykluczona jako zamknięta, bloker poza listą)');
UbrT::eq(10, count($q('top_opportunities', ['per_page' => 10])['data']['items']), '100. domyślny widok: 10 najlepszych firm, reszta na kolejnych stronach');
UbrT::eq(4, $q('top_opportunities', ['recommendation' => 'review_website'])['data']['total'], '101. filtr Recommended Next Action');
UbrT::eq(0, $q('top_opportunities', ['redesign' => 'strong'])['data']['total'], '102. filtr Redesign Assessment (STRONG: brak)');
UbrT::eq(14, $q('top_opportunities', ['redesign' => 'moderate'])['data']['total'], '103. filtr Redesign Assessment (MODERATE: 14)');
UbrT::eq([$c['review']], $ids($q('top_opportunities', ['region' => 'podkarpackie'])), '104. filtr województwa');
UbrT::eq([$c['review']], $ids($q('top_opportunities', ['industry' => 'maszyn testowa'])), '105. filtr branży');
UbrT::eq(6, $q('top_opportunities', ['scope' => 'prospects'])['data']['total'], '106. zapisane / niezapisane: tylko Prospects (6, bez zamkniętej Won)');
UbrT::eq(8, $q('top_opportunities', ['scope' => 'not_prospects'])['data']['total'], '106b. tylko niezapisane (odkryte, jeszcze nie w Prospects)');
$na = $q('needs_attention')['data']['items'];
UbrT::eq(count($na), count(array_unique(array_map(fn ($i) => $i['company']['id'], $na))), '107. Needs Attention: każda firma raz, z jedną główną kategorią');
$naRes = current(array_filter($na, fn ($i) => $i['company']['id'] === $c['research']));
UbrT::ok($naRes && $naRes['action']['type'] === APE::ANALYSIS_MISSING && $naRes['issues'] === ['Niska kompletność danych (test).'], '108. dodatkowe braki jako informacje pomocnicze (bez powtórzenia głównej kategorii)');
$naTypes = array_map(fn ($i) => $i['action']['type'], $na);
$naTiers = array_map(fn ($t) => APE::TYPES[$t]['tier'], $naTypes);
$naSorted = $naTiers;
sort($naSorted);
UbrT::eq($naSorted, $naTiers, '109. Needs Attention uporządkowane wg kategorii działania');
UbrT::ok(! array_diff(array_unique($naTypes), [APE::FIND_DECISION_MAKER, APE::REVIEW_WEBSITE, APE::VERIFY_COMPANY, APE::ANALYSIS_MISSING, APE::RESEARCH_FURTHER]), '110. Needs Attention obejmuje tylko kategorie: Verify / Review / Research / Find DM / Missing analysis');
$rtc = $q('ready_to_contact')['data']['items'];
$rReady = current(array_filter($rtc, fn ($i) => $i['company']['id'] === $c['ready']));
$rTwin = current(array_filter($rtc, fn ($i) => $i['company']['id'] === $c['twin']));
UbrT::ok($rReady['contact']['name'] === 'Jan Testowy' && $rReady['contact']['title'] === 'Prezes Zarządu' && $rReady['channels'] === ['E-mail ogólny firmy'], '111. Ready to Contact: najlepszy decydent, stanowisko i dostępne kanały z zapisanych danych');
UbrT::ok($rTwin['contact'] === null && $rTwin['channels'] === [], '112. brak decydenta → brak kontaktu (bez generowania e-maili i zgadywania telefonów)');
UbrT::ok(! str_contains(wp_json_encode($rtc), '@ac-twin'), '113. żadnych wymyślonych adresów e-mail');
$pDefault = ubrt_rest($uC, 'GET', '/action-center/priorities')['data'];
UbrT::ok(count($pDefault['items']) === 5 && $pDefault['per_page'] === 5 && $pDefault['total'] === 11, '114. Today\'s Priorities: maksymalnie 5 działań naraz (11 łącznie, kolejne na żądanie)');
UbrT::ok(count($ov['data']['priorities']['items']) <= 5, '115. widok główny pokazuje najwyżej 5 priorytetów');
UbrT::ok(count(array_filter($pAll['items'], fn ($i) => in_array($i['cta']['action'], ['open_sales', 'open_contacts', 'open_lead', 'preview', 'find_dm', 'verify', 'analyze'], true))) === count($pAll['items']), '116. każde CTA prowadzi do istniejącego widoku / akcji (bez martwych przycisków)');

/* ------------------------------------------------------------------ */
UbrT::group('Manual Website Review');

$mr0 = ubrt_rest($uC, 'GET', '/companies/' . $c['review'] . '/manual-review');
UbrT::ok($mr0['status'] === 200 && $mr0['data']['status'] === 'not_reviewed' && $mr0['data']['history'] === [], '117. domyślnie: brak oceny ręcznej');
$mr1 = ubrt_rest($uC2, 'POST', '/companies/' . $c['review'] . '/manual-review', ['review_status' => 'very_poor', 'review_note' => 'Przestarzały wygląd <b>test</b>']);
UbrT::ok($mr1['status'] === 200 && $mr1['data']['status'] === 'very_poor' && $mr1['data']['reviewer']['id'] === $uC2 && $mr1['data']['note'] === 'Przestarzały wygląd test', '118. tworzenie oceny (kto, kiedy, notatka bez HTML)');
$mr2 = ubrt_rest($uC, 'POST', '/companies/' . $c['review'] . '/manual-review', ['review_status' => 'poor']);
UbrT::ok($mr2['data']['status'] === 'poor' && count($mr2['data']['history']) === 2 && $mr2['data']['history'][0]['previous_label'] === 'Bardzo słaba' && $mr2['data']['history'][1]['label'] === 'Bardzo słaba', '119. edycja oceny: historia zachowana (poprzednia → nowa), bez nadpisywania');
UbrT::eq(1, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::MANUAL_REVIEWS)} WHERE organization_id = %d AND company_id = %d", $orgC, $c['review']), '120. jedna bieżąca ocena na firmę w organizacji');
UbrT::eq(422, ubrt_rest($uC, 'POST', '/companies/' . $c['review'] . '/manual-review', ['review_status' => 'excellent'])['status'], '121. walidacja statusu');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/companies/' . $c['review'] . '/manual-review', ['review_status' => 'good'])['status'], '122. IDOR: inna organizacja nie oceni firmy spoza swojej puli');
UbrT::eq(404, ubrt_rest($uB, 'GET', '/companies/' . $c['review'] . '/manual-review')['status'], '123. IDOR: inna organizacja nie odczyta oceny');
UbrT::eq(403, ubrt_rest($uX, 'GET', '/companies/' . $c['review'] . '/manual-review')['status'], '124. użytkownik bez organizacji: 403');
UbrT::eq(401, ubrt_rest(0, 'POST', '/companies/' . $c['review'] . '/manual-review', ['review_status' => 'good'])['status'], '125. anonim: 401');
app(ResultRepository::class)->add($sB, $orgB, $c['review'], 'ubrtest', 1, []);
$mrB = ubrt_rest($uB, 'GET', '/companies/' . $c['review'] . '/manual-review');
UbrT::ok($mrB['status'] === 200 && $mrB['data']['status'] === 'not_reviewed' && $mrB['data']['history'] === [], '126. multi-tenancy: firma widoczna w dwóch organizacjach — ocena C niewidoczna dla B');
UbrT::eq(0, (int) $db->value("SELECT stale FROM {$db->table(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d AND company_id = %d", $orgC, $c['review']), '127. ocena ręczna nie oznacza oceny Lead Intelligence do przeliczenia');
$revRow = current(array_filter($q('review_website')['data']['items'], fn ($i) => $i['company']['id'] === $c['review']));
UbrT::ok($revRow['lead']['score'] === 74 && $revRow['lead']['redesign'] === 'moderate' && $revRow['manual_review']['sentence'] === 'Strona została oceniona ręcznie jako słaba.', '128. lista pokazuje ocenę ręczną jako zdanie — Lead Potential (74) i Redesign (MODERATE) bez zmian');
UbrT::eq([$c['review']], $ids($q('top_opportunities', ['manual_review' => 'poor'])), '129. filtr oceny ręcznej w Top Opportunities');
UbrT::eq(13, $q('top_opportunities', ['manual_review' => 'not_reviewed'])['data']['total'], '130. filtr „brak oceny ręcznej”');
UbrT::eq(1, $q('needs_attention', ['manual_review' => 'poor'])['data']['total'], '131. filtr oceny ręcznej w Needs Attention');

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — My Work / Team Work / Unassigned');

$mine = ubrt_rest($uC2, 'GET', '/action-center/priorities', ['owner' => 'mine', 'per_page' => 50])['data'];
UbrT::eq([$c['today']], array_map(fn ($i) => $i['company']['id'], $mine['items']), '65. My Work członka: tylko jego follow-up i jego prospekci');
$mineOwner = ubrt_rest($uC, 'GET', '/action-center/priorities', ['owner' => 'mine', 'per_page' => 50])['data'];
UbrT::ok(in_array($c['follow'], array_map(fn ($i) => $i['company']['id'], $mineOwner['items']), true) && ! in_array($c['today'], array_map(fn ($i) => $i['company']['id'], $mineOwner['items']), true), '66. My Work właściciela nie zawiera pracy przypisanej komuś innemu');
$un = ubrt_rest($uC, 'GET', '/action-center/queues/high_potential', ['owner' => 'unassigned', 'per_page' => 50])['data'];
UbrT::ok(in_array($c['ready'], array_map(fn ($i) => $i['company']['id'], $un['items']), true), '67. Unassigned: firmy spoza Prospects i prospekci bez właściciela');
UbrT::eq(200, ubrt_rest($uC, 'GET', '/action-center', ['owner' => 'zly'])['status'], '68. nieznana wartość owner → domyślnie Team (bez błędu)');

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — Quick Actions, odłożenie, cache, autoryzacja');

$sn = ubrt_rest($uC, 'POST', '/action-center/snoozes', ['company_id' => $c['review'], 'action_type' => APE::REVIEW_WEBSITE, 'days' => 3]);
UbrT::ok($sn['status'] === 200 && ! empty($sn['data']['snoozed_until']), '69. odłożenie działania o 3 dni');
$after = ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 50])['data'];
UbrT::ok(! in_array($c['review'], array_map(fn ($i) => $i['company']['id'], $after['items']), true) && $after['total'] === $pAll['total'] - 1, '70. odłożone działanie znika z priorytetów, licznik od razu odświeżony (cache wersjonowany)');
$rq = $q('review_website')['data']['items'];
UbrT::ok((current(array_filter($rq, fn ($i) => $i['company']['id'] === $c['review']))['snoozed_until'] ?? null) !== null, '71. w kolejce firma nadal widoczna z oznaczeniem „odłożone”');
ubrt_rest($uC, 'POST', '/action-center/snoozes', ['company_id' => $c['review'], 'action_type' => APE::REVIEW_WEBSITE, 'days' => 7]);
UbrT::eq(1, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ACTION_SNOOZES)} WHERE organization_id = %d AND company_id = %d", $orgC, $c['review']), '72. ponowne odłożenie aktualizuje termin (bez duplikatu)');
UbrT::eq(422, ubrt_rest($uC, 'POST', '/action-center/snoozes', ['company_id' => $c['follow'], 'action_type' => APE::FOLLOW_UP_OVERDUE, 'days' => 3])['status'], '73. follow-upu nie można odłożyć (tylko przełożyć termin)');
UbrT::eq(422, ubrt_rest($uC, 'POST', '/action-center/snoozes', ['company_id' => $c['ready'], 'action_type' => APE::CONTACT_READY, 'days' => 99])['status'], '74. walidacja okresu odłożenia');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/action-center/snoozes', ['company_id' => $c['ready'], 'action_type' => APE::CONTACT_READY, 'days' => 1])['status'], '75. IDOR: inna organizacja nie odłoży działania firmy spoza swojej puli');
UbrT::eq(403, ubrt_rest($uX, 'POST', '/action-center/snoozes', ['company_id' => $c['ready'], 'action_type' => APE::CONTACT_READY, 'days' => 1])['status'], '76. użytkownik bez organizacji: 403');
UbrT::eq(401, ubrt_rest(0, 'GET', '/action-center')['status'], '77. anonim: 401');
UbrT::eq(200, ubrt_rest($uC, 'DELETE', '/action-center/snoozes', ['company_id' => $c['review'], 'action_type' => APE::REVIEW_WEBSITE])['status'], '78. przywrócenie odłożonego działania');
UbrT::ok(in_array($c['review'], array_map(fn ($i) => $i['company']['id'], ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 50])['data']['items']), true), '79. po przywróceniu działanie wraca na listę');

// Oznaczenie follow-upu jako wykonanego istniejącym endpointem → odświeżony widok (bez czekania na TTL cache).
$fid = (int) $db->value("SELECT id FROM {$db->table(Tables::PROSPECT_FOLLOWUPS)} WHERE prospect_id = %d AND status = 'open'", $acPid['follow']);
UbrT::eq(404, ubrt_rest($uB, 'POST', '/followups/' . $fid, ['status' => 'completed'])['status'], '80. IDOR: inna organizacja nie zamknie follow-upu');
ubrt_rest($uC, 'POST', '/followups/' . $fid, ['status' => 'completed']);
$ov2 = ubrt_rest($uC, 'GET', '/action-center')['data'];
UbrT::ok(array_column($ov2['kpis'], 'value', 'key')['followups_due'] === 3 && str_starts_with((string) array_column($ov2['kpis'], 'detail', 'key')['followups_due'], 'w tym zaległe: 1'), '81. po wykonaniu follow-upu licznik zaległych spada od razu (invalidation)');
$p2 = array_map(fn ($i) => $i['type'] . '#' . $i['company']['id'], ubrt_rest($uC, 'GET', '/action-center/priorities', ['per_page' => 50])['data']['items']);
UbrT::ok(in_array(APE::CONTACT_READY . '#' . $c['follow'], $p2, true), '82. bez otwartego follow-upu firma wraca do działań z rekomendacji (Contact Ready)');

$v0 = ActionCenterService::orgVersion($orgC);
$gv0 = ActionCenterService::globalVersion();
do_action('ubr/company/changed', $c['ready'], 'analysis_done', null);
UbrT::ok(ActionCenterService::globalVersion() === $gv0 + 1, '83. zmiana wspólnych danych firmy (analiza/weryfikacja) → nowa wersja globalna cache');
do_action('ubr/company/changed', $c['ready'], 'prospect_added', $orgC);
UbrT::ok(ActionCenterService::orgVersion($orgC) > $v0, '84. zmiana prywatnych danych organizacji → nowa wersja cache organizacji');
$liRepo->markStale($c['ready'], $orgC, 'manual');
$liRepo->save($orgC, $c['ready'], ['stale' => 0]); // przywrócenie stanu po teście

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — izolacja, puste stany, regresje');

$ovB = ubrt_rest($uB, 'GET', '/action-center')['data'];
$bIds = array_map(fn ($i) => $i['company']['id'], $ovB['priorities']['items']);
UbrT::ok(! array_intersect($bIds, array_values($c)), '85. organizacja B nie widzi priorytetów organizacji C');
foreach (['ready_to_contact', 'blocked', 'followups'] as $key) {
	UbrT::ok(! array_intersect(array_map(fn ($i) => $i['company']['id'], ubrt_rest($uB, 'GET', '/action-center/queues/' . $key, ['per_page' => 50])['data']['items']), array_values($c)), "86. kolejka {$key} organizacji B bez firm C");
}
$uE = $mk('ownerE');
$orgE = app(OrganizationService::class)->create('ubrtest Org E', $uE);
$created['orgs'][] = $orgE;
$ovE = ubrt_rest($uE, 'GET', '/action-center');
UbrT::ok($ovE['status'] === 200 && $ovE['data']['priorities']['total'] === 0 && array_sum(array_column($ovE['data']['kpis'], 'value')) === 0 && $ovE['data']['coverage']['items'][0]['value'] === 0, '87. pusta organizacja: 200, zero działań i liczników (stan pusty)');
UbrT::eq([], ubrt_rest($uE, 'GET', '/action-center/queues/high_potential')['data']['items'], '88. pusta kolejka zwraca pustą listę');

$liAfter = $wpdb->get_results($wpdb->prepare("SELECT company_id, lead_score, recommendation, rank_score FROM {$db->table(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d ORDER BY company_id", $orgC), ARRAY_A);
UbrT::eq($liBefore, $liAfter, '89. regresja Lead Intelligence: Action Center nie zmienia wyników, rekomendacji ani rankingu');
$sideAfter = [(int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}"), (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_RUNS)}"), (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::API_REQUESTS)}"), (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CLICKUP_JOBS)}")];
UbrT::eq($sideBefore, $sideAfter, '90. brak nowych analiz, wyszukiwań decydentów, zapytań API i zadań ClickUp');
$dash = ubrt_rest($uC, 'GET', '/dashboard');
UbrT::ok($dash['status'] === 200 && isset($dash['data']['pipeline']['by_status'], $dash['data']['top_leads'], $dash['data']['recent_searches']), '91. regresja Dashboard: dotychczasowy endpoint bez zmian');
UbrT::eq(7, ubrt_rest($uC, 'GET', '/prospects', ['per_page' => 50])['data']['total'], '92. regresja Prospects: lista bez zmian (7 aktywnych)');
UbrT::eq(17, ubrt_rest($uC, 'GET', '/discovery/searches/' . $sC . '/results', ['per_page' => 50])['data']['total'], '93. regresja Discover: wyniki wyszukiwania bez zmian (17)');
$board = ubrt_rest($uC, 'GET', '/pipeline/board');
UbrT::eq(200, $board['status'], '94. regresja Pipeline: tablica działa');

/* ------------------------------------------------------------------ */
UbrT::group('Action Center — strefa czasowa WordPressa');

$tzPrev = get_option('timezone_string');
$offPrev = get_option('gmt_offset');
update_option('timezone_string', 'Pacific/Kiritimati'); // UTC+14 — „dziś” lokalnie różni się od dnia UTC
[$now, $todayEnd, $weekEnd] = ActionCenterRepository::bounds();
$localEnd = (new DateTimeImmutable('today', wp_timezone()))->modify('+1 day -1 second')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
UbrT::eq($localEnd, $todayEnd, '95. koniec „dziś” liczony w strefie WordPressa (UTC+14), zapisany w UTC');
UbrT::ok(strtotime($weekEnd . ' UTC') - strtotime($todayEnd . ' UTC') === 7 * DAY_IN_SECONDS, '96. „najbliższe 7 dni” = 7 pełnych dni lokalnych po dzisiejszym');
update_option('timezone_string', 'America/Los_Angeles');
[, $todayEndLa] = ActionCenterRepository::bounds();
UbrT::ok($todayEndLa !== $todayEnd, '97. zmiana strefy WordPressa zmienia granice dnia (bez sztywnego UTC)');
update_option('timezone_string', $tzPrev);
update_option('gmt_offset', $offPrev);
