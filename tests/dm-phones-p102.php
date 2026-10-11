<?php

/**
 * Testy Prompt 10.2 — Direct Decision Maker Phones (bezpośrednie telefony decydentów z dowodem powiązania).
 * Dołączane z tests/run.php PO tests/calling-p10.php (wspólne: $orgD, $orgE, $uD, $uD1, $uE, $uX, $p10mk, $ctr, $db).
 * Strony firm i Apollo wyłącznie na mockach; dane fikcyjne tylko w bazie testowej (sprzątane po testach).
 */

use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Domain\Phones\PersonPhoneExtractor;
use App\Ubr\Infrastructure\Contacts\ApolloPeopleSource;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\ContactPhoneLinkRepository;
use App\Ubr\Services\DecisionPhoneService;

if (! defined('ABSPATH')) {
	exit(1);
}

/* ------------------------------------------------------------------ */
UbrT::group('P10.2 — ekstrakcja: osoba + stanowisko + numer (dowód powiązania)');

$px = new PersonPhoneExtractor();
$pxBy = function (array $r): array {
	$out = [];

	foreach ($r['people'] as $p) {
		foreach ($p['phones'] as $ph) {
			$out[$p['name']->full() . '|' . $ph['e164']] = $ph + ['title' => $p['title'], 'role' => $p['role']['role'] ?? null];
		}
	}

	return $out;
};

$cards = $px->extract('<body><header>tel. 22 111 22 33</header><div class="team">'
	. '<div class="card"><h3>Jan Kowalski</h3><p>Prezes Zarządu</p><p>tel. <a href="tel:+48601222333">601 222 333</a></p></div>'
	. '<div class="card"><h3>Anna Nowak</h3><p>Dyrektor Marketingu</p><p>kom. 602 333 444</p><p>e-mail: a.nowak@firma.ubrtest.pl</p></div></div>'
	. '<footer>Centrala: 22 111 22 33</footer></body>', 'https://firma.ubrtest.pl/zespol', ['+48221112233']);
$cb = $pxBy($cards);
UbrT::ok(($cb['Jan Kowalski|+48601222333']['status'] ?? null) === 'VERIFIED' && $cb['Jan Kowalski|+48601222333']['evidence'] === 'SAME_BLOCK', 'P10.2-1. karta pracownika: imię i nazwisko, stanowisko i link tel: w jednym bloku → VERIFIED');
UbrT::ok(($cb['Anna Nowak|+48602333444']['status'] ?? null) === 'VERIFIED' && $cb['Anna Nowak|+48602333444']['role'] === 'marketing_director', 'P10.2-1. numer w tekście („kom.”) przy osobie ze stanowiskiem → VERIFIED, rola Dyrektor Marketingu');
UbrT::ok(($cards['numbers']['+48221112233']['class'] ?? null) === 'COMPANY_GENERAL' && ! isset($cb['Jan Kowalski|+48221112233']), 'P10.2-1. numer centrali ze stopki / nagłówka → numer ogólny firmy, nie przypisany osobie');

$table = $px->extract('<body><table><tr><th>Osoba</th><th>Stanowisko</th><th>Telefon</th></tr>'
	. '<tr><td>Piotr Zieliński</td><td>Marketing Manager</td><td>tel. 603 444 555</td></tr>'
	. '<tr><td>Ewa Wójcik</td><td>Kierownik Magazynu</td><td>tel. 604 555 666</td></tr></table></body>', 'https://firma.ubrtest.pl/kontakt');
$tb = $pxBy($table);
UbrT::ok(($tb['Piotr Zieliński|+48603444555']['evidence'] ?? null) === 'TABLE_ROW' && $tb['Piotr Zieliński|+48603444555']['status'] === 'VERIFIED', 'P10.2-2. tabela HTML: jeden wiersz = osoba, stanowisko, numer → VERIFIED (TABLE_ROW)');
UbrT::ok(($tb['Ewa Wójcik|+48604555666']['title'] ?? null) === 'Kierownik Magazynu' && $tb['Ewa Wójcik|+48604555666']['role'] === null && $tb['Ewa Wójcik|+48604555666']['status'] === 'VERIFIED', 'P10.2-2. stanowisko z TEGO wiersza (nie z sąsiedniego); stanowisko spoza katalogu ról = numer osoby, ale nie decydent');
$foot = $px->extract('<body><main><p>O firmie</p></main><footer><div><p>Biuro: tel. 55 239 14 01</p></div><div><p>Michał Zipser – Sales Manager</p><p>tel. +48 880 575 251</p></div><div><p>Krzysztof Kowalczyk</p><p>tel. +48 55 239 14 87</p></div></footer></body>', 'https://firma.ubrtest.pl/contact', ['+48552391401']);
$fb = $pxBy($foot);
UbrT::ok(($fb['Michał Zipser|+48880575251']['status'] ?? null) === 'LIKELY' && $fb['Michał Zipser|+48880575251']['evidence'] === 'FOOTER_BLOCK' && ($fb['Krzysztof Kowalczyk|+48552391487']['status'] ?? null) === 'UNCERTAIN' && ($foot['numbers']['+48552391401']['class'] ?? null) === 'COMPANY_GENERAL', 'P10.2-2. stopka: blok osoby ze stanowiskiem → LIKELY (nie VERIFIED), bez stanowiska → UNCERTAIN, „Biuro” → numer ogólny');

