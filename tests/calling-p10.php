<?php

/**
 * Testy Prompt 10 — Decision Maker Finder 2.0 + Phone Intelligence + Cold Calling Workspace.
 * Dołączane z tests/run.php po testach QA P7 (wspólne: $mk, $ingest, $searches, $created, $db, $wpdb, $uX).
 * Własne organizacje testowe D (właściciel, admin, 2 członków) i E (izolacja). Bez zapytań zewnętrznych: Apollo
 * i strony firm wyłącznie na mockach (pre_http_request), dane fikcyjne usuwane po testach.
 */

use App\Ubr\Domain\ActionCenter\ActionPriorityEngine as APE;
use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Domain\Calling\CompanySize;
use App\Ubr\Domain\Phones\CompanyPhoneExtractor;
use App\Ubr\Domain\Phones\PhoneNumber;
use App\Ubr\Domain\Phones\PhonePicker;
use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Contacts\ApolloPeopleSource;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\CallFilters;
use App\Ubr\Infrastructure\Repositories\CompanyPhoneRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ContactRepository;
use App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\PhoneRevealRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Services\ApolloPhoneRunner;
use App\Ubr\Services\ApolloPhoneService;
use App\Ubr\Services\CompanyPhoneService;
use App\Ubr\Services\LeadIntelligenceService;
use App\Ubr\Services\OrganizationService;

if (! defined('ABSPATH')) {
	exit(1);
}

/* ------------------------------------------------------------------ */
UbrT::group('P10 — normalizacja numerów telefonów');

$pn = fn (string $v) => PhoneNumber::parse($v)['e164'] ?? null;
UbrT::eq('+48123456789', $pn('+48 12 345 67 89'), 'P10-1. „+48 12 345 67 89” → E.164');
UbrT::eq('+48123456789', $pn('(0-12) 345 67 89'), 'P10-1. stary zapis z zerem „(0-12) 345 67 89”');
UbrT::eq('+48600100200', $pn('0048 600 100 200'), 'P10-1. prefiks 0048');
UbrT::eq('+48600100200', $pn('tel:+48600100200'), 'P10-1. link tel:');
UbrT::ok(PhoneNumber::parse('600 100 200')['mobile'] === true && PhoneNumber::parse('22 123 45 67')['mobile'] === false, 'P10-1. rozpoznanie komórki / stacjonarnego');
$pnExt = PhoneNumber::parse('+48 22 123 45 67 wew. 12');
UbrT::ok($pnExt['e164'] === '+48221234567' && $pnExt['extension'] === '12', 'P10-1. numer wewnętrzny („wew. 12”) osobno');
UbrT::ok($pn('+49 30 1234567') === '+49301234567' && PhoneNumber::parse('+49 30 1234567')['country'] === 'DE', 'P10-1. numer zagraniczny z kodem kraju');
foreach (['5252248481' => 'NIP (10 cyfr)', '525-224-84-81' => 'NIP z kreskami', '012345678' => 'REGON', '0000123456' => 'KRS', '00-950' => 'kod pocztowy', '61 1090 1014 0000 0712 1981 2874' => 'rachunek bankowy', 'ABC-123-456' => 'kod produktu', '805 123 456' => 'nieistniejący prefiks 805', '12345' => 'za krótki'] as $bad => $label) {
	UbrT::eq(null, $pn($bad), "P10-1. odrzucony: {$label}");
}
UbrT::ok(PhoneNumber::format('+48123456789') === '12 345 67 89' && PhoneNumber::format('+48600100200') === '600 100 200', 'P10-1. formatowanie do wyświetlenia');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — ekstrakcja telefonów ze strony firmy');

$p10html = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Ubrtest Fabryka","telephone":"+48 22 100 20 30",'
	. '"contactPoint":[{"@type":"ContactPoint","telephone":"+48 22 100 20 40","contactType":"sales"}],'
	. '"department":[{"@type":"Organization","name":"Oddział Kraków","address":{"addressLocality":"Kraków"},"telephone":"+48 12 300 40 50"},{"@type":"Organization","name":"Filiale Berlin","telephone":"+49 30 1234567"}]}</script></head>'
	. '<body><header><a href="tel:+48221002030">Zadzwoń: 22 100 20 30</a></header>'
	. '<main><p>NIP: 525-224-84-81, REGON: 012345678, KRS: 0000123456</p><p>Konto: 61 1090 1014 0000 0712 1981 2874</p>'
	. '<p>Fax: 22 999 88 77</p><p>ul. Testowa 1, 00-950 Warszawa</p><p>Kod produktu 123-456-789</p></main>'
	. '<footer><p>Tel. 22 100 20 30</p><p>Serwis: tel. 22 100 20 50</p></footer></body></html>';
$p10found = (new CompanyPhoneExtractor())->extract($p10html, 'https://fabryka.ubrtest.pl/', 'home');
$p10by = [];
foreach ($p10found as $f) {
	$p10by[$f['e164']] = $f;
}
UbrT::ok(isset($p10by['+48221002030']) && $p10by['+48221002030']['source'] === 'TEL_LINK' && $p10by['+48221002030']['confidence'] === 'high', 'P10-2. link tel: → numer główny, wysoka pewność');
UbrT::eq(1, count(array_filter($p10found, fn ($f) => $f['e164'] === '+48221002030')), 'P10-2. deduplikacja: ten sam numer (tel:, Schema.org, stopka) zapisany raz');
UbrT::ok(($p10by['+48221002040']['type'] ?? null) === 'SALES', 'P10-2. ContactPoint „sales” → typ SALES');
UbrT::ok(($p10by['+48123004050']['type'] ?? null) === 'BRANCH' && str_contains((string) $p10by['+48123004050']['branch_label'], 'Kraków'), 'P10-2. oddział ze Schema.org (typ BRANCH, etykieta oddziału)');
UbrT::ok(($p10by['+48221002050']['type'] ?? null) === 'SUPPORT', 'P10-2. „Serwis: tel.” → typ SUPPORT');
UbrT::ok(! empty($p10by['+48221002030']['in_footer']), 'P10-2. numer w stopce oznaczony');
UbrT::ok(! isset($p10by['+48229998877']), 'P10-2. numer faksu odrzucony');
$p10digits = implode(' ', array_keys($p10by));
UbrT::ok(! str_contains($p10digits, '5252248481') && ! str_contains($p10digits, '012345678') && ! str_contains($p10digits, '123456789'), 'P10-2. NIP / REGON / KRS / rachunek / kod produktu nie są numerami');

$p10primary = PhonePicker::primary(array_map(fn ($f) => $f + ['id' => crc32($f['e164']), 'phone_type' => $f['type'], 'last_seen_at' => Db::now(), 'country_code' => $f['country']], $p10found), 'PL');
UbrT::eq('+48221002030', $p10primary['e164'] ?? null, 'P10-3. numer główny: centrala (tel: + Schema.org) przed sprzedażą, serwisem i oddziałami');
$p10foreign = PhonePicker::primary([
	['id' => 1, 'e164' => '+49301234567', 'phone_type' => 'MAIN', 'source' => 'SCHEMA', 'page_kind' => 'contact', 'confidence' => 'high', 'country_code' => 'DE', 'last_seen_at' => Db::now()],
	['id' => 2, 'e164' => '+48221002050', 'phone_type' => 'UNKNOWN', 'source' => 'TEXT', 'page_kind' => 'home', 'confidence' => 'medium', 'country_code' => 'PL', 'last_seen_at' => Db::now()],
], 'PL');
UbrT::eq('+48221002050', $p10foreign['e164'] ?? null, 'P10-3. zagraniczny numer nie jest numerem głównym polskiej firmy');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — organizacje testowe D / E i firmy');

