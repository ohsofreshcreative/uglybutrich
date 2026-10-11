<?php
/**
 * Testy Prompt 10.4.3 — Discover (e-mail firmowy, Opportunity Score w tle, Lead Intelligence bez uruchamiania z list),
 * Website Age Intelligence (Wayback Machine mockowane), „Przejmij firmę” (Decision Maker Finder w tle, statusy,
 * ponowienie) i ClickUp dwuetapowo (mock API: mapowanie pól, ochrona ręcznych zmian, idempotencja, timeout, retry).
 *
 * Dołączane z tests/run.php (wspólne: $mk, $ingest, $searches, $created, $db, $wpdb, $uX). Wszystkie usługi zewnętrzne
 * mockowane — bez zapytań do Internet Archive, ClickUp, Apollo ani rejestrów. Osoby i firmy FIKCYJNE.
 */

use App\Ubr\Domain\Analysis\Age\AgeEstimator;
use App\Ubr\Domain\Analysis\Age\FingerprintComparator;
use App\Ubr\Domain\Analysis\Age\FingerprintExtractor;
use App\Ubr\Domain\Analysis\Age\TechSignals;
use App\Ubr\Domain\Contacts\GeneralEmailPicker;
use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Infrastructure\Repositories\VerificationQueueRepository;
use App\Ubr\Infrastructure\Security\SecretBox;
use App\Ubr\Services\ClickUpDestinationService;
use App\Ubr\Services\ClickUpExportService;
use App\Ubr\Services\ClickUpRunner;
use App\Ubr\Services\ContactResearchRunner;
use App\Ubr\Services\ContactResearchService;
use App\Ubr\Services\LeadIntelligenceRunner;
use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\OwnershipService;
use App\Ubr\Services\VerificationRunner;
use App\Ubr\Services\WebsiteAgeRunner;
use App\Ubr\Services\WebsiteAgeService;

if (! defined('ABSPATH')) {
	exit(1);
}

/** Strona testowa o danym projekcie (motyw, klasy, menu) i treści. */
function ubrt_p1043_page(string $theme, string $cls, array $nav, string $content, string $head = ''): string
{
	$n = implode('', array_map(fn ($t) => "<a href='/" . sanitize_title($t) . "'>{$t}</a>", $nav));

	return "<!doctype html><html><head><meta name='viewport' content='width=device-width'><link rel='stylesheet' href='/wp-content/themes/{$theme}/style.css?ver=1.2'><link rel='stylesheet' href='/wp-content/themes/{$theme}/css/main.min.css'><script src='/wp-content/themes/{$theme}/js/app.js?ver=3.1'></script>{$head}</head>"
		. "<body class='{$cls}-body home'><header class='{$cls}-header'><div class='{$cls}-logo'>Logo</div><nav class='{$cls}-nav'>{$n}</nav></header><main class='{$cls}-main'><section class='{$cls}-hero'><h1>Witamy</h1></section>"
		. "<section class='{$cls}-offer'><div class='{$cls}-card'>{$content}</div></section><section class='{$cls}-about'><p>O nas</p></section></main><footer class='{$cls}-footer'><div class='{$cls}-cols'><p>© 2025 Firma</p></div></footer></body></html>";
}

$p13nav = ['Oferta', 'O nas', 'Realizacje', 'Kontakt'];
$p13new = fn (string $content = 'Usługi CNC i spawanie', string $head = '') => ubrt_p1043_page('nowyprojekt', 'nw', $GLOBALS['p13nav'] ?? ['Oferta', 'O nas', 'Realizacje', 'Kontakt'], $content, $head);
$GLOBALS['p13nav'] = $p13nav;
$p13old = fn (string $content = 'Treść') => "<html><head><link rel=stylesheet href='/templates/starydesign/css/template.css'><script src='/media/system/js/mootools.js'></script></head><body><table width=900><tr><td class='menu'><a href=/a>Start</a><a href=/b>Firma</a><a href=/c>Produkty</a></td></tr><tr><td class='tresc'><div class='box'>{$content}</div><div class='box2'>x</div></td></tr></table><div id='stopka' class='stopka'>(c) 2009</div></body></html>";
$p13mid = fn (string $content = 'Treść') => ubrt_p1043_page('srodkowy', 'md', ['Start', 'Firma', 'Produkty', 'Galeria', 'Kontakt'], $content);

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — Website Age: odcisk projektu, porównanie, poszlaki');

$fpNew = FingerprintExtractor::extract($p13new(), 'https://x.ubrtest.pl/');
$cmpSame = FingerprintComparator::compare($fpNew, FingerprintExtractor::extract($p13new('Zupełnie inny artykuł, nowe zdjęcie <img src=a.jpg> i nowy numer 22 100 20 30'), 'https://x.ubrtest.pl/'));
$cmpOld = FingerprintComparator::compare($fpNew, FingerprintExtractor::extract($p13old(), 'http://x.ubrtest.pl/'));
$minorHtml = str_replace(["<section class='nw-hero'>", '</head>'], ["<section class='nw-slider'><div class='nw-slide'>a</div></section><section class='nw-hero nw-hero--v2'>", "<link rel='stylesheet' href='/wp-content/themes/nowyprojekt/css/extra.css'></head>"], ubrt_p1043_page('nowyprojekt', 'nw', array_merge($p13nav, ['Blog']), 'Usługi'));
$cmpMinor = FingerprintComparator::compare($fpNew, FingerprintExtractor::extract($minorHtml, 'https://x.ubrtest.pl/'));
$cmpTiny = FingerprintComparator::compare($fpNew, FingerprintExtractor::extract('<html><body><div id="app"></div><script src="/a.js"></script></body></html>', 'https://x.ubrtest.pl/'));
UbrT::ok($cmpSame['verdict'] === 'same', 'WA-3. zmiany treści (artykuł, zdjęcie, numer telefonu) → ten sam projekt, nie redesign');
UbrT::ok($cmpOld['verdict'] === 'major' && str_contains($cmpOld['reason'], 'motyw'), 'WA-4. inny motyw / struktura → istotna przebudowa (major)');
UbrT::ok(in_array($cmpMinor['verdict'], ['minor'], true), 'WA-3. drobne zmiany wizualne (nowa sekcja, dodatkowy CSS, pozycja menu) → minor, nie redesign (' . $cmpMinor['verdict'] . ' ' . $cmpMinor['similarity'] . ')');
UbrT::ok($cmpTiny['verdict'] === 'uncertain' && $cmpTiny['similarity'] === null, 'WA-8. za mało cech (strona ze skryptu) → wynik niepewny, bez zgadywania');
UbrT::eq('themes/nowyprojekt/css/main.css', FingerprintExtractor::asset('https://web.archive.org/web/20190101000000/https://x.ubrtest.pl/wp-content/themes/nowyprojekt/css/main.min.css?ver=5.2.1', 'x.ubrtest.pl'), 'WA. ścieżki zasobów bez wersji, .min i przepisania Wayback');

$techOld = TechSignals::analyze("<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\"><html><head><script src='/js/jquery-1.4.2.min.js'></script><script src='/js/swfobject.js'></script></head><body><table><tr><td><table><tr><td>x</td></tr></table></td></tr></table><table><tr><td><table><tr><td>y</td></tr></table></td></tr></table><p>© 2011 Firma</p></body></html>", 'http://x.ubrtest.pl/', [], 2026);
$techNew = TechSignals::analyze("<!doctype html><html><head><meta name='viewport' content='width=device-width'><link rel='stylesheet' href='/css/bootstrap.min.css?ver=5.3.0'></head><body><img loading='lazy' src='a.webp'><div class='wp-block-group'>x</div><p>© 2026</p></body></html>", 'https://x.ubrtest.pl/', ['last-modified' => 'Wed, 01 Oct 2026 10:00:00 GMT'], 2026);
UbrT::ok($techOld['modernity'] === 'dated' && in_array('no_viewport', array_column($techOld['signals'], 'key'), true) && in_array('flash', array_column($techOld['signals'], 'key'), true) && $techOld['copyright_year'] === 2011, 'WA. poszlaki: brak viewport, Flash, układ tabelowy, stary jQuery, stopka 2011 → technicznie przestarzała');
UbrT::ok($techNew['modernity'] === 'modern' && in_array('bootstrap', array_column($techNew['signals'], 'key'), true) && ($techNew['dates'][0]['source'] ?? '') === 'Nagłówek Last-Modified', 'WA. poszlaki: Bootstrap 5, lazy, WebP, bloki WP → nowoczesna; Last-Modified tylko jako metadana');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — Website Age: oszacowanie (AgeEstimator)');