$dept = $px->extract('<body><section><p>Marek Adamiak</p><p>Prezes Zarządu</p><p>tel. 22 515 76 44</p><p>Sekretariat: tel. 22 515 76 00</p><p>Dział sprzedaży: tel. 22 515 76 10</p><p>Oddział Gdańsk: tel. 58 300 40 50</p></section></body>', 'https://firma.ubrtest.pl/kontakt', ['+48225157600'], ['+48583004050']);
$db2 = $pxBy($dept);
UbrT::ok(($db2['Marek Adamiak|+48225157644']['status'] ?? null) === 'VERIFIED', 'P10.2-3. numer przy prezesie → VERIFIED');
UbrT::ok(($db2['Marek Adamiak|+48225157600']['status'] ?? null) === 'REJECTED' && ($dept['numbers']['+48225157600']['class'] ?? null) === 'COMPANY_GENERAL', 'P10.2-3. „Sekretariat:” (numer centrali) w bloku osoby → odrzucony, numer ogólny');
UbrT::ok(($db2['Marek Adamiak|+48225157610']['evidence'] ?? null) === 'DEPARTMENT_LABEL' && ($dept['numbers']['+48225157610']['class'] ?? null) === 'DEPARTMENT', 'P10.2-3. „Dział sprzedaży:” → numer działu, odrzucony jako numer osoby');
UbrT::ok(($db2['Marek Adamiak|+48583004050']['status'] ?? null) === 'REJECTED' && ($dept['numbers']['+48583004050']['class'] ?? null) === 'BRANCH', 'P10.2-3. numer oddziału → BRANCH, nie numer osoby');

$shared = $px->extract('<body><div><h4>Jan Kowalski</h4><p>Prezes</p><p>tel. 22 100 20 30</p></div><div><h4>Anna Nowak</h4><p>Członek Zarządu</p><p>tel. 22 100 20 30</p></div><p>NIP: 525-224-84-81 REGON 012345678 KRS 0000123456</p></body>', 'https://firma.ubrtest.pl/zarzad');
$sb = $pxBy($shared);
UbrT::ok(($sb['Jan Kowalski|+48221002030']['evidence'] ?? null) === 'SHARED_NUMBER' && ($sb['Anna Nowak|+48221002030']['status'] ?? null) === 'REJECTED', 'P10.2-4. ten sam numer przy kilku osobach → numer wspólny, odrzucony');
UbrT::ok(! array_filter(array_keys($shared['numbers']), fn ($e) => str_contains($e, '5252248481') || str_contains($e, '012345678')), 'P10.2-4. NIP / REGON / KRS nie są numerami');

