<?php

/**
 * Testy Prompt 10.4 — Performance Optimization & Progressive Discovery.
 * Dołączane z tests/run.php PO tests/apollo-matching-p103.php (wspólne: $orgD, $orgE, $uD, $uE, $uX, $sD, $p10mk, $ingest,
 * $searches, $db, $wpdb, $ctxD). Strony firm, rejestry i Apollo wyłącznie na mockach (pre_http_request); równoległe
 * pobieranie w trybie symulacji (`ubr/http/parallel_sim`). Czasy z testów NIE są pomiarami produkcyjnymi.
 */

use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Http\PageCache;
use App\Ubr\Infrastructure\Http\PageFetcher;
use App\Ubr\Infrastructure\Http\ParallelFetcher;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;
use App\Ubr\Infrastructure\Http\WebsiteInspector;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\VerificationQueueRepository;
use App\Ubr\Services\CompanyPresenter;
use App\Ubr\Services\VerificationRunner;

if (! defined('ABSPATH')) {
	exit(1);
}

remove_filter('ubr/http/page_cache_enabled', '__return_false');
$pc = app(PageCache::class);
$wpdb->query("DELETE FROM {$db->table(Tables::PAGE_CACHE)} WHERE url LIKE '%ubrtest.pl%'");
$p104http = [];
$p104count = function ($pre, $args, $url) use (&$p104http) {
	if (str_contains((string) $url, 'ubrtest.pl')) {
		$p104http[] = ($GLOBALS['ubrt_p104_sim'] ?? false ? 'sim ' : 'live ') . $url;
	}

	return $pre;
};
add_filter('pre_http_request', $p104count, 1, 3);

/* ------------------------------------------------------------------ */
UbrT::group('P10.4 — wspólna pamięć podręczna stron (PageCache)');

