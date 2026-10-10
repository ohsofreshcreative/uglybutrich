<?php

/**
 * Testy regresji audytu QA (Prompt 7). Dołączane z tests/run.php po testach Action Center (wspólne: $uA, $uB, $orgA,
 * $orgC, $uC, $c, $mkVisible, $db, $wpdb). Każda grupa odpowiada błędowi wykrytemu w audycie (docs/QA-REPORT.md).
 * Bez zapytań zewnętrznych: HTTP mockowane przez pre_http_request, DNS przez filtr ubr/url_guard/resolve.
 */

use App\Ubr\Domain\ActionCenter\ActionPriorityEngine as APE;
use App\Ubr\Domain\Companies\CompanyNormalizer;
use App\Ubr\Domain\Contacts\PeopleExtractor;
use App\Ubr\Domain\Contacts\PersonName;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Migrator;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Http\PageFetcher;
use App\Ubr\Infrastructure\Http\UrlGuard;
use App\Ubr\Infrastructure\Http\WebsiteChecker;
use App\Ubr\Infrastructure\Http\WebsiteInspector;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Services\ActionCenterService;
use App\Ubr\Services\Bootstrapper;
use App\Ubr\Services\LeadIntelligenceService;
use App\Ubr\Support\Lock;

if (! defined('ABSPATH')) {
	exit(1);
}

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — SSRF: adresy IPv4 osadzone w IPv6, przypięcie DNS, przekierowania');

foreach (['::ffff:7f00:1' => '127.0.0.1 (zapis szesnastkowy)', '::ffff:a9fe:a9fe' => 'metadata 169.254.169.254', '::ffff:a00:1' => '10.0.0.1', '0:0:0:0:0:ffff:7f00:0001' => 'pełny zapis', '::7f00:1' => 'IPv4-compatible', '64:ff9b::a00:1' => 'NAT64 → 10.0.0.1', '2002:7f00:1::' => '6to4 → 127.0.0.1', 'fec0::1' => 'site-local', '2001::1' => 'Teredo'] as $ip => $label) {
	UbrT::ok(! UrlGuard::isPublicIp($ip), "P7-1. {$ip} ({$label}) zablokowany");
}
UbrT::ok(UrlGuard::isPublicIp('::ffff:8.8.8.8') && UrlGuard::isPublicIp('2a00:1450:4001:82a::200e') && UrlGuard::isPublicIp('64:ff9b::808:808'), 'P7-1. publiczne adresy IPv6 (także z osadzonym publicznym IPv4) dozwolone');
UbrT::ok(! (new UrlGuard())->check('http://[::ffff:7f00:1]/')['ok'] && ! (new UrlGuard())->check('http://[::ffff:a9fe:a9fe]/latest/meta-data/')['ok'], 'P7-1. URL z literałem IPv6 → zablokowany');

$p7check = (new UrlGuard())->check('https://pin.ubrtest.pl/');
UbrT::ok($p7check['ok'] && ($p7check['ips'] ?? []) === ['93.184.216.34'], 'P7-2. UrlGuard zwraca sprawdzone adresy IP (do przypięcia połączenia)');
$p7unpin = PageFetcher::pin('https://pin.ubrtest.pl/x', ['93.184.216.34']);
$p7hooked = has_action('http_api_curl') !== false;
$p7unpin();
UbrT::ok($p7hooked && has_action('http_api_curl') === false, 'P7-2. przypięcie IP (CURLOPT_RESOLVE) działa tylko na czas jednego żądania');