$uD = $mk('ownerD');
$uDadmin = $mk('adminD');
$uD1 = $mk('memberD1');
$uD2 = $mk('memberD2');
$uE = $mk('ownerE');
$orgD = app(OrganizationService::class)->create('ubrtest Org D', $uD);
$orgE = app(OrganizationService::class)->create('ubrtest Org E', $uE);
$created['orgs'][] = $orgD;
$created['orgs'][] = $orgE;
app(MemberRepository::class)->add($orgD, $uDadmin, Role::Admin);
app(MemberRepository::class)->add($orgD, $uD1, Role::Member);
app(MemberRepository::class)->add($orgD, $uD2, Role::Member);
$sD = $searches->create(['organization_id' => $orgD, 'user_id' => $uD, 'label' => 'ubrtest P10', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
$sE = $searches->create(['organization_id' => $orgE, 'user_id' => $uE, 'label' => 'ubrtest P10 E', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
$p10companies = app(CompanyRepository::class);
$p10mk = function (string $id, string $name, string $site, array $fields = [], bool $alsoE = false) use ($ingest, $orgD, $orgE, $sD, $sE, $p10companies) {
	$cid = $ingest->ingest(ubrt_candidate(['id' => 'p10-' . $id, 'name' => 'Ubrtest P10 ' . $name, 'website' => $site]))['company_id'];
	app(ResultRepository::class)->add($sD, $orgD, $cid, 'ubrtest', 50, []);

	if ($alsoE) {
		app(ResultRepository::class)->add($sE, $orgE, $cid, 'ubrtest', 50, []);
	}

	$p10companies->update($cid, $fields + ['verification_status' => 'verified', 'domain_status' => 'confirmed']);

	return $cid;
};
$P = [
	'company' => $p10mk('company', 'Tylko Centrala', 'https://p10-company.ubrtest.pl/', ['employees_min' => 30, 'employees_max' => 30], true),
	'direct' => $p10mk('direct', 'Decydent Z Numerem', 'https://p10-direct.ubrtest.pl/', ['employees_min' => 51, 'employees_max' => 200]),
	'same' => $p10mk('same', 'Numer Osoby Rowny Firmie', 'https://p10-same.ubrtest.pl/'),
	'prod' => $p10mk('prod', 'Dyrektor Produkcji', 'https://p10-prod.ubrtest.pl/'),
	'find' => $p10mk('find', 'Bez Numeru', 'https://p10-find.ubrtest.pl/'),
	'research' => $p10mk('research', 'Niezweryfikowana', 'https://p10-research.ubrtest.pl/', ['verification_status' => 'pending']),
	'blocked' => $p10mk('blocked', 'Blokada LI', 'https://p10-blocked.ubrtest.pl/'),
	'dnc' => $p10mk('dnc', 'Zakaz Kontaktu', 'https://p10-dnc.ubrtest.pl/'),
	'apollo' => $p10mk('apollo', 'Apollo Osoba', 'https://p10-apollo.ubrtest.pl/', [], true),
	'claim' => $p10mk('claim', 'Do Przejecia', 'https://p10-claim.ubrtest.pl/'),
	'other' => $p10mk('other', 'Cudza', 'https://p10-other.ubrtest.pl/'),
];
UbrT::ok(count(array_filter($P)) === 11, 'firmy testowe organizacji D utworzone');

$cpr = app(CompanyPhoneRepository::class);
$cps = app(CompanyPhoneService::class);
$ph = fn (string $n, string $type = 'MAIN', string $kind = 'contact', string $src = 'TEL_LINK') => PhoneNumber::parse($n) + ['type' => $type, 'source' => $src, 'source_url' => 'https://x.ubrtest.pl/kontakt', 'page_kind' => $kind, 'context' => 'test', 'confidence' => 'high', 'branch_label' => null];
foreach (['company' => '22 111 00 01', 'direct' => '22 111 00 02', 'same' => '22 111 00 03', 'prod' => '22 111 00 04', 'blocked' => '22 111 00 05', 'dnc' => '22 111 00 06', 'claim' => '22 111 00 07', 'other' => '22 111 00 08', 'apollo' => '22 111 00 09'] as $k => $num) {
	$cpr->recordExtracted($P[$k], [$ph($num)]);
}
UbrT::eq('+48221110001', $p10companies->find($P['company'])['main_phone_e164'], 'P10-3. numer główny zapisany w firmie (listy bez N+1)');

// Ekstrakcja przy analizie / odświeżeniu: dokumenty strony → numery (bez ponownej analizy).
$saved = $cps->fromDocuments($P['find'], [['html' => '<footer>Tel. 22 777 66 55</footer>', 'url' => 'https://p10-find.ubrtest.pl/', 'kind' => 'home']]);
UbrT::ok($saved === 1 && $cpr->forCompanies([$P['find']], $orgD)[$P['find']][0]['e164'] === '+48227776655', 'P10-3. numery ze strony zapisane z dokumentów analizy (bez nowego crawla)');
$wpdb->delete($db->table(Tables::COMPANY_PHONES), ['company_id' => $P['find']]);
$p10companies->update($P['find'], ['main_phone_e164' => null, 'main_phone_raw' => null, 'main_phone_type' => null, 'phones_count' => 0]);

// Numer ręczny organizacji: prywatny i nigdy nie nadpisywany ekstrakcją.
$ctxD = new \App\Ubr\Domain\Tenancy\TenantContext($uD, $orgD, Role::Owner, ['id' => $orgD]);
$ctxE = new \App\Ubr\Domain\Tenancy\TenantContext($uE, $orgE, Role::Owner, ['id' => $orgE]);
$manualRes = ubrt_rest($uD1, 'POST', '/companies/' . $P['company'] . '/phones', ['number' => '+48 600 700 800', 'type' => 'MAIN', 'label' => 'Recepcja (ręcznie)']);
UbrT::eq(201, $manualRes['status'], 'P10-4. dodanie numeru ręcznego (członek organizacji)');
$cpr->recordExtracted($P['company'], [$ph('600 700 800', 'SUPPORT', 'home', 'TEXT'), $ph('22 111 00 01')]);
$manualRow = $db->row("SELECT * FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d AND org_key = %d", $P['company'], $orgD);
UbrT::ok($manualRow && $manualRow['phone_type'] === 'MAIN' && (int) $manualRow['manual'] === 1 && $manualRow['raw_number'] !== '', 'P10-4. ekstrakcja nie nadpisuje numeru ręcznego');
UbrT::eq('+48600700800', $cps->forCompany($ctxD, $P['company'])['main']['e164'] ?? null, 'P10-4. ręczny numer główny organizacji ma pierwszeństwo (tylko dla tej organizacji)');
$ePhones = ubrt_rest($uE, 'GET', '/companies/' . $P['company'] . '/phones');
UbrT::ok($ePhones['status'] === 200 && ! array_filter($ePhones['data']['phones'], fn ($x) => $x['manual']) && ! str_contains(wp_json_encode($ePhones['data']), 'Recepcja (ręcznie)') && ($ePhones['data']['main']['e164'] ?? null) === '+48221110001', 'P10-4. organizacja E widzi numery ze strony, ale nie numer ręczny organizacji D');
UbrT::eq(404, ubrt_rest($uE, 'DELETE', '/companies/' . $P['company'] . '/phones/' . $manualRow['id'])['status'], 'P10-4. organizacja E nie usunie numeru organizacji D');
UbrT::eq(404, ubrt_rest($uE, 'GET', '/companies/' . $P['direct'] . '/phones')['status'], 'P10-4. numery firmy niewidocznej dla organizacji → 404');
UbrT::eq(422, ubrt_rest($uD1, 'POST', '/companies/' . $P['company'] . '/phones', ['number' => '525-224-84-81'])['status'], 'P10-4. NIP jako numer ręczny → odrzucony (422)');
UbrT::eq(403, ubrt_rest($uX, 'GET', '/companies/' . $P['company'] . '/phones')['status'], 'P10-4. osoba spoza organizacji: 403');

// Ręczne odświeżenie numerów ze strony (strona główna + Kontakt; mock HTTP, bez sieci).
$GLOBALS['ubrt_sites']['p10-refresh.ubrtest.pl'] = '<html><body><a href="/kontakt">Kontakt</a><footer>Tel. 22 333 44 55</footer></body></html>';
$GLOBALS['ubrt_sites']['p10-refresh.ubrtest.pl/kontakt'] = '<html><body><h1>Kontakt</h1><p>Centrala: <a href="tel:+48223334400">22 333 44 00</a></p></body></html>';
$P['refresh'] = $p10mk('refresh', 'Odswiezanie', 'https://p10-refresh.ubrtest.pl/');
$rf = ubrt_rest($uD1, 'POST', '/companies/' . $P['refresh'] . '/phones/refresh');
UbrT::ok($rf['status'] === 200 && ($rf['data']['main']['e164'] ?? null) === '+48223334400' && count($rf['data']['phones']) === 2 && ! empty($rf['data']['checked_at']), 'P10-4. „Odśwież ze strony”: strona główna + Kontakt, numer główny z Kontaktu (centrala)');
$rf2 = ubrt_rest($uD1, 'POST', '/companies/' . $P['refresh'] . '/phones/refresh');
UbrT::ok($rf2['status'] === 200 && str_contains((string) ($rf2['data']['message'] ?? ''), 'przed chwilą'), 'P10-4. ponowne odświeżenie w ciągu 10 min — bez pobierania (blokada)');
delete_transient('ubr_phones_refresh_' . $P['refresh']);

// Ponowne odświeżenie po zmianie strony: numery z tej samej strony zastąpione (aktualny typ), bez duplikatów, ręczne bez zmian.
$cps->addManual($ctxD, $P['refresh'], ['number' => '+48 601 222 333', 'type' => 'MAIN', 'label' => 'ręczny (test)']);
$GLOBALS['ubrt_sites']['p10-refresh.ubrtest.pl/kontakt'] = '<html><body><h1>Kontakt</h1><p>Recepcja: <a href="tel:+48223334400">22 333 44 00</a></p><p>Kadry: <a href="tel:+48223334499">22 333 44 99</a></p></body></html>';
$rf3 = ubrt_rest($uD1, 'POST', '/companies/' . $P['refresh'] . '/phones/refresh');
$rfRows = $db->rows("SELECT e164, org_key, phone_type, `manual` FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d ORDER BY e164", $P['refresh']);
$rfWeb = array_filter($rfRows, fn ($r) => (int) $r['org_key'] === 0);
UbrT::ok($rf3['status'] === 200 && count($rfWeb) === 3 && count(array_unique(array_column($rfWeb, 'e164'))) === 3, 'P10-4b. ponowne odświeżenie: numery strony zastąpione, bez duplikatów');
UbrT::ok((bool) array_filter($rfRows, fn ($r) => $r['e164'] === '+48601222333' && (int) $r['manual'] === 1 && (int) $r['org_key'] === $orgD), 'P10-4b. numer ręczny organizacji pozostał po ponownym odświeżeniu');
UbrT::eq('OTHER', array_values(array_filter($rfWeb, fn ($r) => $r['e164'] === '+48223334499'))[0]['phone_type'] ?? null, 'P10-4b. „Kadry” → dział (OTHER), nie centrala');
delete_transient('ubr_phones_refresh_' . $P['refresh']);

// robots.txt zabrania pobierania → bez pobrania strony i bez numerów.
$GLOBALS['ubrt_sites']['p10-robots.ubrtest.pl'] = '<html><body><footer>Tel. 22 444 55 66</footer></body></html>';
$GLOBALS['ubrt_sites']['p10-robots.ubrtest.pl/robots.txt'] = "User-agent: *\nDisallow: /\n";
$P['robots'] = $p10mk('robots', 'Robots', 'https://p10-robots.ubrtest.pl/');
$rb = ubrt_rest($uD1, 'POST', '/companies/' . $P['robots'] . '/phones/refresh');
UbrT::ok($rb['status'] === 422 && str_contains((string) $rb['data']['message'], 'robots.txt') && ! $db->value("SELECT COUNT(*) FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d", $P['robots']), 'P10-4b. robots.txt zabrania → strona nie jest pobierana, brak numerów');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — jakość ekstrakcji: etykiety, osoby, działy, centrala (struktury z rzeczywistych stron)');

$xq = function (string $html) {
	$f = (new CompanyPhoneExtractor())->extract($html, 'https://x.ubrtest.pl/kontakt', 'contact');
	$by = [];
	foreach ($f as $p) {
		$by[$p['e164']] = $p;
	}
	$main = PhonePicker::primary(array_map(fn ($p) => $p + ['phone_type' => $p['type'], 'country_code' => $p['country'], 'last_seen_at' => Db::now(), 'id' => crc32($p['e164'])], $f), 'PL');

	return [$by, $main['e164'] ?? null];
};
[$g, $gm] = $xq('<html><head><script type="application/ld+json">{"@type":"Organization","contactPoint":{"@type":"ContactPoint","telephone":"+48 48 384 58 20","contactType":"customer service"}}</script></head><body><ul><li>Sprzedaż: <a href="tel:+48483845823">+48 48 384 58 23</a></li><li>Magazyn - Dostawy: <a href="tel:+48483845864">+48 48 384 58 64</a></li><li>Kadry: <a href="tel:+48483845829">+48 48 384 58 29</a></li><li>Recepcja: <a href="tel:+48483845820">+48 48 384 58 20</a></li></ul><p>Małgorzata Gałek <a href="tel:+48483845845">+48 48 384 58 45</a></p><p>NIP: 9482044896 REGON: 670226350</p></body></html>');
UbrT::ok($gm === '+48483845820' && $g['+48483845820']['type'] === 'MAIN', 'P10-2b. „Recepcja” → centrala i numer główny (etykieta wygrywa z ContactPoint „customer service”)');
UbrT::ok($g['+48483845829']['type'] === 'OTHER' && $g['+48483845864']['type'] === 'OTHER' && $g['+48483845823']['type'] === 'SALES', 'P10-2b. „Kadry” / „Magazyn” → dział (nie główny), „Sprzedaż” → SALES');
UbrT::ok($g['+48483845845']['type'] === 'OTHER' && ! isset($g['+48948204489']), 'P10-2b. numer osoby (imię i nazwisko) → OTHER; NIP / REGON nie są numerami');
[$z, $zm] = $xq('<body><div><h4>Centrala</h4><p>tel. <a href="tel:+48323928500">+48 32 39 28 500</a><br>e-mail: biuro@x.pl</p></div><div><h4>Sekretariat Zarządu:</h4><p>tel: <a href="tel:+48322841370">+48 32 284 13 70</a> <a href="tel:+48323928501">+48 32 3928 501</a></p></div><div><h4>Biuro Projektowe:</h4><p>tel. <a href="tel:+48323918540">+48 32 39 28 540</a></p></div><div><h4>Serwis</h4><p>tel. <a href="tel:+48607101011">+ 48 607 101 011</a></p></div></body>');
UbrT::ok($z['+48323928500']['type'] === 'MAIN' && $z['+48322841370']['type'] === 'MAIN' && $z['+48323928501']['type'] === 'MAIN', 'P10-2b. nagłówki „Centrala” / „Sekretariat Zarządu” (także drugi numer pod tą samą etykietą) → MAIN');
UbrT::ok($z['+48323918540']['type'] === 'OTHER' && $z['+48323918540']['confidence'] === 'low' && str_contains($z['+48323918540']['context'], 'tekst linku'), 'P10-2b. „Biuro Projektowe” → dział; tekst linku ≠ numer w tel: → niska pewność (do weryfikacji)');
UbrT::eq('SUPPORT', $z['+48607101011']['type'], 'P10-2b. „Serwis” → SUPPORT');
[$me, $mm] = $xq('<body><p>Metal Expert Sp. z o.o.<br>82-300 Elbląg, Stoczniowa 2<br>tel. +48 55 239 14 01</p><p>Krzysztof Kowalczyk<br>tel. +48 55 239 14 87<br>kom. +48 539 969 225<br>e-mail: k.kowalczyk@x.pl</p><p>Piotr Mariański – Purchasing Manager tel. +48 694 416 095</p></body>');
UbrT::ok($mm === '+48552391401' && $me['+48552391487']['type'] === 'OTHER' && $me['+48539969225']['type'] === 'OTHER' && $me['+48694416095']['type'] === 'OTHER', 'P10-2b. numer firmy pod adresem = główny; numery osób (stacjonarny, „kom.”, stanowisko) → OTHER (tekst rozdzielony <br>)');
[$fa, $fm] = $xq('<body><h3>Centrala Grupy</h3><p>FAMUR S.A.<br>ul. Armii Krajowej 51<br>40-698 Katowice</p><p>T. <a href="tel:+48323596300">+48 32 359 63 00</a> F. +48 32 359 66 77</p><h3>Sprzedaż</h3><p>T: <a href="tel:+48323596450">+48 32 359 64 50</a></p><h3>Dla Mediów</h3><p>Aneta Rumniak T: <a href="tel:+48605415444">+48 605 415 444</a></p></body>');
UbrT::ok($fm === '+48323596300' && $fa['+48323596300']['type'] === 'MAIN' && ! isset($fa['+48323596677']), 'P10-2b. „Centrala Grupy” nad adresem (kod pocztowy nie przerywa etykiety) → MAIN; faks pominięty');
UbrT::ok($fa['+48605415444']['type'] === 'OTHER' && $fa['+48323596450']['type'] === 'SALES', 'P10-2b. kontakt dla mediów (osoba) → OTHER, sprzedaż → SALES');
$xq2 = function (string $html, string $company) {
	$f = (new CompanyPhoneExtractor())->extract($html, 'https://x.ubrtest.pl/kontakt', 'contact', $company);
	$by = [];
	foreach ($f as $p) {
		$by[$p['e164']] = $p;
	}
	$rows = [];
	foreach (array_values($f) as $i => $p) {
		$rows[] = $p + ['phone_type' => $p['type'], 'country_code' => $p['country'], 'last_seen_at' => Db::now(), 'id' => $i + 1];
	}

	return [$by, PhonePicker::primary($rows, 'PL')['e164'] ?? null];
};
[$al, $alm] = $xq2('<body><h3>Alchemia S.A.</h3><p>Aleje Jerozolimskie 92<br>00-807 Warszawa</p><p>tel.: <a href="tel:+48226586452">+48 22 658 64 52</a></p><p>tel.: <a href="tel:+48226586438">+48 22 658 64 38</a></p><h3>Huta Bankowa Sp. z o.o.</h3><p>Sekretariat tel.: <a href="tel:+48322957405">+48 32 295 74 05</a></p><p>Marcin Bursztynowicz tel.: <a href="tel:+48604456064">+48 604 456 064</a></p></body>', 'Alchemia SA');
UbrT::ok($alm === '+48226586452' && $al['+48226586452']['type'] === 'MAIN', 'P10-2b. strona grupy: blok własnej spółki z adresem → centrala, pierwszy numer na stronie jako główny');
UbrT::ok($al['+48322957405']['type'] === 'BRANCH' && str_contains((string) $al['+48322957405']['branch_label'], 'Huta Bankowa'), 'P10-2b. „Sekretariat” innej spółki z grupy → oddział / inna spółka (nie numer główny tej firmy)');
[$gl, $glm] = $xq2('<body><ul><li><a href="tel:+48483845823">Sprzedaż: +48 48 384 58 23</a></li><li><a href="tel:+48483845829">Kadry: +48 48 384 58 29</a></li><li><a href="tel:+48483845820">Recepcja: +48 48 384 58 20</a></li></ul></body>', 'GGG Sp. z o.o.');
UbrT::ok($glm === '+48483845820' && $gl['+48483845829']['type'] === 'OTHER' && $gl['+48483845823']['type'] === 'SALES', 'P10-2b. etykieta w treści linku tel: („Recepcja: …”) rozpoznana');
[$bo] = $xq2('<body><h2>Zadzwoń do nas!</h2><p>Numer telefonu +48 22 715 40 40</p></body>', 'Bosch Polska');
UbrT::ok(isset($bo['+48227154040']), 'P10-2c. „Numer telefonu +48 …” (odmiana słowa telefon) rozpoznany');
[$ng, $ngm] = $xq2('<body><p>NG Engineering Sp. z o.o.<br>ul. Testowa 1, 54-000 Wrocław<br>T 0048 71 733 99 00</p><ul><li>Krzysztof Cieplik</li><li><b>T</b> 0048 666 039 310</li></ul></body>', 'NG Engineering Group');
UbrT::ok($ngm === '+48717339900' && $ng['+48717339900']['type'] === 'MAIN' && $ng['+48666039310']['type'] === 'OTHER', 'P10-2c. „T 0048 …” rozpoznane; numer spółki pod adresem = centrala („Engineering” w nazwie nie jest stanowiskiem)');
[$ek, $ekm] = $xq2('<body><h1>Kontakt</h1><p>Infolinia: <a href="tel:+48324591500">+48 32 459 15 00</a></p><footer><p>Kornice, ul. Spacerowa 4</p><p>Oddział Wodzisław Śląski: ul. Testowa 1</p><p>Oddział Kędzierzyn-Koźle: ul. Próbna 2</p><p>Infolinia: <a href="tel:+48324591500">+48 32 459 15 00</a></p></footer></body>', 'Eko-Okna S.A.');
UbrT::ok($ekm === '+48324591500' && $ek['+48324591500']['type'] !== 'BRANCH', 'P10-2c. „Infolinia:” pod blokiem adresów oddziałów nie jest numerem oddziału (jak ekookna.pl)');
UbrT::eq('MAIN', CompanyPhoneExtractor::classify('Investor Relations Media Center SECO/WARWICK HEADQUARTER Sobieskiego 8, 66-200 Świebodzin Tel.'), 'P10-2c. „HEADQUARTER” (l. poj.) = centrala, mimo słowa „Media” w menu (jak SECO/WARWICK)');
[$nn] = $xq2('<body><p>Kod 54-000, art. 123456789, pkt 12 345 678, ilość 1 000 000 szt.</p></body>', 'X');
UbrT::eq([], $nn, 'P10-2c. ciągi cyfr bez słowa „tel.” (artykuł, ilość, kod) nie są numerami');
UbrT::eq(null, PhonePicker::primary([['id' => 1, 'e164' => '+919821286261', 'phone_type' => 'UNKNOWN', 'country_code' => null, 'country' => 'IN', 'last_seen_at' => Db::now()], ['id' => 2, 'e164' => '+48322957405', 'phone_type' => 'MAIN', 'branch_label' => 'inna spółka: Huta Bankowa Sp. z o.o.', 'country_code' => 'PL', 'last_seen_at' => Db::now()]], 'PL'), 'P10-3b. tylko numer zagraniczny / innej spółki z grupy → brak numeru głównego (zamiast błędnego)');
UbrT::eq('+48683820500', PhonePicker::primary([['id' => 1, 'e164' => '+919821286261', 'phone_type' => 'UNKNOWN', 'country_code' => null, 'page_kind' => 'contact', 'source' => 'SCHEMA', 'confidence' => 'high', 'last_seen_at' => Db::now()], ['id' => 2, 'e164' => '+48683820500', 'phone_type' => 'OTHER', 'country_code' => 'PL', 'page_kind' => 'contact', 'last_seen_at' => Db::now()]], 'PL')['e164'] ?? null, 'P10-3b. numer z nieznanym krajem (+91, brak w mapie prefiksów) nie jest główny dla firmy z Polski (jak SECO/WARWICK)');

// <base href> na stronie: link względny „pl/kontakt” liczony od adresu bazowego, nie od /pl/start.
$GLOBALS['ubrt_sites']['p10-base.ubrtest.pl/pl/start'] = '<html><head><base href="https://p10-base.ubrtest.pl/"></head><body><a href="pl/kontakt">Kontakt</a></body></html>';
$GLOBALS['ubrt_sites']['p10-base.ubrtest.pl/pl/kontakt'] = '<html><body><p>Firma Base Sp. z o.o. T 0048 71 733 99 00</p></body></html>';
$P['base'] = $p10mk('base', 'Base', 'https://p10-base.ubrtest.pl/pl/start');
$bh = ubrt_rest($uD1, 'POST', '/companies/' . $P['base'] . '/phones/refresh');
UbrT::ok($bh['status'] === 200 && ($bh['data']['main']['e164'] ?? null) === '+48717339900', 'P10-4c. <base href> uwzględniony przy szukaniu strony Kontakt');

// Strona Kontakt tylko z listą krajów → jedna dodatkowa strona („Polska”).
$GLOBALS['ubrt_sites']['p10-hop.ubrtest.pl'] = '<html><body><a href="/kontakt">Kontakt</a></body></html>';
$GLOBALS['ubrt_sites']['p10-hop.ubrtest.pl/kontakt'] = '<html><body><a href="/kontakt/polska">Polska</a> <a href="/kontakt/niemcy">Niemcy</a></body></html>';
$GLOBALS['ubrt_sites']['p10-hop.ubrtest.pl/kontakt/polska'] = '<html><body><p>Centrala: <a href="tel:+48225556677">22 555 66 77</a></p></body></html>';
$P['hop'] = $p10mk('hop', 'Hop', 'https://p10-hop.ubrtest.pl/');
$hp = ubrt_rest($uD1, 'POST', '/companies/' . $P['hop'] . '/phones/refresh');
UbrT::ok($hp['status'] === 200 && ($hp['data']['main']['e164'] ?? null) === '+48225556677' && str_contains((string) $hp['data']['main']['source_url'], '/kontakt/polska'), 'P10-4c. Kontakt z listą krajów → numer z podstrony „Polska” (maks. 1 dodatkowe pobranie), źródło zapisane');

// Jak ISCAR (LocalWP): krótszy adres innego kraju z „contact-” nie wygrywa z „poland”.
$GLOBALS['ubrt_sites']['p10-hop2.ubrtest.pl'] = '<html><body><a href="/pl-pl/contact-iscar">Kontakt</a></body></html>';
$GLOBALS['ubrt_sites']['p10-hop2.ubrtest.pl/pl-pl/contact-iscar'] = '<html><body><a href="/pl-pl/contact-iscar/uae">UAE</a> <a href="/pl-pl/contact-iscar/poland">Poland</a></body></html>';
$GLOBALS['ubrt_sites']['p10-hop2.ubrtest.pl/pl-pl/contact-iscar/uae'] = '<html><body><p>Tel: +971 4 824 3432</p></body></html>';
$GLOBALS['ubrt_sites']['p10-hop2.ubrtest.pl/pl-pl/contact-iscar/poland'] = '<html><body><p>Tel: +48 71 332 59 99</p></body></html>';
$P['hop2'] = $p10mk('hop2', 'Hop Dwa', 'https://p10-hop2.ubrtest.pl/');
$hp2 = ubrt_rest($uD1, 'POST', '/companies/' . $P['hop2'] . '/phones/refresh');
UbrT::ok($hp2['status'] === 200 && ($hp2['data']['main']['e164'] ?? null) === '+48713325999' && ! in_array('+97148243432', array_column($hp2['data']['phones'] ?? [], 'e164'), true), 'P10-4c. lista krajów: „Poland” wygrywa z krótszym adresem innego kraju (bez numeru ZEA)');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — Apollo People Search (mock): stanowiska, powiązanie z firmą, identyfikatory');

$p10http = [];
$p10apollo = ['search' => null, 'match' => null, 'result' => []];
$p10mock = function ($pre, $args, $url) use (&$p10http, &$p10apollo) {
	if (! str_starts_with((string) $url, 'https://api.apollo.io/')) {
		return $pre;
	}

	$p10http[] = ['url' => $url, 'method' => $args['method'] ?? 'GET'];
	$json = fn ($code, $body) => ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

	if (str_contains($url, '/mixed_people/api_search')) {
		return $json(200, $p10apollo['search']);
	}

	if (str_contains($url, '/people/match')) {
		return is_array($p10apollo['match']) && isset($p10apollo['match']['__code']) ? $json($p10apollo['match']['__code'], ['error' => 'test']) : $json(200, $p10apollo['match']);
	}

	if (str_contains($url, '/webhook_result/')) {
		$r = array_shift($p10apollo['result']) ?? [404, ['error_code' => 'result_pending', 'retry_after_seconds' => 30]];

		return $json($r[0], $r[1]);
	}

	return $json(404, []);
};
add_filter('pre_http_request', $p10mock, PHP_INT_MAX, 3);
add_filter('ubr/contacts/apollo_people_enabled', '__return_true');

$p10apollo['search'] = ['people' => [
	['id' => 'p10ceo0001', 'first_name' => 'Anna', 'last_name' => 'Prezesowa', 'title' => 'Prezes Zarządu', 'organization' => ['id' => 'orgP10', 'primary_domain' => 'p10-apollo.ubrtest.pl']],
	['id' => 'p10ecom001', 'first_name' => 'Piotr', 'last_name' => 'Sklepowy', 'title' => 'Kierownik E-commerce', 'organization_id' => 'orgP10'],
	['id' => 'p10prod001', 'first_name' => 'Jan', 'last_name' => 'Produkcyjny', 'title' => 'Dyrektor Produkcji', 'organization' => ['primary_domain' => 'p10-apollo.ubrtest.pl']],
	['id' => 'p10other01', 'first_name' => 'Ewa', 'last_name' => 'Obca', 'title' => 'CEO', 'organization' => ['id' => 'orgOTHER', 'primary_domain' => 'p10-apollo-inna.ubrtest.pl', 'name' => 'Ubrtest P10 Apollo Osoba']],
	['id' => 'p10mask001', 'first_name' => 'Tomasz', 'last_name' => 'K***', 'title' => 'Właściciel'],
]];
delete_transient('ubr_rl_apollo');
$p10search = app(ApolloPeopleSource::class)->search('p10-apollo.ubrtest.pl', $orgD, 'orgP10');
$p10names = array_map(fn ($c) => $c['name']->full(), $p10search['candidates']);
$p10q = urldecode((string) ($p10http[0]['url'] ?? ''));
UbrT::ok(str_contains($p10q, 'person_titles[]=Prezes Zarządu') && str_contains($p10q, 'person_titles[]=CEO') && str_contains($p10q, 'include_similar_titles=true') && str_contains($p10q, 'q_organization_domains_list[]=p10-apollo.ubrtest.pl'), 'P10-5. zapytanie: stanowiska EN + PL, podobne tytuły, filtr domeny');
UbrT::ok(in_array('Anna Prezesowa', $p10names, true) && in_array('Piotr Sklepowy', $p10names, true), 'P10-5. Prezes i Kierownik E-commerce przyjęci (domena / ID organizacji Apollo)');
UbrT::ok(! in_array('Ewa Obca', $p10names, true), 'P10-5. osoba z firmą o PODOBNEJ nazwie, ale inną domeną → odrzucona');
UbrT::ok(! array_filter($p10names, fn ($n) => str_contains($n, 'K***') || str_starts_with($n, 'Tomasz')), 'P10-5. zamaskowane nazwisko → brak kontaktu');
$p10prod = array_values(array_filter($p10search['candidates'], fn ($c) => $c['name']->full() === 'Jan Produkcyjny'))[0] ?? null;
UbrT::ok($p10prod === null || ! in_array($p10prod['role'], CallReadiness::DECISION_ROLES, true), 'P10-5. Dyrektor Produkcji nie jest decydentem w sprawie strony WWW');
$p10ceo = array_values(array_filter($p10search['candidates'], fn ($c) => $c['name']->full() === 'Anna Prezesowa'))[0] ?? [];
UbrT::ok(($p10ceo['external_provider'] ?? '') === 'apollo' && ($p10ceo['external_id'] ?? '') === 'p10ceo0001' && $p10ceo['email'] === null && $p10ceo['phone'] === null, 'P10-5. zapisany identyfikator osoby Apollo; bez e-maili i telefonów z wyszukiwania');
UbrT::eq(0, (int) $db->value("SELECT billable FROM {$db->table(Tables::API_REQUESTS)} WHERE organization_id = %d AND endpoint = %s ORDER BY id DESC LIMIT 1", $orgD, ApolloPeopleSource::ENDPOINT), 'P10-5. People Search zapisany jako niepłatny (0 kredytów wg dokumentacji)');
remove_filter('ubr/contacts/apollo_people_enabled', '__return_true');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — kontakty testowe i Cold Call Readiness (PHP = SQL)');

$ctr = app(ContactRepository::class);
$mkContact = function (int $companyId, string $name, string $title, string $role, ?string $phone = null, array $extra = []) use ($ctr) {
	return $ctr->insert($extra + [
		'company_id' => $companyId, 'org_key' => 0, 'person_key' => 'p10-' . md5($name . $companyId), 'first_name' => strtok($name, ' '), 'last_name' => substr($name, strpos($name, ' ') + 1),
		'full_name' => $name, 'job_title' => $title, 'normalized_role' => $role, 'verification_status' => 'probable', 'confidence_level' => 'medium',
		'contact_priority_score' => 80, 'rank_position' => 1, 'public_business_phone' => $phone, 'source_type' => 'WEBSITE', 'needs_review' => 0, 'active' => 1,
	]);
};
$ct = [
	'direct' => $mkContact($P['direct'], 'Adam Bezposredni', 'Prezes Zarządu', 'ceo', '+48 601 000 001'),
	'same' => $mkContact($P['same'], 'Beata Recepcyjna', 'Właścicielka', 'owner', '22 111 00 03'),
	'prod' => $mkContact($P['prod'], 'Cezary Produkcja', 'Dyrektor Produkcji', 'other', '+48 601 000 004'),
	'apollo' => $mkContact($P['apollo'], 'Anna Prezesowa', 'Prezes Zarządu', 'ceo', null, ['external_provider' => 'apollo', 'external_id' => 'p10ceo0001', 'public_business_email' => null]),
	'noApollo' => $mkContact($P['find'], 'Daria Strona', 'Właścicielka', 'owner'),
];
UbrT::eq('+48601000001', $ctr->find($ct['direct'])['phone_e164'], 'P10-6. numer osoby normalizowany do phone_e164 przy zapisie');

app(LeadIntelligenceRepository::class)->save($orgD, $P['blocked'], ['lead_score' => 60, 'score_status' => 'scored', 'confidence' => 'medium', 'coverage' => 60, 'redesign' => 'moderate', 'recommendation' => 'do_not_contact', 'rank_score' => 0.0, 'has_blocker' => 1, 'analysis_id' => null, 'input_hash' => str_repeat('b', 64), 'input_snapshot' => ['test' => true], 'result' => ['blockers' => [['key' => 'inactive', 'label' => 'test']]], 'algorithm_version' => LeadIntelligenceService::VERSION, 'calculated_at' => Db::now()]);
$dncPid = (int) ubrt_rest($uD, 'POST', '/prospects', ['company_id' => $P['dnc'], 'search_id' => $sD])['data']['prospect_id'];
$dncRes = ubrt_rest($uD, 'POST', '/prospects/' . $dncPid . '/do-not-contact', ['value' => true, 'reason' => 'Prośba firmy (test)']);
UbrT::ok($dncRes['status'] === 200 && $dncRes['data']['prospect']['do_not_contact'] === true && $dncRes['data']['prospect']['do_not_contact_reason'] === 'Prośba firmy (test)', 'P10-7. zakaz kontaktu zapisany z powodem');

$p10ready = fn (int $cid) => app(\App\Ubr\Services\OwnershipService::class)->readiness($ctxD, $cid);
// Prompt 10.2: numer zapisany przy osobie BEZ dowodu powiązania nie daje READY_DIRECT; dowód = powiązanie VERIFIED.
UbrT::ok($p10ready($P['direct']) !== 'READY_DIRECT', 'P10.2-a. numer przy osobie bez powiązania z dowodem → nie READY_DIRECT');
$p10links = app(\App\Ubr\Infrastructure\Repositories\ContactPhoneLinkRepository::class);
$p10links->upsertAuto(['contact_id' => $ct['direct'], 'company_id' => $P['direct'], 'e164' => '+48601000001', 'link_status' => 'VERIFIED', 'evidence' => 'SAME_BLOCK', 'source_url' => 'https://p10-direct.ubrtest.pl/zarzad', 'evidence_excerpt' => 'Adam Bezposredni · Prezes Zarządu · tel. 601 000 001']);
$p10links->upsertAuto(['contact_id' => $ct['same'], 'company_id' => $P['same'], 'e164' => '+48221110003', 'link_status' => 'REJECTED', 'evidence' => 'GENERAL_NUMBER']);
$p10links->upsertAuto(['contact_id' => $ct['prod'], 'company_id' => $P['prod'], 'e164' => '+48601000004', 'link_status' => 'VERIFIED', 'evidence' => 'SAME_BLOCK']);
$expect = ['company' => 'CALL_COMPANY', 'direct' => 'READY_DIRECT', 'same' => 'CALL_COMPANY', 'prod' => 'CALL_COMPANY', 'find' => 'FIND_PHONE', 'research' => 'RESEARCH', 'blocked' => 'BLOCKED', 'dnc' => 'BLOCKED'];
foreach ($expect as $k => $state) {
	UbrT::eq($state, $p10ready($P[$k]), "P10-7. SQL: {$k} → {$state}");
}
$php = fn (array $s) => CallReadiness::classify($s);
UbrT::ok(
	$php(['company_phone' => true, 'verification_status' => 'verified']) === 'CALL_COMPANY'
	&& $php(['direct_phone' => true, 'company_phone' => true]) === 'READY_DIRECT'
	&& $php(['verification_status' => 'verified', 'domain_status' => 'confirmed']) === 'FIND_PHONE'
	&& $php(['verification_status' => 'pending']) === 'RESEARCH'
	&& $php(['blocked' => true, 'direct_phone' => true]) === 'BLOCKED',
	'P10-7. PHP: ta sama reguła (BLOCKED > READY_DIRECT > CALL_COMPANY > RESEARCH > FIND_PHONE)'
);
UbrT::ok(CompanySize::present(['employees_min' => 30, 'employees_max' => 30])['label'] === '30 os.' && CompanySize::present(['employees_min' => 51, 'employees_max' => 200])['band'] === '51-200' && CompanySize::present([])['label'] === 'Brak danych', 'P10-8. wielkość: dokładna liczba, przedział, „Brak danych”');

// Lead Potential i scoringi niezależne od telefonów (P10 nie zmienia danych wejściowych Lead Intelligence).
$liSvc = app(LeadIntelligenceService::class);
$snapBefore = LeadIntelligenceService::hash($liSvc->snapshot($orgD, $P['claim']));
$cpr->recordExtracted($P['claim'], [$ph('22 111 00 17', 'SALES')]);
$cps->addManual($ctxD, $P['claim'], ['number' => '+48 602 000 017', 'type' => 'MAIN']);
UbrT::eq($snapBefore, LeadIntelligenceService::hash($liSvc->snapshot($orgD, $P['claim'])), 'P10-9. znaleziony telefon nie zmienia danych wejściowych Lead Potential (scoring bez zmian)');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — Apollo Phone Enrichment („Odblokuj telefon”, mock)');

$p10http = [];
$rv = app(ApolloPhoneService::class);
$pre = $rv->preflight($ctxD, $ct['apollo']);
UbrT::ok(! $pre['can_unlock'] && in_array('disabled', array_column($pre['reasons'], 'code'), true) && $pre['confirm_token'] === null, 'P10-10. domyślnie wyłączone (UBR_APOLLO_PHONE_ENABLED) — brak tokenu');
add_filter('ubr/apollo/phone_enabled', '__return_true');
$preM = ubrt_rest($uD1, 'GET', '/contacts/' . $ct['apollo'] . '/phone-reveal');
UbrT::ok($preM['status'] === 200 && in_array('forbidden', array_column($preM['data']['reasons'], 'code'), true), 'P10-10. członek bez uprawnienia: „forbidden” (owner / admin)');
UbrT::eq(403, ubrt_rest($uD1, 'POST', '/contacts/' . $ct['apollo'] . '/phone-reveal', ['confirm_token' => 'x', 'acknowledge_unknown_cost' => true])['status'], 'P10-10. POST przez członka → 403');
UbrT::ok(in_array('no_apollo_id', array_column($rv->preflight($ctxD, $ct['noApollo'])['reasons'], 'code'), true), 'P10-10. osoba bez identyfikatora Apollo → nie można odblokować');
$pre = ubrt_rest($uD, 'GET', '/contacts/' . $ct['apollo'] . '/phone-reveal')['data'];
// Prompt 10.3: stawki z dokumentacji Apollo pokazane jako dokumentacja; koszt na koncie nadal „wymaga potwierdzenia”.
UbrT::ok($pre['can_unlock'] && $pre['cost']['known'] === false && $pre['cost']['requires_acknowledgement'] === true && $pre['cost']['label'] === 'Koszt wymaga potwierdzenia' && str_contains($pre['cost']['message'], 'Wg dokumentacji Apollo') && is_string($pre['confirm_token']), 'P10-10. sprawdzenie: koszt niepotwierdzony („Koszt wymaga potwierdzenia”, stawki tylko jako dokumentacja), token potwierdzenia');
UbrT::eq(0, count($p10http), 'P10-10. sprawdzenie i okno potwierdzenia nie wysyłają zapytań do Apollo');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/contacts/' . $ct['apollo'] . '/phone-reveal', ['confirm_token' => $pre['confirm_token'], 'acknowledge_unknown_cost' => false])['status'], 'P10-10. bez potwierdzenia nieznanego kosztu → 422');
UbrT::eq(422, ubrt_rest($uD, 'POST', '/contacts/' . $ct['apollo'] . '/phone-reveal', ['confirm_token' => 'zly.' . (time() + 60), 'acknowledge_unknown_cost' => true])['status'], 'P10-10. nieprawidłowy token → 422');
UbrT::eq(0, count($p10http), 'P10-10. odrzucone próby nie wysłały zapytania (brak zużycia kredytów)');

$p10apollo['match'] = ['request_id' => '123456789', 'person' => ['id' => 'p10ceo0001']];
delete_transient('ubr_rl_apollo');
$rev1 = ubrt_rest($uD, 'POST', '/contacts/' . $ct['apollo'] . '/phone-reveal', ['confirm_token' => $pre['confirm_token'], 'acknowledge_unknown_cost' => true]);
$matchUrl = urldecode((string) ($p10http[0]['url'] ?? ''));
UbrT::ok($rev1['status'] === 200 && $rev1['data']['status'] === 'pending' && $rev1['data']['created'] === true, 'P10-10. zlecenie po potwierdzeniu: status „pending” (numer dostarczany asynchronicznie)');
UbrT::ok(count($p10http) === 1 && str_contains($matchUrl, '/people/match') && str_contains($matchUrl, 'id=p10ceo0001') && str_contains($matchUrl, 'reveal_phone_number=true') && str_contains($matchUrl, 'poll_only=true') && str_contains($matchUrl, 'reveal_personal_emails=false'), 'P10-10. jedno zapytanie People Enrichment: reveal_phone_number + poll_only, bez e-maili');
$rev2 = ubrt_rest($uD, 'POST', '/contacts/' . $ct['apollo'] . '/phone-reveal', ['confirm_token' => $pre['confirm_token'], 'acknowledge_unknown_cost' => true]);
UbrT::ok($rev2['status'] === 200 && $rev2['data']['created'] === false && $rev2['data']['reveal_id'] === $rev1['data']['reveal_id'] && count($p10http) === 1, 'P10-10. podwójne kliknięcie: to samo zlecenie, bez drugiego zapytania (idempotencja)');
UbrT::eq(1, (int) $db->value("SELECT billable FROM {$db->table(Tables::API_REQUESTS)} WHERE organization_id = %d AND endpoint = %s ORDER BY id DESC LIMIT 1", $orgD, ApolloPhoneService::MATCH_ENDPOINT), 'P10-10. zapytanie odnotowane jako płatne w dzienniku API');
UbrT::ok(in_array('pending', array_column($rv->preflight($ctxD, $ct['apollo'])['reasons'], 'code'), true), 'P10-10. w trakcie: kolejne odblokowanie zablokowane („pobieranie trwa”)');

$revRow = app(PhoneRevealRepository::class)->find($rev1['data']['reveal_id']);
UbrT::ok($revRow['request_id'] === '123456789' && (int) $revRow['user_id'] === $uD && (int) $revRow['organization_id'] === $orgD, 'P10-10. zapisane: request_id, użytkownik, organizacja');
$wpdb->update($db->table(Tables::PHONE_REVEALS), ['next_poll_at' => gmdate('Y-m-d H:i:s', time() - 5)], ['id' => $revRow['id']]);
delete_transient('ubr_rl_apollo');
app(ApolloPhoneRunner::class)->tick(false);
$revRow = app(PhoneRevealRepository::class)->find($revRow['id']);
UbrT::ok($revRow['status'] === 'pending' && (int) $revRow['attempts'] === 1 && strtotime($revRow['next_poll_at'] . ' UTC') > time(), 'P10-11. wynik jeszcze niegotowy (404 result_pending) → kolejne odpytanie później');

$p10apollo['result'][] = [200, ['people' => [
	['id' => 'p10ceo0001', 'email' => 'anna.prywatna@example.test', 'phone_numbers' => [
		['raw_number' => '+48 603 000 001', 'sanitized_number' => '+48603000001', 'type_cd' => 'mobile', 'confidence_cd' => 'high'],
		['raw_number' => '+48 22 111 00 99', 'sanitized_number' => '+48221110099', 'type_cd' => 'work_hq'],
	]],
	['id' => 'innaOsoba01', 'phone_numbers' => [['sanitized_number' => '+48604000009', 'type_cd' => 'mobile']]],
], 'credits_consumed' => 9]];
$wpdb->update($db->table(Tables::PHONE_REVEALS), ['next_poll_at' => gmdate('Y-m-d H:i:s', time() - 5)], ['id' => $revRow['id']]);
delete_transient('ubr_rl_apollo');
app(ApolloPhoneRunner::class)->tick(false);
$revRow = app(PhoneRevealRepository::class)->find($revRow['id']);
$cPhones = app(PhoneRevealRepository::class)->phonesFor($orgD, [$ct['apollo']])[$ct['apollo']] ?? [];
UbrT::ok($revRow['status'] === 'completed' && (float) $revRow['credits_consumed'] === 9.0 && (int) $revRow['phones_found'] === 1, 'P10-11. wynik odebrany: status „completed”, zapisane kredyty z odpowiedzi Apollo');
UbrT::ok(count($cPhones) === 1 && $cPhones[0]['e164'] === '+48603000001' && $cPhones[0]['phone_type'] === 'MOBILE', 'P10-11. numer osoby zapisany (komórkowy); numer innej osoby z odpowiedzi pominięty');
UbrT::ok((bool) $db->value("SELECT 1 FROM {$db->table(Tables::COMPANY_PHONES)} WHERE company_id = %d AND org_key = %d AND e164 = '+48221110099' AND `manual` = 1", $P['apollo'], $orgD), 'P10-11. numer centrali z Apollo → numer firmy organizacji (nie numer osoby)');
UbrT::ok(empty($ctr->find($ct['apollo'])['public_business_email']) && ! $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACTS)} WHERE public_business_email = 'anna.prywatna@example.test'"), 'P10-11. e-mail z odpowiedzi NIE jest zapisywany');
$stateD = $rv->phoneState($ctxD, $ctr->find($ct['apollo']), $cPhones, $revRow);
UbrT::ok($stateD['status'] === 'AVAILABLE' && $stateD['numbers'][0]['e164'] === '+48603000001' && $stateD['reveal_possible'] === false, 'P10-11. stan AVAILABLE tylko z prawdziwym numerem');
UbrT::ok(in_array('already_revealed', array_column($rv->preflight($ctxD, $ct['apollo'])['reasons'], 'code'), true) && $rv->preflight($ctxD, $ct['apollo'], true)['can_unlock'] === true, 'P10-11. ponowne pobranie tylko świadomie (tryb „refresh”)');
$eList = ubrt_rest($uE, 'GET', '/discovery/searches/' . $sE . '/results', ['per_page' => 50]);
UbrT::ok($eList['status'] === 200 && ! str_contains(wp_json_encode($eList['data']), '603000001') && ! str_contains(wp_json_encode($eList['data']), '221110099'), 'P10-11. numery odblokowane przez organizację D niewidoczne dla organizacji E');
UbrT::eq(404, ubrt_rest($uE, 'GET', '/contacts/' . $ct['direct'] . '/phone-reveal')['status'], 'P10-11. organizacja E: osoba z firmy niewidocznej → 404');

// Brak numeru u źródła, błąd zlecenia, przerwany proces, limit dzienny, LOCKED nigdy bez potwierdzenia.
$ct['apollo2'] = $mkContact($P['apollo'], 'Bogdan Drugi', 'Właściciel', 'owner', null, ['external_provider' => 'apollo', 'external_id' => 'p10own0002', 'rank_position' => 2]);
$tok = $rv->preflight($ctxD, $ct['apollo2'])['confirm_token'];
$p10apollo['match'] = ['request_id' => '223456789'];
delete_transient('ubr_rl_apollo');
$r2 = $rv->reveal($ctxD, $ct['apollo2'], $tok, true);
$rv->applyResult(app(PhoneRevealRepository::class)->find($r2['reveal_id']), ['people' => [['id' => 'p10own0002', 'phone_numbers' => []]], 'credits_consumed' => 1]);
$st2 = ApolloPhoneService::state([], [], app(PhoneRevealRepository::class)->latest($orgD, $ct['apollo2']), true);
UbrT::ok($st2['status'] === 'UNAVAILABLE' && $st2['numbers'] === [], 'P10-12. Apollo nie zwróciło numeru → UNAVAILABLE');
$ct['apollo3'] = $mkContact($P['apollo'], 'Celina Trzecia', 'Dyrektor Marketingu', 'marketing_director', null, ['external_provider' => 'apollo', 'external_id' => 'p10mkt0003', 'rank_position' => 3]);
$p10apollo['match'] = ['__code' => 403];
$before = count($p10http);
delete_transient('ubr_rl_apollo');
$r3 = $rv->reveal($ctxD, $ct['apollo3'], $rv->preflight($ctxD, $ct['apollo3'])['confirm_token'], true);
$st3 = ApolloPhoneService::state([], [], app(PhoneRevealRepository::class)->latest($orgD, $ct['apollo3']), true);
UbrT::ok($r3['status'] === 'error' && $st3['status'] === 'ERROR' && count($p10http) === $before + 1, 'P10-12. błąd Apollo (403) → ERROR, bez automatycznego ponowienia');
$wpdb->insert($db->table(Tables::PHONE_REVEALS), ['organization_id' => $orgD, 'contact_id' => $ct['apollo3'], 'company_id' => $P['apollo'], 'user_id' => $uD, 'provider' => 'apollo', 'idempotency_key' => 'p10-stale-' . wp_generate_password(6, false), 'status' => 'reserved', 'requested_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'updated_at' => Db::now()]);
$staleId = (int) $wpdb->insert_id;
app(ApolloPhoneRunner::class)->tick(false);
UbrT::eq('error', app(PhoneRevealRepository::class)->find($staleId)['status'], 'P10-12. zlecenie przerwane między rezerwacją a odpowiedzią → błąd (bez ponowienia płatnego zapytania)');
for ($i = 0; $i < ApolloPhoneService::dailyLimit(); $i++) {
	$wpdb->insert($db->table(Tables::PHONE_REVEALS), ['organization_id' => $orgD, 'contact_id' => $ct['apollo3'], 'company_id' => $P['apollo'], 'user_id' => $uD, 'provider' => 'apollo', 'idempotency_key' => 'p10-limit-' . $i . '-' . wp_generate_password(6, false), 'status' => 'error', 'requested_at' => Db::now(), 'updated_at' => Db::now()]);
}
UbrT::ok(in_array('limit', array_column($rv->preflight($ctxD, $ct['noApollo'])['reasons'], 'code'), true), 'P10-12. dzienny limit odblokowań organizacji');
$allStates = [];
foreach ([null, ['status' => 'pending'], ['status' => 'reserved'], ['status' => 'error'], ['status' => 'unavailable'], ['status' => 'completed']] as $latest) {
	$allStates[] = ApolloPhoneService::state([], [], $latest, true)['status'];
}
UbrT::ok(! in_array('LOCKED', $allStates, true) && ! in_array('AVAILABLE', $allStates, true), 'P10-12. bez numeru nigdy AVAILABLE; LOCKED nie jest przypisywany bez potwierdzenia źródła');
remove_filter('ubr/apollo/phone_enabled', '__return_true');
remove_filter('pre_http_request', $p10mock, PHP_INT_MAX);

/* ------------------------------------------------------------------ */
UbrT::group('P10 — Company Ownership: przejęcie, przekazanie, izolacja');

$cl1 = ubrt_rest($uD1, 'POST', '/companies/' . $P['claim'] . '/claim', ['search_id' => $sD]);
$claimPid = (int) ($cl1['data']['prospect_id'] ?? 0);
UbrT::ok($cl1['status'] === 200 && $cl1['data']['claimed'] === true && (int) $db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $claimPid) === $uD1, 'P10-13. wolna firma → przejęta do moich Prospects');
$cl2 = ubrt_rest($uD1, 'POST', '/companies/' . $P['claim'] . '/claim');
UbrT::ok($cl2['status'] === 200 && $cl2['data']['claimed'] === false && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d", $orgD, $P['claim']) === 1, 'P10-13. moja firma: bez duplikatu');
$cl3 = ubrt_rest($uD2, 'POST', '/companies/' . $P['claim'] . '/claim');
UbrT::ok($cl3['status'] === 409 && ($cl3['data']['code'] ?? '') === 'ubr_assigned_other', 'P10-13. cudza firma: 409, bez przejęcia');
UbrT::eq(409, ubrt_rest($uD, 'POST', '/companies/' . $P['claim'] . '/claim')['status'], 'P10-13. nawet właściciel organizacji nie przejmie przypadkiem („Przejmij” ≠ przekazanie)');
UbrT::eq(409, ubrt_rest($uD2, 'POST', '/prospects/' . $claimPid, ['owner_user_id' => $uD2])['status'], 'P10-13. członek nie przepisze cudzej firmy przez edycję prospekta');
UbrT::eq(409, ubrt_rest($uD2, 'POST', '/prospects/' . $claimPid . '/transfer', ['user_id' => $uD2])['status'], 'P10-13. przekazanie bez uprawnienia → 409');
$tr = ubrt_rest($uDadmin, 'POST', '/prospects/' . $claimPid . '/transfer', ['user_id' => $uD2]);
UbrT::ok($tr['status'] === 200 && $tr['data']['prospect']['owner_user_id'] === $uD2, 'P10-13. przekazanie przez administratora (uprawnienie prospects.transfer)');
UbrT::eq(422, ubrt_rest($uDadmin, 'POST', '/prospects/' . $claimPid . '/transfer', ['user_id' => $uE])['status'], 'P10-13. przekazanie osobie spoza organizacji → odrzucone');
UbrT::eq(404, ubrt_rest($uE, 'POST', '/companies/' . $P['claim'] . '/claim')['status'], 'P10-13. organizacja E nie przejmie firmy, której nie widzi');
UbrT::ok((bool) $db->value("SELECT 1 FROM {$db->table(Tables::PROSPECT_EVENTS)} WHERE prospect_id = %d AND type = 'owner'", $claimPid), 'P10-13. zmiana opiekuna w historii zdarzeń');

// Zarchiwizowany prospekt: brak aktywnego opiekuna → przejęcie przywraca i przypisuje przejmującego.
ubrt_rest($uDadmin, 'POST', '/prospects/' . $claimPid . '/archive');
$cl4 = ubrt_rest($uD1, 'POST', '/companies/' . $P['claim'] . '/claim');
UbrT::ok($cl4['status'] === 200 && $cl4['data']['restored'] === true && (int) $db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $claimPid) === $uD1, 'P10-13. zarchiwizowana firma: przejęcie przywraca ją i przypisuje przejmującego');

// Wyścig: inne połączenie z bazą przypisuje firmę między odczytem a zapisem — warunkowy UPDATE wpuszcza tylko jednego.
$p10race = $p10mk('race', 'Wyscig', 'https://p10-race.ubrtest.pl/');
$racePid = app(\App\Ubr\Infrastructure\Repositories\ProspectRepository::class)->add($orgD, $p10race, $uD, null)['id'];
$conn2 = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$conn2->query(sprintf("UPDATE %s SET owner_user_id = %d WHERE id = %d AND owner_user_id IS NULL", $db->table(Tables::PROSPECTS), $uD2, $racePid));
$raceAffected = $conn2->affected_rows;
$conn2->close();
$raceClaim = ubrt_rest($uD1, 'POST', '/companies/' . $p10race . '/claim');
UbrT::ok($raceAffected === 1 && $raceClaim['status'] === 409 && (int) $db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $racePid) === $uD2, 'P10-13. równoległe przejęcie (2. połączenie z bazą): wygrywa jedno, drugie dostaje 409');
$ownD = app(\App\Ubr\Services\OwnershipService::class);
UbrT::ok($ownD->assignIfFree(new \App\Ubr\Domain\Tenancy\TenantContext($uD1, $orgD, Role::Member, ['id' => $orgD]), $racePid) === false, 'P10-13. warunkowe przypisanie nie nadpisuje istniejącego opiekuna');

UbrT::eq(409, ubrt_rest($uD1, 'POST', '/companies/' . $P['dnc'] . '/claim')['status'], 'P10-13. firma z zakazem kontaktu: przejęcie do kontaktu odrzucone');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — wynik rozmowy i historia kontaktu (istniejące aktywności)');

$actCount = fn (int $pid) => (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECT_ACTIVITIES)} WHERE prospect_id = %d", $pid);
UbrT::eq(422, ubrt_rest($uD1, 'POST', '/companies/' . $P['company'] . '/call-outcome', ['outcome' => 'zle'])['status'], 'P10-14. nieznany wynik → 422');
UbrT::eq(null, $db->value("SELECT id FROM {$db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d", $orgD, $P['company']), 'P10-14. odrzucony wynik nie tworzy prospekta');
$oc = ubrt_rest($uD1, 'POST', '/companies/' . $P['company'] . '/call-outcome', ['outcome' => 'no_answer', 'note' => 'Nikt nie odebrał (test)']);
$ocPid = (int) ($oc['data']['prospect']['id'] ?? 0);
UbrT::ok($oc['status'] === 201 && $oc['data']['claimed'] === true && $oc['data']['prospect']['owner_user_id'] === $uD1, 'P10-14. wynik dla wolnej firmy: firma przypisana do dzwoniącego');
UbrT::ok($oc['data']['activities'][0]['type'] === 'call' && $oc['data']['activities'][0]['outcome'] === 'no_answer' && $oc['data']['activities'][0]['outcome_label'] === 'Nie odebrał' && $actCount($ocPid) === 1, 'P10-14. aktywność „Telefon” z wynikiem w istniejącej historii (jeden wpis)');
UbrT::ok($oc['data']['prospect']['status'] === 'contacted' && $oc['data']['prospect']['last_contact_outcome'] === 'no_answer' && ! empty($oc['data']['prospect']['last_contact_at']), 'P10-14. etap Nowy → Skontaktowano; ostatni kontakt zapisany');
$oc2 = ubrt_rest($uD1, 'POST', '/companies/' . $P['company'] . '/call-outcome', ['outcome' => 'meeting_booked']);
UbrT::ok($oc2['data']['prospect']['status'] === 'in_discussion' && $actCount($ocPid) === 2, 'P10-14. „Umówiono spotkanie” → W rozmowach');
UbrT::eq(409, ubrt_rest($uD2, 'POST', '/companies/' . $P['company'] . '/call-outcome', ['outcome' => 'interested'])['status'], 'P10-14. wynik dla firmy innego opiekuna → 409');
UbrT::eq(2, $actCount($ocPid), 'P10-14. odrzucony wynik nie dopisuje aktywności');
UbrT::eq(409, ubrt_rest($uD1, 'POST', '/companies/' . $P['dnc'] . '/call-outcome', ['outcome' => 'no_answer'])['status'], 'P10-14. zakaz kontaktu: zapis rozmowy z listy zablokowany');
UbrT::eq(422, ubrt_rest($uD1, 'POST', '/prospects/' . $ocPid . '/activities', ['type' => 'email', 'outcome' => 'no_answer', 'description' => 'x'])['status'], 'P10-14. wynik rozmowy tylko dla aktywności „Telefon”');
UbrT::eq(409, ubrt_rest($uD1, 'POST', '/companies/' . $P['blocked'] . '/call-outcome', ['outcome' => 'no_answer'])['status'], 'P10-14. twarda blokada (BLOCKED): zapis rozmowy z listy zablokowany');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — listy: dane Cold Calling, filtry na całym zbiorze, sortowanie, akcje');

$listD = fn (int $u, array $q = []) => ubrt_rest($u, 'GET', '/discovery/searches/' . $sD . '/results', $q + ['per_page' => 100]);
$byName = function (array $res) {
	$out = [];
	foreach ($res['data']['items'] ?? [] as $i) {
		$out[str_replace('Ubrtest P10 ', '', $i['name'])] = $i;
	}

	return $out;
};
$l = $byName($listD($uD2));
UbrT::ok(isset($l['Tylko Centrala']['call']) && $l['Tylko Centrala']['call']['company_phone']['number'] === '600 700 800' && $l['Tylko Centrala']['call']['company_phone']['tel'] === 'tel:+48600700800', 'P10-15. wiersz: numer firmy (ręczny główny organizacji), link tel:');
UbrT::ok($l['Decydent Z Numerem']['call']['readiness']['key'] === 'READY_DIRECT' && $l['Decydent Z Numerem']['call']['decision_maker']['name'] === 'Adam Bezposredni' && $l['Decydent Z Numerem']['call']['decision_maker']['phone']['numbers'][0]['e164'] === '+48601000001', 'P10-15. wiersz: decydent z bezpośrednim numerem');
UbrT::ok($l['Tylko Centrala']['call']['ownership']['state'] === 'ASSIGNED_OTHER' && $l['Tylko Centrala']['call']['ownership']['owner_name'] !== null && $l['Tylko Centrala']['call']['actions']['can_call'] === false && $l['Tylko Centrala']['call']['actions']['can_claim'] === false && $l['Tylko Centrala']['call']['actions']['can_record_outcome'] === false, 'P10-15. firma innego opiekuna: widoczny opiekun, akcje kontaktowe niedostępne');
UbrT::ok($l['Tylko Centrala']['call']['ownership']['last_contact_outcome_label'] === 'Umówiono spotkanie' && $l['Tylko Centrala']['call']['ownership']['in_progress'] === true, 'P10-15. ostatni kontakt i status „w toku” z historii aktywności');
UbrT::ok($l['Zakaz Kontaktu']['call']['ownership']['dnc'] === true && $l['Zakaz Kontaktu']['call']['actions']['can_call'] === false && in_array('DO_NOT_CONTACT', $l['Zakaz Kontaktu']['call']['ownership']['flags'], true), 'P10-15. zakaz kontaktu: flaga DNC (statusy łączą się), brak akcji kontaktowych');
UbrT::ok($l['Bez Numeru']['call']['actions']['can_claim'] === true && $l['Bez Numeru']['call']['actions']['can_call'] === false && $l['Tylko Centrala']['call']['size']['label'] === '30 os.' && $l['Bez Numeru']['call']['size']['label'] === 'Brak danych', 'P10-15. wolna firma: „Przejmij”; wielkość lub „Brak danych”');
UbrT::ok($l['Apollo Osoba']['call']['decision_maker']['phone'] !== null && $l['Apollo Osoba']['call']['decision_maker']['phone']['status'] === 'AVAILABLE', 'P10-15. odblokowany numer decydenta widoczny na liście organizacji D');

$names = fn (array $res) => array_map(fn ($i) => str_replace('Ubrtest P10 ', '', $i['name']), $res['data']['items'] ?? []);
$fDirect = $names($listD($uD2, ['phone' => 'direct']));
UbrT::ok(in_array('Decydent Z Numerem', $fDirect, true) && ! in_array('Numer Osoby Rowny Firmie', $fDirect, true) && ! in_array('Dyrektor Produkcji', $fDirect, true), 'P10-16. filtr „numer do decydenta”: bez numeru firmy u osoby i bez ról produkcyjnych');
$fNone = $names($listD($uD2, ['phone' => 'none']));
UbrT::ok(in_array('Bez Numeru', $fNone, true) && ! in_array('Tylko Centrala', $fNone, true), 'P10-16. filtr „bez numeru”');
$fReady = $names($listD($uD2, ['readiness' => 'BLOCKED']));
UbrT::ok(in_array('Blokada LI', $fReady, true) && in_array('Zakaz Kontaktu', $fReady, true) && ! in_array('Bez Numeru', $fReady, true), 'P10-16. filtr gotowości (BLOCKED)');
$fFree = $names($listD($uD2, ['free_with_phone' => 1]));
UbrT::ok(in_array('Decydent Z Numerem', $fFree, true) && ! in_array('Tylko Centrala', $fFree, true) && ! in_array('Zakaz Kontaktu', $fFree, true) && ! in_array('Blokada LI', $fFree, true) && ! in_array('Bez Numeru', $fFree, true), 'P10-16. „Wolne firmy z telefonem”: bez opiekuna, z numerem, bez blokad i zakazu kontaktu');
$fMine = $names($listD($uD1, ['call_owner' => 'mine']));
UbrT::ok(in_array('Tylko Centrala', $fMine, true) && in_array('Do Przejecia', $fMine, true) && ! in_array('Bez Numeru', $fMine, true), 'P10-16. filtr „moje”');
UbrT::ok(in_array('Tylko Centrala', $names($listD($uD2, ['call_owner' => 'others'])), true) && in_array('Zakaz Kontaktu', $names($listD($uD2, ['call_owner' => 'dnc'])), true), 'P10-16. filtry „innych pracowników” i „nie kontaktować”');
UbrT::eq(['Tylko Centrala'], $names($listD($uD2, ['size' => '11-50'])), 'P10-16. filtr wielkości (11–50)');
UbrT::ok(in_array('Tylko Centrala', $names($listD($uD2, ['contacted' => '7'])), true) && ! in_array('Tylko Centrala', $names($listD($uD2, ['contacted' => 'never'])), true), 'P10-16. filtr ostatniego kontaktu');
UbrT::eq(['Tylko Centrala'], $names($listD($uD2, ['pipeline_stage' => 'in_discussion'])), 'P10-16. filtr etapu pipeline');
$smallPage = $listD($uD2, ['phone' => 'any', 'per_page' => 5]);
$fullAny = $listD($uD2, ['phone' => 'any']);
UbrT::ok(count($smallPage['data']['items']) === 5 && $smallPage['data']['total'] === count($fullAny['data']['items']) && $smallPage['data']['total'] > 5, 'P10-16. filtr działa na całym zbiorze (total z SQL, nie z bieżącej strony)');
$sorted = $names($listD($uD2, ['sort' => 'readiness']));
UbrT::ok(($sorted[0] ?? '') === 'Decydent Z Numerem' || ($sorted[0] ?? '') === 'Apollo Osoba', 'P10-16. sortowanie po gotowości: najpierw READY_DIRECT');
UbrT::ok(end($sorted) === 'Blokada LI' || end($sorted) === 'Zakaz Kontaktu', 'P10-16. BLOCKED na końcu');
$bySize = $names($listD($uD2, ['sort' => 'size']));
UbrT::eq('Decydent Z Numerem', $bySize[0] ?? null, 'P10-16. sortowanie po wielkości (51–200 przed 30)');
$az = $names($listD($uD2, ['sort' => 'name']));
$azSorted = $az;
usort($azSorted, 'strcasecmp');
UbrT::ok(count($az) > 5 && $az === $azSorted, 'P10-16. sortowanie A–Z');
$lastC = $names($listD($uD2, ['sort' => 'last_contact']));
UbrT::eq('Tylko Centrala', $lastC[0] ?? null, 'P10-16. sortowanie po ostatnim kontakcie');
$pros = ubrt_rest($uD1, 'GET', '/prospects', ['phone' => 'any', 'call_owner' => 'mine', 'sort' => 'readiness']);
UbrT::ok($pros['status'] === 200 && isset($pros['data']['items'][0]['call']) && in_array('Ubrtest P10 Tylko Centrala', array_column($pros['data']['items'], 'name'), true), 'P10-16. Prospects: te same filtry i dane Cold Calling');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — Action Center: „Gotowe do telefonu”, DNC i firmy innych opiekunów');

UbrT::eq(null, APE::classify(['has_li' => true, 'has_blocker' => false, 'do_not_contact' => true, 'recommendation' => 'contact_now', 'analysis_status' => 'completed', 'domain' => 'x.test', 'prospect_status' => 'new', 'prospect_archived' => false, 'has_open_followup' => false]), 'P10-17. zakaz kontaktu → brak działania w priorytetach (PHP)');
$liSave = fn (int $cid, string $rec = 'contact_now') => app(LeadIntelligenceRepository::class)->save($orgD, $cid, ['lead_score' => 80, 'score_status' => 'scored', 'confidence' => 'high', 'coverage' => 80, 'redesign' => 'strong', 'recommendation' => $rec, 'rank_score' => 70.0, 'has_blocker' => 0, 'analysis_id' => null, 'input_hash' => str_repeat('c', 64), 'input_snapshot' => ['test' => true], 'result' => ['recommendation' => ['key' => $rec, 'reason' => 'test']], 'algorithm_version' => LeadIntelligenceService::VERSION, 'calculated_at' => Db::now()]);
foreach (['company', 'direct', 'dnc', 'find'] as $k) {
	$liSave($P[$k]);
	$p10companies->update($P[$k], ['analysis_status' => 'completed', 'analyzed_at' => Db::now()]);
}
\App\Ubr\Services\ActionCenterService::bumpOrganization($orgD);
$q = fn (int $u) => array_map(fn ($i) => str_replace('Ubrtest P10 ', '', $i['company']['name']), ubrt_rest($u, 'GET', '/action-center/queues/ready_to_call', ['per_page' => 50])['data']['items'] ?? []);
$q2 = $q($uD2);
$q1 = $q($uD1);
UbrT::ok(in_array('Decydent Z Numerem', $q2, true) && ! in_array('Zakaz Kontaktu', $q2, true) && ! in_array('Bez Numeru', $q2, true), 'P10-17. kolejka „Gotowe do telefonu”: z numerem, bez zakazu kontaktu');
UbrT::ok(! in_array('Tylko Centrala', $q2, true) && in_array('Tylko Centrala', $q1, true), 'P10-17. firma innego opiekuna poza moją kolejką; u opiekuna widoczna');
$recD2 = ubrt_rest($uD2, 'GET', '/action-center/priorities', ['per_page' => 50]);
$recNames = array_map(fn ($i) => str_replace('Ubrtest P10 ', '', (string) ($i['company']['name'] ?? '')), $recD2['data']['items'] ?? []);
UbrT::ok($recD2['status'] === 200 && ! in_array('Zakaz Kontaktu', $recNames, true) && ! in_array('Tylko Centrala', $recNames, true), 'P10-17. „Today’s Priorities”: bez DNC i bez firm innych opiekunów');
$dash = ubrt_rest($uD2, 'GET', '/action-center');
$rtc = array_values(array_filter($dash['data']['queues'] ?? [], fn ($x) => $x['key'] === 'ready_to_call'))[0] ?? null;
UbrT::ok($dash['status'] === 200 && $rtc && $rtc['count'] === count($q2) && $rtc['label_pl'] === 'Gotowe do telefonu', 'P10-17. kolejka „Gotowe do telefonu” w Action Center z licznikiem zgodnym z listą');
$kpiKeys = array_column($dash['data']['kpis'] ?? [], 'key');
UbrT::ok(! in_array('ready_to_call', $kpiKeys, true) && in_array('ready_to_contact', $kpiKeys, true), 'P10-17. istniejące KPI bez zmian (nowa kolejka nie jest nowym KPI)');

/* ------------------------------------------------------------------ */
UbrT::group('P10 — bezpieczeństwo');

UbrT::ok(! str_contains(wp_json_encode($listD($uD2)['data']), $ubrtFakeKey) && ! str_contains(wp_json_encode($pre), $ubrtFakeKey), 'P10-18. klucz API nie trafia do odpowiedzi');
UbrT::eq(403, ubrt_rest($uX, 'POST', '/companies/' . $P['find'] . '/claim')['status'], 'P10-18. osoba spoza organizacji: przejęcie 403');
UbrT::eq(403, ubrt_rest($uX, 'GET', '/contacts/' . $ct['apollo'] . '/phone-reveal')['status'], 'P10-18. osoba spoza organizacji: odblokowanie 403');
UbrT::eq(404, ubrt_rest($uE, 'POST', '/prospects/' . $claimPid . '/do-not-contact', ['value' => true])['status'], 'P10-18. zakaz kontaktu cudzego prospekta (inna organizacja) → 404');
UbrT::eq(404, ubrt_rest($uE, 'POST', '/prospects/' . $claimPid . '/transfer', ['user_id' => $uE])['status'], 'P10-18. przekazanie prospekta innej organizacji → 404');
$xss = ubrt_rest($uD1, 'POST', '/companies/' . $P['find'] . '/call-outcome', ['outcome' => 'call_back', 'note' => '<img src=x onerror=alert(1)>Oddzwonić']);
UbrT::ok($xss['status'] === 201 && ! str_contains((string) $xss['data']['activities'][0]['description'], '<img'), 'P10-18. notatka do rozmowy bez HTML (XSS)');
$inj = $listD($uD2, ['readiness' => "READY_DIRECT' OR 1=1 -- ", 'size' => '1-10) OR (1=1', 'call_owner' => 'x', 'industry_q' => "%' OR '1'='1"]);
UbrT::ok($inj['status'] === 200 && count($inj['data']['items']) === 0, 'P10-18. filtry odporne na SQL injection (whitelist, LIKE z escapowaniem)');