$GLOBALS['ubrt_sites']['cache104.ubrtest.pl'] = '<html><head><title>Cache 104</title></head><body><a href="/kontakt">Kontakt</a> ' . str_repeat('treść ', 200) . '</body></html>';
$GLOBALS['ubrt_sites']['cache104.ubrtest.pl/kontakt'] = '<html><body>Kontakt: tel. 22 104 10 40</body></html>';
$fetcher = app(PageFetcher::class);
$p104http = [];
$f1 = $fetcher->fetch('https://cache104.ubrtest.pl/');
$f2 = $fetcher->fetch('https://cache104.ubrtest.pl/');
UbrT::ok($f1['ok'] && ! $f1['cached'] && $f2['ok'] && $f2['cached'] && $f2['body'] === $f1['body'] && count($p104http) === 1, 'P10.4-1. druga pobrana ta sama strona — z cache, bez ponownego zapytania (zapytań: ' . count($p104http) . ')');
$f3 = $fetcher->fetch('https://cache104.ubrtest.pl/', 12, true);
UbrT::ok(! $f3['cached'] && count($p104http) === 2, 'P10.4-1. odświeżenie (fresh) — nowe zapytanie, wynik nadpisuje cache');
$GLOBALS['ubrt_sites']['cache104.ubrtest.pl'] = '<html><head><title>Cache 104 v2</title></head><body>nowa wersja</body></html>';
UbrT::ok(str_contains($fetcher->fetch('https://cache104.ubrtest.pl/')['body'], 'Cache 104') && ! str_contains($fetcher->fetch('https://cache104.ubrtest.pl/')['body'], 'v2'), 'P10.4-1. w czasie ważności cache zwraca zapisaną wersję (TTL ' . PageCache::ttl() . ' s)');
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::PAGE_CACHE)} SET expires_at = %s WHERE url = %s", gmdate('Y-m-d H:i:s', time() - 5), 'https://cache104.ubrtest.pl/'));
$pc->forget();
app()->forgetInstance(PageCache::class);
UbrT::ok(str_contains(app(PageFetcher::class)->fetch('https://cache104.ubrtest.pl/')['body'], 'v2'), 'P10.4-1. po wygaśnięciu — aktualna treść strony (aktualność danych)');
$p104http = [];
$d1 = app(PageFetcher::class)->fetch('https://down.ubrtest.pl/');
$d2 = app(PageFetcher::class)->fetch('https://down.ubrtest.pl/kontakt');
UbrT::ok(! $d1['ok'] && ! $d2['ok'] && count($p104http) === 1 && str_contains((string) $d2['error'], 'zapamiętane'), 'P10.4-2. niedostępny serwer: kolejne strony tego serwera bez ponownego czekania na timeout (zapytań: ' . count($p104http) . ')');
$row = $db->row("SELECT TIMESTAMPDIFF(SECOND, fetched_at, expires_at) AS ttl, http_status FROM {$db->table(Tables::PAGE_CACHE)} WHERE url = %s", 'https://down.ubrtest.pl');
UbrT::ok($row && (int) $row['ttl'] === PageCache::ERROR_TTL && $row['http_status'] === null, 'P10.4-2. błąd połączenia zapamiętany krótko (' . ($row['ttl'] ?? '?') . ' s) dla serwera');
$pc2 = app(PageCache::class);
$pc2->store('https://cache104.ubrtest.pl/5xx', ['code' => 503, 'headers' => ['set-cookie' => 'sid=sekret', 'content-type' => 'text/html'], 'body' => 'błąd', 'ms' => 10]);
$r5 = $db->row("SELECT TIMESTAMPDIFF(SECOND, fetched_at, expires_at) AS ttl, headers FROM {$db->table(Tables::PAGE_CACHE)} WHERE url = %s", 'https://cache104.ubrtest.pl/5xx');
UbrT::ok((int) $r5['ttl'] === PageCache::SERVER_ERROR_TTL && ! str_contains((string) $r5['headers'], 'sekret') && str_contains((string) $r5['headers'], 'content-type'), 'P10.4-2. odpowiedź 5xx — krótki czas życia; nagłówki techniczne bez ciasteczek');
$pc2->store('https://cache104.ubrtest.pl/big', ['code' => 200, 'headers' => [], 'body' => str_repeat('x', PageCache::MAX_STORE_BYTES + 1), 'ms' => 5]);
UbrT::eq(null, $db->value("SELECT id FROM {$db->table(Tables::PAGE_CACHE)} WHERE url = %s", 'https://cache104.ubrtest.pl/big'), 'P10.4-2. zbyt duża odpowiedź nie jest zapisywana');
$pc2->store('https://cache104.ubrtest.pl/trunc', ['code' => 200, 'headers' => [], 'body' => str_repeat('y', 1000), 'ms' => 5], 1000);
UbrT::ok($pc2->lookup('https://cache104.ubrtest.pl/trunc', 1000) !== null && $pc2->lookup('https://cache104.ubrtest.pl/trunc', 5000) === null, 'P10.4-2. odpowiedź ucięta limitem nie jest podawana modułowi z większym limitem');
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::PAGE_CACHE)} SET expires_at = %s WHERE url LIKE %s", gmdate('Y-m-d H:i:s', time() - 60), '%cache104.ubrtest.pl/5xx'));
UbrT::ok($pc2->purgeExpired(100) >= 1 && $db->value("SELECT id FROM {$db->table(Tables::PAGE_CACHE)} WHERE url = %s", 'https://cache104.ubrtest.pl/5xx') === null, 'P10.4-2. przeterminowane wpisy usuwane (healthcheck)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4 — równoległe pobieranie i plan podstron (bez dodatkowych zapytań)');

$inspector = app(WebsiteInspector::class);
$homeIdsOnSub = '<html><head><title>Plan</title></head><body>
<a href="/kontakt">Kontakt</a>
<a href="/o-firmie">O firmie</a>
</body></html>';
$homeIdsOnHome = '<html><head><title>Plan</title></head><body>
<a href="/kontakt">Kontakt</a>
<footer>NIP: 526-000-00-05, KRS: 0000900001</footer>
</body></html>';
UbrT::eq('https://plan.ubrtest.pl/kontakt', $inspector->nextSubpage($homeIdsOnSub, 'https://plan.ubrtest.pl/'), 'P10.4-3. plan podstron: identyfikatory nie na stronie głównej → najpierw Kontakt (jak inspect())');
UbrT::eq(null, $inspector->nextSubpage($homeIdsOnHome, 'https://plan.ubrtest.pl/'), 'P10.4-3. NIP i KRS na stronie głównej → bez pobierania podstron (bez dodatkowych zapytań)');
UbrT::eq(null, $inspector->nextSubpage($homeIdsOnSub, 'https://plan.ubrtest.pl/', ['<p>NIP: 526-000-00-05, KRS: 0000900001</p>']), 'P10.4-3. identyfikatory znalezione na Kontakcie → koniec (jak inspect())');
UbrT::ok(ParallelFetcher::concurrency() >= 1 && ParallelFetcher::concurrency() <= 12 && VerificationRunner::batchSize() >= 1 && VerificationRunner::batchSize() <= 12, 'P10.4-3. ograniczona liczba równoległych połączeń (' . ParallelFetcher::concurrency() . ') i firm w toku (' . VerificationRunner::batchSize() . ')');