$s = fn (string $ts, string $v, ?float $sim = null, string $reason = '') => ['ts' => $ts, 'verdict' => $v, 'similarity' => $sim, 'reason' => $reason];
$e1 = AgeEstimator::estimate([$s('20260901000000', 'same', 1.0), $s('20251001000000', 'same', 0.95), $s('20240401000000', 'same', 0.9), $s('20231001000000', 'major', 0.05, 'Zmiana motywu strony.'), $s('20061001000000', 'major', 0.02)], [], '20061001000000', 200, [], 'ok', 2026, '20261011000000');
UbrT::ok($e1['launch_from'] === 2023 && $e1['launch_to'] === 2024 && $e1['launch_year'] === null && $e1['status'] === 'NEW' && $e1['last_redesign_year'] === 2024, 'WA-1. nowa strona na starej domenie (2006): wdrożenie 2023–2024, NIE 20 lat; bez arbitralnego roku ze środka');
UbrT::ok($e1['confidence'] === 'MEDIUM' && $e1['first_seen_year'] === 2006 && str_contains(wp_json_encode($e1['evidence'], JSON_UNESCAPED_UNICODE), 'nie jest data rejestracji domeny'), 'WA-1/10. pewność wg odstępu kopii; wiek domeny tylko jako dowód pomocniczy');
$e1b = AgeEstimator::estimate([$s('20260901000000', 'same', 1.0), $s('20240501000000', 'same', 0.92), $s('20240301000000', 'major', 0.1, 'Zmiana motywu strony.')], [], '20100101000000', 120, [], 'ok', 2026, '20261011000000');
UbrT::ok($e1b['launch_year'] === 2024 && $e1b['confidence'] === 'HIGH', 'WA-10. granica w odstępie ≤ 13 mies., jednoznaczne porównania → rok 2024, pewność wysoka');
$e2 = AgeEstimator::estimate([$s('20260901000000', 'same', 1.0), $s('20231001000000', 'same', 0.97), $s('20181001000000', 'same', 0.96), $s('20120301000000', 'same', 0.93)], [], '20120301000000', 150, [], 'ok', 2026, '20261011000000');
UbrT::ok($e2['launch_to'] === 2012 && $e2['launch_from'] === null && $e2['status'] === 'OUTDATED' && $e2['last_redesign_year'] === null && $e2['confidence'] === 'MEDIUM' && $e2['age_years'] === 14, 'WA-2. stara strona bez redesignu: projekt co najmniej od 2012 (14 lat), OUTDATED, redesign nie wykryty');
$e3 = AgeEstimator::estimate([$s('20260901000000', 'same', 0.8), $s('20251001000000', 'same', 0.74), $s('20231001000000', 'same', 0.71), $s('20211001000000', 'minor', 0.6)], [], '20211001000000', 60, [], 'ok', 2026, '20261011000000');
UbrT::ok($e3['last_redesign_year'] === null && $e3['earlier_redesigns'] === 0 && $e3['launch_to'] === 2021, 'WA-3. częste zmiany treści i drobne zmiany wizualne → bez redesignu');
$e4 = AgeEstimator::estimate([$s('20260901000000', 'same', 1.0), $s('20211001000000', 'same', 0.9), $s('20201001000000', 'major', 0.1), $s('20151001000000', 'major', 0.05), $s('20101001000000', 'major', 0.04)], [['newer' => '20201001000000', 'older' => '20151001000000', 'verdict' => 'major'], ['newer' => '20151001000000', 'older' => '20101001000000', 'verdict' => 'same']], '20101001000000', 190, [], 'ok', 2026, '20261011000000');
UbrT::ok($e4['launch_from'] === 2020 && $e4['launch_to'] === 2021 && $e4['earlier_redesigns'] === 1 && $e4['status'] === 'AGING', 'WA-4. strona po kilku redesignach: obecny projekt 2020–2021, wcześniejsza przebudowa w archiwum');
$e5 = AgeEstimator::estimate([], [], null, 0, $techOld, 'empty', 2026);
UbrT::ok($e5['status'] === 'UNKNOWN' && $e5['confidence'] === 'UNKNOWN' && $e5['launch_year'] === null && $e5['modernity'] === 'dated', 'WA-5. brak historii Wayback → wiek nieznany (bez wymyślania dat), nowoczesność techniczna osobno');
$e6 = AgeEstimator::estimate([], [], null, 0, [], 'unavailable', 2026);
UbrT::ok($e6['status'] === 'UNKNOWN' && str_contains(wp_json_encode($e6['evidence'], JSON_UNESCAPED_UNICODE), 'nie odpowiadało'), 'WA-6. archiwum niedostępne → UNKNOWN z uzasadnieniem');
$e7 = AgeEstimator::estimate([$s('20260901000000', 'same', 1.0), $s('20181001000000', 'same', 0.9), $s('20121001000000', 'same', 0.9)], [], '20121001000000', 100, $techNew, 'ok', 2026, '20261011000000');
UbrT::ok($e7['confidence'] === 'LOW' && str_contains(wp_json_encode($e7['evidence'], JSON_UNESCAPED_UNICODE), 'Sprzeczne sygnały'), 'WA-7. sprzeczne dane (projekt z 2012, technologie z 2019–2021) → niższa pewność i oznaczenie do weryfikacji');
$e8 = AgeEstimator::estimate([$s('20260901000000', 'uncertain'), $s('20201001000000', 'error')], [], '20201001000000', 20, [], 'ok', 2026);
UbrT::ok($e8['status'] === 'UNKNOWN' && $e8['launch_to'] === null, 'WA-8. brak możliwości porównania → wiek nieznany');
UbrT::ok(AgeEstimator::short('OUTDATED', null, 2017, 2018, 8) === '~2017–18' && AgeEstimator::short('NEW', 2025, 2025, 2025, 1) === 'nowa' && AgeEstimator::short('OUTDATED', null, null, 2012, 14) === '≥14 lat' && AgeEstimator::short('UNKNOWN', null, null, null, null) === 'wiek nieznany' && AgeEstimator::short('AGING', 2020, 2020, 2020, 6) === '~2020', 'WA. etykiety listy: ~2020 / ~2017–18 / ≥14 lat / nowa / wiek nieznany');
UbrT::ok(WebsiteAgeService::plan([['ts' => '20100105000000', 'digest' => 'a'], ['ts' => '20180605000000', 'digest' => 'b'], ['ts' => '20230905000000', 'digest' => 'c'], ['ts' => '20251005000000', 'digest' => 'd'], ['ts' => '20260905000000', 'digest' => 'e']], '20261011000000') === ['20260905000000', '20251005000000', '20230905000000', '20180605000000', '20100105000000'], 'WA. reprezentatywne kopie (najnowsza, ~1/3/5/8/12 lat, najstarsza), bez pobierania całej historii');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — Website Age: kolejka w tle, archiwum (mock), cache, timeout, ponowienia');

