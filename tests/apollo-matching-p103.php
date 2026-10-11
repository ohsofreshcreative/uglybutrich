<?php

/**
 * Testy Prompt 10.3 — Apollo Contact Matching & Phone Unlock.
 * Dołączane z tests/run.php PO tests/dm-phones-p102.php (wspólne: $orgD, $orgE, $uD, $uD1, $uE, $uX, $p10mk, $ctr, $db, $wpdb,
 * $ctxD, $ctxE, $sD, $ubrtFakeKey). Apollo WYŁĄCZNIE na mockach (pre_http_request) — wyniki nie potwierdzają działania
 * rzeczywistego API. Dane fikcyjne tylko w bazie testowej (sprzątane po testach przez kaskadę firm / organizacji).
 */

use App\Ubr\Domain\Contacts\ApolloMatcher;
use App\Ubr\Infrastructure\Contacts\ApolloPeopleSource;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\ContactPhoneLinkRepository;
use App\Ubr\Infrastructure\Repositories\PhoneRevealRepository;
use App\Ubr\Services\ApolloContactService;
use App\Ubr\Services\ApolloPhoneService;
use App\Ubr\Services\CallPresenter;

if (! defined('ABSPATH')) {
	exit(1);
}

/* ------------------------------------------------------------------ */
UbrT::group('P10.3 — ApolloMatcher (czysta logika)');

UbrT::ok(ApolloMatcher::maskMatches('Dr***z', 'drozdz') && ApolloMatcher::maskMatches('Wi***z', 'widzisz') && ! ApolloMatcher::maskMatches('Dr***z', 'drozd') && ! ApolloMatcher::maskMatches('Dr***z', 'dz'), 'P10.3-1. maska Apollo: zgodny początek i koniec nazwiska, dłuższe od widocznych liter');
UbrT::ok(! ApolloMatcher::maskMatches('Kowalski', 'kowalski') && ! ApolloMatcher::maskMatches('***', 'kowalski'), 'P10.3-1. bez gwiazdek albo bez widocznych liter — to nie jest maska do porównania');
$mc = ['id' => 1, 'first_name' => 'Aleksandra', 'last_name' => 'Drożdż', 'full_name' => 'Aleksandra Drożdż', 'normalized_role' => 'marketing_manager'];
$mm = ApolloMatcher::match(['apollo_person_id' => 'apX0000001', 'first_name' => 'Aleksandra', 'last_name' => null, 'last_name_obfuscated' => 'Dr***z', 'normalized_role' => 'marketing_manager', 'belongs_to_company' => 1], [$mc]);
UbrT::ok($mm[0]['level'] === ApolloMatcher::STRONG && $mm[0]['contact_id'] === 1, 'P10.3-1. maska + imię + to samo stanowisko → STRONG (nie EXACT)');
$mmFull = ApolloMatcher::match(['apollo_person_id' => 'apX0000001', 'first_name' => 'Aleksandra', 'last_name' => 'Drożdż', 'normalized_role' => 'marketing_manager', 'belongs_to_company' => 1], [$mc]);
UbrT::eq(ApolloMatcher::EXACT, $mmFull[0]['level'], 'P10.3-1. pełne imię i nazwisko + firma + zgodne stanowisko → EXACT');
$mmNo = ApolloMatcher::match(['apollo_person_id' => 'apX0000001', 'first_name' => 'Aleksandra', 'last_name_obfuscated' => 'Ko***i', 'belongs_to_company' => 1], [$mc]);
UbrT::eq(ApolloMatcher::NO_MATCH, $mmNo[0]['level'], 'P10.3-1. imię zgodne, nazwisko sprzeczne z maską → NO_MATCH (bez zgadywania)');
$allLevels = [];
foreach ([['marketing_manager'], ['ceo'], [null]] as [$r]) {
	$allLevels[] = ApolloMatcher::match(['apollo_person_id' => 'apX0000001', 'first_name' => 'Aleksandra', 'last_name_obfuscated' => 'Dr***z', 'normalized_role' => $r, 'belongs_to_company' => 1], [$mc])[0]['level'];
}
UbrT::ok(! in_array(ApolloMatcher::EXACT, $allLevels, true), 'P10.3-1. zamaskowane nazwisko NIGDY nie daje EXACT (' . implode(', ', $allLevels) . ')');

/* ------------------------------------------------------------------ */
UbrT::group('P10.3 — fixture: firma i kontakty (fikcyjne, tylko baza testowa)');