$simLatency = ['slow104.ubrtest.pl' => 0.6, 'down.ubrtest.pl' => 0.6];
$sim = fn () => function (string $url, array $args) use ($simLatency) {
	$GLOBALS['ubrt_p104_sim'] = true;
	$r = wp_remote_get($url, $args);
	$GLOBALS['ubrt_p104_sim'] = false;

	return [$r, $simLatency[(string) parse_url($url, PHP_URL_HOST)] ?? 0.2];
};
add_filter('ubr/http/parallel_sim', $sim);
foreach (['par1', 'par2'] as $h) {
	$GLOBALS['ubrt_sites'][$h . '.ubrtest.pl'] = '<html><body>' . $h . '</body></html>';
}
$pf = app(ParallelFetcher::class);
$t = microtime(true);
$doneUrls = [];
foreach (['par1', 'par2'] as $h) {
	$pf->add('https://' . $h . '.ubrtest.pl/', 'g' . $h, 7, WebsiteInspector::MAX_BYTES, function ($r, $u) use (&$doneUrls) {
		$doneUrls[] = $u;
	});
}
$pf->add('https://slow104.ubrtest.pl/', 'gslow', 7, WebsiteInspector::MAX_BYTES, function ($r, $u) use (&$doneUrls) {
	$doneUrls[] = $u;
});
$pf->wait('gpar1', 5);
$afterFast = microtime(true) - $t;
$pf->wait('gslow', 5);
$all = microtime(true) - $t;
UbrT::ok($afterFast < 0.5 && $all < 1.0 && count($doneUrls) === 3 && $doneUrls[2] === 'https://slow104.ubrtest.pl/', sprintf('P10.4-4. równolegle: szybkie strony gotowe po %.2f s, wolna po %.2f s (sekwencyjnie ≥ 1,0 s) — wolna strona nie blokuje pozostałych (symulacja)', $afterFast, $all));
UbrT::ok(app(PageCache::class)->lookup('https://par1.ubrtest.pl/') !== null && app(PageCache::class)->lookup('https://slow104.ubrtest.pl/') !== null, 'P10.4-4. pobrane równolegle strony trafiają do wspólnego cache');
$pf->add('https://par1.ubrtest.pl/', 'again', 7);
UbrT::ok(! $pf->busy('again'), 'P10.4-4. adres już w cache — bez ponownego zapytania (zakończone od razu)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4 — weryfikacja: kilka firm naraz, kolejność wg gotowości, telefony bez dodatkowych zapytań');