$far = $px->extract('<body><main><h2>Zarząd</h2><p>Jan Kowalski</p><p>Anna Nowak</p><h2>Kontakt</h2><p>Biuro obsługi klienta czynne 8–16.</p><p>Godziny pracy: pon.–pt.</p><p>Zapraszamy do kontaktu.</p><p>Formularz poniżej.</p><p>Informacja RODO.</p><p>tel. 22 444 55 66</p></main></body>', 'https://firma.ubrtest.pl/o-nas');
UbrT::ok(! array_filter($pxBy($far), fn ($l) => in_array($l['status'], ['VERIFIED', 'LIKELY'], true)), 'P10.2-5. nazwisko „gdzieś na stronie” + numer dalej → brak powiązania z osobą');
$seq = $px->extract('<body><main><p>Krzysztof Lis</p><p>Dyrektor Marketingu</p><p>tel. 605 100 200</p><p>Tomasz Wolny</p><p>tel. 606 200 300</p></main></body>', 'https://firma.ubrtest.pl/kontakt');
$qb = $pxBy($seq);
UbrT::ok(($qb['Krzysztof Lis|+48605100200']['status'] ?? null) === 'LIKELY' && ($qb['Tomasz Wolny|+48606200300']['status'] ?? null) === 'UNCERTAIN', 'P10.2-5. lista płaska: imię → stanowisko → numer = LIKELY; bez stanowiska = UNCERTAIN (kilka osób, kilka numerów)');
$page = $px->extract('<body><h1>Jan Kowalski</h1><p>Prezes Zarządu</p><p>Telefon: +48 22 333 44 55</p><footer>Centrala tel. 22 333 00 00</footer></body>', 'https://firma.ubrtest.pl/zespol/jan-kowalski');
UbrT::ok(($pxBy($page)['Jan Kowalski|+48223334455']['evidence'] ?? null) === 'PERSON_PAGE', 'P10.2-5. indywidualna podstrona osoby → VERIFIED (PERSON_PAGE)');
$dup = $px->extract('<body><div><h3>Jan Kowalski</h3><p>Prezes Zarządu</p><p><a href="tel:+48601222333">601 222 333</a> · tel. 601 222 333</p></div></body>', 'https://firma.ubrtest.pl/zespol');
UbrT::eq(1, count(array_filter(array_keys($pxBy($dup)), fn ($k) => str_ends_with($k, '+48601222333'))), 'P10.2-5. duplikaty (link tel: + tekst) → jedno powiązanie');
$co = $px->extract('<body><p>Firma X sp. z o.o., ul. Długa 1, 00-001 Warszawa. Prezes Zarządu: Jan Kowalski. Tel. 22 123 45 67</p></body>', 'https://firma.ubrtest.pl/kontakt');
UbrT::ok(! array_filter($pxBy($co), fn ($l) => $l['status'] === 'VERIFIED'), 'P10.2-5. blok danych firmy (adres, forma prawna) z nazwiskiem — numer nie jest potwierdzonym numerem osoby');

$bad = $px->extract("<body><div><h3>Jan Kowalski</h3><p>Prezes Zarz\xb9du \xe9t\xe9 | x</p><p>tel. 601 222 333</p></div></body>", 'https://firma.ubrtest.pl/zespol');
UbrT::ok(is_array($bad['people']), 'P10.2-5. strona z niepoprawnym UTF-8 (inne kodowanie) — analiza bez błędu (LocalWP: 7 firm)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.2 — dopasowanie do kontaktów, nowi decydenci, ponowne uruchomienie');

$dpLinks = app(ContactPhoneLinkRepository::class);
$dpSvc = app(DecisionPhoneService::class);
$Q = [
	'team' => $p10mk('dp-team', 'DP Zespol', 'https://p102-team.ubrtest.pl/'),
	'other' => $p10mk('dp-other', 'DP Inna Firma', 'https://p102-other.ubrtest.pl/', [], true),
];
$db->execute("INSERT INTO {$db->table(Tables::COMPANY_PHONES)} (company_id, org_key, raw_number, e164, phone_type, source, source_url, page_kind, confidence, is_primary, `manual`, found_at, last_seen_at, created_at, updated_at) VALUES (%d, 0, '22 700 00 00', '+48227000000', 'MAIN', 'TEL_LINK', 'https://p102-team.ubrtest.pl/kontakt', 'contact', 'high', 1, 0, NOW(), NOW(), NOW(), NOW()), (%d, 0, '600 700 001', '+48600700001', 'OTHER', 'TEXT', 'https://p102-team.ubrtest.pl/kontakt', 'contact', 'medium', 0, 0, NOW(), NOW(), NOW(), NOW())", $Q['team'], $Q['team']);
$db->execute("UPDATE {$db->table(Tables::COMPANIES)} SET main_phone_e164 = '+48227000000' WHERE id = %d", $Q['team']);
$exist = $mkContact($Q['team'], 'Ewa Prezeska', 'Prezes Zarządu', 'ceo', null, ['person_key' => 'ewa prezeska']);
$similar = $mkContact($Q['team'], 'Jan Kowalczyk', 'Członek Zarządu', 'board_member', null, ['person_key' => 'jan kowalczyk', 'rank_position' => 2]);
$elsewhere = $mkContact($Q['other'], 'Robert Marketingowy', 'Dyrektor Marketingu', 'marketing_director', null, ['person_key' => 'robert marketingowy']);
$liSave($Q['team']);
$scoreBefore = (int) $ctr->find($exist)['contact_priority_score'];
$GLOBALS['ubrt_sites']['p102-team.ubrtest.pl'] = '<html><body><a href="/kontakt">Kontakt</a></body></html>';
$GLOBALS['ubrt_sites']['p102-team.ubrtest.pl/kontakt'] = '<html><body><a href="/zarzad">Zarząd</a><div class="card"><h3>Ewa Prezeska</h3><p>Prezes Zarządu</p><p>tel. kom. 600 700 001</p></div>'
	. '<div class="card"><h3>Jan Kowalski</h3><p>Członek Zarządu</p><p>tel. 600 700 002</p></div>'
	. '<div class="card"><h3>Lena Brakstanowiska</h3><p>tel. 600 700 003</p></div>'
	. '<div class="card"><h3>Robert Marketingowy</h3><p>Dyrektor Marketingu</p><p>tel. 600 700 004</p><p>r.marketingowy@p102-team.ubrtest.pl</p></div>'
	. '<p>Centrala: <a href="tel:+48227000000">22 700 00 00</a></p></body></html>';