$p7requested = [];
$p7mock = function ($pre, $args, $url) use (&$p7requested) {
	$p7requested[] = $url;

	if (str_contains($url, 'redir-meta.ubrtest.pl')) {
		return ['headers' => ['location' => 'http://[::ffff:a9fe:a9fe]/latest/meta-data/'], 'body' => '', 'response' => ['code' => 302, 'message' => 'Found'], 'cookies' => [], 'filename' => null];
	}

	if (str_contains($url, 'redir-ok.ubrtest.pl')) {
		return ['headers' => ['location' => 'https://target-ok.ubrtest.pl/'], 'body' => '', 'response' => ['code' => 301, 'message' => 'Moved'], 'cookies' => [], 'filename' => null];
	}

	if (str_contains($url, 'target-ok.ubrtest.pl')) {
		return ['headers' => [], 'body' => '<html><head><title>Cel OK</title></head><body>Ubrtest Cel</body></html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
	}

	return $pre;
};
add_filter('pre_http_request', $p7mock, PHP_INT_MAX, 3); // po mockach ubrtest (priorytet 10) — nasz scenariusz wygrywa
$p7insp = app(WebsiteInspector::class)->inspect('https://redir-meta.ubrtest.pl');
$p7chk = app(WebsiteChecker::class)->check('https://redir-meta.ubrtest.pl', 'Ubrtest');
$p7fetch = app(PageFetcher::class)->fetch('https://redir-meta.ubrtest.pl/');
$p7meta = array_filter($p7requested, fn ($u) => str_contains($u, 'a9fe'));
UbrT::ok($p7meta === [], 'P7-3. przekierowanie na metadata (IPv6) nigdy nie jest pobierane: weryfikacja, checker i analiza');
UbrT::ok($p7chk['status'] === 'unreachable' && str_contains((string) $p7chk['error'], 'SSRF'), 'P7-3. WebsiteChecker: przekierowanie zablokowane z przyczyną');
UbrT::ok($p7fetch['blocked'] !== null && $p7fetch['body'] === '', 'P7-3. PageFetcher: przekierowanie zablokowane, brak treści');
UbrT::ok(empty($p7insp['title']) && ($p7insp['http_code'] ?? null) === null, 'P7-3. WebsiteInspector (weryfikacja firmy): brak danych z zablokowanego adresu');
$p7ok = app(WebsiteChecker::class)->check('https://redir-ok.ubrtest.pl', 'Ubrtest Cel');
UbrT::ok($p7ok['status'] === 'redirected' && $p7ok['title'] === 'Cel OK' && str_contains((string) $p7ok['final_url'], 'target-ok'), 'P7-3. poprawne przekierowanie publiczne nadal działa (adres końcowy, tytuł)');
remove_filter('pre_http_request', $p7mock, PHP_INT_MAX);

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — XSS: linki zewnętrzne tylko http/https');

UbrT::eq(null, UrlGuard::httpUrl('javascript:alert(1)//linkedin.com/in/x'), 'P7-4. javascript: odrzucony');
UbrT::eq(null, UrlGuard::httpUrl('data:text/html,<b>x</b>'), 'P7-4. data: odrzucony');
UbrT::eq(null, UrlGuard::httpUrl(" javascript:alert(1)"), 'P7-4. javascript: z białym znakiem odrzucony');
UbrT::eq('https://www.linkedin.com/in/jan-kowalski', UrlGuard::httpUrl('https://www.linkedin.com/in/jan-kowalski'), 'P7-4. poprawny adres https przepuszczony');

$p7html = '<html><head><script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'Person', 'name' => 'Jan Testowski', 'jobTitle' => 'Prezes Zarządu', 'sameAs' => "javascript:fetch('/x')//linkedin.com/in/x"]) . '</script><script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'Person', 'name' => 'Anna Przykładowa', 'jobTitle' => 'Dyrektor Marketingu', 'sameAs' => 'https://pl.linkedin.com/in/anna-przyk/']) . '</script></head><body></body></html>';
$p7people = app(PeopleExtractor::class)->extract($p7html, 'https://firma.ubrtest.pl/o-nas', 'O nas', 'about', 'Ubrtest Firma', 'firma.ubrtest.pl');
$p7profiles = [];

foreach ($p7people['candidates'] as $p) {
	if (is_array($p) && isset($p['name'])) {
		$p7profiles[(string) (is_object($p['name']) ? $p['name']->full() : $p['name'])] = $p['profile'] ?? null;
	}
}
UbrT::ok(array_key_exists('Jan Testowski', $p7profiles) && $p7profiles['Jan Testowski'] === null, 'P7-4. JSON-LD sameAs z javascript: nie zostaje profilem osoby');
UbrT::ok(($p7profiles['Anna Przykładowa'] ?? null) === 'https://pl.linkedin.com/in/anna-przyk', 'P7-4. poprawny profil z JSON-LD zachowany');