$R = [
	'acme' => $p10mk('p103-acme', 'P103 Acme', 'https://p103-acme.ubrtest.pl/', [], true),
	'nodom' => $p10mk('p103-nodom', 'P103 Bez Domeny', 'https://p103-nodom.ubrtest.pl/'),
	'mis' => $p10mk('p103-mis', 'P103 Inny Podmiot', 'https://p103-mis.ubrtest.pl/'),
];
$wpdb->update($db->table(Tables::COMPANIES), ['domain' => null, 'website_url' => null], ['id' => $R['nodom']]);
$wpdb->update($db->table(Tables::COMPANIES), ['domain_status' => 'mismatch'], ['id' => $R['mis']]);
$acmeDomain = ApolloContactService::domainOf(app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->find($R['acme']));
UbrT::eq('p103-acme.ubrtest.pl', $acmeDomain, 'P10.3-2. domena firmy ustalona z danych firmy');
$mkC = function (string $name, string $role, string $title, array $extra = []) use ($ctr, $R) {
	return $ctr->insert($extra + [
		'company_id' => $R['acme'], 'org_key' => 0, 'person_key' => 'p103-' . md5($name), 'first_name' => strtok($name, ' '), 'last_name' => substr($name, strpos($name, ' ') + 1),
		'full_name' => $name, 'job_title' => $title, 'normalized_role' => $role, 'verification_status' => 'probable', 'confidence_level' => 'medium',
		'contact_priority_score' => 70, 'rank_position' => 2, 'source_type' => 'WEBSITE', 'needs_review' => 0, 'active' => 1,
	]);
};
$C = [
	'drozdz' => $mkC('Aleksandra Drożdż', 'marketing_manager', 'Marketing Manager'),
	'widzisz' => $mkC('Michał Widzisz', 'marketing_specialist', 'Specjalista ds. marketingu'),
	'kiedewicz' => $mkC('Ewelina Kiedewicz', 'marketing_specialist', 'Specjalistka ds. marketingu'),
	'nowak' => $mkC('Jan Nowak', 'ceo', 'Prezes Zarządu'),
	'nowaczek' => $mkC('Jan Nowaczek', 'vice_president', 'Wiceprezes Zarządu'),
	'akowalska' => $mkC('Anna Kowalska', 'marketing_director', 'Dyrektor Marketingu'),
	'bkowalska' => $mkC('Barbara Kowalska', 'ceo', 'Prezes'),
	'rogowski' => $mkC('Robert Rogowski', 'vice_president', 'Wiceprezes Zarządu'),
	'celina' => $mkC('Celina Apollowa', 'marketing_director', 'Dyrektor Marketingu', ['external_provider' => 'apollo', 'external_id' => 'p103cel001']),
	'dawid' => $mkC('Dawid Konfliktowy', 'marketing_manager', 'Marketing Manager', ['external_provider' => 'apollo', 'external_id' => 'p103dwd999']),
];
$p103people = [
	['id' => 'p103drz001', 'first_name' => 'Aleksandra', 'last_name_obfuscated' => 'Dr***z', 'title' => 'Marketing Manager', 'has_direct_phone' => 'Yes', 'organization' => ['id' => 'orgP103', 'primary_domain' => 'p103-acme.ubrtest.pl']],
	['id' => 'p103wdz001', 'first_name' => 'Michal', 'last_name_obfuscated' => 'Wi***z', 'title' => 'Marketing Specialist', 'has_direct_phone' => 'Maybe: please request direct dial via people/bulk_match'],
	['id' => 'p103kdw001', 'first_name' => 'Ewelina', 'last_name_obfuscated' => 'Ki***z', 'title' => null],
	['id' => 'p103nwk001', 'first_name' => 'Jan', 'last_name_obfuscated' => 'No***k', 'title' => 'CEO', 'has_direct_phone' => 'No'],
	['id' => 'p103kow001', 'first_name' => 'Anna', 'last_name_obfuscated' => 'Ko***a', 'title' => 'Marketing Director', 'has_direct_phone' => 'Yes'],
	['id' => 'p103rog001', 'first_name' => 'Robert', 'last_name' => 'Rogowski', 'title' => 'Vice President', 'has_direct_phone' => 'Yes'],
	['id' => 'p103cel001', 'first_name' => 'Celina', 'last_name_obfuscated' => 'Ap***a', 'title' => 'Head of Marketing'],
	['id' => 'p103dwd001', 'first_name' => 'Dawid', 'last_name_obfuscated' => 'Ko***y', 'title' => 'Marketing Manager'],
	['id' => 'p103zen001', 'first_name' => 'Zenon', 'last_name_obfuscated' => 'Ze***r', 'title' => 'Owner <script>alert(1)</script>'],
	['id' => 'p103grp001', 'first_name' => 'Grzegorz', 'last_name_obfuscated' => 'Gr***p', 'title' => 'CEO', 'organization' => ['id' => 'orgP103GRP', 'primary_domain' => 'p103-acme-group.ubrtest.pl', 'name' => 'Ubrtest P103 Acme']],
	['id' => 'p103drz001', 'first_name' => 'Aleksandra', 'last_name_obfuscated' => 'Dr***z', 'title' => 'Marketing Manager'],
	['first_name' => 'Bez', 'last_name_obfuscated' => 'Id***r', 'title' => 'CEO'],
];
$p103mock = ['code' => 200, 'body' => ['people' => $p103people, 'total_entries' => 12]];
$p103http = [];
$p103filter = function ($pre, $args, $url) use (&$p103mock, &$p103http) {
	if (! str_contains($url, 'api.apollo.io')) {
		return $pre;
	}

	$p103http[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'key_in_header' => ($args['headers']['x-api-key'] ?? '') !== ''];

	if ($p103mock === 'network') {
		return new WP_Error('http_request_failed', 'cURL error 28: timeout');
	}

	return ['headers' => [], 'body' => wp_json_encode($p103mock['body']), 'response' => ['code' => $p103mock['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $p103filter, PHP_INT_MAX - 2, 3);
$acs = app(ApolloContactService::class);
$p103clear = function () use ($wpdb, $R) {
	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_apollo\\_people\\_%'") as $o) {
		delete_transient(substr($o, strlen('_transient_'))); // tylko pamięć podręczna odpowiedzi People Search
	}

	delete_option('ubr_lock_apollo_people_' . $R['acme']);
	delete_transient('ubr_rl_apollo');
};
$p103clear();

/* ------------------------------------------------------------------ */
UbrT::group('P10.3 — „Szukaj decydentów w Apollo” (mock, zgoda, cache, limity)');

$cand0 = ubrt_rest($uD, 'GET', '/companies/' . $R['acme'] . '/apollo-candidates');
UbrT::ok($cand0['status'] === 200 && $cand0['data']['candidates'] === [] && count($p103http) === 0, 'P10.3-2. widok kandydatów (otwarcie karty) — tylko odczyt z bazy, 0 zapytań do Apollo');
$dis = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
UbrT::ok($dis['status'] === 200 && $dis['data']['search']['state'] === 'disabled' && $dis['data']['search']['executed'] === false && count($p103http) === 0, 'P10.3-2. funkcja wyłączona (UBR_APOLLO_PEOPLE_ENABLED) — nic nie zostało wysłane');
add_filter('ubr/contacts/apollo_people_enabled', '__return_true');
$nc = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', []);
UbrT::ok($nc['status'] === 200 && $nc['data']['search']['state'] === 'confirm_required' && str_contains($nc['data']['search']['message'], 'potwierdź') && count($p103http) === 0, 'P10.3-2. bez świadomej zgody (confirm) — informacja o koszcie, 0 zapytań');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/companies/' . $R['nodom'] . '/apollo-candidates/search', ['confirm' => true])['status'], 'P10.3-2. firma bez domeny → 422 (bez zapytania)');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/companies/' . $R['mis'] . '/apollo-candidates/search', ['confirm' => true])['status'], 'P10.3-2. domena innego podmiotu → 422 (bez zapytania)');
UbrT::eq(403, ubrt_rest($uX, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true])['status'], 'P10.3-2. użytkownik spoza organizacji → 403');
UbrT::eq(0, count($p103http), 'P10.3-2. odrzucone żądania nie wysłały zapytań');