$vq = app(VerificationQueueRepository::class);
$cr = app(CompanyRepository::class);
$mkV = function (string $slug, string $name, string $krs, string $nip, string $host, bool $idsOnHome = false) use ($ingest, $cr) {
	$GLOBALS['ubrt_sites'][$host] = '<html><head><title>' . $name . ' – konstrukcje</title></head><body><a href="/kontakt">Kontakt</a><footer>© ' . $name . ' Sp. z o.o. ' . ($idsOnHome ? 'NIP: ' . $nip . ', KRS: ' . $krs : '') . ' tel. 22 104 00 0' . strlen($slug) . '</footer></body></html>';
	$GLOBALS['ubrt_sites'][$host . '/kontakt'] = '<html><body><h1>Kontakt</h1><p>' . $name . ' Sp. z o.o., ul. Testowa 1, 00-001 Warszawa, NIP: ' . $nip . ', KRS: ' . $krs . ', tel. +48 22 104 10 1' . strlen($slug) . '</p></body></html>';
	$GLOBALS['ubrt_krs'][$krs] = ubrt_krs_odpis($krs, mb_strtoupper($name) . ' SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', $nip, 'MAZOWIECKIE', 'WARSZAWA');
	$GLOBALS['ubrt_vat'][$nip] = ['name' => mb_strtoupper($name) . ' SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => $nip, 'statusVat' => 'Czynny', 'krs' => $krs, 'workingAddress' => 'UL. TESTOWA 1, 00-001 WARSZAWA'];

	return $ingest->ingest(ubrt_candidate(['id' => 'p104-' . $slug, 'name' => $name, 'website' => 'https://' . $host]))['company_id'];
};
$V = [
	'down' => $ingest->ingest(ubrt_candidate(['id' => 'p104-down', 'name' => 'Ubrtest Niedostepna Sto Cztery', 'website' => 'https://down.ubrtest.pl']))['company_id'],
	'a' => $mkV('alfa', 'Ubrtest Alfa Sto Cztery', '0000910401', '5260000005', 'alfa104.ubrtest.pl'),
	'b' => $mkV('beta', 'Ubrtest Beta Sto Cztery', '0000910402', '7770000011', 'beta104.ubrtest.pl', true),
	'c' => $mkV('gamma', 'Ubrtest Gamma Sto Cztery', '0000910403', '1234563218', 'slow104.ubrtest.pl'),
];
$wpdb->query("DELETE FROM {$db->table(Tables::VERIFICATION_QUEUE)}");
$wpdb->query("DELETE FROM {$db->table(Tables::PAGE_CACHE)} WHERE url LIKE '%ubrtest.pl%'");
foreach ($V as $id) {
	$vq->enqueue($id, $orgD, null, null, 5);
}
$order = [];
$track = function ($companyId, $reason) use (&$order) {
	if ($reason === 'verification_done') {
		$order[] = (int) $companyId;
	}
};
add_action('ubr/company/changed', $track, 1, 2);
$p104http = [];
delete_transient('ubr_rl_reg_krs');
delete_transient('ubr_rl_reg_vat');
app()->forgetInstance(PageCache::class);
$vt = microtime(true);
$stats = app(VerificationRunner::class)->tick(60, false);
$vt = microtime(true) - $vt;
remove_action('ubr/company/changed', $track, 1);
$live = array_values(array_filter($p104http, fn ($u) => str_starts_with($u, 'live ') && preg_match('#//(alfa104|beta104|slow104|down)\.ubrtest\.pl#', $u)));
UbrT::ok($stats['processed'] === 4 && $stats['verified'] === 4, 'P10.4-5. jeden krok workera przetworzył 4 firmy (paczka, równoległe strony) — ' . json_encode($stats));
UbrT::ok(count($order) === 4 && $order[0] !== (int) $V['down'], 'P10.4-5. najpierw firma, której strony są już pobrane — niedostępna strona (pierwsza w kolejce) nie blokuje pozostałych');
UbrT::eq([], $live, 'P10.4-5. inspekcja nie pobierała stron ponownie — wszystkie z równoległego pobrania (cache)');
$sitesSim = array_values(array_filter($p104http, fn ($u) => str_starts_with($u, 'sim ')));
UbrT::ok(count(array_filter($sitesSim, fn ($u) => str_contains($u, 'beta104.ubrtest.pl/kontakt'))) === 0 && count(array_filter($sitesSim, fn ($u) => str_contains($u, 'alfa104.ubrtest.pl/kontakt'))) === 1, 'P10.4-5. pobierane tylko strony potrzebne inspekcji (Kontakt tylko, gdy identyfikatorów brak na stronie głównej)');
UbrT::ok($cr->find($V['a'])['verification_status'] === 'verified' && $cr->find($V['b'])['verification_status'] === 'verified' && $cr->find($V['c'])['verification_status'] === 'verified', 'P10.4-5. wynik weryfikacji taki sam jak przy pobieraniu sekwencyjnym (VERIFIED z rejestru)');
UbrT::ok($cr->find($V['down'])['verification_status'] !== 'verified' && $cr->find($V['down'])['website_status'] === 'unreachable', 'P10.4-5. strona niedostępna → firma NIE jest VERIFIED tylko z danych Apollo / działającej domeny');
UbrT::ok((int) $cr->find($V['a'])['phones_count'] >= 1 && (int) $cr->find($V['c'])['phones_count'] >= 1 && $cr->find($V['a'])['phones_checked_at'] !== null, 'P10.4-6. numery firmy zapisane już przy weryfikacji — ze stron pobranych do weryfikacji (bez dodatkowych zapytań)');
UbrT::ok($cr->find($V['down'])['phones_checked_at'] === null, 'P10.4-6. strona niedostępna — numery nie są „sprawdzone”');
remove_filter('ubr/http/parallel_sim', $sim);

/* ------------------------------------------------------------------ */
UbrT::group('P10.4 — Progressive Discovery: weryfikacja startuje po pierwszej stronie wyników');

UbrTestProvider::$failuresLeft = 0;
UbrTestProvider::$candidates = [];
for ($i = 1; $i <= 6; $i++) {
	UbrTestProvider::$candidates[] = ubrt_candidate(['id' => 'p104-prog' . $i, 'name' => 'Ubrtest Progresywna ' . $i, 'website' => 'https://prog' . $i . '.ubrtest.pl', 'city' => 'Łódź']);
}
$ps = ubrt_rest($uD, 'POST', '/discovery/searches', ['phrase' => 'progresywna', 'providers' => ['ubrtest'], 'include_unknown_size' => true]);
$psId = (int) ($ps['data']['id'] ?? 0);
$wpdb->query("DELETE FROM {$db->table(Tables::VERIFICATION_QUEUE)}");
$psRow = $searches->find($psId);
$step = (new ReflectionClass(\App\Ubr\Services\DiscoveryRunner::class))->getMethod('fetchStep');
$step->setAccessible(true);
$state = ['phase' => 'fetch', 'pi' => 0, 'cursor' => [], 'attempts' => [], 'errors' => [], 'diagnostics' => ['returned' => 0, 'rejected' => [], 'provider_totals' => [], 'filters' => []]];
$step->invokeArgs(app(\App\Ubr\Services\DiscoveryRunner::class), [$psId, $psRow, \App\Ubr\Domain\Discovery\DiscoveryCriteria::fromStored($psRow['criteria']), 'ubrtest', &$state]);
$queuedAfterPage = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::VERIFICATION_QUEUE)} WHERE search_id = %d", $psId);
$visible = ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results');
UbrT::ok($queuedAfterPage === 3 && in_array($searches->find($psId)['status'], ['queued', 'running'], true), 'P10.4-7. po pierwszej stronie wyników (3 firmy) weryfikacja już w kolejce — wyszukiwanie wciąż trwa');
UbrT::ok($visible['status'] === 200 && count($visible['data']['items']) === 3 && ($visible['data']['items'][0]['stages']['verification']['key'] ?? '') === 'pending', 'P10.4-7. firmy widoczne na liście od razu, oznaczone „Oczekuje na weryfikację”');
$step->invokeArgs(app(\App\Ubr\Services\DiscoveryRunner::class), [$psId, $searches->find($psId), \App\Ubr\Domain\Discovery\DiscoveryCriteria::fromStored($psRow['criteria']), 'ubrtest', &$state]);
UbrT::ok((int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::VERIFICATION_QUEUE)} WHERE search_id = %d", $psId) === 6 && (int) $state['verification_queued'] === 6, 'P10.4-7. kolejna strona — kolejne firmy w kolejce, bez duplikatów (UNIQUE firma)');
$added = app(\App\Ubr\Services\VerificationService::class)->enqueueForSearch($psId, $orgD);
UbrT::eq(0, $added, 'P10.4-7. zakończenie wyszukiwania nie dubluje zleceń (firmy już w kolejce pominięte)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4 — statusy etapów, gotowość i lekkie odświeżanie listy');