$p7views = '';
foreach (['partials/contact-card', 'partials/company-drawer', 'partials/sales-tab', 'partials/discover-domain-cell', 'pages/prospects'] as $v) {
	$p7views .= (string) file_get_contents(get_theme_file_path('resources/views/ubr/' . $v . '.blade.php'));
}
preg_match_all('/:href="([^"]+)"/', $p7views, $p7hrefs);
$p7unsafe = array_filter($p7hrefs[1], fn ($h) => ! str_contains($h, 'safeHref(') && ! str_starts_with($h, "'mailto:") && ! str_starts_with($h, "'tel:") && ! str_contains($h, 'UBR.urls'));
UbrT::eq([], array_values($p7unsafe), 'P7-4. wszystkie zewnętrzne :href w kartach firmy / kontaktów / prospektów przez safeHref()');

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — Decydenci: ogólny adres firmy nie jest adresem osoby');

foreach (['biuro@firma.pl', 'sekretariat@firma.pl', 'info2@firma.pl', 'sales-pl@firma.pl', 'no-reply@firma.pl', 'dzial.handlowy@firma.pl', 'Kontakt@firma.pl'] as $e) {
	UbrT::ok(PeopleExtractor::isGenericEmail($e), "P7-5. {$e} rozpoznany jako adres ogólny / działu");
}
UbrT::ok(! PeopleExtractor::isGenericEmail('jan.kret@firma.pl') && ! PeopleExtractor::isGenericEmail('itkowalski@firma.pl'), 'P7-5. adresy osobowe nie są uznawane za ogólne');
$p7x = app(PeopleExtractor::class);
UbrT::ok(! $p7x->emailBelongsTo('sekretariat@firma.pl', PersonName::parse('Jan Kret')), 'P7-5. „sekretariat@” nie jest adresem Jana Kreta (nazwisko jako podciąg)');
UbrT::ok(! $p7x->emailBelongsTo('marketing@firma.pl', PersonName::parse('Anna Ma')), 'P7-5. „marketing@” nie jest adresem Anny Ma (krótkie nazwisko)');
UbrT::ok($p7x->emailBelongsTo('jan.kret@firma.pl', PersonName::parse('Jan Kret')) && $p7x->emailBelongsTo('a.kowalska@firma.pl', PersonName::parse('Anna Kowalska')), 'P7-5. adresy osobiste nadal przypisywane');

$p7co = $mkVisible('p7-contact', 'Ubrtest P7 Kontakt', 'https://p7-contact.ubrtest.pl/');
$p7man = ubrt_rest($uA, 'POST', '/companies/' . $p7co . '/contacts', ['full_name' => 'Jan Testowy', 'job_title' => 'Prezes Zarządu', 'email' => 'biuro@p7-contact.ubrtest.pl']);
UbrT::eq(422, $p7man['status'], 'P7-5. ręczne dodanie osoby z adresem biuro@ odrzucone (bez zapisu jako e-mail osoby)');
$p7man2 = ubrt_rest($uA, 'POST', '/companies/' . $p7co . '/contacts', ['full_name' => 'Jan Testowy', 'job_title' => 'Prezes Zarządu', 'email' => 'jan.testowy@p7-contact.ubrtest.pl']);
UbrT::ok(in_array($p7man2['status'], [200, 201], true), 'P7-5. ręczne dodanie z adresem osobowym działa');

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — Action Center: ponowna analiza nie cofa firmy do „Brak analizy”');

$p7s = ['has_li' => true, 'has_blocker' => false, 'recommendation' => 'contact_now', 'domain' => 'firma.test', 'prospect_status' => null, 'prospect_archived' => false, 'has_open_followup' => false];
UbrT::eq(APE::CONTACT_READY, APE::classify($p7s + ['analysis_status' => 'queued', 'analyzed_at' => '2026-10-01 10:00:00'])['type'] ?? null, 'P7-6. ponowna analiza w kolejce + zakończona wcześniej → nadal CONTACT_READY');
UbrT::eq(APE::CONTACT_READY, APE::classify($p7s + ['analysis_status' => 'failed', 'analyzed_at' => '2026-10-01 10:00:00'])['type'] ?? null, 'P7-6. nieudana ponowna analiza nie unieważnia poprzedniej zakończonej');
UbrT::eq(APE::ANALYSIS_MISSING, APE::classify($p7s + ['analysis_status' => 'queued', 'analyzed_at' => null])['type'] ?? null, 'P7-6. pierwsza analiza w kolejce (brak zakończonej) → ANALYSIS_MISSING');