$s1 = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
$s1url = urldecode((string) ($p103http[0]['url'] ?? ''));
UbrT::ok($s1['status'] === 200 && $s1['data']['search']['executed'] === true && count($p103http) === 1 && str_contains($s1url, '/mixed_people/api_search') && str_contains($s1url, 'q_organization_domains_list[]=p103-acme.ubrtest.pl') && str_contains($s1url, 'per_page=10'), 'P10.3-3. jedno zapytanie People API Search z domeną firmy i tytułami decydentów');
UbrT::ok(! str_contains($s1url, (string) $ubrtFakeKey) && $p103http[0]['key_in_header'] === true && ! str_contains(wp_json_encode($s1['data']), (string) $ubrtFakeKey), 'P10.3-3. klucz API tylko w nagłówku — nie w adresie ani w odpowiedzi');
UbrT::eq(0, (int) $db->value("SELECT billable FROM {$db->table(Tables::API_REQUESTS)} WHERE organization_id = %d AND endpoint = %s ORDER BY id DESC LIMIT 1", $orgD, ApolloPeopleSource::ENDPOINT), 'P10.3-3. zapytanie zapisane w dzienniku API jako niepłatne (wg dokumentacji)');
UbrT::eq(10, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::APOLLO_PEOPLE)} WHERE company_id = %d", $R['acme']), 'P10.3-3. kandydaci bez duplikatów (ten sam identyfikator dwa razy → 1), osoba bez identyfikatora pominięta');
$cands = [];
foreach ($s1['data']['candidates'] as $c) {
	$cands[$c['first_name']] = $c;
}
$lvl = fn (string $first) => $cands[$first]['top']['level'] ?? null;
$st = fn (string $first) => $cands[$first]['top']['status'] ?? null;
UbrT::ok($lvl('Aleksandra') === 'STRONG' && $st('Aleksandra') === 'SUGGESTED' && (int) $cands['Aleksandra']['top']['contact_id'] === $C['drozdz'], 'P10.3-4. pełne imię + maska + to samo stanowisko → STRONG, wymaga zatwierdzenia');
UbrT::ok($lvl('Michal') === 'STRONG' && (int) $cands['Michal']['top']['contact_id'] === $C['widzisz'], 'P10.3-4. „Michal” (bez polskich znaków) ↔ „Michał” + maska + ten sam obszar → STRONG');
UbrT::ok($lvl('Ewelina') === 'POSSIBLE', 'P10.3-4. maska bez stanowiska → POSSIBLE (za mało dowodów)');
$janLevels = array_column(array_filter($cands['Jan']['matches'], fn ($m) => $m['contact_id'] > 0), 'level', 'contact_id');
UbrT::ok(($janLevels[$C['nowak']] ?? null) === 'POSSIBLE' && ($janLevels[$C['nowaczek']] ?? null) === 'POSSIBLE', 'P10.3-4. podobne nazwiska (Nowak / Nowaczek pasują do maski „No***k”) → oba tylko POSSIBLE, bez automatycznego wyboru');
UbrT::ok($lvl('Anna') === 'STRONG' && (int) $cands['Anna']['top']['contact_id'] === $C['akowalska'] && ! in_array($C['bkowalska'], array_column($cands['Anna']['matches'], 'contact_id'), true), 'P10.3-4. dwie osoby o tym samym nazwisku — dopasowana tylko osoba ze zgodnym imieniem');
UbrT::ok($lvl('Robert') === 'EXACT' && $st('Robert') === 'APPROVED' && $cands['Robert']['top']['decided_by_system'] === true, 'P10.3-4. pełne imię i nazwisko z Apollo + firma + stanowisko → EXACT, zatwierdzone automatycznie');
UbrT::ok($lvl('Celina') === 'EXACT' && $st('Celina') === 'APPROVED', 'P10.3-4. istniejący identyfikator Apollo przy kontakcie → EXACT');
UbrT::ok($lvl('Dawid') === 'CONFLICT' && $st('Dawid') === 'SUGGESTED' && $cands['Dawid']['top']['can_approve'] === false, 'P10.3-4. kontakt ma INNY identyfikator Apollo → CONFLICT, nie można zatwierdzić');
UbrT::ok($lvl('Zenon') === 'NO_MATCH' && (int) $cands['Zenon']['top']['contact_id'] === 0, 'P10.3-4. brak kontaktu → NO_MATCH');
UbrT::ok($lvl('Grzegorz') === 'CONFLICT' && $cands['Grzegorz']['belongs_to_company'] === false, 'P10.3-4. podobna nazwa firmy, inna domena w Apollo → osoba spoza firmy (CONFLICT)');
UbrT::ok($cands['Aleksandra']['name_masked'] === true && $cands['Aleksandra']['display_name'] === 'Aleksandra Dr***z' && $cands['Robert']['name_masked'] === false, 'P10.3-4. zamaskowane nazwisko pokazane jako maska (bez odtwarzania)');
UbrT::ok($cands['Aleksandra']['phone_availability'] === 'AVAILABLE' && $cands['Michal']['phone_availability'] === 'UNKNOWN' && $cands['Ewelina']['phone_availability'] === 'UNKNOWN' && $cands['Jan']['phone_availability'] === 'UNAVAILABLE' && $cands['Ewelina']['phone_availability_label'] === 'Dostępność telefonu nieznana', 'P10.3-5. dostępność telefonu: „Yes” → AVAILABLE, „Maybe…” / brak → UNKNOWN („Dostępność telefonu nieznana”), „No” → UNAVAILABLE');
UbrT::ok(! array_filter($s1['data']['candidates'], fn ($c) => $c['phone_availability'] === 'LOCKED'), 'P10.3-5. LOCKED nie jest ustawiany (API nie podaje tego wprost)');
$s1json = wp_json_encode($s1['data']);
UbrT::ok(! str_contains($s1json, 'p103drz001') && ! str_contains($s1json, 'apollo_person_id') && ! str_contains($s1json, 'orgP103'), 'P10.3-5. identyfikatory Apollo (osoby / firmy) nie trafiają do przeglądarki');
UbrT::ok((string) $ctr->find($C['drozdz'])['external_id'] === '' && (string) $ctr->find($C['rogowski'])['external_id'] === '', 'P10.3-5. kontakty wspólne bez zmian (identyfikator Apollo tylko w dopasowaniu organizacji)');