$stg = fn (array $r) => CompanyPresenter::stages($r + ['domain' => 'x.pl']);
UbrT::eq(['running', 'inactive', 'mismatch', 'verified', 'uncertain', 'pending'], [
	$stg(['verification_status' => 'pending', 'vq_status' => 'running'])['verification']['key'],
	$stg(['verification_status' => 'inactive'])['verification']['key'],
	$stg(['verification_status' => 'verified', 'domain_status' => 'mismatch'])['verification']['key'],
	$stg(['verification_status' => 'verified'])['verification']['key'],
	$stg(['verification_status' => 'uncertain'])['verification']['key'],
	$stg(['verification_status' => 'pending', 'vq_status' => 'queued'])['verification']['key'],
], 'P10.4-8. weryfikacja: W toku / Nieaktywna / Niezgodność / Zweryfikowana / Niepewna / Oczekuje');
UbrT::eq(['queued', 'running', 'done', 'failed', 'none'], array_map(fn ($a) => $stg(['analysis_status' => $a])['website']['key'], ['queued', 'running', 'completed', 'failed', null]), 'P10.4-8. strona WWW: Oczekuje / Analizowanie / Gotowe / Błąd / Nie analizowano');
UbrT::eq(['found', 'none', 'running', 'error', 'pending'], [
	$stg(['phones_count' => 2])['phones']['key'],
	$stg(['phones_count' => 0, 'phones_checked_at' => Db::now()])['phones']['key'],
	$stg(['vq_status' => 'running'])['phones']['key'],
	$stg(['website_status' => 'unreachable', 'verification_status' => 'uncertain'])['phones']['key'],
	$stg(['verification_status' => 'pending'])['phones']['key'],
], 'P10.4-8. telefony: Znaleziono / Nie znaleziono / Wyszukiwanie / Błąd / Oczekuje');
UbrT::eq(['queued', 'running', 'found', 'none', 'failed'], [
	$stg(['contacts_status' => 'queued'])['dm']['key'], $stg(['contacts_status' => 'running'])['dm']['key'],
	$stg(['contacts_status' => 'completed', 'top_contact_id' => 5])['dm']['key'], $stg(['contacts_status' => 'completed'])['dm']['key'], $stg(['contacts_status' => 'failed'])['dm']['key'],
], 'P10.4-8. decydent: Oczekuje / Wyszukiwanie / Znaleziono / Nie znaleziono / Błąd');
UbrT::ok(CallReadiness::classify(['company_phone' => true, 'verification_status' => 'inactive']) === CallReadiness::BLOCKED && CallReadiness::classify(['company_phone' => true, 'direct_phone' => true, 'verification_status' => 'verified', 'domain_status' => 'mismatch']) === CallReadiness::RESEARCH, 'P10.4-9. NIEAKTYWNA (z numerem) → BLOCKED; NIEZGODNOŚĆ domeny (z numerem) → RESEARCH — nie trafiają do gotowych leadów');
$cr->update($V['a'], ['verification_status' => 'inactive']);
UbrT::eq('BLOCKED', app(\App\Ubr\Services\OwnershipService::class)->readiness($ctxD, $V['a']), 'P10.4-9. SQL: firma nieaktywna w rejestrze z numerem → BLOCKED');
$cr->update($V['a'], ['verification_status' => 'verified', 'domain_status' => 'mismatch']);
UbrT::eq('RESEARCH', app(\App\Ubr\Services\OwnershipService::class)->readiness($ctxD, $V['a']), 'P10.4-9. SQL: niezgodność domeny z numerem → RESEARCH (nie „gotowa do telefonu”)');
$cr->update($V['a'], ['domain_status' => 'confirmed']);