$p7ready = function () use ($uC) {
	return array_map(fn ($i) => (int) $i['company']['id'], ubrt_rest($uC, 'GET', '/action-center/queues/ready_to_contact', ['per_page' => 50])['data']['items'] ?? []);
};
$p7companies = app(CompanyRepository::class);
$p7orig = $p7companies->find($c['ready']);
$p7a = $p7ready();
$p7companies->update($c['ready'], ['analysis_status' => 'queued', 'analyzed_at' => '2026-10-01 10:00:00']);
ActionCenterService::bumpOrganization($orgC);
$p7b = $p7ready();
$p7companies->update($c['ready'], ['analysis_status' => 'queued', 'analyzed_at' => null]);
ActionCenterService::bumpOrganization($orgC);
$p7c = $p7ready();
$p7companies->update($c['ready'], ['analysis_status' => $p7orig['analysis_status'], 'analyzed_at' => $p7orig['analyzed_at']]);
ActionCenterService::bumpOrganization($orgC);
UbrT::ok(in_array($c['ready'], $p7a, true) && $p7b === $p7a, 'P7-6. SQL: firma z zakończoną analizą zostaje w Ready to Contact podczas ponownej analizy');
UbrT::ok(! in_array($c['ready'], $p7c, true), 'P7-6. SQL: bez zakończonej analizy firma wypada z Ready to Contact (bez zmian reguły)');

$p7mine = ubrt_rest($uC, 'GET', '/action-center', ['owner' => 'unassigned'])['data']['kpis'] ?? [];
$p7all = ubrt_rest($uC, 'GET', '/action-center', ['owner' => 'all'])['data']['kpis'] ?? [];
$p7tp = fn ($k) => (int) (array_values(array_filter($k, fn ($x) => $x['key'] === 'total_prospects'))[0]['value'] ?? -1);
$p7unassigned = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND archived_at IS NULL AND owner_user_id IS NULL", $orgC);
UbrT::ok($p7tp($p7mine) === $p7unassigned && $p7tp($p7all) >= $p7tp($p7mine), 'P7-7. KPI Total Prospects respektuje przełącznik Unassigned (' . $p7tp($p7mine) . ' z ' . $p7tp($p7all) . ')');

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — Pipeline: archiwizacja, kwalifikacja, usunięcie członka');