$p103http = [];
$s2 = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
UbrT::ok($s2['status'] === 200 && $s2['data']['search']['cached'] === true && count($p103http) === 0, 'P10.3-6. ponowne wyszukanie → pamięć podręczna, 0 zapytań');
$matchCount = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE organization_id = %d AND company_id = %d", $orgD, $R['acme']);
$acs->rematch($orgD, $R['acme']);
UbrT::eq($matchCount, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE organization_id = %d AND company_id = %d", $orgD, $R['acme']), 'P10.3-6. ponowne dopasowanie nie tworzy duplikatów');
$p103clear();
$cool = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
UbrT::ok($cool['status'] === 429 && count($p103http) === 0, 'P10.3-6. ta sama firma w ciągu 24 h (bez cache) → 429, bez zapytania');
delete_transient('ubr_apollo_search_done_' . $R['acme']);
add_option('ubr_lock_apollo_people_' . $R['acme'], time(), '', false);
UbrT::ok(ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true])['status'] === 429 && count($p103http) === 0, 'P10.3-6. równoległe wyszukiwanie tej samej firmy (blokada) → 429, bez drugiego zapytania');
delete_option('ubr_lock_apollo_people_' . $R['acme']);
$dayKey = 'ubr_apollo_search_day_' . $orgD . '_' . gmdate('Ymd');
$dayPrev = (int) get_transient($dayKey);
set_transient($dayKey, ApolloContactService::dailyLimit(), DAY_IN_SECONDS);
UbrT::ok(ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true])['status'] === 429 && count($p103http) === 0, 'P10.3-6. dzienny limit organizacji → 429');
set_transient($dayKey, $dayPrev, DAY_IN_SECONDS);

foreach ([[403, 'klucz master', 'brak uprawnień'], [429, 'limit zapytań', 'limit API'], ['network', 'brak odpowiedzi', 'błąd sieci']] as [$code, $frag, $label]) {
	$p103clear();
	delete_transient('ubr_apollo_search_done_' . $R['acme']);
	$p103mock = $code === 'network' ? 'network' : ['code' => $code, 'body' => ['error' => 'test']];
	$before = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::APOLLO_PEOPLE)} WHERE company_id = %d", $R['acme']);
	$http0 = count($p103http);
	$er = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
	UbrT::ok($er['status'] === 200 && $er['data']['search']['state'] === 'error' && str_contains($er['data']['search']['message'], $frag) && count($p103http) === $http0 + 1 && count($er['data']['candidates']) === $before, "P10.3-7. API: {$label} → czytelny błąd, jedno zapytanie (bez ponawiania), kandydaci bez zmian");
}
$p103clear();
delete_transient('ubr_apollo_search_done_' . $R['acme']);
$p103mock = ['code' => 200, 'body' => ['people' => []]];
$em = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
UbrT::ok($em['status'] === 200 && $em['data']['search']['found'] === 0 && count($em['data']['candidates']) === 10, 'P10.3-7. API: brak osób → 0 nowych, istniejący kandydaci i decyzje zachowane');
$p103clear();
delete_transient('ubr_apollo_search_done_' . $R['acme']);
$p103mock = ['code' => 200, 'body' => ['people' => $p103people]];

/* ------------------------------------------------------------------ */
UbrT::group('P10.3 — decyzje: zatwierdź / odrzuć / odłącz / połącz / sprawdź ponownie');