$full = ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results', ['per_page' => 25]);
$ids = array_column($full['data']['items'], 'id');
$since = $full['data']['server_time'];
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::COMPANIES)} SET updated_at = %s WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')', gmdate('Y-m-d H:i:s', time() - 600)));
$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::VERIFICATION_QUEUE)} SET updated_at = %s WHERE search_id = %d", gmdate('Y-m-d H:i:s', time() - 600), $psId));
$noChange = ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results', ['ids' => implode(',', $ids), 'changed_since' => $since]);
$cr->update($ids[1], ['verification_status' => 'verified']);
$oneChange = ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results', ['ids' => implode(',', $ids), 'changed_since' => $since]);
UbrT::ok($noChange['status'] === 200 && $noChange['data']['items'] === [] && ($noChange['data']['changes_only'] ?? false) === true, 'P10.4-10. lekkie odświeżenie: brak zmian → pusta odpowiedź (bez ponownego pobierania listy)');
UbrT::ok(count($oneChange['data']['items']) === 1 && $oneChange['data']['items'][0]['id'] === $ids[1] && $oneChange['data']['items'][0]['stages']['verification']['key'] === 'verified', 'P10.4-10. zmieniona firma zwracana sama, z aktualnym statusem etapu');
$filtered = ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results', ['ids' => implode(',', $ids), 'filter' => 'verified']);
UbrT::ok(count($filtered['data']['items']) === 1, 'P10.4-10. lekkie odświeżenie respektuje filtry listy (filtr „zweryfikowane”)');
UbrT::eq(404, ubrt_rest($uE, 'GET', '/discovery/searches/' . $psId . '/results', ['ids' => implode(',', $ids)])['status'], 'P10.4-10. inna organizacja → 404 (izolacja)');
UbrT::ok($full['data']['total'] === 6 && count(ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results', ['per_page' => 2, 'page' => 3])['data']['items']) === 2, 'P10.4-10. paginacja bez zmian (6 firm, strona 3 po 2)');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4 — limity, kolejki, przyciski tel:, brak regresji Apollo');

$http = app(ProviderHttpClient::class);
$idleCalls = 0;
$idle = function () use (&$idleCalls) {
	$idleCalls++;
};
add_action('ubr/http/idle', $idle);
$mockP = fn ($pre, $args, $url) => str_contains((string) $url, 'throttle104.ubrtest.pl') ? ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null] : $pre;
add_filter('pre_http_request', $mockP, 2, 3);
delete_transient('ubr_rl_p104');
$http->send('p104', 'GET', 'https://throttle104.ubrtest.pl/a', [], 400);
$t2 = microtime(true);
$http->send('p104', 'GET', 'https://throttle104.ubrtest.pl/b', [], 400);
$gap = microtime(true) - $t2;
remove_filter('pre_http_request', $mockP, 2);
remove_action('ubr/http/idle', $idle);
UbrT::ok($gap >= 0.35 && $idleCalls === 1, sprintf('P10.4-11. limit częstotliwości dostawcy zachowany (odstęp %.2f s), czas oczekiwania udostępniony innym transferom', $gap));
UbrT::ok(\App\Ubr\Support\Lock::isFree('rl_p104'), 'P10.4-11. blokada limitu zwolniona po zapytaniu');