$p7pc = $mkVisible('p7-pipe', 'Ubrtest P7 Pipeline', 'https://p7-pipe.ubrtest.pl/');
$p7pid = (int) ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $p7pc, 'search_id' => 999999999])['data']['prospect_id'];
UbrT::ok($p7pid > 0 && (int) $db->value("SELECT source_search_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $p7pid) === 0, 'P7-8. search_id spoza organizacji nie jest zapisywany przy prospekcie');
$liRepoP7 = app(LeadIntelligenceRepository::class);
app(LeadIntelligenceService::class)->evaluate($orgA, $p7pc, 'manual');
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::LEAD_INTELLIGENCE)} SET stale = 0 WHERE organization_id = %d AND company_id = %d", $orgA, $p7pc));
ubrt_rest($uA, 'POST', '/prospects/' . $p7pid, ['qualification' => 'not_a_fit']);
UbrT::eq(1, (int) $db->value("SELECT stale FROM {$db->table(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d AND company_id = %d", $orgA, $p7pc), 'P7-9. zmiana kwalifikacji („not a fit” = hard blocker) oznacza ocenę Lead Intelligence do przeliczenia');
$p7org = (int) $db->value("SELECT organization_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $p7pid);
$p7v = ActionCenterService::orgVersion($p7org);
UbrT::eq(200, ubrt_rest($uA, 'DELETE', '/prospects/' . $p7pid)['status'], 'P7-10. DELETE /prospects/{id} archiwizuje');
$p7v2 = ActionCenterService::orgVersion($p7org);
UbrT::ok($p7v2 > $p7v, "P7-10. archiwizacja przez DELETE odświeża liczniki Action Center (wersja cache {$p7v} → {$p7v2}, org {$p7org})");
UbrT::ok((int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECT_EVENTS)} WHERE prospect_id = %d AND type = 'archived'", $p7pid) === 1, 'P7-10. jedno zdarzenie archived (jedna ścieżka archiwizacji)');
UbrT::eq(404, ubrt_rest($uB, 'DELETE', '/prospects/' . $p7pid)['status'], 'P7-10. inna organizacja nie archiwizuje prospektu (IDOR)');

$p7user = $mk('p7member');
app(MemberRepository::class)->add($orgA, $p7user, \App\Ubr\Domain\Tenancy\Role::Member);
$p7pc2 = $mkVisible('p7-owner', 'Ubrtest P7 Owner', 'https://p7-owner.ubrtest.pl/');
$p7pid2 = (int) ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $p7pc2])['data']['prospect_id'];
ubrt_rest($uA, 'POST', '/prospects/' . $p7pid2, ['owner_user_id' => $p7user]);
ubrt_rest($uA, 'POST', '/prospects/' . $p7pid2 . '/followups', ['title' => 'P7 follow-up', 'due_at' => gmdate('c', time() + DAY_IN_SECONDS), 'assigned_user_id' => $p7user]);
UbrT::eq($p7user, (int) $db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $p7pid2), 'P7-11. (przygotowanie) prospekt przypisany do członka');
UbrT::eq(200, ubrt_rest($uA, 'DELETE', '/organization/members/' . $p7user)['status'], 'P7-11. usunięcie członka organizacji');
UbrT::ok($db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $p7pid2) === null && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECT_FOLLOWUPS)} WHERE prospect_id = %d AND assigned_user_id = %d", $p7pid2, $p7user) === 0, 'P7-11. po usunięciu członka jego prospekty i follow-upy wracają do „Unassigned”');

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — Kolejki: przerwane procesy, blokady, Lead Intelligence');

$p7an = app(AnalysisRepository::class);
$p7aid = $p7an->create(['company_id' => $p7pc, 'organization_id' => $orgA, 'user_id' => $uA, 'trigger_type' => 'manual', 'status' => 'running', 'url' => 'https://p7-pipe.ubrtest.pl/', 'algorithm_version' => 'test', 'attempts' => 3, 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 600), 'locked_until' => gmdate('Y-m-d H:i:s', time() - 60)]);
$p7dead = $p7an->failExhausted(3);
UbrT::ok(in_array($p7aid, array_map(fn ($r) => (int) $r['id'], $p7dead), true) && $p7an->find($p7aid)['status'] === 'failed', 'P7-12. analiza przerwana twardo po wyczerpaniu prób → failed (bez nieskończonego ponawiania)');
UbrT::eq([], array_filter($p7an->failExhausted(3), fn ($r) => (int) $r['id'] === $p7aid), 'P7-12. ponowne wywołanie nie zwraca już tej pozycji');
$p7aid2 = $p7an->create(['company_id' => $p7pc, 'organization_id' => $orgA, 'user_id' => $uA, 'trigger_type' => 'manual', 'status' => 'running', 'url' => 'https://p7-pipe.ubrtest.pl/', 'algorithm_version' => 'test', 'attempts' => 1, 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 600), 'locked_until' => gmdate('Y-m-d H:i:s', time() - 60)]);
UbrT::ok(! in_array($p7aid2, array_map(fn ($r) => (int) $r['id'], $p7an->failExhausted(3)), true), 'P7-12. pozycja z pozostałymi próbami nadal może być wznowiona');
$wpdb->delete($db->table(Tables::ANALYSES), ['id' => $p7aid]);
$wpdb->delete($db->table(Tables::ANALYSES), ['id' => $p7aid2]);