$mid = fn (string $first, int $contactId) => (int) $db->value("SELECT m.id FROM {$db->table(Tables::APOLLO_MATCHES)} m JOIN {$db->table(Tables::APOLLO_PEOPLE)} p ON p.id = m.candidate_id WHERE m.organization_id = %d AND p.company_id = %d AND p.first_name = %s AND m.contact_id = %d", $orgD, $R['acme'], $first, $contactId);
$candId = fn (string $first) => (int) $db->value("SELECT id FROM {$db->table(Tables::APOLLO_PEOPLE)} WHERE company_id = %d AND first_name = %s", $R['acme'], $first);
UbrT::eq(null, $acs->apolloIdFor($orgD, $C['drozdz']), 'P10.3-8. propozycja STRONG nie przypisuje identyfikatora Apollo przed zatwierdzeniem');
UbrT::eq(404, ubrt_rest($uE, 'POST', '/apollo-matches/' . $mid('Aleksandra', $C['drozdz']), ['action' => 'approve'])['status'], 'P10.3-8. inna organizacja nie może zatwierdzić dopasowania D (IDOR) → 404');
UbrT::eq(403, ubrt_rest($uX, 'POST', '/apollo-matches/' . $mid('Aleksandra', $C['drozdz']), ['action' => 'approve'])['status'], 'P10.3-8. użytkownik spoza organizacji → 403');
$ap = ubrt_rest($uD1, 'POST', '/apollo-matches/' . $mid('Aleksandra', $C['drozdz']), ['action' => 'approve', 'note' => '<b>Sprawdzone</b> na LinkedIn firmy']);
UbrT::ok($ap['status'] === 200 && $acs->apolloIdFor($orgD, $C['drozdz']) === 'p103drz001', 'P10.3-8. zatwierdzenie STRONG → identyfikator Apollo przypisany do kontaktu w organizacji');
$hist = $db->row("SELECT * FROM {$db->table(Tables::APOLLO_MATCH_HISTORY)} WHERE match_id = %d AND change_type = 'approve'", $mid('Aleksandra', $C['drozdz']));
UbrT::ok($hist && (int) $hist['user_id'] === $uD1 && $hist['from_status'] === 'SUGGESTED' && $hist['to_status'] === 'APPROVED' && $hist['note'] === 'Sprawdzone na LinkedIn firmy', 'P10.3-8. historia: kto, kiedy, z jakiego statusu, notatka bez HTML');
$mrow = $db->row("SELECT * FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE id = %d", $mid('Aleksandra', $C['drozdz']));
UbrT::ok((int) $mrow['decided_by'] === $uD1 && $mrow['decided_at'] && $mrow['last_verified_at'] && $mrow['match_source'] === 'PEOPLE_SEARCH' && $mrow['evidence'], 'P10.3-8. zapisane: źródło, dowody, data dopasowania, zatwierdzający, data weryfikacji');
UbrT::eq(null, $acs->apolloIdFor($orgE, $C['drozdz']), 'P10.3-8. zatwierdzenie w organizacji D nie działa w organizacji E (izolacja)');
$candE = ubrt_rest($uE, 'GET', '/companies/' . $R['acme'] . '/apollo-candidates');
UbrT::ok($candE['status'] === 200 && ! array_filter($candE['data']['candidates'], fn ($c) => $c['matches'] !== []), 'P10.3-8. organizacja E widzi kandydatów firmy, ale nie dopasowań organizacji D');
UbrT::eq(200, ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Aleksandra', $C['drozdz']), ['action' => 'approve'])['status'], 'P10.3-8. ponowne zatwierdzenie — idempotentne');

$acs->rematch($orgD, $R['acme']);
$janNowak = $mid('Jan', $C['nowak']);
UbrT::eq(422, ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Zenon', 0), ['action' => 'approve'])['status'], 'P10.3-9. NO_MATCH nie może być zatwierdzone');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Dawid', $C['dawid']), ['action' => 'approve'])['status'], 'P10.3-9. CONFLICT (inny identyfikator przy kontakcie) nie może być zatwierdzony');
$approveJan = ubrt_rest($uD, 'POST', '/apollo-matches/' . $janNowak, ['action' => 'approve']);
UbrT::ok($approveJan['status'] === 200 && $acs->apolloIdFor($orgD, $C['nowak']) === 'p103nwk001', 'P10.3-9. POSSIBLE — zatwierdzenie tylko świadomą decyzją użytkownika');
UbrT::eq(409, ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Jan', $C['nowaczek']), ['action' => 'approve'])['status'], 'P10.3-9. ten sam identyfikator Apollo dla drugiego kontaktu → 409 (konflikt), bez nadpisania');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/apollo-candidates/' . $candId('Zenon') . '/link', ['contact_id' => $C['nowak']])['status'], 'P10.3-9. ręczne połączenie przy sprzecznym imieniu / nazwisku → 422 (bez zgadywania)');
$acs->rematch($orgD, $R['acme']);
$nwRow = $db->row("SELECT match_level, match_status FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE id = %d", $mid('Jan', $C['nowaczek']));
UbrT::ok($nwRow['match_level'] === 'CONFLICT' && $nwRow['match_status'] === 'SUGGESTED', 'P10.3-9. po zatwierdzeniu: drugi kontakt dla tego identyfikatora oznaczony jako CONFLICT');