$offenders = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(get_theme_file_path('resources/views/ubr'), FilesystemIterator::SKIP_DOTS)) as $file) {
	$code = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
	if (preg_match('/href=["\']tel:|safeTel\(|>\s*Zadzwoń\s*<|[\'"]Zadzwoń/u', $code)) {
		$offenders[] = basename($file->getPathname());
	}
}
UbrT::eq([], $offenders, 'P10.4-12. brak przycisków / linków otwierających aplikację telefonu (tel:) w widokach aplikacji');
$tpl = (string) file_get_contents(get_theme_file_path('resources/views/ubr/partials/call/phones-cell.blade.php'));
UbrT::ok(str_contains($tpl, 'copyPhone(') && str_contains((string) file_get_contents(get_theme_file_path('resources/views/ubr/partials/call/quick-actions.blade.php')), 'openOutcome('), 'P10.4-12. kopiowanie numerów i zapis wyniku rozmowy zachowane');

$GLOBALS['ubrt_apollo_calls'] = 0;
ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results');
ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId . '/results', ['ids' => implode(',', $ids), 'changed_since' => $since]);
UbrT::eq(0, (int) $GLOBALS['ubrt_apollo_calls'], 'P10.4-13. renderowanie listy i lekkie odświeżenie — 0 zapytań do Apollo');
UbrT::ok(! \App\Ubr\Services\ApolloPhoneService::enabled() || has_filter('ubr/apollo/phone_enabled'), 'P10.4-13. płatne odblokowanie telefonów nadal wyłączone (bez zmian ustawień)');
delete_transient('ubr_kick_' . $psId);
ubrt_rest($uD, 'GET', '/discovery/searches/' . $psId);
UbrT::ok((bool) get_transient('ubr_kick_' . $psId), 'P10.4-14. sprawdzanie zablokowanych kroków przy odpytywaniu statusu — najwyżej co 10 s');

remove_filter('pre_http_request', $p104count, 1);
$wpdb->query("DELETE FROM {$db->table(Tables::PAGE_CACHE)} WHERE url LIKE '%ubrtest.pl%'");
$wpdb->query("DELETE FROM {$db->table(Tables::VERIFICATION_QUEUE)} WHERE search_id = " . (int) $psId);
add_filter('ubr/http/page_cache_enabled', '__return_false');