// Cache archiwum z wcześniejszych (np. przerwanych) uruchomień nie może wpływać na wynik testów.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_wb\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ubr\\_wb\\_%' OR option_name LIKE '\\_transient\\_ubr\\_site\\_age\\_work\\_%'");
delete_option('ubr_site_age_day'); // dzienny licznik analiz (limit 150) — testy nie zależą od wcześniejszych uruchomień
$GLOBALS['ubrt_wb'] = [];
$GLOBALS['ubrt_wb_calls'] = 0;
$wbMock = function ($pre, $args, $url) {
	if (! str_starts_with((string) $url, 'https://web.archive.org/')) {
		return $pre;
	}

	$GLOBALS['ubrt_wb_calls']++;
	$json = fn ($code, $body, $ct = 'text/html') => ['headers' => ['content-type' => $ct], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
	$mode = $GLOBALS['ubrt_wb_mode'] ?? 'ok';

	if ($mode === 'timeout') {
		return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 6001 milliseconds');
	}

	if ($mode === 'down') {
		return $json(503, 'Service Unavailable');
	}

	if (str_contains($url, '/cdx/search/cdx')) {
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		$domain = rtrim((string) ($q['url'] ?? ''), '/');
		$rows = [['timestamp', 'digest', 'statuscode', 'mimetype']];

		foreach ((array) ($GLOBALS['ubrt_wb'][$domain]['captures'] ?? []) as [$ts, $digest]) {
			$rows[] = [$ts, $digest, '200', 'text/html'];
		}

		return $json(200, wp_json_encode($rows), 'application/json');
	}

	if (preg_match('#/web/(\d{14})id_/http://([^/]+)/#', $url, $m)) {
		$html = $GLOBALS['ubrt_wb'][$m[2]]['snap']($m[1]) ?? null;

		return $html === null ? $json(404, 'not archived') : $json(200, $html);
	}

	return $json(404, 'unmocked');
};
add_filter('pre_http_request', $wbMock, 4, 3);
add_filter('ubr/wayback/interval_ms', fn () => 0);
add_filter('ubr/site_age/verification_busy', '__return_false', 20);

$ageRunner = app(WebsiteAgeRunner::class);
$ageSvc = app(WebsiteAgeService::class);
$companiesRepo = app(CompanyRepository::class);
$ageWork = function () use ($ageRunner) {
	for ($i = 0; $i < 30; $i++) {
		$r = $ageRunner->tick(30, false);

		if ($r['processed'] === 0) {
			break;
		}
	}
};
$monthly = function (int $fromYear, int $toYear): array {
	$out = [];

	for ($y = $fromYear; $y <= $toYear; $y++) {
		foreach (['01', '04', '07', '10'] as $mo) {
			if ($y === 2026 && $mo === '10') {
				continue;
			}

			$out[] = [$y . $mo . '05000000', 'dg' . $y . $mo];
		}
	}

	return $out;
};
$ageCompany = function (string $key, string $liveHtml) use ($mkVisible, $companiesRepo) {
	$host = 'age-' . $key . '.ubrtest.pl';
	$GLOBALS['ubrt_sites'][$host] = $liveHtml;
	$id = $mkVisible('p1043-age-' . $key, 'Ubrtest Age ' . $key, 'https://' . $host . '/');
	$companiesRepo->update($id, ['domain_status' => 'confirmed', 'verification_status' => 'verified', 'website_status' => 'reachable']);

	return [$id, $host];
};

// 1. Nowa strona (od 2024-03) na domenie archiwizowanej od 2006.
[$a1, $h1] = $ageCompany('nowa', $p13new());
$GLOBALS['ubrt_wb'][$h1] = ['captures' => $monthly(2006, 2026), 'snap' => fn ($ts) => strcmp($ts, '20240301000000') >= 0 ? $GLOBALS['p13newHtml'] : $GLOBALS['p13oldHtml']];
$GLOBALS['p13newHtml'] = $p13new('Archiwalna treść ' . wp_rand(1, 999));
$GLOBALS['p13oldHtml'] = $p13old();
// Tylko firmy z tej grupy w kolejce wieku (wcześniejsze grupy mogły zlecić analizy innych firm testowych).
$db->execute("UPDATE {$db->table(Tables::COMPANIES)} SET site_age_state = NULL WHERE site_age_state IN ('queued', 'running')");
$q1 = $ageSvc->queue($a1);
$c1 = $companiesRepo->find($a1);
UbrT::ok($q1['queued'] && $c1['site_age_state'] === 'queued', 'WA-14. zlecenie analizy wieku tylko ustawia kolejkę (bez zapytań w żądaniu)');
UbrT::eq(0, $GLOBALS['ubrt_wb_calls'], 'WA-14. zlecenie nie wysyła zapytań do archiwum');
$ageWork();
$c1 = $companiesRepo->find($a1);
$ev1 = json_decode((string) $c1['site_age_evidence'], true);
UbrT::ok($c1['site_age_state'] === 'done' && (int) $c1['site_launch_from'] === 2024 && (int) $c1['site_launch_to'] === 2024 && $c1['site_age_status'] === 'NEW', 'WA-1. (archiwum) nowa strona na starej domenie → wdrożenie 2024 po doprecyzowaniu granicy, status NEW (' . $c1['site_launch_from'] . '–' . $c1['site_launch_to'] . ')');
UbrT::ok($GLOBALS['ubrt_wb_calls'] <= 1 + WebsiteAgeService::MAX_SAMPLES + WebsiteAgeService::MAX_REFINE && count($ev1['samples']) <= WebsiteAgeService::MAX_SAMPLES + WebsiteAgeService::MAX_REFINE, 'WA. ograniczona liczba zapytań do archiwum (CDX + maks. 10 kopii; było: ' . $GLOBALS['ubrt_wb_calls'] . ')');
UbrT::ok(in_array('Wayback Machine (Internet Archive)', $ev1['sources'], true) && in_array('Analiza HTML / CSS / JS obecnej strony', $ev1['sources'], true) && $ev1['first_seen_year'] === 2006, 'WA-9. zapisane źródła danych i najstarsza kopia jako dowód');
UbrT::ok(in_array($c1['site_age_confidence'], ['HIGH', 'MEDIUM'], true) && ! empty($c1['site_age_checked_at']), 'WA-10. poziom pewności i data analizy zapisane');

// 11. Cache: ponowna analiza tej samej domeny bez zapytań do archiwum.
$GLOBALS['ubrt_wb_calls'] = 0;
$companiesRepo->update($a1, ['site_age_checked_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)]);
$q1b = $ageSvc->queue($a1, true);
$ageWork();
UbrT::ok($q1b['queued'] && $GLOBALS['ubrt_wb_calls'] === 0 && $companiesRepo->find($a1)['site_age_state'] === 'done', 'WA-11. cache: lista kopii i odciski kopii z pamięci — 0 zapytań przy ponownej analizie');
UbrT::eq('fresh', $ageSvc->queue($a1)['reason'], 'WA. aktualny wynik (< 90 dni) nie jest liczony ponownie bez „Sprawdź ponownie”');
UbrT::eq('cooldown', $ageSvc->queue($a1, true)['reason'], 'WA. „Sprawdź ponownie” ograniczone (24 h)');

// 2. Stara strona bez redesignu.
[$a2, $h2] = $ageCompany('stara', $p13old('Aktualna oferta'));
$GLOBALS['ubrt_wb'][$h2] = ['captures' => $monthly(2012, 2026), 'snap' => fn ($ts) => $GLOBALS['p13oldHtml']];
$ageSvc->queue($a2);
$ageWork();
$c2 = $companiesRepo->find($a2);
UbrT::ok((int) $c2['site_launch_to'] === 2012 && $c2['site_launch_from'] === null && $c2['site_age_status'] === 'OUTDATED' && $c2['site_last_redesign_year'] === null && $c2['site_modernity'] === 'dated', 'WA-2. (archiwum) ten sam projekt od 2012 → co najmniej 14 lat, OUTDATED, bez redesignu, technicznie przestarzała ' . wp_json_encode(array_intersect_key($c2, array_flip(['site_age_state', 'site_launch_from', 'site_launch_to', 'site_age_status', 'site_last_redesign_year', 'site_modernity', 'site_age_confidence']))));

// 4. Kilka redesignów.
[$a4, $h4] = $ageCompany('redesigny', $p13new());
$GLOBALS['ubrt_wb'][$h4] = ['captures' => $monthly(2010, 2026), 'snap' => fn ($ts) => strcmp($ts, '20210101000000') >= 0 ? $GLOBALS['p13newHtml'] : (strcmp($ts, '20160101000000') >= 0 ? $GLOBALS['p13midHtml'] : $GLOBALS['p13oldHtml'])];
$GLOBALS['p13midHtml'] = $p13mid();
$ageSvc->queue($a4);
$ageWork();
$c4 = $companiesRepo->find($a4);
$ev4 = json_decode((string) $c4['site_age_evidence'], true);
UbrT::ok((int) $c4['site_launch_to'] === 2021 && (int) $c4['site_launch_from'] >= 2020 && (int) $ev4['earlier_redesigns'] >= 1, 'WA-4. (archiwum) kilka projektów → obecny od 2020–2021, wcześniejsze przebudowy wykryte (' . $c4['site_launch_from'] . '–' . $c4['site_launch_to'] . ', ' . $ev4['earlier_redesigns'] . ')');

// 5. Brak historii.
[$a5, $h5] = $ageCompany('bezhistorii', $p13new());
$GLOBALS['ubrt_wb'][$h5] = ['captures' => [], 'snap' => fn ($ts) => null];
$ageSvc->queue($a5);
$ageWork();
$c5 = $companiesRepo->find($a5);
UbrT::ok($c5['site_age_state'] === 'done' && $c5['site_age_status'] === 'UNKNOWN' && $c5['site_launch_year'] === null, 'WA-5. (archiwum) brak kopii → UNKNOWN, zakończone bez dat');

// Strona firmy chwilowo nieosiągalna → ponowienie (bez zapisu „nieznany” na 90 dni), po 3 próbach UNKNOWN z uzasadnieniem.
[$aU, $hU] = $ageCompany('nieosiagalna', $p13new());
$companiesRepo->update($aU, ['website_url' => 'https://down.age-nieosiagalna.ubrtest.pl/']); // mock: host down.* → timeout
$GLOBALS['ubrt_wb_calls'] = 0;
$ageSvc->queue($aU);
$ageWork();
$cU = $companiesRepo->find($aU);
UbrT::ok($cU['site_age_state'] === 'queued' && (int) $cU['site_age_attempts'] === 1 && $cU['site_age_checked_at'] === null && $GLOBALS['ubrt_wb_calls'] === 0, 'WA. strona chwilowo nieosiągalna → ponowienie później (próba 1), bez zapytań do archiwum');
for ($i = 0; $i < 2; $i++) {
	$companiesRepo->update($aU, ['site_age_next_at' => gmdate('Y-m-d H:i:s', time() - 5)]);
	$ageWork();
}
$cU = $companiesRepo->find($aU);
UbrT::ok($cU['site_age_state'] === 'failed' && $cU['site_age_status'] === 'UNKNOWN' && str_contains((string) $cU['site_age_evidence'], 'Strona firmy nie odpowiada'), 'WA. po 3 próbach: UNKNOWN z uzasadnieniem „strona nie odpowiadała” (bez wymyślania dat)');

// 6/12/13. Archiwum niedostępne / timeout → ograniczone ponowienia, po 3 próbach UNKNOWN.
[$a6, $h6] = $ageCompany('awaria', $p13new());
$GLOBALS['ubrt_wb'][$h6] = ['captures' => $monthly(2015, 2026), 'snap' => fn ($ts) => $GLOBALS['p13newHtml']];
$GLOBALS['ubrt_wb_mode'] = 'timeout';
$ageSvc->queue($a6);
$ageWork();
$c6 = $companiesRepo->find($a6);
UbrT::ok($c6['site_age_state'] === 'queued' && (int) $c6['site_age_attempts'] === 1 && strtotime($c6['site_age_next_at'] . ' UTC') >= time() + 1700, 'WA-12/13. timeout archiwum → ponowienie za 30 min (próba 1), bez blokowania kolejki');
$GLOBALS['ubrt_wb_mode'] = 'down';
$companiesRepo->update($a6, ['site_age_next_at' => gmdate('Y-m-d H:i:s', time() - 5)]);
$ageWork();
$companiesRepo->update($a6, ['site_age_next_at' => gmdate('Y-m-d H:i:s', time() - 5)]);
$ageWork();
$c6 = $companiesRepo->find($a6);
UbrT::ok($c6['site_age_state'] === 'failed' && $c6['site_age_status'] === 'UNKNOWN' && str_contains((string) $c6['site_age_evidence'], 'nie odpowiada'), 'WA-6/13. HTTP 503 przy kolejnych próbach → po 3 próbach koniec ze statusem UNKNOWN (bez nieskończonych ponowień)');
$GLOBALS['ubrt_wb_mode'] = 'ok';

// 14. Nie blokuje Discover: przy pracującej weryfikacji krok jest odkładany (0 zapytań do archiwum).
[$a14, $h14] = $ageCompany('ustep', $p13new());
$GLOBALS['ubrt_wb'][$h14] = ['captures' => $monthly(2020, 2026), 'snap' => fn ($ts) => $GLOBALS['p13newHtml']];
$ageSvc->queue($a14);
remove_filter('ubr/site_age/verification_busy', '__return_false', 20);
add_filter('ubr/site_age/verification_busy', '__return_true', 21);
$GLOBALS['ubrt_wb_calls'] = 0;
$deferred = $ageRunner->tick(30, false);
UbrT::ok($deferred['deferred'] === true && $GLOBALS['ubrt_wb_calls'] === 0 && $companiesRepo->find($a14)['site_age_state'] === 'queued', 'WA-14. Website Age ustępuje weryfikacji firm (krok odłożony, bez zapytań)');
remove_filter('ubr/site_age/verification_busy', '__return_true', 21);
add_filter('ubr/site_age/verification_busy', '__return_false', 20);
$ageWork();
UbrT::eq('done', $companiesRepo->find($a14)['site_age_state'], 'WA-14. po zakończeniu weryfikacji analiza wieku kończy się w tle');

// 15. Paginacja / filtry / sortowanie nie uruchamiają analizy wieku ani analizy strony.
$pagCo = $mkVisible('p1043-pag', 'Ubrtest Paginacja Wieku', 'https://age-pag.ubrtest.pl/');
$GLOBALS['ubrt_wb_calls'] = 0;
$analysesBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}");
$runsBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_RUNS)}");
foreach ([['per_page' => 10], ['per_page' => 25, 'sort' => 'name'], ['per_page' => 50, 'site_age' => '7'], ['per_page' => 100, 'filter' => 'with_domain', 'site_age' => 'unknown']] as $params) {
	ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', $params);
}
UbrT::ok($companiesRepo->find($pagCo)['site_age_state'] === null && $GLOBALS['ubrt_wb_calls'] === 0, 'WA-15. zmiana paginacji / sortowania / filtrów nie uruchamia Website Age');
UbrT::ok((int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}") === $analysesBefore && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_RUNS)}") === $runsBefore, 'D-11/12/13. paginacja, filtry i sortowanie nie uruchamiają analizy strony ani Decision Maker Findera');