$rej = ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Michal', $C['widzisz']), ['action' => 'reject']);
UbrT::ok($rej['status'] === 200 && $db->value("SELECT match_status FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE id = %d", $mid('Michal', $C['widzisz'])) === 'REJECTED', 'P10.3-10. odrzucenie dopasowania');
$p103clear();
delete_transient('ubr_apollo_search_done_' . $R['acme']);
$p103http = [];
$re = ubrt_rest($uD, 'POST', '/companies/' . $R['acme'] . '/apollo-candidates/search', ['confirm' => true]);
UbrT::ok($re['status'] === 200 && count($p103http) === 1 && $db->value("SELECT match_status FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE id = %d", $mid('Michal', $C['widzisz'])) === 'REJECTED' && $acs->apolloIdFor($orgD, $C['drozdz']) === 'p103drz001', 'P10.3-10. ponowne wyszukanie nie nadpisuje decyzji (odrzucone zostaje odrzucone, zatwierdzone — zatwierdzone)');
UbrT::eq(1, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE organization_id = %d AND candidate_id = %d AND contact_id = %d", $orgD, $candId('Michal'), $C['widzisz']), 'P10.3-10. ponowne wyszukanie — bez duplikatów dopasowań');
$det = ubrt_rest($uD, 'POST', '/apollo-matches/' . $janNowak, ['action' => 'detach']);
UbrT::ok($det['status'] === 200 && $acs->apolloIdFor($orgD, $C['nowak']) === null, 'P10.3-10. odłączenie → kontakt bez identyfikatora Apollo');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/apollo-matches/' . $janNowak, ['action' => 'detach'])['status'], 'P10.3-10. odłączyć można tylko zatwierdzone dopasowanie');
$p103http = [];
$rc = ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Ewelina', $C['kiedewicz']), ['action' => 'recheck']);
UbrT::ok($rc['status'] === 200 && count($p103http) === 0 && $db->value("SELECT change_type FROM {$db->table(Tables::APOLLO_MATCH_HISTORY)} WHERE match_id = %d ORDER BY id DESC LIMIT 1", $mid('Ewelina', $C['kiedewicz'])) === 'recheck', 'P10.3-10. „Sprawdź ponownie” — bez zapytania do Apollo, wpis w historii');
$lk = ubrt_rest($uD, 'POST', '/apollo-candidates/' . $candId('Ewelina') . '/link', ['contact_id' => $C['kiedewicz']]);
UbrT::ok($lk['status'] === 200 && $acs->apolloIdFor($orgD, $C['kiedewicz']) === 'p103kdw001' && $db->value("SELECT match_source FROM {$db->table(Tables::APOLLO_MATCHES)} WHERE id = %d", $mid('Ewelina', $C['kiedewicz'])) === 'MANUAL', 'P10.3-10. „Połącz z kontaktem” — ręczne połączenie (źródło MANUAL)');
// Druga osoba w Apollo pasująca do tej samej maski — kontakt ma już zatwierdzony INNY identyfikator.
$acs->store($R['acme'], 'p103-acme.ubrtest.pl', 'orgP103', [['id' => 'p103drz002', 'first_name' => 'Aleksandra', 'last_name_obfuscated' => 'Dr***z', 'title' => 'Marketing Manager']], $orgD);
$acs->rematch($orgD, $R['acme']);
UbrT::eq('CONFLICT', $db->value("SELECT m.match_level FROM {$db->table(Tables::APOLLO_MATCHES)} m JOIN {$db->table(Tables::APOLLO_PEOPLE)} p ON p.id = m.candidate_id WHERE m.organization_id = %d AND p.apollo_person_id = 'p103drz002' AND m.contact_id = %d", $orgD, $C['drozdz']), 'P10.3-10. drugi identyfikator Apollo dla kontaktu z zatwierdzonym identyfikatorem → CONFLICT');
$cid2 = (int) $db->value("SELECT id FROM {$db->table(Tables::APOLLO_PEOPLE)} WHERE apollo_person_id = 'p103drz002'");
UbrT::ok(ubrt_rest($uD, 'POST', '/apollo-candidates/' . $cid2 . '/link', ['contact_id' => $C['drozdz']])['status'] === 409 && $acs->apolloIdFor($orgD, $C['drozdz']) === 'p103drz001', 'P10.3-10. ręczne połączenie przy konflikcie identyfikatorów → 409, przypisanie bez zmian');
$hl = ubrt_rest($uD, 'GET', '/apollo-matches/' . $mid('Aleksandra', $C['drozdz']) . '/history');
UbrT::ok($hl['status'] === 200 && count($hl['data']['history']) >= 2 && ubrt_rest($uE, 'GET', '/apollo-matches/' . $mid('Aleksandra', $C['drozdz']) . '/history')['data']['history'] === [], 'P10.3-10. historia dopasowania dostępna tylko w organizacji');
$hRows = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::APOLLO_MATCH_HISTORY)} WHERE organization_id = %d", $orgD);
UbrT::ok($hRows >= 10, "P10.3-10. każda zmiana zapisana w historii ({$hRows} wpisów)");

/* ------------------------------------------------------------------ */
UbrT::group('P10.3 — Phone Unlock (mocki; płatne wykonanie zablokowane po stronie serwera)');

$p103http = [];
$rv = app(ApolloPhoneService::class);
$pf = $rv->preflight($ctxD, $C['drozdz']);
UbrT::ok(! $pf['can_unlock'] && in_array('disabled', array_column($pf['reasons'], 'code'), true) && $pf['confirm_token'] === null, 'P10.3-11. domyślnie zablokowane (UBR_APOLLO_PHONE_ENABLED) — brak tokenu potwierdzenia');
$tokFn = (new ReflectionClass(ApolloPhoneService::class))->getMethod('token');
$tokFn->setAccessible(true);
$exp = time() + 300;
$forged = $tokFn->invoke($rv, $ctxD, $C['drozdz'], 'first', $exp) . '.' . $exp;
$byp = ubrt_rest($uD, 'POST', '/contacts/' . $C['drozdz'] . '/phone-reveal', ['confirm_token' => $forged, 'acknowledge_unknown_cost' => true]);
UbrT::ok($byp['status'] === 422 && ($byp['data']['code'] ?? '') === 'ubr_disabled' && count($p103http) === 0 && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PHONE_REVEALS)} WHERE contact_id = %d", $C['drozdz']) === 0, 'P10.3-11. bezpośrednie wywołanie REST z poprawnie podpisanym tokenem przy wyłączonej funkcji → 422, 0 zapytań, brak zlecenia');
$rq = (new ReflectionClass(ApolloPhoneService::class))->getMethod('request');
$rq->setAccessible(true);
$fakeRes = app(PhoneRevealRepository::class)->reserve($orgD, $C['drozdz'], $R['acme'], $uD, 'p103-guard-' . wp_generate_password(6, false));
$rq->invoke($rv, $fakeRes['id'], 'p103drz001', $orgD);
UbrT::ok(count($p103http) === 0 && app(PhoneRevealRepository::class)->find($fakeRes['id'])['status'] === 'error', 'P10.3-11. obrona w głąb: wewnętrzne wywołanie zapytania przy wyłączonej funkcji nic nie wysyła');
$wpdb->delete($db->table(Tables::PHONE_REVEALS), ['id' => $fakeRes['id']]);