$GLOBALS['ubrt_sites']['p102-team.ubrtest.pl/zarzad'] = '<html><body><div class="person"><h2>Marta Nowa</h2><p>Dyrektor Marketingu</p><p>Telefon: <a href="tel:+48600700005">600 700 005</a></p></div></body></html>';
$beforeCount = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACTS)} WHERE company_id = %d", $Q['team']);

$dry = ubrt_rest($uD1, 'POST', '/companies/' . $Q['team'] . '/decision-phones/scan', ['dry_run' => true]);
UbrT::ok($dry['status'] === 200 && $dry['data']['dry_run'] === true && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_PHONE_LINKS)} WHERE company_id = %d", $Q['team']) === 0 && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACTS)} WHERE company_id = %d", $Q['team']) === $beforeCount, 'P10.2-6. tryb analizy (dry_run): wynik bez zapisu powiązań i osób');
UbrT::ok(count($dry['data']['pages']) === 2 && in_array('management', array_column($dry['data']['pages'], 'kind'), true), 'P10.2-6. pobrane tylko strona źródłowa numerów + podlinkowana podstrona „Zarząd”');
UbrT::eq(422, ubrt_rest($uD1, 'POST', '/companies/' . $Q['team'] . '/decision-phones/scan')['status'], 'P10.2-6. blokada 10 min na firmę (limit zapytań)');
delete_transient('ubr_dm_phones_' . $Q['team']);