// Filtr wieku na zapisanych wynikach.
$ageRows = fn (string $v) => array_column(ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['per_page' => 100, 'site_age' => $v])['data']['items'] ?? [], 'id');
UbrT::ok(in_array($a2, $ageRows('10'), true) && ! in_array($a1, $ageRows('5'), true) && in_array($a4, $ageRows('5'), true) && ! in_array($a4, $ageRows('7'), true), 'WA. filtr „5+ / 7+ / 10+ lat” działa na zapisanych wynikach (minimalny wiek projektu)');
UbrT::ok(in_array($a5, $ageRows('unknown'), true) && in_array($pagCo, $ageRows('unknown'), true) && ! in_array($a2, $ageRows('unknown'), true), 'WA. filtr „wiek nieznany”: UNKNOWN i niesprawdzone');
$row2 = array_values(array_filter(ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['per_page' => 100])['data']['items'], fn ($r) => $r['id'] === $a2))[0] ?? null;
UbrT::ok(($row2['site_age']['short'] ?? '') === '≥14 lat' && ($row2['stages']['age']['label'] ?? '') === '≥14 lat' && ($row2['stages']['dm']['label'] ?? '') === 'Po przejęciu', 'WA. lista Discover: kompaktowy wiek („≥14 lat”), decydent „Po przejęciu”');
$det = ubrt_rest($uA, 'GET', '/companies/' . $a1);
UbrT::ok(($det['data']['site_age_detail']['launch_label'] ?? '') === '2024' && ($det['data']['site_age_detail']['status_label'] ?? '') === 'Nowy projekt' && ! empty($det['data']['site_age_detail']['evidence']), 'WA. karta firmy: sekcja Website Age Intelligence (wdrożenie, ocena, dowody)');
$manual = ubrt_rest($uA, 'POST', '/companies/' . $pagCo . '/site-age');
$GLOBALS['ubrt_wb_calls'] = 0;
UbrT::ok($manual['status'] === 202 && $manual['data']['queued'] === true && $GLOBALS['ubrt_wb_calls'] === 0, 'WA. „Sprawdź wiek strony” z karty firmy → zlecenie w tle (202), bez zapytań w żądaniu');
UbrT::eq('cooldown', ubrt_rest($uA, 'POST', '/companies/' . $a5 . '/site-age', ['force' => true])['data']['reason'] ?? null, 'WA. „Sprawdź ponownie” tuż po analizie → czas ochronny (bez nowego zlecenia)');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/companies/' . $a5 . '/site-age')['status'], 'WA. inna organizacja nie zleci analizy firmy, której nie widzi');
remove_filter('pre_http_request', $wbMock, 4);

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — Discover: e-mail firmowy, Opportunity Score w tle, Lead Intelligence bez list');

UbrT::eq('biuro@firma.pl', GeneralEmailPicker::pick(['jan.kowalski@firma.pl', 'noreply@firma.pl', 'biuro@firma.pl', 'info@inna.pl', 'handlowy@firma.pl'], 'firma.pl'), 'D-7. ogólny e-mail: biuro@ (nie osobowy, nie noreply, nie spoza domeny)');
UbrT::eq('handlowy@firma.com.pl', GeneralEmailPicker::pick(['rodo@firma.pl', 'faktury@firma.pl', 'handlowy@firma.com.pl'], 'firma.pl'), 'D-7. bez adresów ogólnych → adres działowy (rodo / faktury pominięte)');
UbrT::eq(null, GeneralEmailPicker::pick(['anna.nowak@firma.pl', 'kontakt@innafirma.pl'], 'firma.pl'), 'D-7. adres osobowy nigdy nie jest ogólnym e-mailem firmy');

$GLOBALS['ubrt_sites']['mail-p1043.ubrtest.pl'] = '<html><head><title>Ubrtest Mail</title><meta name="viewport" content="width=device-width"></head><body><nav><a href="/kontakt">Kontakt</a></nav><p>Oferta firmy.</p></body></html>';
$GLOBALS['ubrt_sites']['mail-p1043.ubrtest.pl/kontakt'] = '<html><head><title>Kontakt</title></head><body><p>Napisz: <a href="mailto:kontakt@mail-p1043.ubrtest.pl">kontakt@mail-p1043.ubrtest.pl</a>, prezes: <a href="mailto:jan.prezes@mail-p1043.ubrtest.pl">jan.prezes@mail-p1043.ubrtest.pl</a></p><p>tel. <a href="tel:+48221002031">22 100 20 31</a></p></body></html>';
$mailCo = $mkVisible('p1043-mail', 'Ubrtest Mail Spolka', 'https://mail-p1043.ubrtest.pl/');
$apolloBefore = (int) ($GLOBALS['ubrt_apollo_calls'] ?? 0);
$runsBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d", $mailCo);
app(VerificationQueueRepository::class)->enqueue($mailCo, $orgA, $uA, $jobId);
delete_option(VerificationRunner::LOCK_OPTION);
// Numery firmy są czytane ze stron w PageCache (jak na produkcji) — w tym przypadku cache włączony.
remove_filter('ubr/http/page_cache_enabled', '__return_false');
$p1043sim = fn () => fn (string $url, array $args) => [wp_remote_get($url, $args), 0.0]; // pobieranie równoległe na mockach (bez sieci)
add_filter('ubr/http/parallel_sim', $p1043sim);
app()->forgetInstance(\App\Ubr\Infrastructure\Http\PageCache::class);
app(VerificationRunner::class)->tick(20, false);
remove_filter('ubr/http/parallel_sim', $p1043sim);
add_filter('ubr/http/page_cache_enabled', '__return_false');
$wpdb->query("DELETE FROM {$db->table(Tables::PAGE_CACHE)} WHERE url LIKE '%mail-p1043.ubrtest.pl%'");
$mc = $companiesRepo->find($mailCo);
UbrT::eq('kontakt@mail-p1043.ubrtest.pl', $mc['general_email'], 'D-7. weryfikacja zapisuje ogólny e-mail firmy ze stron (bez dodatkowych zapytań)');
UbrT::ok((int) $mc['phones_count'] >= 1, 'D-6. telefon firmowy z tych samych stron');
$auto = $db->row("SELECT trigger_type, organization_id, user_id, status FROM {$db->table(Tables::ANALYSES)} WHERE company_id = %d ORDER BY id DESC LIMIT 1", $mailCo);
UbrT::ok($auto && $auto['trigger_type'] === 'auto' && (int) $auto['organization_id'] === $orgA && $auto['user_id'] === null, 'D-8. Website Opportunity Score zlecony w tle po weryfikacji (trigger auto, osobny limit)');
UbrT::ok((int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d", $mailCo) === $runsBefore && (int) ($GLOBALS['ubrt_apollo_calls'] ?? 0) === $apolloBefore, 'D-10/15. Discover nie uruchamia Decision Maker Findera ani Apollo');
$mrow = array_values(array_filter(ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['per_page' => 100])['data']['items'], fn ($r) => $r['id'] === $mailCo))[0] ?? null;
UbrT::ok(($mrow['general_email'] ?? '') === 'kontakt@mail-p1043.ubrtest.pl' && ($mrow['stages']['email']['key'] ?? '') === 'found', 'D-7. e-mail i etap „E-mail: Znaleziono” na liście Discover');
UbrT::ok(app(\App\Ubr\Services\AnalysisService::class)->queueAuto($mailCo, $orgA) === false, 'D-8. bez podwójnej analizy (trwająca / aktualna)');

// Lead Intelligence: wyświetlenie listy nie zleca przeliczeń (proces w tle).
wp_clear_scheduled_hook(LeadIntelligenceRunner::CRON_HOOK);
LeadIntelligenceRunner::resetKick();
$liFresh = $mkVisible('p1043-li', 'Ubrtest Lead Bez Oceny', 'https://li-p1043.ubrtest.pl/');
UbrT::ok(app(\App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository::class)->countPending(\App\Ubr\Services\LeadIntelligenceService::VERSION) > 0, 'D-9. (warunek) są firmy bez oceny Lead Intelligence');
ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['per_page' => 25]);
ubrt_rest($uA, 'GET', '/prospects', ['per_page' => 25]);
UbrT::ok(wp_next_scheduled(LeadIntelligenceRunner::CRON_HOOK) === false, 'D-9. lista Discover / Prospects nie uruchamia przeliczenia Lead Intelligence');
LeadIntelligenceRunner::resetKick();
do_action('ubr/company/changed', $liFresh, 'verification_done', null);
UbrT::ok(wp_next_scheduled(LeadIntelligenceRunner::CRON_HOOK) !== false, 'D-9. Lead Intelligence liczone przez proces w tle (zmiana danych firmy)');
wp_clear_scheduled_hook(LeadIntelligenceRunner::CRON_HOOK);

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — Przejmij firmę: przypisanie, analiza decydenta w tle, statusy');