// Testy P10 wyczerpały dzienny limit odblokowań organizacji D (sztuczne zlecenia „p10-limit-*”) — usuwamy te dane testowe.
$wpdb->query($wpdb->prepare("DELETE FROM {$db->table(Tables::PHONE_REVEALS)} WHERE organization_id = %d AND idempotency_key LIKE 'p10-limit-%%'", $orgD));
add_filter('ubr/apollo/phone_enabled', '__return_true');
$pf2 = ubrt_rest($uD, 'GET', '/contacts/' . $C['drozdz'] . '/phone-reveal')['data'];
UbrT::ok($pf2['can_unlock'] && $pf2['cost']['known'] === false && $pf2['cost']['label'] === 'Koszt wymaga potwierdzenia' && $pf2['cost']['documented']['max_credits'] === 9 && is_string($pf2['confirm_token']) && count($p103http) === 0, 'P10.3-12. zatwierdzony identyfikator Apollo → możliwe zlecenie; koszt „wymaga potwierdzenia” (stawki z dokumentacji oznaczone jako dokumentacja); 0 zapytań');
UbrT::ok(in_array('no_apollo_id', array_column($rv->preflight($ctxD, $C['widzisz'])['reasons'], 'code'), true) && in_array('no_apollo_id', array_column($rv->preflight($ctxD, $C['nowak'])['reasons'], 'code'), true), 'P10.3-12. bez zatwierdzonego identyfikatora (odrzucone / odłączone / propozycja) → nie można odblokować');
app(ContactPhoneLinkRepository::class)->decide($orgD, $C['rogowski'], $R['acme'], '+48601103001', 'VERIFIED', 'MANUAL_CONFIRMED', $uD);
UbrT::ok(in_array('verified_exists', array_column($rv->preflight($ctxD, $C['rogowski'])['reasons'], 'code'), true), 'P10.3-12. numer potwierdzony już zapisany przy osobie → bez ponownego pobierania');
$preM = ubrt_rest($uD1, 'GET', '/contacts/' . $C['drozdz'] . '/phone-reveal')['data'];
UbrT::ok(in_array('forbidden', array_column($preM['reasons'], 'code'), true) && ubrt_rest($uD1, 'POST', '/contacts/' . $C['drozdz'] . '/phone-reveal', ['confirm_token' => $pf2['confirm_token'], 'acknowledge_unknown_cost' => true])['status'] === 403, 'P10.3-12. członek bez uprawnienia contacts.reveal → „forbidden” / 403');
UbrT::ok(in_array('no_apollo_id', array_column($rv->preflight($ctxE, $C['drozdz'])['reasons'], 'code'), true), 'P10.3-12. organizacja E nie korzysta z identyfikatora zatwierdzonego w D');

$p103mock = ['code' => 200, 'body' => ['request_id' => '987654321', 'person' => ['id' => 'p103drz001']]];
delete_transient('ubr_rl_apollo');
$p103http = [];
$rv1 = ubrt_rest($uD, 'POST', '/contacts/' . $C['drozdz'] . '/phone-reveal', ['confirm_token' => $pf2['confirm_token'], 'acknowledge_unknown_cost' => true]);
$mu = urldecode((string) ($p103http[0]['url'] ?? ''));
$revRow = $db->row("SELECT * FROM {$db->table(Tables::PHONE_REVEALS)} WHERE id = %d", (int) ($rv1['data']['reveal_id'] ?? 0));
UbrT::ok($rv1['status'] === 200 && $rv1['data']['status'] === 'pending' && count($p103http) === 1 && str_contains($mu, '/people/match') && str_contains($mu, 'id=p103drz001') && str_contains($mu, 'reveal_personal_emails=false'), 'P10.3-13. symulowany sukces (mock): jedno zapytanie dla zatwierdzonego identyfikatora osoby');
UbrT::ok(($revRow['apollo_person_id'] ?? null) === 'p103drz001' && (int) $revRow['user_id'] === $uD, 'P10.3-13. zlecenie zapamiętuje identyfikator osoby Apollo i użytkownika');
$rv2 = ubrt_rest($uD, 'POST', '/contacts/' . $C['drozdz'] . '/phone-reveal', ['confirm_token' => $pf2['confirm_token'], 'acknowledge_unknown_cost' => true]);
UbrT::ok($rv2['status'] === 200 && $rv2['data']['created'] === false && count($p103http) === 1, 'P10.3-13. podwójne kliknięcie — bez drugiego zapytania (brak podwójnego obciążenia)');
$rv->applyResult($revRow, ['people' => [
	['id' => 'p103obca01', 'phone_numbers' => [['sanitized_number' => '+48601103999', 'type_cd' => 'mobile']]],
	['id' => 'p103drz001', 'phone_numbers' => [['sanitized_number' => '+48601103002', 'type_cd' => 'work_direct']]],
], 'credits_consumed' => 9]);
$saved = app(PhoneRevealRepository::class)->phonesFor($orgD, [$C['drozdz']])[$C['drozdz']] ?? [];
UbrT::ok(count($saved) === 1 && $saved[0]['e164'] === '+48601103002', 'P10.3-13. wynik zapisany przy osobie — tylko numer osoby z identyfikatora zlecenia (cudzy numer pominięty)');
UbrT::eq(9.0, (float) $db->value("SELECT credits_consumed FROM {$db->table(Tables::PHONE_REVEALS)} WHERE id = %d", (int) $revRow['id']), 'P10.3-13. zużyte kredyty zapisane z wyniku (mock)');
UbrT::ok(in_array('already_revealed', array_column($rv->preflight($ctxD, $C['drozdz'])['reasons'], 'code'), true), 'P10.3-13. numer już pobrany → kolejne pobranie tylko świadomie (tryb odświeżenia)');