$apolloCalls = 0;
$dpApolloSpy = function ($pre, $args, $url) use (&$apolloCalls) {
	if (str_contains((string) $url, 'api.apollo.io')) {
		$apolloCalls++;
	}

	return $pre;
};
add_filter('pre_http_request', $dpApolloSpy, 1, 3);
$run = ubrt_rest($uD1, 'POST', '/companies/' . $Q['team'] . '/decision-phones/scan');
remove_filter('pre_http_request', $dpApolloSpy, 1);
UbrT::ok($run['status'] === 200 && $apolloCalls === 0, 'P10.2-6. wyszukiwanie telefonów decydentów nie wywołuje Apollo (bez enrichmentu)');
$lk = $dpLinks->effectiveFor($orgD, [$exist])[$exist] ?? [];
UbrT::ok(($lk[0]['e164'] ?? null) === '+48600700001' && $lk[0]['link_status'] === 'VERIFIED' && $lk[0]['source_type'] === 'WEBSITE' && str_contains((string) $lk[0]['source_url'], '/kontakt') && $lk[0]['found_at'], 'P10.2-7. istniejący decydent: numer z bloku osoby powiązany (VERIFIED, źródło i data pozyskania)');
UbrT::eq($scoreBefore, (int) $ctr->find($exist)['contact_priority_score'], 'P10.2-7. Contact Priority Score bez zmian (telefon nie podnosi oceny)');
UbrT::eq(null, $ctr->find($exist)['phone_e164'], 'P10.2-7. dane kontaktu bez zmian (numer tylko jako powiązanie z dowodem)');
$newJan = $ctr->findByKey($Q['team'], 0, 'jan kowalski');
UbrT::ok(($dpLinks->effectiveFor($orgD, [$similar])[$similar] ?? []) === [] && $newJan && (int) $newJan['id'] !== $similar, 'P10.2-7. podobne nazwisko (Kowalczyk ≠ Kowalski) nie jest łączone — osobny kontakt, numer tylko przy właściwej osobie');
$newMarta = $ctr->findByKey($Q['team'], 0, 'marta nowa');
UbrT::ok($newMarta && $newMarta['source_type'] === 'COMPANY_WEBSITE' && str_contains((string) $newMarta['source_url'], '/zarzad') && $newMarta['normalized_role'] === 'marketing_director', 'P10.2-8. nowy decydent ze strony „Zarząd” dodany z rzeczywistym źródłem (rola Dyrektor Marketingu)');
UbrT::ok(($dpLinks->effectiveFor($orgD, [(int) $newMarta['id']])[(int) $newMarta['id']][0]['link_status'] ?? null) === 'VERIFIED', 'P10.2-8. numer nowego decydenta powiązany (VERIFIED)');
UbrT::ok(! $ctr->findByKey($Q['team'], 0, 'lena brakstanowiska'), 'P10.2-8. osoba bez stanowiska nie jest dodawana jako decydent');
UbrT::ok(($dpLinks->effectiveFor($orgD, [$elsewhere])[$elsewhere] ?? []) === [] && $ctr->findByKey($Q['team'], 0, 'robert marketingowy') !== null, 'P10.2-8. osoba o tym samym nazwisku w INNEJ firmie nie dostaje numeru (powiązanie tylko w firmie strony)');
$mainRow = $db->row("SELECT owner_class FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d AND e164 = '+48227000000'", $Q['team']);
$otherRow = $db->row("SELECT owner_class, owner_note FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d AND e164 = '+48600700001'", $Q['team']);
UbrT::ok(DecisionPhoneService::redact('Anna Nowak · a.nowak@firma.ubrtest.pl · tel. 602') === 'Anna Nowak · [e-mail] · tel. 602' && ! str_contains(wp_json_encode($run['data']), '@'), 'P10.2-9. adresy e-mail ze stron nie są zapisywane ani zwracane (fragmenty dowodów bez e-maili)');
UbrT::ok(($mainRow['owner_class'] ?? null) === 'COMPANY_GENERAL' && ($otherRow['owner_class'] ?? null) === 'PERSON_DIRECT' && str_contains((string) $otherRow['owner_note'], 'Ewa Prezeska'), 'P10.2-9. właściciel numerów firmy: centrala = COMPANY_GENERAL, numer z bloku osoby = PERSON_DIRECT z opisem');
UbrT::eq('READY_DIRECT', app(\App\Ubr\Services\OwnershipService::class)->readiness($ctxD, $Q['team']), 'P10.2-10. potwierdzony numer decydenta → READY_DIRECT');

$linksBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_PHONE_LINKS)} WHERE company_id = %d", $Q['team']);
$contactsBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACTS)} WHERE company_id = %d", $Q['team']);
$dpSvc->process(app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->find($Q['team']), [['html' => $GLOBALS['ubrt_sites']['p102-team.ubrtest.pl/kontakt'], 'url' => 'https://p102-team.ubrtest.pl/kontakt'], ['html' => $GLOBALS['ubrt_sites']['p102-team.ubrtest.pl/zarzad'], 'url' => 'https://p102-team.ubrtest.pl/zarzad']], true, false);
UbrT::ok($linksBefore === (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_PHONE_LINKS)} WHERE company_id = %d", $Q['team']) && $contactsBefore === (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACTS)} WHERE company_id = %d", $Q['team']), 'P10.2-11. ponowne uruchomienie: bez duplikatów osób i powiązań (idempotencja)');

// Literówka na stronie (LocalWP: „Kornulik” obok „Korniluk”): jedna osoba, wariant do ręcznej weryfikacji.
$Q['typo'] = $p10mk('dp-typo', 'DP Literowka', 'https://p102-typo.ubrtest.pl/');
$typoHtml = '<html><body><div class="board"><div><h3>Maciej Kornulik</h3><p>Prezes zarządu</p><p>Pan Maciej Korniluk od 2024 roku kieruje spółką.</p></div><ul><li>Maciej Korniluk · Prezes Zarządu</li></ul></div></body></html>';
$typoRes = $dpSvc->process(app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->find($Q['typo']), [['html' => $typoHtml, 'url' => 'https://p102-typo.ubrtest.pl/zarzad']], true, false);
UbrT::ok($ctr->findByKey($Q['typo'], 0, 'maciej korniluk') !== null && $ctr->findByKey($Q['typo'], 0, 'maciej kornulik') === null && $typoRes['summary']['possible_duplicates'] === 1, 'P10.2-11. literówka w nazwisku na stronie: jedna osoba (częstsza pisownia), wariant jako możliwy duplikat — bez drugiego kontaktu');