$p7conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$p7key = substr(md5(DB_NAME . '|' . $wpdb->prefix) . ':ubrt_p7_lock', 0, 64);
UbrT::ok(Lock::acquire('ubrt_p7_lock'), 'P7-13. blokada MySQL uzyskana');
UbrT::eq('0', (string) $p7conn->query("SELECT GET_LOCK('" . $p7conn->real_escape_string($p7key) . "', 0)")->fetch_row()[0], 'P7-13. drugi proces (inne połączenie) nie uzyskuje tej samej blokady — wykluczenie atomowe');
Lock::release('ubrt_p7_lock');
UbrT::eq('1', (string) $p7conn->query("SELECT GET_LOCK('" . $p7conn->real_escape_string($p7key) . "', 0)")->fetch_row()[0], 'P7-13. po zwolnieniu blokadę uzyskuje inny proces');
$p7conn->close(); // zamknięcie połączenia zwalnia blokadę automatycznie (jak śmierć procesu)
UbrT::ok(Lock::isFree('ubrt_p7_lock'), 'P7-13. blokada zwolniona automatycznie po zakończeniu połączenia (proces zabity)');

$p7m = app(Migrator::class);
$p7conn2 = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$p7mkey = substr(DB_NAME . '.' . $wpdb->prefix . 'ubr_migration_lock', 0, 64);
$p7conn2->query("SELECT GET_LOCK('" . $p7conn2->real_escape_string($p7mkey) . "', 0)");
update_option(Migrator::OPTION, $p7m->latestVersion() - 1);
UbrT::eq([], $p7m->migrate(), 'P7-14. migracja nie startuje, gdy inny proces trzyma blokadę migracji');
UbrT::eq($p7m->latestVersion() - 1, $p7m->currentVersion(), 'P7-14. wersja schematu bez zmian (brak równoległego DDL)');
$p7conn2->close();
UbrT::eq([$p7m->latestVersion()], $p7m->migrate(), 'P7-14. po zwolnieniu blokady migracja dokończona (idempotentnie)');
update_option(Migrator::ERROR_OPTION, ['at' => time(), 'version' => 9, 'message' => 'test']);
UbrT::ok($p7m->lastError() !== null, 'P7-14. błąd migracji zapisywany (komunikat w panelu WP, wstrzymanie ponawiania)');
update_option(Migrator::OPTION, $p7m->latestVersion() - 1);
$p7m->migrate();
UbrT::ok($p7m->lastError() === null && ! $p7m->needsMigration(), 'P7-14. udana migracja czyści zapisany błąd');
UbrT::ok(! Bootstrapper::autoMigrateEnabled(), 'P7-14. w WP-CLI migracje uruchamia tylko `wp ubr migrate` / `install` (nie każde polecenie)');

$p7liRows = $liRepoP7->pending(LeadIntelligenceService::VERSION, 500);
UbrT::ok($liRepoP7->countPending(LeadIntelligenceService::VERSION) === min(500, count($p7liRows)) || ($liRepoP7->countPending(LeadIntelligenceService::VERSION) > 0) === (count($p7liRows) > 0), 'P7-15. szybki licznik kolejki Lead Intelligence zgodny z kolejką (pusta ↔ 0)');
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::LEAD_INTELLIGENCE)} SET stale = 1, updated_at = %s WHERE organization_id = %d AND company_id = %d", gmdate('Y-m-d H:i:s', time() - 3600), $orgA, $p7pc));
$liRepoP7->deferFailed($orgA, $p7pc);
UbrT::ok(strtotime((string) $db->value("SELECT updated_at FROM {$db->table(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d AND company_id = %d", $orgA, $p7pc) . ' UTC') > time() - 60, 'P7-15. nieudane przeliczenie przesuwa firmę na koniec kolejki (brak blokowania pozostałych)');
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::LEAD_INTELLIGENCE)} SET stale = 0, updated_at = %s WHERE organization_id = %d AND company_id = %d", gmdate('Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS), $orgA, $p7pc));
UbrT::ok($liRepoP7->markAged(7) >= 1 && (string) $db->value("SELECT stale_reason FROM {$db->table(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d AND company_id = %d", $orgA, $p7pc) === 'aging', 'P7-15. oceny starsze niż 7 dni okresowo przeliczane (świeżość analizy zależy od czasu)');
app(\App\Ubr\Services\LeadIntelligenceRunner::class)->tick(500, false);

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — Walidacja danych i obsługa błędów');