$uF = $mk('ownerF1043');
$uF1 = $mk('memberF1043a');
$uF2 = $mk('memberF1043b');
$orgF = app(OrganizationService::class)->create('ubrtest Org F 10.4.3', $uF);
$created['orgs'][] = $orgF;
app(MemberRepository::class)->add($orgF, $uF1, Role::Member);
app(MemberRepository::class)->add($orgF, $uF2, Role::Member);
$sF = $searches->create(['organization_id' => $orgF, 'user_id' => $uF, 'label' => 'ubrtest P10.4.3', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
$fMk = function (string $key, string $name, string $site, array $fields = []) use ($ingest, $orgF, $sF, $companiesRepo) {
	$cid = $ingest->ingest(ubrt_candidate(['id' => 'p1043-' . $key, 'name' => 'Ubrtest F ' . $name, 'website' => $site]))['company_id'];
	app(ResultRepository::class)->add($sF, $orgF, $cid, 'ubrtest', 10, []);
	$companiesRepo->update($cid, $fields + ['verification_status' => 'verified', 'domain_status' => $site !== '' ? 'confirmed' : 'unavailable', 'verification_checked_at' => Db::now(), 'website_status' => 'reachable']);

	return $cid;
};
$fWork = function () {
	$runner = app(ContactResearchRunner::class);

	for ($i = 0; $i < 10; $i++) {
		if ($runner->tick(false)['processed'] === 0) {
			break;
		}
	}
};
$runsFor = fn (int $cid) => (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}ubr_contact_research_runs WHERE company_id = %d", $cid));

$GLOBALS['ubrt_sites']['claim-a.ubrtest.pl'] = '<html><head><title>Ubrtest F Alfa</title></head><body><nav><a href="/zespol">Zespół</a><a href="/kontakt">Kontakt</a></nav><p>Producent.</p></body></html>';
$GLOBALS['ubrt_sites']['claim-a.ubrtest.pl/zespol'] = '<html><head><title>Zespół</title></head><body><h1>Zespół</h1><div class="person"><h3>Anna Kowalska</h3><p>Dyrektor Marketingu</p><p>tel. bezpośredni: <a href="tel:+48600100201">600 100 201</a></p><a href="mailto:a.kowalska@claim-a.ubrtest.pl">a.kowalska@claim-a.ubrtest.pl</a></div></body></html>';
$GLOBALS['ubrt_sites']['claim-a.ubrtest.pl/kontakt'] = '<html><head><title>Kontakt</title></head><body><p>Biuro: <a href="mailto:biuro@claim-a.ubrtest.pl">biuro@claim-a.ubrtest.pl</a>, centrala <a href="tel:+48221002032">22 100 20 32</a></p></body></html>';
$cA = $fMk('a', 'Alfa', 'https://claim-a.ubrtest.pl/', ['general_email' => 'biuro@claim-a.ubrtest.pl', 'employees_min' => 20, 'employees_max' => 40, 'employees_confidence' => 'declared']);

$cl = ubrt_rest($uF1, 'POST', '/companies/' . $cA . '/claim', ['search_id' => $sF]);
$clPid = (int) ($cl['data']['prospect_id'] ?? 0);
UbrT::ok($cl['status'] === 200 && $cl['data']['claimed'] === true && (int) $db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $clPid) === $uF1, 'CL-1. prawidłowe przejęcie: firma przypisana do pracownika');
UbrT::ok(($cl['data']['automation']['research']['queued'] ?? false) === true && $runsFor($cA) === 1, 'CL-1. przejęcie zleca analizę decydenta w tle (pracownik nie czeka)');
UbrT::ok(($cl['data']['automation']['clickup']['status'] ?? '') === 'disabled', 'CL-7. zadania ClickUp po przejęciu domyślnie wyłączone — przejęcie udane bez ClickUp');
add_filter('ubr/clickup/on_claim', '__return_true', 50);
$GLOBALS['ubrt_sites']['claim-nocu.ubrtest.pl'] = $GLOBALS['ubrt_sites']['claim-a.ubrtest.pl'];
$cNoCu = $fMk('nocu', 'Bez ClickUp', 'https://claim-nocu.ubrtest.pl/');
$clNoCu = ubrt_rest($uF1, 'POST', '/companies/' . $cNoCu . '/claim');
remove_filter('ubr/clickup/on_claim', '__return_true', 50);
UbrT::ok($clNoCu['status'] === 200 && $clNoCu['data']['claimed'] === true && ($clNoCu['data']['automation']['clickup']['status'] ?? '') === 'needs_config', 'CL-7. włączone, ale pracownik bez połączenia ClickUp → „Wymaga konfiguracji”, przejęcie i tak udane');
$db->execute("DELETE FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d", $cNoCu);
$run = $db->row("SELECT trigger_type, stage, status FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d ORDER BY id DESC LIMIT 1", $cA);
UbrT::ok($run['trigger_type'] === 'claim' && $run['stage'] === 'queued' && $run['status'] === 'queued', 'CL. zadanie analizy: trigger „claim”, etap „queued”');
UbrT::eq('pending', ubrt_rest($uF1, 'GET', '/companies/' . $cA . '/claim-status')['data']['research']['key'], 'DM-16. status „Oczekuje na analizę”');

$cl2 = ubrt_rest($uF1, 'POST', '/companies/' . $cA . '/claim');
UbrT::ok($cl2['status'] === 200 && $cl2['data']['claimed'] === false && $cl2['data']['automation'] === null && $runsFor($cA) === 1, 'CL-2. podwójne kliknięcie: bez duplikatu analizy i zadań');
$cl3 = ubrt_rest($uF2, 'POST', '/companies/' . $cA . '/claim');
UbrT::ok($cl3['status'] === 409 && ($cl3['data']['code'] ?? '') === 'ubr_assigned_other' && $runsFor($cA) === 1 && (int) $db->value("SELECT owner_user_id FROM {$db->table(Tables::PROSPECTS)} WHERE id = %d", $clPid) === $uF1, 'CL-3/4. drugi pracownik: 409, właściciel bez zmian, bez nowej analizy');
UbrT::eq('in_progress', app(ContactResearchService::class)->requestForClaim(app(\App\Ubr\Services\TenantResolver::class)->switchTo($uF1, $orgF), $cA)['reason'], 'CL-13. trwająca analiza → bez drugiej (brak duplikatów analiz)');

$fWork();
$st = ubrt_rest($uF1, 'GET', '/companies/' . $cA . '/claim-status')['data']['research'];
$runDone = $db->row("SELECT stage, status FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d ORDER BY id DESC LIMIT 1", $cA);
UbrT::ok($runDone['stage'] === 'done' && in_array($runDone['status'], ['completed', 'partial'], true), 'DM-16. etapy analizy: szukamy decydenta → szukamy telefonu → gotowe');
UbrT::ok(($st['decision_maker']['name'] ?? '') === 'Anna Kowalska' && str_contains((string) ($st['decision_maker']['title'] ?? ''), 'Marketing'), 'DM-3/7. decydent ze strony firmy (dyrektor marketingu) jako najlepszy kontakt');
UbrT::ok(in_array($st['key'], ['direct', 'review', 'no_phone'], true) && ($st['company_phone'] ?? '') !== '' && ($st['general_email'] ?? '') === 'biuro@claim-a.ubrtest.pl', 'DM-16. status po analizie + telefon firmowy i e-mail firmowy w karcie (' . $st['key'] . ')');
UbrT::ok(! $st['direct_phone'] || $st['direct_phone']['e164'] !== '+48221002032', 'DM-11. numer centrali nie jest telefonem decydenta');

UbrT::eq('fresh', app(ContactResearchService::class)->requestForClaim(app(\App\Ubr\Services\TenantResolver::class)->switchTo($uF1, $orgF), $cA)['reason'], 'CL-9. zakończona aktualna analiza nie jest powtarzana');
$retry = ubrt_rest($uF1, 'POST', '/companies/' . $cA . '/claim-research');
UbrT::ok($retry['status'] === 200 && $retry['data']['reason'] === 'cooldown', 'DM-17. „Ponów wyszukiwanie decydenta” zabezpieczone przed wielokrotnym uruchamianiem (cooldown)');
$db->execute("UPDATE {$db->table(Tables::CONTACT_RUNS)} SET finished_at = %s WHERE company_id = %d", gmdate('Y-m-d H:i:s', time() - 7200), $cA);
$retry2 = ubrt_rest($uF1, 'POST', '/companies/' . $cA . '/claim-research');
UbrT::ok($retry2['status'] === 202 && $retry2['data']['queued'] === true && $runsFor($cA) === 2, 'CL-12. ponowienie po czasie ochronnym → nowe zadanie w tle');
$fWork();
// Analiza zakończona błędem: szybkie „Ponów” też ma czas ochronny (krótszy — 10 min), bez serii analiz.
$lastRun = (int) $db->value("SELECT MAX(id) FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d", $cA);
$db->execute("UPDATE {$db->table(Tables::CONTACT_RUNS)} SET status = 'failed', finished_at = %s WHERE id = %d", Db::now(), $lastRun);
UbrT::eq('cooldown', ubrt_rest($uF1, 'POST', '/companies/' . $cA . '/claim-research')['data']['reason'] ?? null, 'DM-17. analiza zakończona błędem → „Ponów” od razu zablokowane (czas ochronny)');
$db->execute("UPDATE {$db->table(Tables::CONTACT_RUNS)} SET finished_at = %s WHERE id = %d", gmdate('Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS), $lastRun);
$retry3 = ubrt_rest($uF1, 'POST', '/companies/' . $cA . '/claim-research');
UbrT::ok(($retry3['data']['queued'] ?? false) === true && $runsFor($cA) === 3, 'DM-17. po błędzie ponowienie możliwe po 10 min');
$fWork();

// Brak decydenta.
$GLOBALS['ubrt_sites']['claim-none.ubrtest.pl'] = '<html><head><title>Ubrtest F Bez</title></head><body><p>Strona bez osób. biuro@claim-none.ubrtest.pl</p></body></html>';
$cNone = $fMk('none', 'Bez Decydenta', 'https://claim-none.ubrtest.pl/');
ubrt_rest($uF1, 'POST', '/companies/' . $cNone . '/claim');
$fWork();
UbrT::eq('none', ubrt_rest($uF1, 'GET', '/companies/' . $cNone . '/claim-status')['data']['research']['key'], 'CL-8. brak decydenta → „Nie znaleziono decydenta”');

// BLOCKED (nieaktywna) i DO_NOT_CONTACT.
// Każda firma testowa z własną domeną (firmy są deduplikowane po domenie).
foreach (['claim-inactive', 'claim-dnc', 'claim-err'] as $h) {
	$GLOBALS['ubrt_sites'][$h . '.ubrtest.pl'] = $GLOBALS['ubrt_sites']['claim-none.ubrtest.pl'];
}
$cInactive = $fMk('inactive', 'Nieaktywna', 'https://claim-inactive.ubrtest.pl/', ['verification_status' => 'inactive']);
$clIn = ubrt_rest($uF1, 'POST', '/companies/' . $cInactive . '/claim');
UbrT::ok($clIn['status'] === 200 && ($clIn['data']['automation']['research']['reason'] ?? '') === 'blocked' && $runsFor($cInactive) === 0, 'CL-5. firma BLOCKED (nieaktywna w rejestrze) → bez automatycznej analizy kontaktów ' . wp_json_encode([$clIn['status'], $clIn['data']['automation'] ?? $clIn['data']['message'] ?? null, $runsFor($cInactive)], JSON_UNESCAPED_UNICODE));
$cDnc = $fMk('dnc', 'Zakaz', 'https://claim-dnc.ubrtest.pl/');
$clD = ubrt_rest($uF1, 'POST', '/companies/' . $cDnc . '/claim');
ubrt_rest($uF1, 'POST', '/prospects/' . (int) $clD['data']['prospect_id'] . '/do-not-contact', ['value' => true, 'reason' => 'test']);
$db->execute("DELETE FROM {$db->table(Tables::CONTACT_RUNS)} WHERE company_id = %d", $cDnc);
UbrT::eq('blocked', app(ContactResearchService::class)->requestForClaim(app(\App\Ubr\Services\TenantResolver::class)->switchTo($uF1, $orgF), $cDnc, true)['reason'], 'CL-6. DO_NOT_CONTACT → bez analizy kontaktów (także „Ponów”)');
$manualDnc = ubrt_rest($uF1, 'POST', '/companies/' . $cDnc . '/contacts/research', ['force' => true]);
UbrT::ok($manualDnc['data']['queued'] === [] && ($manualDnc['data']['skipped'][0]['reason'] ?? '') === 'blocked' && $runsFor($cDnc) === 0, 'CL-6. ręczne wyszukiwanie decydentów też respektuje zakaz kontaktu (pominięte: blocked)');

// Błąd kolejki: przejęcie udane, komunikat o błędzie analizy.
$cErr = $fMk('err', 'Blad Kolejki', 'https://claim-err.ubrtest.pl/');
$real = app(ContactResearchService::class);
$broken = (new ReflectionClass(ContactResearchService::class))->newInstanceWithoutConstructor();
$failing = new class ($broken) extends ContactResearchService {
	public function __construct(private object $inner)
	{
	}

	public function requestForClaim(\App\Ubr\Domain\Tenancy\TenantContext $ctx, int $companyId, bool $retry = false): array
	{
		throw new RuntimeException('kolejka niedostępna (test)');
	}
};
app()->instance(ContactResearchService::class, $failing);
$clErr = ubrt_rest($uF1, 'POST', '/companies/' . $cErr . '/claim');
app()->instance(ContactResearchService::class, $real);
UbrT::ok($clErr['status'] === 200 && $clErr['data']['claimed'] === true && ($clErr['data']['automation']['research']['reason'] ?? '') === 'error', 'CL-11. błąd kolejki nie cofa przejęcia (komunikat + możliwość ponowienia) ' . wp_json_encode([$clErr['status'], $clErr['data']['claimed'] ?? null, $clErr['data']['automation'] ?? $clErr['data']['message'] ?? null], JSON_UNESCAPED_UNICODE));

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — Decision Maker Finder po przejęciu: zapisane Apollo, bez płatnych zapytań');

$GLOBALS['ubrt_sites']['claim-apollo.ubrtest.pl'] = '<html><head><title>Ubrtest F Apollo</title></head><body><p>Firma bez zespołu na stronie.</p></body></html>';
$cAp = $fMk('apollo', 'Apollo Saved', 'https://claim-apollo.ubrtest.pl/');
foreach ([['ap1043aaaa1', 'Marta', 'Nowicka', null, 'Chief Executive Officer'], ['ap1043bbbb2', 'Piotr', '', 'K***i', 'Marketing Director']] as [$pid, $first, $last, $mask, $title]) {
	$db->insert(Tables::APOLLO_PEOPLE, ['company_id' => $cAp, 'apollo_person_id' => $pid, 'first_name' => $first, 'last_name' => $last ?: null, 'last_name_obfuscated' => $mask, 'title' => $title, 'belongs_to_company' => 1, 'found_at' => Db::now(), 'last_seen_at' => Db::now(), 'created_at' => Db::now(), 'updated_at' => Db::now()]);
}
add_filter('ubr/contacts/apollo_people_enabled', '__return_true');
$apBefore = (int) ($GLOBALS['ubrt_apollo_calls'] ?? 0);
ubrt_rest($uF1, 'POST', '/companies/' . $cAp . '/claim');
$fWork();
remove_filter('ubr/contacts/apollo_people_enabled', '__return_true');
$apNames = $db->column("SELECT full_name FROM {$db->table(Tables::CONTACTS)} WHERE company_id = %d", $cAp);
UbrT::ok(in_array('Marta Nowicka', $apNames, true), 'DM-8. zapisany wynik Apollo (pełne nazwisko) wykorzystany jako kandydat');
UbrT::ok(! array_filter($apNames, fn ($n) => str_starts_with((string) $n, 'Piotr')), 'DM-14. zamaskowane nazwisko Apollo nie tworzy kontaktu ani dopasowania');
UbrT::eq($apBefore, (int) ($GLOBALS['ubrt_apollo_calls'] ?? 0), 'DM-15. przejęcie nie wysyła zapytań do Apollo (bez płatnych operacji), mimo włączonego People Search');
$apStatus = ubrt_rest($uF1, 'GET', '/companies/' . $cAp . '/claim-status')['data']['research'];
UbrT::ok(in_array($apStatus['key'], ['review', 'no_phone'], true), 'DM-13. kontakt tylko z Apollo (bez potwierdzenia) → status wymagający weryfikacji / bez telefonu (' . $apStatus['key'] . ')');

/* ------------------------------------------------------------------ */
UbrT::group('P10.4.3 — ClickUp dwuetapowo (mock API)');

add_filter('ubr/clickup/throttle', '__return_false');
$GLOBALS['ubrt_cu2'] = ['calls' => [], 'tasks' => [], 'next' => [], 'seq' => 0, 'fields' => []];
$cuFields = fn (bool $withDmPhone = true) => array_values(array_filter([
	['id' => 'f-contact', 'name' => 'Contact', 'type' => 'short_text'],
	['id' => 'f-cnum', 'name' => 'Contact number', 'type' => 'phone'],
	$withDmPhone ? ['id' => 'f-dmph', 'name' => 'Decision maker phone', 'type' => 'phone'] : null,
	['id' => 'f-email', 'name' => 'Lead email', 'type' => 'email'],
	['id' => 'f-web', 'name' => 'Website', 'type' => 'url'],
	['id' => 'f-src', 'name' => 'Source', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'opt-ubr', 'name' => 'Ugly but Rich', 'orderindex' => 0], ['id' => 'opt-x', 'name' => 'Inne', 'orderindex' => 1]]]],
	['id' => 'f-funnel', 'name' => 'Funnel step', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'opt-leads', 'name' => 'Leads', 'orderindex' => 0], ['id' => 'opt-won', 'name' => 'Won', 'orderindex' => 1]]]],
]));
$GLOBALS['ubrt_cu2']['fields'] = $cuFields(false);
$cu2Mock = function ($pre, $args, $url) {
	if (! str_starts_with((string) $url, 'https://api.clickup.com/api/v2')) {
		return $pre;
	}

	$s = &$GLOBALS['ubrt_cu2'];
	$method = strtoupper($args['method'] ?? 'GET');
	$path = (string) parse_url(substr($url, strlen('https://api.clickup.com/api/v2')), PHP_URL_PATH);
	$body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
	$s['calls'][] = ['method' => $method, 'path' => $path, 'body' => $body];
	$json = fn ($code, $data) => ['headers' => [], 'body' => wp_json_encode($data), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

	foreach ($s['next'] as $i => $n) {
		if (str_starts_with($method . ' ' . $path, $n['match'])) {
			array_splice($s['next'], $i, 1);

			if ($n['resp'] === 'timeout') {
				if (! empty($n['create_anyway'])) {
					$id = 'tk' . (++$s['seq']);
					$s['tasks'][$id] = ['name' => $body['name'] ?? '', 'md' => $body['markdown_content'] ?? '', 'assignees' => $body['assignees'] ?? [], 'fields' => [], 'created' => time()];
				}

				return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds');
			}

			return $json($n['resp'], ['err' => 'mock', 'ECODE' => 'MOCK_' . $n['resp']]);
		}
	}

	$custom = function (array $t) use ($s) {
		$out = [];

		foreach ($s['fields'] as $f) {
			$out[] = $f + ['value' => $t['fields'][$f['id']] ?? null];
		}

		return $out;
	};

	if ($method === 'GET' && preg_match('#^/list/L9/field$#', $path)) {
		return $json(200, ['fields' => $s['fields']]);
	}

	if ($method === 'POST' && $path === '/list/L9/task') {
		$id = 'tk' . (++$s['seq']);
		$s['tasks'][$id] = ['name' => $body['name'] ?? '', 'md' => $body['markdown_content'] ?? '', 'assignees' => $body['assignees'] ?? [], 'fields' => [], 'created' => time()];

		return $json(200, ['id' => $id, 'url' => 'https://app.clickup.com/t/' . $id]);
	}

	if ($method === 'GET' && $path === '/list/L9/task') {
		$tasks = [];

		foreach (array_reverse($s['tasks'], true) as $id => $t) {
			$tasks[] = ['id' => $id, 'name' => $t['name'], 'markdown_description' => $t['md'], 'url' => 'https://app.clickup.com/t/' . $id, 'custom_fields' => $custom($t)];
		}

		return $json(200, ['tasks' => $tasks]);
	}

	if (preg_match('#^/task/(tk\d+)$#', $path, $m) && isset($s['tasks'][$m[1]])) {
		if ($method === 'PUT') {
			$s['tasks'][$m[1]]['md'] = $body['markdown_content'] ?? $s['tasks'][$m[1]]['md'];
		}

		$t = $s['tasks'][$m[1]];

		return $json(200, ['id' => $m[1], 'name' => $t['name'], 'markdown_description' => $t['md'], 'custom_fields' => $custom($t)]);
	}

	if ($method === 'POST' && preg_match('#^/task/(tk\d+)/field/([a-z\-]+)$#', $path, $m) && isset($s['tasks'][$m[1]])) {
		$s['tasks'][$m[1]]['fields'][$m[2]] = $body['value'] ?? null;

		return $json(200, []);
	}

	return $json(404, ['err' => 'unmocked ' . $method . ' ' . $path, 'ECODE' => 'MOCK_404']);
};
add_filter('pre_http_request', $cu2Mock, 3, 3);
$cu2Calls = fn (string $prefix) => count(array_filter($GLOBALS['ubrt_cu2']['calls'], fn ($c) => str_starts_with($c['method'] . ' ' . $c['path'], $prefix)));
$cu2Repo = app(ClickUpRepository::class);
$cu2Run = function () {
	delete_option('ubr_clickup_lock');

	return app(ClickUpRunner::class)->tick(false, 30);
};
$box = new SecretBox();
$connF1 = $cu2Repo->saveConnection($orgF, $uF1, ['status' => 'connected', 'token_ciphertext' => $box->encrypt('tok-f1-test'), 'key_id' => SecretBox::keyId(), 'clickup_user_id' => '555', 'clickup_username' => 'ubrtest-f1', 'authorized_teams' => [['id' => '901', 'name' => 'T']], 'destination' => ['team_id' => '901', 'team_name' => 'T', 'space_id' => 'S1', 'space_name' => 'S', 'folder_id' => '', 'folder_name' => '', 'list_id' => 'L9', 'list_name' => 'Leads'], 'settings' => [], 'connected_at' => Db::now()]);

// Brak pola „Decision maker phone” → propozycja konfiguracji (bez tworzenia pól).
$opts = ubrt_rest($uF1, 'GET', '/clickup/options', ['level' => 'fields', 'parent_id' => 'L9']);
UbrT::ok(in_array('decision_maker_phone', array_column($opts['data']['missing'] ?? [], 'key'), true) && ($opts['data']['missing'][array_search('decision_maker_phone', array_column($opts['data']['missing'], 'key'))]['proposed_type'] ?? '') === 'phone' && $cu2Calls('POST /list/L9/field') === 0, 'CU-17. brak pola „Decision maker phone” → propozycja (typ Phone), bez tworzenia pól w ClickUp');
$GLOBALS['ubrt_cu2']['fields'] = $cuFields(true);
$opts = ubrt_rest($uF1, 'GET', '/clickup/options', ['level' => 'fields', 'parent_id' => 'L9']);
$sug = $opts['data']['suggested'] ?? [];
UbrT::ok(($sug['contact'] ?? '') === 'f-contact' && ($sug['contact_number'] ?? '') === 'f-cnum' && ($sug['decision_maker_phone'] ?? '') === 'f-dmph' && ($sug['lead_email'] ?? '') === 'f-email' && ($sug['source'] ?? '') === 'f-src' && ($sug['funnel_step'] ?? '') === 'f-funnel' && ($sug['website'] ?? '') === 'f-web', 'CU. propozycja mapowania po nazwach pól listy (Contact, Contact number, Decision maker phone, Lead email, Website, Source, Funnel step)');
$saveMap = ubrt_rest($uF1, 'POST', '/clickup/settings', ['field_mapping' => $sug]);
UbrT::eq(200, $saveMap['status'], 'CU. mapowanie zapisane po zatwierdzeniu (tylko pola istniejące na liście)');

// Domyślnie WYŁĄCZONE: wdrożenie nie zaczyna samo tworzyć zadań w ClickUp — włącza właściciel / admin organizacji.
$GLOBALS['ubrt_sites']['claim-off.ubrtest.pl'] = $GLOBALS['ubrt_sites']['claim-none.ubrtest.pl'];
$cOff = $fMk('cuoff', 'ClickUp Wylaczone', 'https://claim-off.ubrtest.pl/');
$postsOff = $cu2Calls('POST /list/L9/task');
$clOff = ubrt_rest($uF1, 'POST', '/companies/' . $cOff . '/claim');
$cu2Run();
UbrT::ok(($clOff['data']['automation']['clickup']['status'] ?? '') === 'disabled' && $cu2Calls('POST /list/L9/task') === $postsOff, 'CU. zadania po przejęciu domyślnie wyłączone — przejęcie bez zadania ClickUp');
UbrT::eq('disabled', ubrt_rest($uF1, 'GET', '/companies/' . $cOff . '/claim-status')['data']['clickup']['key'] ?? null, 'CU. karta firmy: ClickUp „Wyłączone” (z informacją, kto włącza)');
$stF1 = ubrt_rest($uF1, 'GET', '/clickup/status')['data']['claim_sync'] ?? [];
UbrT::ok(($stF1['enabled'] ?? null) === false && ($stF1['can_manage'] ?? null) === false, 'CU. status w Settings: wyłączone; pracownik nie zmienia ustawienia organizacji');
UbrT::eq(403, ubrt_rest($uF1, 'POST', '/clickup/claim-sync', ['enabled' => true])['status'], 'CU. włączenie przez pracownika (member) → 403');
$onF = ubrt_rest($uF, 'POST', '/clickup/claim-sync', ['enabled' => true]);
UbrT::ok($onF['status'] === 200 && $onF['data']['enabled'] === true && ubrt_rest($uF1, 'GET', '/clickup/status')['data']['claim_sync']['enabled'] === true, 'CU. właściciel organizacji włącza zadania po przejęciu (Settings → ClickUp)');
UbrT::eq(422 <= (int) ubrt_rest($uF, 'POST', '/clickup/claim-sync', ['use_my_connection' => true])['status'] ? 1 : 0, 1, 'CU. „moje połączenie dla pracowników” wymaga własnego połączenia z listą');

// Etap 1: przejęcie → zadanie.
$GLOBALS['ubrt_sites']['claim-cu.ubrtest.pl'] = $GLOBALS['ubrt_sites']['claim-a.ubrtest.pl'];
$GLOBALS['ubrt_sites']['claim-cu.ubrtest.pl/zespol'] = str_replace('claim-a', 'claim-cu', $GLOBALS['ubrt_sites']['claim-a.ubrtest.pl/zespol']);
$GLOBALS['ubrt_sites']['claim-cu.ubrtest.pl/kontakt'] = str_replace(['claim-a', '22 100 20 32', '+48221002032'], ['claim-cu', '22 100 20 33', '+48221002033'], $GLOBALS['ubrt_sites']['claim-a.ubrtest.pl/kontakt']);
$cCu = $fMk('cu', 'ClickUp Sp z oo', 'https://claim-cu.ubrtest.pl/', ['general_email' => 'biuro@claim-cu.ubrtest.pl']);
$db->insert(Tables::COMPANY_PHONES, ['company_id' => $cCu, 'org_key' => 0, 'raw_number' => '22 100 20 33', 'e164' => '+48221002033', 'country_code' => 'PL', 'phone_type' => 'MAIN', 'source' => 'TEL_LINK', 'source_url' => 'https://claim-cu.ubrtest.pl/kontakt', 'page_kind' => 'contact', 'confidence' => 'high', 'is_primary' => 1, 'manual' => 0, 'found_at' => Db::now(), 'last_seen_at' => Db::now(), 'created_at' => Db::now(), 'updated_at' => Db::now()]);
$clCu = ubrt_rest($uF1, 'POST', '/companies/' . $cCu . '/claim');
UbrT::ok($clCu['status'] === 200 && ($clCu['data']['automation']['clickup']['status'] ?? '') === 'queued', 'CU-1. przejęcie zleca zadanie ClickUp w tle');
$cu2Run();
$exp = $cu2Repo->exportFor($orgF, $cCu, 'L9');
$task = $GLOBALS['ubrt_cu2']['tasks'][$exp['task_id'] ?? ''] ?? null;
UbrT::ok($exp && $exp['export_status'] === 'success' && $task && $task['name'] === 'Ubrtest F ClickUp Sp z oo', 'CU-1/2. zadanie utworzone, nazwa = nazwa firmy');
UbrT::ok(($task['fields']['f-cnum'] ?? '') === '+48221002033' && ($task['fields']['f-email'] ?? '') === 'biuro@claim-cu.ubrtest.pl' && ($task['fields']['f-web'] ?? '') === 'https://claim-cu.ubrtest.pl/' && ($task['fields']['f-src'] ?? '') === 'opt-ubr' && ($task['fields']['f-funnel'] ?? '') === 'opt-leads', 'CU-3/5/6/7/8. Contact number (telefon firmowy), Lead email, Website, Source = Ugly but Rich, Funnel step = Leads');
UbrT::ok(! isset($task['fields']['f-contact']) && ! isset($task['fields']['f-dmph']), 'CU-4. przed analizą: Contact i Decision maker phone puste (bez kopiowania telefonu firmowego)');
UbrT::ok(($task['assignees'] ?? []) === [555] && str_contains($task['md'], 'ubr-ref: o' . $orgF . '-c' . $cCu . '-e' . $exp['id']), 'CU-9. przypisany pracownik (jego konto ClickUp) i znacznik idempotencji w opisie');
UbrT::eq('added', ubrt_rest($uF1, 'GET', '/companies/' . $cCu . '/claim-status')['data']['clickup']['key'], 'CU. status karty: „Dodano do ClickUp”');

// Etap 2: decydent znaleziony → aktualizacja TEGO SAMEGO zadania.
$tasksBefore = count($GLOBALS['ubrt_cu2']['tasks']);
$fWork();
$cu2Run();
$task = $GLOBALS['ubrt_cu2']['tasks'][$exp['task_id']];
$stCu = ubrt_rest($uF1, 'GET', '/companies/' . $cCu . '/claim-status')['data'];
UbrT::ok(count($GLOBALS['ubrt_cu2']['tasks']) === $tasksBefore && ($task['fields']['f-contact'] ?? '') === 'Anna Kowalska', 'CU-10/12. po znalezieniu decydenta: Contact uzupełniony w TYM SAMYM zadaniu (bez nowego zadania)');
$dmOk = ($stCu['research']['direct_phone']['confirmed'] ?? false) === true;
UbrT::ok($dmOk ? ($task['fields']['f-dmph'] ?? '') === $stCu['research']['direct_phone']['e164'] && $task['fields']['f-dmph'] !== '+48221002033' : ! isset($task['fields']['f-dmph']), 'CU-11. Decision maker phone tylko z potwierdzonym powiązaniem z osobą (nigdy telefon firmowy) — ' . ($dmOk ? 'uzupełniony' : 'pusty, numer niepewny'));
UbrT::ok(str_contains($task['md'], 'Anna Kowalska') && str_contains($task['md'], 'Dyrektor Marketingu'), 'CU. stanowisko decydenta w opisie zadania (pole Contact = tylko imię i nazwisko)');
UbrT::eq('updated', $stCu['clickup']['key'], 'CU. status karty: „Zaktualizowano ClickUp”');

// Ochrona ręcznych zmian.
$GLOBALS['ubrt_cu2']['tasks'][$exp['task_id']]['fields']['f-contact'] = 'Jan Ręczny';
$GLOBALS['ubrt_cu2']['tasks'][$exp['task_id']]['fields']['f-cnum'] = '';
app(ClickUpExportService::class)->queueClaimUpdate($cCu);
$cu2Run();
$task = $GLOBALS['ubrt_cu2']['tasks'][$exp['task_id']];
$expNow = $cu2Repo->exportFor($orgF, $cCu, 'L9');
UbrT::ok(($task['fields']['f-contact'] ?? '') === 'Jan Ręczny' && str_contains((string) $expNow['conflicts'], '"field":"contact"'), 'CU-13. ręcznie zmieniony Contact NIE nadpisany; konflikt synchronizacji zapisany');
UbrT::eq('+48221002033', $task['fields']['f-cnum'] ?? '', 'CU-20. ręcznie wyczyszczone pole uzupełnione (bez usuwania danych użytkownika)');
UbrT::ok(count(ubrt_rest($uF1, 'GET', '/companies/' . $cCu . '/claim-status')['data']['clickup']['conflicts']) >= 1, 'CU-13. konflikt widoczny w karcie firmy');

// Istniejące zadanie: ponowne przejęcie / synchronizacja nie tworzy nowego.
$again = app(ClickUpExportService::class)->queueForClaim(app(\App\Ubr\Services\TenantResolver::class)->switchTo($uF1, $orgF), $cCu);
$cu2Run();
UbrT::ok($again['status'] === 'linked' && count($GLOBALS['ubrt_cu2']['tasks']) === $tasksBefore && $cu2Calls('POST /list/L9/task') === 1, 'CU-12/18/19. istniejące powiązanie → aktualizacja tego zadania, bez duplikatu');

// Timeout przy tworzeniu: zadanie powstało → odnalezione po znaczniku, bez drugiego POST.
foreach (['claim-t1', 'claim-t2', 'claim-nomap'] as $h) {
	$GLOBALS['ubrt_sites'][$h . '.ubrtest.pl'] = $GLOBALS['ubrt_sites']['claim-none.ubrtest.pl'];
}
$cT = $fMk('timeout', 'Timeout Utworzone', 'https://claim-t1.ubrtest.pl/');
$GLOBALS['ubrt_cu2']['next'][] = ['match' => 'POST /list/L9/task', 'resp' => 'timeout', 'create_anyway' => true];
ubrt_rest($uF1, 'POST', '/companies/' . $cT . '/claim');
$cu2Run();
$expT = $cu2Repo->exportFor($orgF, $cT, 'L9');
UbrT::ok($expT['export_status'] === 'unknown' && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE export_id = %d AND operation = 'reconcile' AND status = 'queued'", $expT['id']) === 1, 'CU-14. timeout po wysłaniu → stan nieznany i sprawdzenie wyniku (bez ponownego POST)');
$db->execute("UPDATE {$db->table(Tables::CLICKUP_JOBS)} SET next_attempt_at = %s WHERE export_id = %d AND operation = 'reconcile'", gmdate('Y-m-d H:i:s', time() - 1), $expT['id']);
$postsT = $cu2Calls('POST /list/L9/task');
$cu2Run();
$expT = $cu2Repo->exportFor($orgF, $cT, 'L9');
UbrT::ok($expT['export_status'] === 'success' && ! empty($expT['task_id']) && $cu2Calls('POST /list/L9/task') === $postsT, 'CU-14/18. zadanie odnalezione po znaczniku ubr-ref (nie po domenie) — powiązane bez duplikatu');

// Timeout przy tworzeniu: zadanie NIE powstało → 2 sprawdzenia, potem jednorazowe ponowne utworzenie.
$cT2 = $fMk('timeout2', 'Timeout Nieutworzone', 'https://claim-t2.ubrtest.pl/');
$GLOBALS['ubrt_cu2']['next'][] = ['match' => 'POST /list/L9/task', 'resp' => 'timeout'];
ubrt_rest($uF1, 'POST', '/companies/' . $cT2 . '/claim');
$cu2Run();
$expT2 = $cu2Repo->exportFor($orgF, $cT2, 'L9');
for ($i = 0; $i < 4; $i++) {
	$db->execute("UPDATE {$db->table(Tables::CLICKUP_JOBS)} SET next_attempt_at = %s WHERE export_id = %d AND status = 'queued'", gmdate('Y-m-d H:i:s', time() - 1), $expT2['id']);
	$cu2Run();
}
$expT2 = $cu2Repo->exportFor($orgF, $cT2, 'L9');
$tasksT2 = array_filter($GLOBALS['ubrt_cu2']['tasks'], fn ($t) => $t['name'] === 'Ubrtest F Timeout Nieutworzone');
UbrT::ok($expT2['export_status'] === 'success' && count($tasksT2) === 1, 'CU-15. po dwóch negatywnych sprawdzeniach jednorazowe ponowne utworzenie → dokładnie jedno zadanie');

// Retry przy błędzie 500 (aktualizacja) i zachowanie danych po błędzie.
$GLOBALS['ubrt_cu2']['next'][] = ['match' => 'GET /task/' . $exp['task_id'], 'resp' => 500];
app(ClickUpExportService::class)->queueClaimUpdate($cCu);
$cu2Run();
$retryJob = $db->row("SELECT status, attempts FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE export_id = %d AND operation = 'update' ORDER BY id DESC LIMIT 1", $exp['id']);
UbrT::ok($retryJob['status'] === 'queued' && $cu2Repo->exportFor($orgF, $cCu, 'L9')['export_status'] === 'success', 'CU-15/20. błąd 500 → ponowienie automatyczne; powiązanie i dane zachowane');
$db->execute("UPDATE {$db->table(Tables::CLICKUP_JOBS)} SET next_attempt_at = %s WHERE export_id = %d AND status = 'queued'", gmdate('Y-m-d H:i:s', time() - 1), $exp['id']);
$cu2Run();
UbrT::eq('success', $db->value("SELECT status FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE export_id = %d AND operation = 'update' ORDER BY id DESC LIMIT 1", $exp['id']), 'CU-15. ponowienie zakończone sukcesem');

// Brak mapowania użytkownika: połączenie wskazane przez właściciela organizacji (inny pracownik).
app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class)->update($orgF, ['settings' => ['clickup_on_claim' => true, 'clickup_claim_connection_id' => $connF1]]);
$cMap = $fMk('nomap', 'Bez Mapowania', 'https://claim-nomap.ubrtest.pl/');
$clMap = ubrt_rest($uF2, 'POST', '/companies/' . $cMap . '/claim');
$cu2Run();
$expMap = $cu2Repo->exportFor($orgF, $cMap, 'L9');
$taskMap = $GLOBALS['ubrt_cu2']['tasks'][$expMap['task_id'] ?? ''] ?? null;
UbrT::ok(($clMap['data']['automation']['clickup']['status'] ?? '') === 'queued' && $taskMap && ($taskMap['assignees'] ?? []) === [], 'CU-16. pracownik bez połączenia ClickUp: zadanie przez połączenie organizacji, bez przypisanej osoby (brak mapowania)');
UbrT::ok(str_contains((string) $db->value("SELECT result FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE export_id = %d AND operation = 'create' ORDER BY id DESC LIMIT 1", $expMap['id']), 'Brak mapowania pracownika'), 'CU-16. informacja o braku mapowania w historii operacji');

remove_filter('pre_http_request', $cu2Mock, 3);
remove_filter('ubr/clickup/throttle', '__return_false');