/* ------------------------------------------------------------------ */
UbrT::group('P10.2 — decyzje organizacji: potwierdź / odrzuć / przypisz / usuń');

$rej = ubrt_rest($uD1, 'POST', '/contacts/' . $exist . '/phone-links', ['action' => 'reject', 'e164' => '+48600700001']);
UbrT::ok($rej['status'] === 200 && ($rej['data']['phone_links'][0]['status'] ?? null) === 'REJECTED' && $rej['data']['phone_links'][0]['decided_by_organization'] === true, 'P10.2-12. odrzucenie błędnego powiązania (decyzja organizacji)');
UbrT::eq('READY_DIRECT', app(\App\Ubr\Services\OwnershipService::class)->readiness($ctxD, $Q['team']), 'P10.2-12. po odrzuceniu jednego numeru — inne potwierdzone numery decydentów firmy nadal liczone');
$eSees = $dpLinks->effectiveFor($orgE, [$exist])[$exist] ?? [];
UbrT::ok(($eSees[0]['link_status'] ?? null) === 'VERIFIED', 'P10.2-12. decyzja organizacji D niewidoczna dla organizacji E (dane wspólne bez zmian)');
$dpSvc->process(app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->find($Q['team']), [['html' => $GLOBALS['ubrt_sites']['p102-team.ubrtest.pl/kontakt'], 'url' => 'https://p102-team.ubrtest.pl/kontakt']], true, false);
UbrT::eq('REJECTED', $dpLinks->effectiveFor($orgD, [$exist])[$exist][0]['link_status'] ?? null, 'P10.2-12. ponowna ekstrakcja nie nadpisuje decyzji ręcznej');
$conf = ubrt_rest($uD1, 'POST', '/contacts/' . $exist . '/phone-links', ['action' => 'confirm', 'e164' => '+48600700001']);
UbrT::ok($conf['status'] === 200 && $conf['data']['phone_links'][0]['status'] === 'VERIFIED' && $conf['data']['phone_links'][0]['source'] === 'MANUAL' && $conf['data']['phone_links'][0]['verified_at'], 'P10.2-12. potwierdzenie: status VERIFIED, źródło „Ręcznie potwierdzony”, data weryfikacji');
$assign = ubrt_rest($uD1, 'POST', '/contacts/' . $similar . '/phone-links', ['action' => 'assign', 'e164' => '+48227000000']);
UbrT::ok($assign['status'] === 200 && ($assign['data']['phone_links'][0]['evidence'] ?? null) === 'MANUAL_ASSIGN', 'P10.2-13. ręczne przypisanie istniejącego numeru firmy do kontaktu');
UbrT::eq(422, ubrt_rest($uD1, 'POST', '/contacts/' . $similar . '/phone-links', ['action' => 'assign', 'e164' => '+48 699 999 999'])['status'], 'P10.2-13. przypisać można tylko istniejący numer tej firmy');
$aLink = (int) $assign['data']['phone_links'][0]['id'];
$del = ubrt_rest($uD1, 'DELETE', '/contacts/' . $similar . '/phone-links/' . $aLink);
UbrT::ok($del['status'] === 200 && $del['data']['phone_links'] === [] && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d AND e164 = '+48227000000'", $Q['team']) === 1, 'P10.2-13. usunięcie powiązania — numer firmy pozostaje');

/* ------------------------------------------------------------------ */
UbrT::group('P10.2 — Contact Readiness, filtry, listy, Dashboard');

$php = fn (array $s) => CallReadiness::classify($s);
UbrT::ok($php(['likely_phone' => true, 'verification_status' => 'verified']) === 'RESEARCH' && $php(['likely_phone' => true, 'company_phone' => true]) === 'CALL_COMPANY', 'P10.2-14. PHP: numer decydenta tylko „prawdopodobny” → RESEARCH (bez numeru firmy) / CALL_COMPANY (z numerem firmy)');
$listQ = fn (array $f) => ubrt_rest($uD1, 'GET', '/discovery/searches/' . $sD . '/results', $f + ['per_page' => 100]);
$names = fn (array $r) => array_map(fn ($i) => str_replace('Ubrtest P10 ', '', $i['name']), $r['data']['items'] ?? []);
UbrT::ok(in_array('DP Zespol', $names($listQ(['phone' => 'dm_verified'])), true) && ! in_array('Tylko Centrala', $names($listQ(['phone' => 'dm_verified'])), true), 'P10.2-15. filtr „Ma potwierdzony telefon decydenta” (cały zbiór)');
UbrT::ok(in_array('Tylko Centrala', $names($listQ(['phone' => 'company_only'])), true) && ! in_array('DP Zespol', $names($listQ(['phone' => 'company_only'])), true), 'P10.2-15. filtr „Ma tylko telefon firmy”');
$allN = count($names($listQ([])));
UbrT::eq($allN, count($names($listQ(['phone' => 'direct']))) + count($names($listQ(['phone' => 'no_dm']))), 'P10.2-15. „Ma bezpośredni” + „Brak telefonu decydenta” = wszystkie');
UbrT::ok(in_array('DP Zespol', $names($listQ(['phone_source' => 'website'])), true) && in_array('DP Zespol', $names($listQ(['dm_role' => 'marketing_director'])), true) && ! in_array('Tylko Centrala', $names($listQ(['dm_role' => 'marketing_director'])), true), 'P10.2-15. filtry „Źródło telefonu” i „Stanowisko decydenta”');
UbrT::eq(200, $listQ(['phone' => "dm_verified' OR 1=1", 'phone_source' => 'x', 'dm_role' => "ceo'--"])['status'], 'P10.2-15. nieznane wartości filtrów ignorowane (whitelist)');
$rowTeam = array_values(array_filter($listQ([])['data']['items'], fn ($i) => str_ends_with($i['name'], 'DP Zespol')))[0] ?? null;
$dmPhone = $rowTeam['call']['decision_maker']['phone'] ?? null;
UbrT::ok($dmPhone && $dmPhone['numbers'][0]['verified'] === true && $dmPhone['numbers'][0]['source_label'] !== null && $rowTeam['call']['company_phone']['e164'] === '+48227000000', 'P10.2-16. lista: telefon decydenta (potwierdzony, źródło) obok telefonu firmy');
UbrT::ok(! in_array('+48227000000', array_column($dmPhone['numbers'] ?? [], 'e164'), true), 'P10.2-16. numer firmy nie jest pokazywany jako numer osoby');
UbrT::ok($dmPhone['reveal_possible'] === false, 'P10.2-16. Phone Enrichment wyłączony — brak „Odblokuj telefon”');
\App\Ubr\Services\ActionCenterService::bumpOrganization($orgD);
$acq = ubrt_rest($uD1, 'GET', '/action-center/queues/ready_to_call', ['per_page' => 50]);
$acDirect = ubrt_rest($uD1, 'GET', '/action-center/queues/ready_to_call', ['per_page' => 50, 'call_kind' => 'direct']);
UbrT::ok(isset($acq['data']['breakdown']['direct'], $acq['data']['breakdown']['company']) && $acq['data']['breakdown']['direct'] === $acDirect['data']['total'] && $acq['data']['breakdown']['direct'] + $acq['data']['breakdown']['company'] === $acq['data']['total'], 'P10.2-17. „Gotowe do telefonu”: liczby „do decydenta” + „przez centralę” = całość');
$acTeam = array_values(array_filter($acDirect['data']['items'], fn ($i) => str_ends_with($i['company']['name'], 'DP Zespol')))[0] ?? null;
UbrT::ok($acTeam && ! empty($acTeam['call']['decision_maker']['phone']['verified']), 'P10.2-17. wiersz kolejki: potwierdzony numer decydenta');
$kpi = array_column(ubrt_rest($uD1, 'GET', '/action-center')['data']['kpis'] ?? [], 'key');
UbrT::ok(! in_array('ready_direct', $kpi, true) && in_array('ready_to_contact', $kpi, true), 'P10.2-17. KPI bez zmian');

/* ------------------------------------------------------------------ */
UbrT::group('P10.2 — Apollo People Search (mock) i bezpieczeństwo');

$apolloMock = ['status' => 200, 'body' => ['people' => [['id' => 'p102abc001', 'first_name' => 'Ewa', 'last_name' => 'Prezeska', 'title' => 'CEO', 'organization' => ['primary_domain' => 'p102-team.ubrtest.pl']], ['id' => 'p102abc002', 'first_name' => 'Jan', 'last_name_obfuscated' => 'K***i', 'title' => 'Marketing Manager']], 'total_entries' => 2]];
$apolloHttp = [];
$dpApollo = function ($pre, $args, $url) use (&$apolloMock, &$apolloHttp) {
	if (! str_contains((string) $url, 'api.apollo.io')) {
		return $pre;
	}

	$apolloHttp[] = $url;

	return ['headers' => [], 'body' => wp_json_encode($apolloMock['body']), 'response' => ['code' => $apolloMock['status'], 'message' => ''], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $dpApollo, PHP_INT_MAX - 1, 3);
UbrT::eq(422, ubrt_rest($uD, 'POST', '/integrations/apollo/people-test', ['company_id' => $Q['team']])['status'], 'P10.2-18. test People Search wymaga potwierdzenia');
UbrT::eq(403, ubrt_rest($uD1, 'POST', '/integrations/apollo/people-test', ['company_id' => $Q['team'], 'confirm' => true])['status'], 'P10.2-18. test People Search tylko dla właściciela / administratora');
$pt = ubrt_rest($uD, 'POST', '/integrations/apollo/people-test', ['company_id' => $Q['team'], 'confirm' => true]);
UbrT::ok($pt['status'] === 200 && $pt['data']['ok'] === true && $pt['data']['accepted'] === 1 && $pt['data']['people'][1]['last_name_masked'] === true && count($apolloHttp) === 1 && str_contains($apolloHttp[0], 'mixed_people/api_search'), 'P10.2-18. jedno zapytanie People API Search; zamaskowane nazwisko rozpoznane; nic nie zapisano');
UbrT::eq(0, (int) $db->value("SELECT billable FROM {$db->table(Tables::API_REQUESTS)} WHERE organization_id = %d AND endpoint = %s ORDER BY id DESC LIMIT 1", $orgD, ApolloPeopleSource::ENDPOINT), 'P10.2-18. zapytanie zapisane jako niepłatne (0 kredytów wg dokumentacji)');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/integrations/apollo/people-test', ['company_id' => $Q['team'], 'confirm' => true])['status'], 'P10.2-18. limit: jeden test na godzinę');
foreach ([[403, 'klucz master'], [401, 'nieprawidłowy klucz'], [429, 'limit zapytań']] as [$code, $frag]) {
	$apolloMock = ['status' => $code, 'body' => ['error' => 'x']];
	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_ubr_apollo_people_%'") as $o) {
		delete_option($o);
	}
	$res = app(ApolloPeopleSource::class)->controlledTest('p102-team.ubrtest.pl', $orgD, null);
	UbrT::ok($res['ok'] === false && str_contains((string) $res['error'], $frag), "P10.2-18. People Search HTTP {$code} → czytelny błąd, bez ponawiania");
}
remove_filter('pre_http_request', $dpApollo, PHP_INT_MAX - 1);
delete_transient('ubr_apollo_people_test_' . $orgD);

UbrT::eq(404, ubrt_rest($uE, 'POST', '/contacts/' . $exist . '/phone-links', ['action' => 'reject', 'e164' => '+48600700001'])['status'], 'P10.2-19. inna organizacja (firma niewidoczna) → 404 (IDOR)');
UbrT::eq(403, ubrt_rest($uX, 'POST', '/contacts/' . $exist . '/phone-links', ['action' => 'confirm', 'e164' => '+48600700001'])['status'], 'P10.2-19. użytkownik spoza organizacji → 403');
UbrT::eq(404, ubrt_rest($uE, 'POST', '/companies/' . $Q['team'] . '/decision-phones/scan')['status'], 'P10.2-19. skan firmy niewidocznej dla organizacji → 404');
UbrT::eq(404, ubrt_rest($uE, 'DELETE', '/contacts/' . $exist . '/phone-links/' . (int) ($conf['data']['phone_links'][0]['id'] ?? 0))['status'], 'P10.2-19. usunięcie cudzego powiązania → 404');
$eLink = $dpLinks->effectiveFor($orgE, [$elsewhere])[$elsewhere] ?? [];
UbrT::ok(! array_filter($eLink, fn ($l) => (int) $l['org_key'] === $orgD), 'P10.2-19. decyzje organizacji D nie trafiają do organizacji E');
UbrT::ok(! str_contains(wp_json_encode($pt['data'] ?? []), (string) $ubrtFakeKey), 'P10.2-19. klucz API nie trafia do odpowiedzi testu');