$p103mock = ['code' => 403, 'body' => ['error' => 'forbidden']];
$pfA = $rv->preflight($ctxD, $C['akowalska']);
UbrT::ok(in_array('no_apollo_id', array_column($pfA['reasons'], 'code'), true), 'P10.3-14. propozycja STRONG bez zatwierdzenia — nie można odblokować');
ubrt_rest($uD, 'POST', '/apollo-matches/' . $mid('Anna', $C['akowalska']), ['action' => 'approve']);
$pfA = ubrt_rest($uD, 'GET', '/contacts/' . $C['akowalska'] . '/phone-reveal')['data'];
delete_transient('ubr_rl_apollo');
$p103http = [];
$rvE = ubrt_rest($uD, 'POST', '/contacts/' . $C['akowalska'] . '/phone-reveal', ['confirm_token' => $pfA['confirm_token'], 'acknowledge_unknown_cost' => true]);
UbrT::ok($rvE['status'] === 200 && $rvE['data']['status'] === 'error' && count($p103http) === 1 && str_contains((string) $rvE['data']['error'], 'brak dostępu'), 'P10.3-14. symulowany błąd (403) → status błędu, jedno zapytanie, bez automatycznego ponawiania');

$dncPid103 = (int) ubrt_rest($uD, 'POST', '/prospects', ['company_id' => $R['acme'], 'search_id' => $sD])['data']['prospect_id'];
ubrt_rest($uD, 'POST', '/prospects/' . $dncPid103 . '/do-not-contact', ['value' => true, 'reason' => 'test P10.3']);
UbrT::ok(in_array('dnc', array_column($rv->preflight($ctxD, $C['kiedewicz'])['reasons'], 'code'), true), 'P10.3-14. firma „Nie kontaktować” → pobieranie numeru zablokowane');
ubrt_rest($uD, 'POST', '/prospects/' . $dncPid103 . '/do-not-contact', ['value' => false]);
remove_filter('ubr/apollo/phone_enabled', '__return_true');

/* ------------------------------------------------------------------ */
UbrT::group('P10.3 — gotowość, listy i karta bez wywołań Apollo');

$p103http = [];
ubrt_rest($uD, 'GET', '/companies/' . $R['acme'] . '/contacts');
ubrt_rest($uD, 'GET', '/discovery/searches/' . $sD . '/results', ['per_page' => 50]);
ubrt_rest($uD, 'GET', '/companies/' . $R['acme'] . '/apollo-candidates');
UbrT::eq(0, count($p103http), 'P10.3-15. karta firmy, lista Discover i widok kandydatów — 0 zapytań do Apollo');
$wpdb->delete($db->table(Tables::CONTACT_PHONES), ['contact_id' => $C['drozdz']]);
$wpdb->query($wpdb->prepare("DELETE FROM {$db->table(Tables::CONTACT_PHONE_LINKS)} WHERE contact_id = %d", $C['rogowski']));
UbrT::ok(app(\App\Ubr\Services\OwnershipService::class)->readiness($ctxD, $R['acme']) !== 'READY_DIRECT', 'P10.3-15. sam identyfikator Apollo + sygnał „numer dostępny” bez numeru → NIE READY_DIRECT');
UbrT::eq('Apollo: numer bezpośredni dostępny (płatny, nie pobrano)', CallPresenter::directLabel(['numbers' => [], 'apollo' => ['availability' => 'AVAILABLE']]), 'P10.3-15. opis: sygnał Apollo ≠ numer („nie pobrano”)');
UbrT::eq('Dostępność telefonu nieznana', CallPresenter::directLabel(['numbers' => [], 'apollo' => ['availability' => 'UNKNOWN']]), 'P10.3-15. opis: „Dostępność telefonu nieznana”');
UbrT::eq('Numer bezpośredni potwierdzony', CallPresenter::directLabel(['numbers' => [['verified' => true]], 'apollo' => null]), 'P10.3-15. opis: numer potwierdzony');
$appr = $acs->approvedFor($orgD, [$C['drozdz'], $C['widzisz']]);
UbrT::ok(isset($appr[$C['drozdz']]) && $appr[$C['drozdz']]['availability'] === 'AVAILABLE' && ! isset($appr[$C['widzisz']]), 'P10.3-15. zatwierdzone dopasowania pobierane jedną kwerendą (lista)');

// Retencja: kandydaci niewidziani od dawna i bez zatwierdzonego dopasowania (CLI `wp ubr contacts purge`).
$old = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::APOLLO_PEOPLE)} SET last_seen_at = %s WHERE company_id = %d", $old, $R['acme']));
$purged = $acs->purgeStale(180);
$left = $db->column("SELECT apollo_person_id FROM {$db->table(Tables::APOLLO_PEOPLE)} WHERE company_id = %d", $R['acme']);
UbrT::ok($purged > 0 && in_array('p103drz001', $left, true) && in_array('p103rog001', $left, true) && ! in_array('p103zen001', $left, true), "P10.3-16. retencja: usunięto {$purged} nieaktualnych kandydatów, zatwierdzone dopasowania zachowane");

remove_filter('ubr/contacts/apollo_people_enabled', '__return_true');
remove_filter('pre_http_request', $p103filter, PHP_INT_MAX - 2);
$p103clear();
delete_transient('ubr_apollo_search_done_' . $R['acme']);