UbrT::ok(CompanyNormalizer::nip('0000000000') === null && CompanyNormalizer::regon('000000000') === null, 'P7-16. NIP/REGON z samych zer (wypełniacz szablonu) odrzucone');
UbrT::eq('5260250274', CompanyNormalizer::nip('PL 526-025-02-74'), 'P7-16. poprawny NIP nadal akceptowany');

$p7rev = ubrt_rest($uC, 'POST', '/companies/' . $c['review'] . '/manual-review', ['review_status' => 'average', 'review_note' => '']);
UbrT::ok($p7rev['status'] === 200 && $db->value("SELECT review_note FROM {$db->table(Tables::MANUAL_REVIEWS)} WHERE organization_id = %d AND company_id = %d", $orgC, $c['review']) === null, 'P7-17. pusta notatka oceny ręcznej zapisywana jako NULL');

$p7throwing = new class (app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class), app(MemberRepository::class)) extends \App\Ubr\Services\TenantResolver {
	public function __construct(...$args)
	{
	}

	public function require(string $ability, ?int $userId = null): \App\Ubr\Domain\Tenancy\TenantContext
	{
		throw new \App\Ubr\Infrastructure\Database\DatabaseException("Table 'wp_ubr_organizations' doesn't exist");
	}
};
wp_set_current_user($uA);
$p7err = (new \App\Ubr\Http\RestRegistrar(app(), $p7throwing))->authorize('companies.view');
UbrT::ok($p7err instanceof WP_Error && ($p7err->get_error_data()['status'] ?? null) === 500 && ! str_contains($p7err->get_error_message(), 'wp_ubr_'), 'P7-18. błąd bazy przy autoryzacji REST → kontrolowany 500 bez szczegółów (zamiast fatal error)');

$p7ping = (string) file_get_contents(get_theme_file_path('app/Ubr/Http/WorkerEndpoint.php'));
preg_match('/public function ping\(\): void\s*\{(.*?)\n\t\}/s', $p7ping, $p7pm);
UbrT::ok(isset($p7pm[1]) && ! str_contains($p7pm[1], 'heartbeat') && ! str_contains($p7pm[1], 'update_option'), 'P7-19. publiczny ping workera nie zapisuje do bazy (brak fałszowania znacznika życia)');
UbrT::ok(str_contains((string) file_get_contents(get_theme_file_path('tools/site-capture/capture.mjs')), 'routeWebSocket'), 'P7-20. renderer blokuje WebSockety (page.route ich nie przechwytuje)');

/* ------------------------------------------------------------------ */
UbrT::group('QA P7 — Zlecenia workerów po wysłaniu odpowiedzi');

$p7posts = [];
$p7capture = function ($pre, $args, $url) use (&$p7posts) {
	if (str_contains($url, 'ubr_job_worker')) {
		$p7posts[] = $args['body']['job'] ?? '?';

		return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
	}

	return $pre;
};
add_filter('pre_http_request', $p7capture, PHP_INT_MAX, 3);
add_filter('ubr/queue/defer_dispatch', '__return_true');
$p7worker = app(\App\Ubr\Infrastructure\Queue\AsyncWorker::class);
// W WP-CLI odraczanie jest wyłączone — test sprawdza mechanizm przez filtr i bezpośrednie wywołanie flushDeferred().
$p7cli = (new ReflectionClass($p7worker))->getMethod('canDefer');
UbrT::ok($p7cli->invoke($p7worker) === false, 'P7-21. w WP-CLI zlecenia wysyłane od razu (bez odraczania)');
$p7ref = new ReflectionProperty(\App\Ubr\Infrastructure\Queue\AsyncWorker::class, 'deferred');
$p7ref->setValue(null, ['lead:0' => ['lead', 0], 'clickup:0' => ['clickup', 0]]);
$p7worker->flushDeferred();
UbrT::eq(['lead', 'clickup'], $p7posts, 'P7-21. odroczone zlecenia wysyłane jednokrotnie na końcu żądania (po jednym na typ)');
$p7worker->flushDeferred();
UbrT::eq(2, count($p7posts), 'P7-21. ponowne opróżnienie nie wysyła duplikatów');
remove_filter('ubr/queue/defer_dispatch', '__return_true');
remove_filter('pre_http_request', $p7capture, PHP_INT_MAX);
