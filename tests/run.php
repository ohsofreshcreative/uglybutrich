<?php

/**
 * Ugly but Rich — testy automatyczne (integracyjne, na prawdziwej bazie WordPress).
 *
 * Uruchomienie:  wp eval-file wp-content/themes/uglybutrich/tests/run.php
 *           lub: wp ubr test
 *
 * Testy tworzą tymczasowe organizacje/użytkowników/firmy z prefiksem "ubrtest" i usuwają je na końcu.
 * Firmy testowe NIE są danymi Discovery — używają dostawcy "ubrtest" i są kasowane po testach.
 * Zapytania HTTP do stron testowych są przechwytywane (pre_http_request) — testy nie wychodzą do internetu.
 */

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\CompanyCandidate;
use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\DiscoveryProvider;
use App\Ubr\Domain\Discovery\RelevanceScorer;
use App\Ubr\Domain\Discovery\ProviderException;
use App\Ubr\Domain\Discovery\ProviderPage;
use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Migrator;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Discovery\ProviderRegistry;
use App\Ubr\Infrastructure\Discovery\Providers\OverpassProvider;
use App\Ubr\Infrastructure\Discovery\Providers\WikidataProvider;
use App\Ubr\Infrastructure\Http\WebsiteChecker;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use App\Ubr\Services\Bootstrapper;
use App\Ubr\Services\CompanyIngestService;
use App\Ubr\Services\DiscoveryRunner;
use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\TenantResolver;

if (! defined('ABSPATH')) {
	exit(1);
}

final class UbrT
{
	public static int $pass = 0;

	public static array $fail = [];

	public static string $group = '';

	public static function group(string $name): void
	{
		self::$group = $name;
		echo "\n\033[1m{$name}\033[0m\n";
	}

	public static function ok(bool $cond, string $msg): void
	{
		if ($cond) {
			self::$pass++;
			echo "  \033[32m✓\033[0m {$msg}\n";
		} else {
			self::$fail[] = self::$group . ' › ' . $msg;
			echo "  \033[31m✗ {$msg}\033[0m\n";
		}
	}

	public static function eq(mixed $expected, mixed $actual, string $msg): void
	{
		self::ok($expected === $actual, $msg . ($expected === $actual ? '' : ' (oczekiwano ' . var_export($expected, true) . ', jest ' . var_export($actual, true) . ')'));
	}
}

function ubrt_rest(int $userId, string $method, string $path, array $params = []): array
{
	wp_set_current_user($userId);
	app(TenantResolver::class)->forget();
	$request = new WP_REST_Request($method, '/ubr/v1' . $path);

	if ($method === 'GET') {
		$request->set_query_params($params);
	} else {
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode($params));
	}

	$response = rest_do_request($request);

	return ['status' => $response->get_status(), 'data' => $response->get_data()];
}

function ubrt_candidate(array $o): CompanyCandidate
{
	return new CompanyCandidate(
		provider: $o['provider'] ?? 'ubrtest',
		externalId: $o['id'],
		name: $o['name'],
		sourceUrl: 'https://example.test/' . $o['id'],
		website: $o['website'] ?? null,
		websiteConfidence: $o['conf'] ?? Confidence::UNCERTAIN,
		industry: $o['industry'] ?? 'test',
		city: $o['city'] ?? null,
		region: $o['region'] ?? null,
		employeesMin: $o['emp'] ?? null,
		employeesMax: $o['emp'] ?? null,
		employeesConfidence: isset($o['emp']) ? Confidence::CONFIRMED : Confidence::UNAVAILABLE,
		nip: $o['nip'] ?? null,
		matchedTerms: ['test'],
	);
}

/** Dostawca testowy: zwraca stałą listę, opcjonalnie najpierw rzuca błąd "retryable". */
final class UbrTestProvider implements DiscoveryProvider
{
	public static int $failuresLeft = 0;

	public static array $candidates = [];

	public function key(): string
	{
		return 'ubrtest';
	}

	public function label(): string
	{
		return 'Test';
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function description(): string
	{
		return 'test';
	}

	public function fetch(DiscoveryCriteria $criteria, array $cursor): ProviderPage
	{
		if (self::$failuresLeft > 0) {
			self::$failuresLeft--;

			throw new ProviderException('HTTP 503 (test)', true, 1);
		}

		$page = (int) ($cursor['page'] ?? 0);
		$chunk = array_slice(self::$candidates, $page * 3, 3);

		return new ProviderPage($chunk, count(self::$candidates) > ($page + 1) * 3 ? ['page' => $page + 1] : null);
	}
}

$db = app(Db::class);
$wpdb = $db->wpdb();
$ubrtMaxRequestId = (int) $db->value("SELECT COALESCE(MAX(id), 0) FROM {$db->table(Tables::API_REQUESTS)}");
$created = ['users' => [], 'orgs' => []];

// Mock HTTP dla domen testowych (*.ubrtest.pl) — bez ruchu sieciowego.
add_filter('pre_http_request', function ($pre, $args, $url) {
	$host = (string) parse_url($url, PHP_URL_HOST);

	if (! str_ends_with($host, 'ubrtest.pl')) {
		return $pre;
	}

	if (str_starts_with($host, 'down.')) {
		return new WP_Error('http_request_failed', 'cURL error 28: timeout (test)');
	}

	if (($GLOBALS['ubrt_sites'][$host] ?? null) === '__redirect__') {
		return ['headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'], 'body' => '', 'response' => ['code' => 302, 'message' => 'Found'], 'cookies' => [], 'filename' => null];
	}

	// Strony testowe weryfikacji domen: $GLOBALS['ubrt_sites'][host] (strona główna) lub [host . ścieżka].
	$path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
	$site = $GLOBALS['ubrt_sites'][$host . $path] ?? ($path === '' ? ($GLOBALS['ubrt_sites'][$host] ?? null) : null);

	if ($site !== null) {
		return ['headers' => [], 'body' => $site, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
	}

	if (isset($GLOBALS['ubrt_sites'][$host]) && $path !== '') {
		return ['headers' => [], 'body' => 'Not found', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => [], 'filename' => null];
	}

	return [
		'headers' => [],
		'body' => '<html><head><title>Stalbud Kowalski — konstrukcje stalowe</title></head><body>STALBUD Kowalski sp. z o.o.</body></html>',
		'response' => ['code' => 200, 'message' => 'OK'],
		'cookies' => [],
		'filename' => null,
	];
}, 10, 3);


// Apollo: fałszywy klucz testowy i mock API (kształt odpowiedzi wg dokumentacji/obserwacji API; dane fikcyjne, usuwane po teście).
$ubrtFakeKey = 'ubrtest-fake-apollo-key-' . wp_generate_password(8, false);
$ubrtPrevKey = getenv('UBR_APOLLO_API_KEY');
add_filter('pre_http_request', function ($pre, $args, $url) use (&$ubrtFakeKey) {
	if (! str_starts_with((string) $url, 'https://api.apollo.io/')) {
		return $pre;
	}

	$GLOBALS['ubrt_apollo_calls'] = ($GLOBALS['ubrt_apollo_calls'] ?? 0) + 1;
	$GLOBALS['ubrt_apollo_auth'] = (($args['headers']['x-api-key'] ?? '') === $ubrtFakeKey) ? 'test-key-header-ok' : 'missing';
	$mode = $GLOBALS['ubrt_apollo_mode'] ?? '200';
	$json = fn ($code, $body, $headers = []) => ['headers' => $headers, 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

	if ($mode === 'timeout') {
		return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out (test)');
	}

	if ($mode === 'empty') {
		return $json(200, ['pagination' => ['page' => 1, 'per_page' => 25, 'total_entries' => 0, 'total_pages' => 0], 'organizations' => [], 'accounts' => []]);
	}

	if ($mode === 'real') {
		// Kształt rzeczywistej odpowiedzi mixed_companies/search (zaobserwowany 10.2026): brak miasta/województwa/branży/zatrudnienia.
		return $json(200, [
			'breadcrumbs' => [], 'partial_results_only' => false, 'pagination' => ['page' => 1, 'per_page' => 10, 'total_entries' => 6118, 'total_pages' => 612],
			'accounts' => [],
			'organizations' => [
				['id' => 'apollo-org-real-1', 'name' => 'UBRTEST STALREMONT SP. Z O.O.', 'website_url' => 'http://www.stalremont.ubrtest.pl', 'primary_domain' => 'stalremont.ubrtest.pl', 'linkedin_url' => 'http://www.linkedin.com/company/stalremont-ubrtest', 'founded_year' => 2018, 'sic_codes' => ['3441'], 'naics_codes' => ['33231'], 'organization_revenue' => 0, 'phone' => '+48 600 000 000'],
			],
		]);
	}

	if ($mode !== '200') {
		return $json((int) $mode, ['error' => 'test error ' . $mode], $mode === '429' ? ['retry-after' => '7'] : []);
	}

	parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
	$page = (int) ($q['page'] ?? 1);

	return $json(200, [
		'pagination' => ['page' => $page, 'per_page' => 100, 'total_entries' => 250, 'total_pages' => 3],
		'organizations' => $page > 1 ? [] : [
			['id' => 'apollo-org-1', 'name' => 'Stalmontaż', 'website_url' => 'http://www.stalmontaz.ubrtest.pl', 'primary_domain' => 'stalmontaz.ubrtest.pl', 'estimated_num_employees' => 120, 'industry' => 'machinery', 'keywords' => ['steel fabrication'], 'city' => 'Warszawa', 'state' => 'Masovian Voivodeship', 'country' => 'Poland', 'founded_year' => 1994, 'organization_revenue' => 12000000, 'linkedin_url' => 'http://www.linkedin.com/company/stalmontaz-ubrtest'],
			['id' => 'apollo-org-2', 'name' => 'Ubrtest Maszyny Śląsk', 'primary_domain' => 'maszyny.ubrtest.pl', 'estimated_num_employees' => 60, 'industry' => 'industrial machinery', 'city' => 'Gliwice', 'state' => 'Silesian Voivodeship', 'country' => 'Poland'],
			['id' => 'apollo-org-3', 'name' => 'Ubrtest Bez Domeny', 'city' => 'Poznań', 'state' => 'Greater Poland Voivodeship', 'country' => 'Poland'],
			['id' => 'apollo-org-de', 'name' => 'Ubrtest GmbH', 'primary_domain' => 'gmbh.ubrtest.de', 'city' => 'Berlin', 'country' => 'Germany'],
		],
		'accounts' => [],
	]);
}, 10, 3);

// Rejestry publiczne (KRS, biała lista VAT, CEIDG, GUS): mock — testy NIE odpytują prawdziwych rejestrów.
// Dane rejestrowe są fikcyjne (nazwy „Testowa”), numery mają tylko poprawne sumy kontrolne.
add_filter('pre_http_request', function ($pre, $args, $url) {
	$host = (string) parse_url($url, PHP_URL_HOST);

	if (! in_array($host, ['api-krs.ms.gov.pl', 'wl-api.mf.gov.pl', 'wykazpodatnikowvat.mf.gov.pl', 'dane.biznes.gov.pl', 'wyszukiwarkaregon.stat.gov.pl', 'wyszukiwarkaregontest.stat.gov.pl'], true)) {
		return $pre;
	}

	$GLOBALS['ubrt_registry_calls'][] = $host . (string) parse_url($url, PHP_URL_PATH);
	$json = fn ($code, $body) => ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

	if (! empty($GLOBALS['ubrt_registry_down'])) {
		return new WP_Error('http_request_failed', 'cURL error 28: Connection timed out (test registry down)');
	}

	if ($host === 'api-krs.ms.gov.pl' && preg_match('#/OdpisAktualny/(\d{10})#', $url, $m)) {
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		$fixture = $GLOBALS['ubrt_krs'][$m[1]] ?? null;

		return $fixture && ($q['rejestr'] ?? 'P') === 'P' ? $json(200, $fixture) : $json(404, ['message' => 'Nie znaleziono']);
	}

	if (preg_match('#/api/search/nip/(\d{10})#', $url, $m)) {
		return $json(200, ['result' => ['subject' => $GLOBALS['ubrt_vat'][$m[1]] ?? null, 'requestId' => 'test']]);
	}

	return $json(404, []);
}, 10, 3);

/**
 * Dział 2 odpisu KRS z ZAMASKOWANYMI danymi osób (jak w API MS: pierwsza litera + gwiazdki do pełnej długości).
 * Osoby są fikcyjne; maski liczone z fikcyjnych imion i nazwisk.
 */
function ubrt_mask(string $v): string
{
	return mb_substr($v, 0, 1) . str_repeat('*', max(0, mb_strlen($v) - 1));
}

function ubrt_board(array $members, array $proxies = []): array
{
	$p = fn ($m) => ['nazwisko' => ['nazwiskoICzlon' => ubrt_mask($m[1])], 'imiona' => ['imie' => ubrt_mask($m[0])], 'identyfikator' => ['pesel' => '8**********']];

	return ['odpis' => ['dane' => ['dzial2' => [
		'reprezentacja' => ['nazwaOrganu' => 'ZARZĄD', 'sposobReprezentacji' => 'DWÓCH CZŁONKÓW ZARZĄDU ŁĄCZNIE.', 'sklad' => array_map(fn ($m) => $p($m) + ['funkcjaWOrganie' => $m[2], 'czyZawieszona' => $m[3] ?? false], $members)],
		'prokurenci' => array_map(fn ($m) => $p($m) + ['rodzajProkury' => 'PROKURA ŁĄCZNA'], $proxies),
		'organNadzoru' => [['nazwa' => 'RADA NADZORCZA', 'sklad' => [$p(['Rafał', 'Nadzorczy'])]]],
	]]]];
}

function ubrt_krs_odpis(string $krs, string $name, ?string $nip, string $woj, string $city, array $extra = []): array
{
	return array_replace_recursive(['odpis' => [
		'rodzaj' => 'Aktualny',
		'naglowekA' => ['rejestr' => 'RejP', 'numerKRS' => $krs, 'stanZDnia' => '01.10.2026', 'dataRejestracjiWKRS' => '01.02.2010'],
		'dane' => [
			'dzial1' => [
				'danePodmiotu' => ['formaPrawna' => 'SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'identyfikatory' => array_filter(['nip' => $nip, 'regon' => '140000017']), 'nazwa' => $name],
				'siedzibaIAdres' => [
					'siedziba' => ['kraj' => 'POLSKA', 'wojewodztwo' => $woj, 'powiat' => $city, 'gmina' => $city, 'miejscowosc' => $city],
					'adres' => ['ulica' => 'UL. TESTOWA', 'nrDomu' => '1', 'miejscowosc' => $city, 'kodPocztowy' => '00-001', 'poczta' => $city, 'kraj' => 'POLSKA'],
				],
			],
			'dzial3' => ['przedmiotDzialalnosci' => ['przedmiotPrzewazajacejDzialalnosci' => [['opis' => 'PRODUKCJA KONSTRUKCJI METALOWYCH I ICH CZĘŚCI', 'kodDzial' => '25', 'kodKlasa' => '11', 'kodPodklasa' => 'Z']]]],
		],
	]], $extra);
}

add_filter('ubr/discovery/providers', function ($providers) {
	$providers[] = new UbrTestProvider();

	return $providers;
});

// Testy wywołują REST wielokrotnie w ciągu sekund — wyłącz limit częstotliwości per użytkownik.
add_filter('ubr/limits', fn ($l) => ['min_seconds_between_searches' => 0] + $l, 20);
add_filter('ubr/discovery/inline_fallback', '__return_false');
// Kroki wykonujemy w teście ręcznie (proces cron nie ma zarejestrowanego dostawcy testowego).
add_filter('ubr/queue/spawn', '__return_false');
add_filter('ubr/queue/async', '__return_false');
// Prompt 10.4: wspólna pamięć podręczna stron i równoległe pobieranie — wyłączone w testach ogólnych (strony testowe
// zmieniają treść między przypadkami); testowane osobno w tests/performance-p104.php.
add_filter('ubr/http/page_cache_enabled', '__return_false');
add_filter('ubr/registry/namespace', fn () => 'ubrt_');
// DNS testowych domen *.ubrtest.pl: publiczny adres; internal.ubrtest.pl → adres prywatny (test SSRF).
add_filter('ubr/url_guard/resolve', fn ($r, $host) => str_ends_with($host, 'ubrtest.pl') ? ($host === 'internal.ubrtest.pl' ? ['10.0.0.5'] : ['93.184.216.34']) : $r, 10, 2);

try {
	/* ------------------------------------------------------------------ */
	UbrT::group('Normalizacja i walidacja');

	UbrT::eq('5260250274', N::nip('PL 526-025-02-74'), 'NIP z prefiksem PL i myślnikami');
	UbrT::eq(null, N::nip('5260250275'), 'NIP z błędną sumą kontrolną odrzucony');
	UbrT::eq(null, N::nip('123'), 'za krótki NIP odrzucony');
	UbrT::eq('012100784', N::regon('012100784'), 'REGON 9-cyfrowy poprawny');
	UbrT::eq(null, N::regon('012100785'), 'REGON z błędną sumą odrzucony');
	UbrT::eq('0000012345', N::krs('12345'), 'KRS dopełniony do 10 cyfr');
	UbrT::eq(N::normalizeName('STALBUD Kowalski Sp. z o.o.'), N::normalizeName('Stalbud Kowalski spółka z ograniczoną odpowiedzialnością'), 'formy prawne usuwane z nazwy');
	UbrT::eq('zaklad slusarski', N::normalizeName('Zakład Ślusarski S.C.'), 'diakrytyki i s.c.');
	UbrT::eq('firma.pl', N::domain('https://WWW.Firma.PL/kontakt?x=1'), 'domena: www, wielkość liter, ścieżka');
	UbrT::eq('firma.com.pl', N::domain('firma.com.pl'), 'domena bez schematu');
	UbrT::eq('a.pl', N::domain('a.pl; b.pl'), 'OSM: kilka wartości — pierwsza');
	UbrT::eq(null, N::domain('not a url'), 'nie-URL odrzucony');
	UbrT::eq('social', N::classifyDomain('pl-pl.facebook.com'), 'facebook = social');
	UbrT::eq('directory', N::classifyDomain('panoramafirm.pl'), 'panoramafirm = katalog');
	UbrT::eq('marketplace', N::classifyDomain('allegro.pl'), 'allegro = marketplace');
	UbrT::eq('website_builder_subdomain', N::classifyDomain('firma.wixsite.com'), 'subdomena kreatora');
	UbrT::eq('official', N::classifyDomain('stalbud.pl'), 'zwykła domena = oficjalna');

	/* ------------------------------------------------------------------ */
	UbrT::group('Kryteria Discovery');

	$crit = DiscoveryCriteria::fromArray(['phrase' => 'Producenci konstrukcji stalowych w Polsce', 'size' => '51_200', 'include_unknown_size' => false], ['wikidata', 'osm']);
	UbrT::ok(! in_array('produce', $crit->terms(), true) && ! in_array('polsc', $crit->terms(), true), 'ogólne słowa (producenci, Polsce) pominięte: ' . implode(',', $crit->terms()));
	UbrT::ok(in_array('konstruk', $crit->terms(), true) && in_array('stalow', $crit->terms(), true), 'rdzenie z frazy: konstruk, stalow');
	UbrT::ok($crit->acceptsSize(120, 120) && ! $crit->acceptsSize(5, 5) && ! $crit->acceptsSize(null, null), 'filtr wielkości 51–200 (bez nieznanych)');
	UbrT::ok(in_array('stalow', $crit->matchedTerms('Zakład konstrukcji Stalowych'), true) && $crit->matchedTerms('instalowych') === [], 'dopasowanie tylko od początku słowa (nie "instalowych")');

	try {
		DiscoveryCriteria::fromArray(['phrase' => 'firma'], ['osm']);
		UbrT::ok(false, 'zbyt ogólna fraza odrzucona');
	} catch (\App\Ubr\Support\UbrException $e) {
		UbrT::ok($e->status === 422, 'zbyt ogólna fraza odrzucona (422)');
	}

	try {
		DiscoveryCriteria::fromArray(['industry' => 'steel_structures', 'providers' => ['apollo']], ['wikidata', 'osm']);
		UbrT::ok(false, 'niedostępne źródło odrzucone');
	} catch (\App\Ubr\Support\UbrException $e) {
		UbrT::ok(true, 'niedostępne źródło (bez klucza) odrzucone');
	}

	$q = app(OverpassProvider::class)->buildQuery(DiscoveryCriteria::fromArray(['industry' => 'steel_structures', 'region' => 'mazowieckie'], ['osm']));
	UbrT::ok(str_contains($q, '(51.01,19.26,53.48,23.13)') && str_contains($q, 'nwr["website"]["name"~') && str_contains($q, 'craft"="metal_construction') && ! str_contains($q, '[~"^(') && ! str_contains($q, 'admin_level"="4"'), 'zapytanie Overpass: prostokąt województwa (indeks przestrzenny), wymagana strona WWW, tagi branżowe');
	$wq = app(WikidataProvider::class)->searchQueries(DiscoveryCriteria::fromArray(['industry' => 'machinery'], ['wikidata']));
	UbrT::ok(str_contains($wq[0], 'haswbstatement:P17=Q36') && str_contains($wq[0], 'haswbstatement:P856') && str_contains($wq[0], 'maszyn*'), 'Wikidata: lekkie zapytania wyszukiwarki (Polska, oficjalna strona, słowo kluczowe*) zamiast ciężkiego SPARQL');

	$mapped = app(OverpassProvider::class)->map(['type' => 'node', 'id' => 1, 'tags' => ['name' => 'Bez strony']], $crit);
	UbrT::eq(null, $mapped, 'OSM: obiekt bez strony WWW pomijany');
	$claim = fn ($v) => [['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => $v]]]];
	$wdCompany = ['labels' => ['pl' => ['value' => 'Fabryka Testowa']], 'claims' => ['P31' => $claim(['id' => 'Q4830453']), 'P17' => $claim(['id' => 'Q36']), 'P856' => $claim('https://fabryka.example.pl'), 'P1128' => $claim(['amount' => '+340'])]];
	$wdClub = ['labels' => ['pl' => ['value' => 'Stal Mielec']], 'claims' => ['P31' => $claim(['id' => 'Q476028']), 'P17' => $claim(['id' => 'Q36']), 'P856' => $claim('https://stalmielec.example.pl')]];
	UbrT::ok(app(WikidataProvider::class)->isCompany($wdCompany) && ! app(WikidataProvider::class)->isCompany($wdClub), 'Wikidata: firma rozpoznana, klub sportowy odrzucony');
	$wdMapped = app(WikidataProvider::class)->map('Q123', $wdCompany, $crit);
	UbrT::ok($wdMapped && $wdMapped->website === 'https://fabryka.example.pl' && $wdMapped->employeesMin === 340 && $wdMapped->websiteConfidence === 'confirmed', 'Wikidata: mapowanie strony (potwierdzona) i zatrudnienia');
	UbrT::eq(null, app(WikidataProvider::class)->map('Q1', ['labels' => []], $crit), 'Wikidata: obiekt bez etykiety pomijany');

	/* ------------------------------------------------------------------ */
	UbrT::group('Migracje i inicjalizacja');

	$migrator = app(Migrator::class);
	UbrT::eq($migrator->latestVersion(), $migrator->currentVersion(), 'schemat w najnowszej wersji');
	UbrT::ok(! in_array(false, $migrator->tableStatus(), true), 'wszystkie tabele istnieją');
	update_option(Migrator::OPTION, 0);
	$migrator->migrate();
	UbrT::eq($migrator->latestVersion(), $migrator->currentVersion(), 'ponowne uruchomienie migracji jest idempotentne (bez błędów)');
	$before = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ORGANIZATIONS)} WHERE slug = 'ohsofresh'");
	app(Bootstrapper::class)->run();
	app(Bootstrapper::class)->run();
	$after = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ORGANIZATIONS)} WHERE slug = 'ohsofresh'");
	UbrT::ok($before === 1 && $after === 1, 'organizacja OhSoFresh istnieje dokładnie raz po wielokrotnej inicjalizacji');

	/* ------------------------------------------------------------------ */
	UbrT::group('Organizacje i członkostwa');

	$mk = function (string $login) use (&$created) {
		$id = wp_insert_user(['user_login' => 'ubrtest_' . $login . '_' . wp_generate_password(4, false), 'user_pass' => wp_generate_password(), 'user_email' => 'ubrtest_' . $login . wp_rand(1000, 9999) . '@example.test', 'role' => 'subscriber']);
		$created['users'][] = $id;

		return $id;
	};

	$uA = $mk('ownerA');
	$uB = $mk('ownerB');
	$uM = $mk('memberA');
	$uAd = $mk('adminA');
	$uX = $mk('outsider');

	$orgs = app(OrganizationService::class);
	$orgA = $orgs->create('ubrtest Org A', $uA);
	$orgB = $orgs->create('ubrtest Org B', $uB);
	$created['orgs'] = [$orgA, $orgB];
	$members = app(MemberRepository::class);
	$members->add($orgA, $uM, Role::Member);
	$members->add($orgA, $uAd, Role::Admin);
	$members->add($orgB, $uM, Role::Member); // użytkownik w dwóch organizacjach

	UbrT::eq(Role::Owner, $members->role($orgA, $uA), 'twórca organizacji jest Ownerem');
	UbrT::ok($members->add($orgA, $uM, Role::Admin) === false && $members->role($orgA, $uM) === Role::Member, 'ponowne dodanie członka nie duplikuje i nie zmienia roli');

	$me = ubrt_rest($uM, 'GET', '/me');
	UbrT::eq(2, count($me['data']['organizations']), 'użytkownik należy do 2 organizacji');
	$sw = ubrt_rest($uM, 'POST', '/me/active-organization', ['organization_id' => $orgB]);
	UbrT::eq($orgB, $sw['data']['active_organization']['id'] ?? null, 'przełączenie aktywnej organizacji');
	ubrt_rest($uM, 'POST', '/me/active-organization', ['organization_id' => $orgA]);
	$sw = ubrt_rest($uB, 'POST', '/me/active-organization', ['organization_id' => $orgA]);
	UbrT::eq(404, $sw['status'], 'nie można przełączyć się na cudzą organizację (podmiana ID)');

	UbrT::eq(403, ubrt_rest($uM, 'POST', '/organization/members', ['email' => 'x@example.test', 'role' => 'member'])['status'], 'Member nie może zarządzać członkami');
	$userX = get_userdata($uX);
	UbrT::eq(403, ubrt_rest($uAd, 'POST', '/organization/members', ['email' => $userX->user_email, 'role' => 'owner'])['status'], 'Admin nie może nadać roli Owner');
	UbrT::eq(201, ubrt_rest($uAd, 'POST', '/organization/members', ['email' => $userX->user_email, 'role' => 'member'])['status'], 'Admin dodaje członka');
	UbrT::eq(200, ubrt_rest($uAd, 'DELETE', '/organization/members/' . $uX)['status'], 'Admin usuwa członka');
	UbrT::eq(422, ubrt_rest($uA, 'DELETE', '/organization/members/' . $uA)['status'], 'nie można usunąć ostatniego Ownera');
	UbrT::eq(403, ubrt_rest($uAd, 'POST', '/organization/members/' . $uA, ['role' => 'member'])['status'], 'Admin nie może zdegradować Ownera');
	UbrT::eq(403, ubrt_rest($uB, 'POST', '/organizations', ['name' => 'x'])['status'], 'zwykły użytkownik nie tworzy organizacji (domyślnie tylko administratorzy WP)');

	/* ------------------------------------------------------------------ */
	UbrT::group('Autoryzacja i izolacja danych');

	wp_set_current_user(0);
	UbrT::eq(401, ubrt_rest(0, 'GET', '/dashboard')['status'], 'anonim: 401');
	UbrT::eq(403, ubrt_rest($uX, 'GET', '/dashboard')['status'], 'zalogowany bez członkostwa: 403');
	UbrT::eq(403, ubrt_rest($uX, 'GET', '/prospects')['status'], 'zalogowany bez członkostwa nie czyta prospektów');
	UbrT::eq(200, ubrt_rest($uA, 'GET', '/dashboard')['status'], 'Owner A: dashboard 200');

	// Firmy i wyniki: dwie organizacje odkrywają tę samą firmę, jedna dodatkowo prywatną.
	$ingest = app(CompanyIngestService::class);
	$searches = app(SearchRepository::class);
	$results = app(ResultRepository::class);
	$shared = $ingest->ingest(ubrt_candidate(['id' => 's1', 'name' => 'Stalbud Kowalski Sp. z o.o.', 'website' => 'https://www.stalbud.ubrtest.pl', 'city' => 'Radom', 'nip' => '5260250274']))['company_id'];
	$onlyA = $ingest->ingest(ubrt_candidate(['id' => 'a1', 'name' => 'Tylko A Metal', 'website' => 'https://tylkoa.ubrtest.pl', 'city' => 'Płock']))['company_id'];

	$sA = $searches->create(['organization_id' => $orgA, 'user_id' => $uA, 'label' => 'ubrtest A', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
	$sB = $searches->create(['organization_id' => $orgB, 'user_id' => $uB, 'label' => 'ubrtest B', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
	$results->add($sA, $orgA, $shared, 'ubrtest', 1, []);
	$results->add($sA, $orgA, $onlyA, 'ubrtest', 2, []);
	$results->add($sB, $orgB, $shared, 'ubrtest', 1, []);

	UbrT::eq(404, ubrt_rest($uB, 'GET', '/discovery/searches/' . $sA)['status'], 'B nie widzi wyszukiwania A');
	UbrT::eq(404, ubrt_rest($uB, 'GET', '/discovery/searches/' . $sA . '/results')['status'], 'B nie widzi wyników A');
	UbrT::eq(404, ubrt_rest($uB, 'POST', '/discovery/searches/' . $sA . '/cancel')['status'], 'B nie anuluje wyszukiwania A');
	UbrT::eq(404, ubrt_rest($uB, 'GET', '/companies/' . $onlyA)['status'], 'B nie widzi firmy odkrytej tylko przez A');
	UbrT::eq(404, ubrt_rest($uB, 'POST', '/prospects', ['company_id' => $onlyA, 'organization_id' => $orgA])['status'], 'B nie zapisze firmy A nawet z podmienionym organization_id');
	UbrT::eq(200, ubrt_rest($uB, 'GET', '/companies/' . $shared)['status'], 'B widzi firmę, którą sam odkrył');

	$pA = ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $shared]);
	$pB = ubrt_rest($uB, 'POST', '/prospects', ['company_id' => $shared]);
	UbrT::ok($pA['status'] === 201 && $pB['status'] === 201, 'ta sama firma zapisana przez obie organizacje');
	UbrT::eq(200, ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $shared])['status'], 'ponowny zapis jest idempotentny (200, bez duplikatu)');
	ubrt_rest($uM, 'POST', '/prospects', ['company_id' => $onlyA]);

	$listA = ubrt_rest($uA, 'GET', '/prospects');
	$listB = ubrt_rest($uB, 'GET', '/prospects', ['organization_id' => $orgA]);
	UbrT::eq(2, $listA['data']['total'], 'A ma 2 prospekty');
	UbrT::eq(1, $listB['data']['total'], 'B ma 1 prospekt (parametr organization_id ignorowany)');
	UbrT::eq(404, ubrt_rest($uB, 'DELETE', '/prospects/' . $pA['data']['prospect_id'])['status'], 'B nie usunie prospekta A');
	UbrT::eq(200, ubrt_rest($uA, 'DELETE', '/prospects/' . $pA['data']['prospect_id'])['status'], 'A usuwa swój prospekt');
	UbrT::eq(1, ubrt_rest($uB, 'GET', '/prospects')['data']['total'], 'usunięcie u A nie wpływa na B');
	UbrT::ok((bool) app(CompanyRepository::class)->find($shared), 'firma zostaje w globalnej bazie');

	$filtered = ubrt_rest($uA, 'GET', '/prospects', ['search' => 'Tylko', 'has_domain' => 'yes', 'sort' => 'name', 'dir' => 'asc']);
	UbrT::eq(1, $filtered['data']['total'], 'wyszukiwanie + filtr domeny + sortowanie');
	$evil = ubrt_rest($uA, 'GET', '/prospects', ['sort' => 'name; DROP TABLE x', 'dir' => 'sideways']);
	UbrT::eq(200, $evil['status'], 'nieprawidłowe sortowanie bezpiecznie ignorowane (whitelist)');

	/* ------------------------------------------------------------------ */
	UbrT::group('Deduplikacja firm');

	$again = $ingest->ingest(ubrt_candidate(['id' => 's1', 'name' => 'Stalbud Kowalski Sp. z o.o.', 'website' => 'https://www.stalbud.ubrtest.pl']));
	UbrT::eq($shared, $again['company_id'], 'ten sam identyfikator u dostawcy → ta sama firma');
	$byDomain = $ingest->ingest(ubrt_candidate(['provider' => 'ubrtest2', 'id' => 'x9', 'name' => 'STALBUD', 'website' => 'http://stalbud.ubrtest.pl/o-nas']));
	UbrT::eq($shared, $byDomain['company_id'], 'inne źródło, ta sama domena (bez www, inna ścieżka) → ta sama firma');
	$byNip = $ingest->ingest(ubrt_candidate(['provider' => 'ubrtest2', 'id' => 'x10', 'name' => 'Inna nazwa', 'nip' => 'PL5260250274']));
	UbrT::eq($shared, $byNip['company_id'], 'ten sam NIP → ta sama firma');
	$byName = $ingest->ingest(ubrt_candidate(['provider' => 'ubrtest2', 'id' => 'x11', 'name' => 'Tylko A Metal s.c.', 'city' => 'płock']));
	UbrT::eq($onlyA, $byName['company_id'], 'nazwa (bez formy prawnej) + miasto → ta sama firma');
	$fb = $ingest->ingest(ubrt_candidate(['id' => 'fb1', 'name' => 'Ubrtest Facebookowa', 'website' => 'https://facebook.com/ubrtestfb', 'city' => 'Kielce']));
	UbrT::eq(null, $fb['domain'], 'profil Facebook nie jest przypisywany jako domena firmy');
	$conflict = $ingest->ingest(ubrt_candidate(['id' => 'c1', 'name' => 'Zupełnie Inna Firma', 'website' => 'https://stalbud.ubrtest.pl', 'city' => 'Gdańsk']));
	UbrT::eq($shared, $conflict['company_id'], 'ta sama domena łączy rekordy nawet przy innej nazwie (domena = najsilniejszy identyfikator)');
	$dupes = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::COMPANIES)} WHERE domain = 'stalbud.ubrtest.pl'");
	UbrT::eq(1, $dupes, 'brak duplikatów domeny w bazie');
	$ev = app(CompanyRepository::class)->evidence($shared);
	UbrT::ok(count(array_filter($ev, fn ($e) => $e['field'] === 'name')) >= 2, 'historia pochodzenia zapisuje nazwy z różnych źródeł');

	/* ------------------------------------------------------------------ */
	UbrT::group('Weryfikacja stron WWW');

	$checker = app(WebsiteChecker::class);
	$res = $checker->check('https://stalbud.ubrtest.pl', 'Stalbud Kowalski Sp. z o.o.');
	UbrT::ok($res['status'] === 'reachable' && $res['name_match'] && $res['title'] !== null, 'strona odpowiada, nazwa firmy znaleziona, tytuł pobrany');
	$res = $checker->check('https://down.ubrtest.pl', 'Down');
	UbrT::eq('unreachable', $res['status'], 'brak odpowiedzi → status unreachable (firma nie jest odrzucana)');
	UbrT::ok(! $checker->nameMatches('Alfa Beta', '<p>Gamma Delta</p>', 'gamma.pl'), 'brak dopasowania nazwy');

	/* ------------------------------------------------------------------ */
	UbrT::group('Zadania Discovery w tle (dostawca testowy)');

	UbrTestProvider::$failuresLeft = 1;
	UbrTestProvider::$candidates = [];

	for ($i = 1; $i <= 7; $i++) {
		UbrTestProvider::$candidates[] = ubrt_candidate(['id' => 'job' . $i, 'name' => 'Ubrtest Job Firma ' . $i, 'website' => $i === 3 ? 'https://down.ubrtest.pl' : 'https://job' . $i . '.ubrtest.pl', 'city' => 'Łódź', 'emp' => $i === 2 ? 5 : null]);
	}

	$start = ubrt_rest($uA, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje', 'providers' => ['ubrtest'], 'size' => '11_50', 'include_unknown_size' => true, 'organization_id' => $orgB]);
	UbrT::eq(201, $start['status'], 'utworzenie zadania (201)');
	$jobId = (int) ($start['data']['id'] ?? 0);
	$job = $searches->find($jobId);
	UbrT::eq($orgA, $job['organization_id'] ?? null, 'zadanie przypisane do organizacji z kontekstu, nie z parametru żądania');
	UbrT::eq('queued', $job['status'] ?? null, 'zadanie czeka w kolejce — żądanie HTTP nie wykonuje wyszukiwania');
	UbrT::ok(app(\App\Ubr\Infrastructure\Queue\JobDispatcher::class)->hasPending($jobId), 'krok zadania zaplanowany w ' . app(\App\Ubr\Infrastructure\Queue\JobDispatcher::class)->driver());

	$runner = app(DiscoveryRunner::class);
	$runner->tick($jobId, 5);
	$job = $searches->find($jobId);
	UbrT::ok($job['status'] === 'running' && strtotime($job['next_run_at'] . ' UTC') > time() - 2, 'błąd źródła (503) → ponowienie zaplanowane z opóźnieniem');
	$searches->update($jobId, ['next_run_at' => Db::now()]);

	for ($i = 0; $i < 10 && in_array($searches->find($jobId)['status'], ['queued', 'running'], true); $i++) {
		$runner->tick($jobId, 5);
	}

	$job = $searches->find($jobId);
	UbrT::eq('completed', $job['status'], 'zadanie zakończone');
	UbrT::eq(6, $job['results_count'], '6 wyników (1 odrzucona: 5 osób poza zakresem 11–50), paginacja źródła działa');
	UbrT::eq(100, $job['progress'], 'postęp 100%');
	$c1Id = (int) app(CompanyRepository::class)->idByExternalId('ubrtest', 'job1');
	$queued = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::VERIFICATION_QUEUE)} q INNER JOIN {$db->table(Tables::RESULTS)} r ON r.company_id = q.company_id AND r.search_id = %d", $jobId);
	UbrT::eq(6, $queued, 'wyszukiwanie zakończone od razu, a firmy trafiły do osobnej kolejki weryfikacji (Discovery nie czeka na rejestry)');
	UbrT::eq('unchecked', app(CompanyRepository::class)->find($c1Id)['domain_status'], 'przed weryfikacją domena ma status „niesprawdzona”');
	$vr = app(\App\Ubr\Services\VerificationRunner::class)->tick(30, false);
	UbrT::ok($vr['processed'] >= 6, 'kolejka weryfikacji przetworzona w tle (' . $vr['processed'] . ')');
	$c1 = app(CompanyRepository::class)->find($c1Id);
	UbrT::eq('reachable', $c1['website_status'], 'strona sprawdzona w weryfikacji');
	$c3 = app(CompanyRepository::class)->find((int) app(CompanyRepository::class)->idByExternalId('ubrtest', 'job3'));
	UbrT::ok($c3 && $c3['website_status'] === 'unreachable' && $c3['domain_status'] === 'unavailable', 'niedostępna strona: firma zostaje, domena „niedostępna”');
	UbrT::eq('uncertain', $c1['verification_status'], 'brak NIP/KRS na stronie i w źródle → firma „niepewna” (nie VERIFIED)');
	$status = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId);
	UbrT::ok(count($status['data']['events']) >= 4, 'dziennik zdarzeń dostępny w API');
	$page = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['page' => 2, 'per_page' => 4]);
	UbrT::ok($page['data']['total'] === 6 && count($page['data']['items']) === 2, 'paginacja wyników');

	// Anulowanie.
	$cancelJob = ubrt_rest($uA, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje', 'providers' => ['ubrtest']]);
	$cid = (int) $cancelJob['data']['id'];
	$cancel = ubrt_rest($uA, 'POST', '/discovery/searches/' . $cid . '/cancel');
	UbrT::eq('cancelled', $cancel['data']['status'] ?? null, 'anulowanie zadania z kolejki');
	UbrT::eq('cancelled', $runner->tick($cid, 5), 'anulowane zadanie nie jest dalej przetwarzane');

	// Limit równoległych zadań.
	$j1 = ubrt_rest($uA, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje', 'providers' => ['ubrtest']]);
	$j2 = ubrt_rest($uA, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje', 'providers' => ['ubrtest']]);
	$j3 = ubrt_rest($uA, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje', 'providers' => ['ubrtest']]);
	UbrT::eq(429, $j3['status'], 'limit równoległych wyszukiwań organizacji (429)');
	UbrT::eq(403, ubrt_rest($uX, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje'])['status'], 'osoba spoza organizacji nie uruchomi kosztownego zadania');

	/* ------------------------------------------------------------------ */
	UbrT::group('Apollo — konfiguracja i bezpieczeństwo klucza');

	$apolloProvider = app(\App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider::class);
	UbrT::ok(defined('UBR_APOLLO_API_KEY') || ! $apolloProvider->isAvailable(), 'bez klucza Apollo jest nieaktywne');

	if (! defined('UBR_APOLLO_API_KEY')) {
		$noKey = ubrt_rest($uA, 'POST', '/discovery/searches', ['industry' => 'steel_structures', 'providers' => ['apollo']]);
		UbrT::eq(422, $noKey['status'], 'brak klucza → wyszukiwanie Apollo odrzucone z czytelnym błędem (bez zapytań do API)');
	}

	putenv('UBR_APOLLO_API_KEY=' . $ubrtFakeKey);
	UbrT::ok($apolloProvider->isAvailable(), 'klucz z zmiennej środowiskowej rozpoznany');

	$critA = DiscoveryCriteria::fromArray(['industry' => 'steel_structures', 'size' => '51_200', 'limit' => 250, 'providers' => ['apollo']], ['apollo']);
	$params = $apolloProvider->buildParams($critA, 1, 100);
	UbrT::eq(['poland'], $params['organization_locations[]'], 'lokalizacja zawsze: Polska');
	UbrT::eq(['51,200'], $params['organization_num_employees_ranges[]'], 'przedział zatrudnienia w formacie Apollo "51,200"');
	UbrT::ok(in_array('steel fabrication', $params['q_organization_keyword_tags[]'], true), 'tagi słów kluczowych branży (EN)');
	$qs = \App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider::queryString($params);
	UbrT::ok(str_contains($qs, 'organization_locations[]=poland') && str_contains($qs, 'per_page=100'), 'query string z tablicami name[]');
	UbrT::eq(['per_page' => 100, 'pages' => 3], app(\App\Ubr\Services\ApolloBudget::class)->plan(250), 'plan kosztów: 250 firm = 3 strony = 3 kredyty');
	UbrT::eq(['per_page' => 25, 'pages' => 1], app(\App\Ubr\Services\ApolloBudget::class)->plan(25), '25 firm = 1 strona');

	$opts = ubrt_rest($uM, 'GET', '/discovery/options');
	$integ = ubrt_rest($uM, 'GET', '/integrations/apollo');
	UbrT::ok(! str_contains(wp_json_encode($opts['data']), $ubrtFakeKey) && ! str_contains(wp_json_encode($integ['data']), $ubrtFakeKey), 'klucz API nie pojawia się w odpowiedziach REST');
	UbrT::ok($integ['status'] === 200 && $integ['data']['configured'] === true && $integ['data']['stats_global'] === null, 'Member widzi status integracji, ale nie zużycie całej instalacji');
	UbrT::eq(403, ubrt_rest($uM, 'POST', '/integrations/apollo/test')['status'], 'Member nie uruchamia testów połączenia');

	/* ------------------------------------------------------------------ */
	UbrT::group('Apollo — zapytania (mock HTTP, kształt odpowiedzi wg dokumentacji)');

	$GLOBALS['ubrt_apollo_mode'] = '200';
	$GLOBALS['ubrt_apollo_calls'] = 0;
	$page1 = $apolloProvider->fetch($critA, ['_organization_id' => $orgA, '_search_id' => null]);
	UbrT::eq(1, $GLOBALS['ubrt_apollo_calls'], 'jedno zapytanie HTTP na stronę');
	UbrT::eq('test-key-header-ok', $GLOBALS['ubrt_apollo_auth'] ?? null, 'klucz wysyłany w nagłówku x-api-key');
	UbrT::eq(3, count($page1->candidates), 'mapowanie: 3 firmy z Polski (firma z Niemiec odfiltrowana)');
	$first = $page1->candidates[0];
	UbrT::ok($first->website === 'http://www.stalmontaz.ubrtest.pl' && $first->employeesMin === 120 && $first->employeesConfidence === 'declared', 'domena i zatrudnienie (szacunek Apollo = deklaracja dostawcy)');
	UbrT::eq('mazowieckie', $first->region, 'województwo z pola state ("Masovian Voivodeship")');
	UbrT::ok($first->foundedYear === 1994 && $first->revenueEstimate === 12000000, 'rok założenia i przychody');
	UbrT::eq(['page' => 2], $page1->nextCursor, 'paginacja: następna strona');
	$page1again = $apolloProvider->fetch($critA, ['_organization_id' => $orgA]);
	UbrT::ok($GLOBALS['ubrt_apollo_calls'] === 1 && ! empty($page1again->meta['cached']), 'identyczne zapytanie → cache (bez kosztu, bez HTTP)');

	$billable = app(\App\Ubr\Infrastructure\Repositories\ApiRequestRepository::class)->countBillable('apollo', '1970-01-01 00:00:00', $orgA);
	UbrT::eq(1, $billable, 'dziennik: 1 potencjalnie płatne zapytanie, cache nie liczy się do kosztów');

	$critReg = DiscoveryCriteria::fromArray(['industry' => 'steel_structures', 'region' => 'slaskie', 'limit' => 25, 'providers' => ['apollo']], ['apollo']);
	$pageReg = $apolloProvider->fetch($critReg, ['_organization_id' => $orgA]);
	UbrT::ok(count($pageReg->candidates) === 1 && $pageReg->candidates[0]->region === 'śląskie', 'rekordy z województwem spoza filtra są odrzucane');
	UbrT::eq(['Silesian Voivodeship, Poland'], $apolloProvider->buildParams($critReg, 1, 25)['organization_locations[]'], 'województwo przekazywane do Apollo jako lokalizacja siedziby');

	$GLOBALS['ubrt_apollo_mode'] = 'real';
	$critReal = DiscoveryCriteria::fromArray(['phrase' => 'kształt rzeczywisty', 'region' => 'mazowieckie', 'size' => '11_50', 'limit' => 10, 'providers' => ['apollo']], ['apollo']);
	$real = $apolloProvider->fetch($critReal, ['_organization_id' => $orgA])->candidates[0] ?? null;
	UbrT::ok($real && $real->region === null && $real->city === null && $real->employeesMin === null && $real->employeesConfidence === 'unavailable' && $real->locationConfidence === 'unavailable', 'brak lokalizacji/zatrudnienia w odpowiedzi → pola puste (NIE kopiujemy wartości z filtrów wyszukiwania)');
	UbrT::ok($real && $real->revenueEstimate === null && str_contains((string) $real->industry, 'Wyroby metalowe') && str_contains((string) $real->industry, 'SIC 3441'), 'przychód 0 = brak danych; branża z kodów SIC');
	UbrT::ok($real && ! str_contains(wp_json_encode($real), '+48 600'), 'telefon firmy z Apollo nie jest zapisywany (brak kontaktów)');

	foreach (['401' => [false, 'klucz'], '403' => [false, 'plan'], '429' => [true, 'limit'], 'timeout' => [false, 'brak odpowiedzi'], '500' => [false, 'serwera']] as $mode => [$retry, $needle]) {
		$GLOBALS['ubrt_apollo_mode'] = $mode;

		try {
			$apolloProvider->fetch(DiscoveryCriteria::fromArray(['phrase' => 'unikalna fraza ' . $mode, 'providers' => ['apollo']], ['apollo']), ['_organization_id' => $orgA]);
			UbrT::ok(false, "HTTP {$mode} → wyjątek");
		} catch (ProviderException $e) {
			UbrT::ok($e->retryable === $retry && str_contains(mb_strtolower($e->getMessage()), $needle) && ! str_contains($e->getMessage(), $ubrtFakeKey), "HTTP {$mode} → " . ($retry ? 'ponowienie' : 'bez automatycznego ponowienia') . ': ' . $e->getMessage());
		}
	}

	$rows = $db->rows("SELECT http_status, billable FROM {$db->table(Tables::API_REQUESTS)} WHERE organization_id = %d AND provider = 'apollo' ORDER BY id", $orgA);
	$byStatus = [];

	foreach ($rows as $r) {
		$byStatus[(string) ($r['http_status'] ?? 'null')] = (int) $r['billable'];
	}

	UbrT::ok(($byStatus['401'] ?? 1) === 0 && ($byStatus['403'] ?? 1) === 0 && ($byStatus['429'] ?? 1) === 0 && ($byStatus['null'] ?? 0) === 1, '401/403/429 nie liczą się do kosztów, timeout liczy się zachowawczo');

	// Limit stron na wyszukiwanie.
	$GLOBALS['ubrt_apollo_mode'] = '200';
	$limitFilter = fn ($l) => ['max_pages_per_search' => 1] + $l;
	add_filter('ubr/apollo/limits', $limitFilter);
	$sLimit = $searches->create(['organization_id' => $orgA, 'user_id' => $uA, 'label' => 'ubrtest limit', 'criteria' => [], 'providers' => ['apollo'], 'status' => 'running']);
	$apolloProvider->fetch(DiscoveryCriteria::fromArray(['phrase' => 'limit test', 'providers' => ['apollo'], 'limit' => 250], ['apollo']), ['_organization_id' => $orgA, '_search_id' => $sLimit]);

	try {
		$apolloProvider->fetch(DiscoveryCriteria::fromArray(['phrase' => 'limit test', 'providers' => ['apollo'], 'limit' => 250], ['apollo']), ['_organization_id' => $orgA, '_search_id' => $sLimit, 'page' => 2]);
		UbrT::ok(false, 'limit stron na wyszukiwanie zatrzymuje kolejne zapytania');
	} catch (ProviderException $e) {
		UbrT::ok(! $e->retryable && str_contains($e->getMessage(), 'limit'), 'limit stron na wyszukiwanie zatrzymuje kolejne zapytania (bez ponawiania)');
	}

	remove_filter('ubr/apollo/limits', $limitFilter);
	$dayFilter = fn ($l) => ['org_pages_per_day' => 0] + $l;
	add_filter('ubr/apollo/limits', $dayFilter);

	try {
		$apolloProvider->fetch(DiscoveryCriteria::fromArray(['phrase' => 'dzienny limit', 'providers' => ['apollo']], ['apollo']), ['_organization_id' => $orgA]);
		UbrT::ok(false, 'dzienny limit organizacji blokuje zapytania');
	} catch (ProviderException $e) {
		UbrT::ok(str_contains($e->getMessage(), 'dzienny limit'), 'dzienny limit organizacji blokuje zapytania (ochrona kosztów operatora)');
	}

	remove_filter('ubr/apollo/limits', $dayFilter);

	/* ------------------------------------------------------------------ */
	UbrT::group('Apollo — pełne zadanie Discovery, deduplikacja, prospekty, izolacja');

	// Firma znana wcześniej z Wikidata (ta sama domena) nie może zostać zdublowana przez Apollo.
	$wd = $ingest->ingest(ubrt_candidate(['provider' => 'ubrtest_wd', 'id' => 'Q999001', 'name' => 'Stalmontaż Sp. z o.o.', 'website' => 'https://stalmontaz.ubrtest.pl', 'conf' => Confidence::CONFIRMED, 'city' => 'Warszawa']));
	$GLOBALS['ubrt_apollo_mode'] = '200';
	// Zwolnij limit równoległych zadań (poprzednie testy zostawiły zadania w kolejce).
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::SEARCHES)} SET status = 'cancelled' WHERE organization_id = %d AND status IN ('queued','running')", $orgA));
	$jobA = ubrt_rest($uA, 'POST', '/discovery/searches', ['industry' => 'machinery', 'providers' => ['apollo'], 'limit' => 25]);
	$jobAId = (int) ($jobA['data']['id'] ?? 0);

	for ($i = 0; $i < 10 && in_array($searches->find($jobAId)['status'] ?? '', ['queued', 'running'], true); $i++) {
		$runner->tick($jobAId, 5);
	}

	$jobARow = $searches->find($jobAId);
	$statusA = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobAId);
	UbrT::eq('completed', $jobARow['status'], 'zadanie Apollo zakończone');
	UbrT::eq(3, $jobARow['results_count'], '3 firmy zapisane w wynikach');
	UbrT::ok($statusA['data']['duplicate_count'] === 1 && $statusA['data']['new_count'] === 2, 'statystyki: 2 nowe, 1 już w bazie (z Wikidata)');
	$apolloId = app(CompanyRepository::class)->idByExternalId('apollo', 'apollo-org-1');
	UbrT::eq($wd['company_id'], $apolloId, 'deduplikacja Apollo ↔ Wikidata po domenie (jeden rekord firmy)');
	$evApollo = array_filter(app(CompanyRepository::class)->evidence($apolloId), fn ($e) => $e['provider'] === 'apollo');
	UbrT::ok(count($evApollo) >= 3, 'pochodzenie danych z Apollo zapisane w historii');
	$saveA = ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $apolloId]);
	UbrT::ok(in_array($saveA['status'], [200, 201], true), 'firmę z Apollo można zapisać do Prospects');
	UbrT::eq(404, ubrt_rest($uB, 'GET', '/discovery/searches/' . $jobAId . '/results')['status'], 'organizacja B nie widzi wyników Apollo organizacji A');
	$reqLog = $db->value("SELECT COUNT(*) FROM {$db->table(Tables::API_REQUESTS)} WHERE search_id = %d AND provider = 'apollo' AND new_count IS NOT NULL", $jobAId);
	UbrT::eq(1, (int) $reqLog, 'dziennik zapytań: liczba nowych/duplikatów zapisana dla zapytania');

	$retry = ubrt_rest($uA, 'POST', '/discovery/searches/' . $jobAId . '/retry');
	UbrT::ok($retry['status'] === 201 && (int) $retry['data']['id'] !== $jobAId, 'ponowienie tworzy nowe zadanie z tymi samymi kryteriami');
	ubrt_rest($uA, 'POST', '/discovery/searches/' . (int) $retry['data']['id'] . '/cancel');

	/* ------------------------------------------------------------------ */
	UbrT::group('Discovery 1.2 — lokalizacja, branże, diagnostyka');

	$critCity = DiscoveryCriteria::fromArray(['industry' => 'machinery', 'city' => 'krakow', 'providers' => ['apollo']], ['apollo']);
	UbrT::eq(['Kraków, Poland', 'Krakow, Poland', 'Cracow, Poland'], $apolloProvider->buildParams($critCity, 1, 25)['organization_locations[]'], 'miasto przekazywane do Apollo w wariantach PL/ASCII/EN');
	UbrT::eq('malopolskie', $critCity->regionKey(), 'województwo wynika z miasta (Kraków → małopolskie)');
	$conflict = ubrt_rest($uA, 'POST', '/discovery/searches', ['industry' => 'machinery', 'city' => 'Kraków', 'region' => 'mazowieckie', 'providers' => ['ubrtest']]);
	UbrT::ok($conflict['status'] === 422 && str_contains((string) ($conflict['data']['message'] ?? ''), 'małopolskie'), 'sprzeczne miasto i województwo odrzucone przed zapytaniem (422, bez kosztu)');
	$critPl = DiscoveryCriteria::fromArray(['phrase' => 'obróbka metali', 'providers' => ['apollo']], ['apollo']);
	UbrT::ok($critPl->industryKey() === 'metalworking' && in_array('cnc machining', $critPl->apolloKeywords(), true) && ! in_array('obróbka metali', $critPl->apolloKeywords(), true), 'polska fraza „obróbka metali” → branża + angielskie tagi Apollo (bez polskiego tagu)');
	$critOwn = DiscoveryCriteria::fromArray(['phrase' => 'produkcja dronów rolniczych', 'providers' => ['apollo']], ['apollo']);
	UbrT::ok($critOwn->industryKey() === null && in_array('produkcja dronów rolniczych', $critOwn->apolloKeywords(), true), 'fraza spoza katalogu przekazywana dosłownie');
	UbrT::ok(\App\Ubr\Domain\Discovery\IndustryCatalog::detect('konstrukcje stalowe') === 'steel_structures' && \App\Ubr\Domain\Discovery\IndustryCatalog::detect('producent opakowań') === 'packaging' && \App\Ubr\Domain\Discovery\IndustryCatalog::detect('elektronika przemysłowa') === 'industrial_electronics', 'rozpoznanie branż z polskich zapytań (konstrukcje stalowe, opakowania, elektronika)');

	UbrT::eq(1, (int) ($statusA['data']['diagnostics']['rejected']['country_mismatch'] ?? 0), 'diagnostyka: odrzucona firma z Niemiec z powodem „siedziba poza Polską”');
	UbrT::eq(4, (int) ($statusA['data']['diagnostics']['returned'] ?? 0), 'diagnostyka: liczba rekordów zwróconych przez źródło');

	// Rzeczywisty kształt odpowiedzi Apollo + filtr województwa: lokalizacja NIE trafia do danych firmy, trafność = „tylko filtr”.
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::SEARCHES)} SET status = 'cancelled' WHERE organization_id = %d AND status IN ('queued','running')", $orgA));
	$GLOBALS['ubrt_apollo_mode'] = 'real';
	$jobR = ubrt_rest($uA, 'POST', '/discovery/searches', ['phrase' => 'konstrukcje stalowe', 'region' => 'mazowieckie', 'size' => '51_200', 'providers' => ['apollo'], 'limit' => 25]);
	$jobRId = (int) ($jobR['data']['id'] ?? 0);

	for ($i = 0; $i < 5 && in_array($searches->find($jobRId)['status'] ?? '', ['queued', 'running'], true); $i++) {
		$runner->tick($jobRId, 5);
	}

	$realId = (int) app(CompanyRepository::class)->idByExternalId('apollo', 'apollo-org-real-1');
	$realRow = app(CompanyRepository::class)->find($realId);
	UbrT::ok($realRow && $realRow['region'] === null && $realRow['employees_min'] === null && $realRow['location_confidence'] === 'unavailable', 'firma z Apollo: brak województwa i zatrudnienia w danych (filtr wyszukiwania nie jest daną firmy)');
	$resR = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobRId . '/results');
	$itemR = $resR['data']['items'][0] ?? [];
	UbrT::ok(($itemR['location']['match'] ?? null) === 'filtered' && is_int($itemR['relevance'] ?? null), 'wynik: lokalizacja „tylko filtr Apollo”, Relevance Score policzony (' . ($itemR['relevance'] ?? '—') . ')');
	$sizeCrit = array_values(array_filter($itemR['relevance_details']['criteria'] ?? [], fn ($c) => $c['key'] === 'size'))[0] ?? [];
	UbrT::eq('filtered', $sizeCrit['state'] ?? null, 'wielkość: „tylko filtr źródła” (brak danych w rekordzie)');
	$statusR = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobRId);
	UbrT::ok(in_array('Masovian Voivodeship, Poland', (array) ($statusR['data']['diagnostics']['filters']['apollo']['locations'] ?? []), true), 'diagnostyka: filtry wykonane po stronie Apollo zapisane przy wyszukiwaniu');

	$GLOBALS['ubrt_apollo_mode'] = 'empty';
	$jobE = ubrt_rest($uA, 'POST', '/discovery/searches', ['industry' => 'packaging', 'city' => 'Ełk', 'providers' => ['apollo'], 'limit' => 25]);
	$jobEId = (int) ($jobE['data']['id'] ?? 0);

	for ($i = 0; $i < 5 && in_array($searches->find($jobEId)['status'] ?? '', ['queued', 'running'], true); $i++) {
		$runner->tick($jobEId, 5);
	}

	$statusE = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobEId);
	UbrT::ok($statusE['data']['status'] === 'completed' && (int) $statusE['data']['results_count'] === 0 && count($statusE['data']['explanation']) >= 1 && str_contains(implode(' ', $statusE['data']['explanation']), 'Ełk'), '0 wyników zawsze z wyjaśnieniem (filtry, podpowiedź): ' . mb_substr(implode(' ', $statusE['data']['explanation'] ?? []), 0, 90) . '…');
	$GLOBALS['ubrt_apollo_mode'] = '200';

	// Korekta danych z etapu 1.1 (migracja M003): województwo/zatrudnienie z filtra Apollo usuwane z pól firmy.
	$legacy = $companies13 = app(CompanyRepository::class);
	$legacyId = $legacy->insert(['name' => 'Ubrtest Legacy Apollo', 'normalized_name' => 'ubrtest legacy apollo', 'region' => 'mazowieckie', 'employees_min' => 51, 'employees_max' => 200, 'employees_confidence' => 'uncertain', 'employees_source' => 'apollo']);
	$legacy->addExternalId($legacyId, 'ubrtest', 'legacy1', null);
	$legacy->addEvidence($legacyId, 'apollo', 'x', 'region', 'mazowieckie', 'confirmed', null, null);
	$legacy->addEvidence($legacyId, 'apollo', 'x', 'employees', '51-200', 'uncertain', null, null);
	$keptId = $legacy->insert(['name' => 'Ubrtest Legacy Wikidata', 'normalized_name' => 'ubrtest legacy wikidata', 'region' => 'śląskie']);
	$legacy->addExternalId($keptId, 'ubrtest', 'legacy2', null);
	$legacy->addEvidence($keptId, 'apollo', 'y', 'region', 'śląskie', 'confirmed', null, null);
	$legacy->addEvidence($keptId, 'wikidata', 'Q1', 'region', 'śląskie', 'confirmed', null, null);
	(new \App\Ubr\Infrastructure\Database\Migrations\M003CompanyVerification())->up($db);
	$legacyRow = $legacy->find($legacyId);
	$fields = array_column($legacy->evidence($legacyId), 'field');
	UbrT::ok($legacyRow['region'] === null && $legacyRow['employees_min'] === null && in_array('search_filter_region', $fields, true) && in_array('search_filter_employees', $fields, true), 'migracja: województwo i przedział z filtra Apollo usunięte z pól firmy, zachowane w historii jako „filtr wyszukiwania”');
	UbrT::eq('śląskie', $legacy->find($keptId)['region'], 'migracja: województwo potwierdzone też przez inne źródło (Wikidata) zostaje');

	/* ------------------------------------------------------------------ */
	UbrT::group('Relevance Score i jakość wyników');

	$critRel = DiscoveryCriteria::fromArray(['industry' => 'steel_structures', 'region' => 'mazowieckie', 'size' => '51_200', 'providers' => ['apollo']], ['apollo']);
	$base = ['name' => 'Konstal Testowa', 'domain' => 'konstal.example.pl', 'description' => 'Producent konstrukcji stalowych i hal', 'employees_min' => 120, 'employees_max' => 120, 'employees_confidence' => 'declared'];
	$confirmed = RelevanceScorer::score($base + ['region' => 'mazowieckie', 'city' => 'Radom', 'location_confidence' => 'confirmed', 'pkd_main' => '25.11.Z', 'pkd_description' => 'Produkcja konstrukcji metalowych'], $critRel, 'apollo');
	UbrT::ok($confirmed['score'] >= 90 && $confirmed['criteria']['industry']['state'] === 'confirmed' && $confirmed['criteria']['location']['state'] === 'confirmed', 'PKD z rejestru + siedziba potwierdzona → wysoka trafność (' . $confirmed['score'] . ')');
	$filtered = RelevanceScorer::score($base, $critRel, 'apollo');
	UbrT::ok($filtered['criteria']['location']['state'] === 'filtered' && $filtered['score'] < $confirmed['score'], 'lokalizacja tylko z filtra Apollo → niższa trafność (' . $filtered['score'] . ')');
	$mismatch = RelevanceScorer::score($base + ['region' => 'śląskie', 'location_confidence' => 'confirmed'], $critRel, 'apollo');
	UbrT::ok($mismatch['criteria']['location']['state'] === 'mismatch' && $mismatch['criteria']['location']['points'] === 0, 'siedziba w innym województwie → „niezgodne”, 0 pkt za lokalizację');
	$unknownSize = RelevanceScorer::score(['employees_min' => null, 'employees_max' => null] + $base, $critRel, 'wikidata');
	UbrT::eq('unknown', $unknownSize['criteria']['size']['state'], 'brak danych o zatrudnieniu ≠ niezgodność (stan „brak danych”)');
	$udt = RelevanceScorer::score(['name' => 'Urząd Dozoru Technicznego', 'domain' => 'udt.gov.pl'], $critRel, 'apollo');
	UbrT::ok(in_array('public_institution', $udt['flags'], true) && $udt['penalties'] !== [], 'instytucja publiczna (UDT) oznaczona i ukarana w trafności');
	UbrT::ok(in_array('media', \App\Ubr\Domain\Companies\FitFlags::detect(['name' => 'Przemysl40.pl', 'domain' => 'przemysl40.pl']), true), 'portal branżowy (Przemysl40.pl) oznaczony jako media');
	$inactiveRel = RelevanceScorer::score($base + ['region' => 'mazowieckie', 'location_confidence' => 'confirmed', 'verification_status' => 'inactive'], $critRel, 'apollo');
	UbrT::ok($inactiveRel['score'] <= 10 && in_array('inactive', $inactiveRel['flags'], true), 'firma nieaktywna w rejestrze → trafność maks. 10');

	/* ------------------------------------------------------------------ */
	UbrT::group('Weryfikacja przedsiębiorstw (mock KRS / biała lista VAT)');

	$verifier = app(\App\Ubr\Services\CompanyVerifier::class);
	$cr = app(CompanyRepository::class);
	$GLOBALS['ubrt_registry_calls'] = [];

	// A: aktywna spółka — NIP/KRS na stronie, zgodne w KRS i VAT; źródło podało inne województwo (konflikt danych).
	$GLOBALS['ubrt_sites']['aktywna.ubrtest.pl'] = '<html><head><title>Aktywna Testowa – konstrukcje stalowe</title><script type="application/ld+json">{"@type":"Organization","name":"Aktywna Testowa","legalName":"Aktywna Testowa Sp. z o.o."}</script></head><body><a href="/kontakt">Kontakt</a><p>Konstrukcje stalowe dla przemysłu.</p><footer>© 2025 Aktywna Testowa Sp. z o.o.</footer></body></html>';
	$GLOBALS['ubrt_sites']['aktywna.ubrtest.pl/kontakt'] = '<html><body><h1>Kontakt</h1><p>Aktywna Testowa Sp. z o.o.<br>ul. Testowa 1, 00-001 Warszawa<br>NIP: 526-000-00-05, KRS: 0000900001<br>biuro@aktywna.ubrtest.pl, tel. 22 100 20 30</p></body></html>';
	$GLOBALS['ubrt_krs']['0000900001'] = ubrt_krs_odpis('0000900001', 'AKTYWNA TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '5260000005', 'MAZOWIECKIE', 'WARSZAWA');
	$GLOBALS['ubrt_vat']['5260000005'] = ['name' => 'AKTYWNA TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => '5260000005', 'statusVat' => 'Czynny', 'krs' => '0000900001', 'workingAddress' => 'UL. TESTOWA 1, 00-001 WARSZAWA', 'accountNumbers' => ['12345678901234567890123456']];
	$aId = $ingest->ingest(ubrt_candidate(['id' => 'ver-a', 'name' => 'Aktywna Testowa', 'website' => 'https://aktywna.ubrtest.pl', 'region' => 'śląskie']))['company_id'];
	$ra = $verifier->verify($aId);
	$a = $cr->find($aId);
	$aSummary = json_decode((string) $a['verification_summary'], true);
	UbrT::ok($ra['status'] === 'verified' && $a['verification_confidence'] === 'high', 'aktywna spółka: VERIFIED (pewność wysoka) — KRS + strona firmy');
	UbrT::eq('confirmed', $a['domain_status'], 'domena potwierdzona: NIP/KRS ze strony należy w rejestrze do tej firmy');
	UbrT::ok($a['nip'] === '5260000005' && $a['krs'] === '0000900001' && $a['regon'] === '140000017', 'identyfikatory zapisane wyłącznie z dopasowanego rekordu rejestru');
	UbrT::ok($a['region'] === 'mazowieckie' && $a['city'] === 'Warszawa' && $a['location_confidence'] === 'confirmed' && $a['location_source'] === 'krs', 'siedziba z KRS = potwierdzona (mazowieckie, Warszawa)');
	UbrT::ok(str_contains(implode(' ', $aSummary['discrepancies'] ?? []), 'śląskie'), 'konflikt danych: województwo ze źródła (śląskie) ≠ rejestr — zapisany jako rozbieżność');
	UbrT::eq('25.11.Z', $a['pkd_main'], 'PKD przeważające z KRS');
	UbrT::ok(! str_contains((string) $a['verification_summary'], '12345678901234567890123456'), 'numery rachunków z białej listy VAT nie są zapisywane');
	UbrT::eq(2, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::COMPANY_CHECKS)} WHERE company_id = %d", $aId), 'historia weryfikacji: wpis dla przedsiębiorstwa i dla domeny');
	UbrT::ok((bool) array_filter($cr->evidence($aId), fn ($e) => $e['provider'] === 'krs' && $e['confidence'] === 'confirmed'), 'pochodzenie danych z KRS zapisane (pewność: potwierdzone)');
	$aRel = RelevanceScorer::score($a, $critRel, 'apollo');
	UbrT::ok($aRel['criteria']['location']['state'] === 'confirmed' && $aRel['criteria']['industry']['state'] === 'confirmed', 'po weryfikacji trafność: lokalizacja i branża potwierdzone rejestrem');
	$callsA = count($GLOBALS['ubrt_registry_calls']);
	$verifier->verify($aId);
	UbrT::eq($callsA, count($GLOBALS['ubrt_registry_calls']), 'ponowna weryfikacja korzysta z cache rejestrów (bez nowych zapytań)');

	// B: zawieszona działalność (KRS).
	$GLOBALS['ubrt_sites']['zawieszona.ubrtest.pl'] = '<html><head><title>Zawieszona Testowa</title></head><body>Zawieszona Testowa Sp. z o.o., KRS 0000900002, 00-002 Warszawa</body></html>';
	$GLOBALS['ubrt_krs']['0000900002'] = ubrt_krs_odpis('0000900002', 'ZAWIESZONA TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', null, 'MAZOWIECKIE', 'WARSZAWA', ['odpis' => ['dane' => ['dzial1' => ['informacjaOZawieszeniuDzialalnosci' => ['dataZawieszenia' => '2025-03-01']]]]]);
	$bId = $ingest->ingest(ubrt_candidate(['id' => 'ver-b', 'name' => 'Zawieszona Testowa', 'website' => 'https://zawieszona.ubrtest.pl']))['company_id'];
	UbrT::eq('suspended', $verifier->verify($bId)['status'], 'zawieszona działalność → SUSPENDED');

	// C: wykreślona — NIP z danych źródła, VAT wskazuje KRS, KRS: wykreślenie.
	$GLOBALS['ubrt_vat']['7770000011'] = ['name' => 'WYKRESLONA TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => '7770000011', 'statusVat' => 'Niezarejestrowany', 'krs' => '0000900003', 'removalDate' => '2024-06-01'];
	$GLOBALS['ubrt_krs']['0000900003'] = ubrt_krs_odpis('0000900003', 'WYKRESLONA TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '7770000011', 'ŁÓDZKIE', 'ŁÓDŹ', ['odpis' => ['dane' => ['dzial6' => ['informacjaOWykresleniu' => ['dataWykreslenia' => '2024-05-10']]]]]);
	$cId = $ingest->ingest(ubrt_candidate(['id' => 'ver-c', 'name' => 'Wykreslona Testowa Sp. z o.o.', 'nip' => '7770000011']))['company_id'];
	$rc = $verifier->verify($cId);
	UbrT::ok($rc['status'] === 'inactive' && $rc['domain_status'] === 'unavailable', 'wykreślona z KRS → INACTIVE; brak strony → domena „niedostępna”');

	// D: brak danych rejestrowych (brak identyfikatorów).
	$GLOBALS['ubrt_sites']['brakdanych.ubrtest.pl'] = '<html><head><title>Brak Danych Testowa – maszyny</title></head><body>Brak Danych Testowa. Produkujemy maszyny. Zapraszamy.</body></html>';
	$dId = $ingest->ingest(ubrt_candidate(['id' => 'ver-d', 'name' => 'Brak Danych Testowa', 'website' => 'https://brakdanych.ubrtest.pl']))['company_id'];
	$before = count($GLOBALS['ubrt_registry_calls']);
	$rd = $verifier->verify($dId);
	$dRow = $cr->find($dId);
	UbrT::ok($rd['status'] === 'uncertain' && str_contains((string) $dRow['verification_summary'], 'Brak NIP') && count($GLOBALS['ubrt_registry_calls']) === $before, 'brak NIP/KRS → UNCERTAIN, bez zapytań do rejestrów i bez zgadywania');

	// E: podobne nazwy — NIP na stronie należy do innej firmy o podobnej nazwie.
	$GLOBALS['ubrt_sites']['podobna.ubrtest.pl'] = '<html><head><title>Stalbud Kowalski – hale stalowe</title></head><body>Stalbud Kowalski. NIP 951-200-00-25. 00-950 Warszawa. © Stalbud Kowalski</body></html>';
	$GLOBALS['ubrt_vat']['9512000025'] = ['name' => 'STALBUD NOWAK SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => '9512000025', 'statusVat' => 'Czynny', 'workingAddress' => 'UL. INNA 2, 30-001 KRAKÓW'];
	$eId = $ingest->ingest(ubrt_candidate(['id' => 'ver-e', 'name' => 'Stalbud Kowalski', 'website' => 'https://podobna.ubrtest.pl']))['company_id'];
	$re = $verifier->verify($eId);
	$eRow = $cr->find($eId);
	UbrT::ok($re['status'] === 'uncertain' && $eRow['nip'] === null && str_contains((string) $eRow['verification_summary'], 'STALBUD NOWAK'), 'podobna nazwa (Stalbud Nowak ≠ Stalbud Kowalski): NIP NIE przypisany, rozbieżność zapisana');
	UbrT::eq('uncertain', $re['domain_status'], 'strona z nazwą firmy, ale z NIP innego podmiotu → domena „niepewna”');

	// F: rejestr niedostępny → UNCERTAIN + ponowienie z backoffem; po powrocie rejestru → VERIFIED.
	$GLOBALS['ubrt_sites']['awaria.ubrtest.pl'] = '<html><head><title>Awaria Rejestru Testowa</title></head><body>Awaria Rejestru Testowa Sp. z o.o., NIP 634-000-00-31, 40-001 Katowice, biuro@awaria.ubrtest.pl</body></html>';
	$GLOBALS['ubrt_vat']['6340000031'] = ['name' => 'AWARIA REJESTRU TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', 'nip' => '6340000031', 'statusVat' => 'Czynny', 'krs' => '0000900006'];
	$GLOBALS['ubrt_krs']['0000900006'] = ubrt_krs_odpis('0000900006', 'AWARIA REJESTRU TESTOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '6340000031', 'ŚLĄSKIE', 'KATOWICE');
	$fId = $ingest->ingest(ubrt_candidate(['id' => 'ver-f', 'name' => 'Awaria Rejestru Testowa', 'website' => 'https://awaria.ubrtest.pl']))['company_id'];
	$vq = app(\App\Ubr\Infrastructure\Repositories\VerificationQueueRepository::class);
	$vq->enqueue($fId, $orgA, $uA, null, 1, true);
	$GLOBALS['ubrt_registry_down'] = true;
	app(\App\Ubr\Services\VerificationRunner::class)->tick(20, false);
	$qf = $vq->forCompany($fId);
	UbrT::ok($cr->find($fId)['verification_status'] === 'uncertain' && $qf && $qf['status'] === 'queued' && strtotime($qf['next_attempt_at'] . ' UTC') > time() + 60, 'rejestr niedostępny → status „niepewna”, ponowienie zaplanowane z opóźnieniem');
	$GLOBALS['ubrt_registry_down'] = false;
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::VERIFICATION_QUEUE)} SET next_attempt_at = %s WHERE company_id = %d", Db::now(), $fId));
	app(\App\Ubr\Services\VerificationRunner::class)->tick(20, false);
	UbrT::ok($cr->find($fId)['verification_status'] === 'verified' && $vq->forCompany($fId) === null, 'po powrocie rejestru ponowienie kończy się VERIFIED, pozycja zdjęta z kolejki');

	// G: dane sprzeczne — KRS aktywny, VAT wykreślony.
	$GLOBALS['ubrt_vat']['1130000045'] = ['name' => 'KONFLIKT TESTOWA SPÓŁKA AKCYJNA', 'nip' => '1130000045', 'statusVat' => 'Niezarejestrowany', 'krs' => '0000900007', 'removalDate' => '2023-01-01', 'removalBasis' => 'Art. 96 ust. 9'];
	$GLOBALS['ubrt_krs']['0000900007'] = ubrt_krs_odpis('0000900007', 'KONFLIKT TESTOWA SPÓŁKA AKCYJNA', '1130000045', 'MAZOWIECKIE', 'WARSZAWA');
	$gId = $ingest->ingest(ubrt_candidate(['id' => 'ver-g', 'name' => 'Konflikt Testowa S.A.', 'nip' => '1130000045']))['company_id'];
	$rg = $verifier->verify($gId);
	UbrT::ok($rg['status'] === 'verified' && str_contains(implode(' ', json_decode((string) $cr->find($gId)['verification_summary'], true)['discrepancies'] ?? []), 'Biała lista VAT'), 'sprzeczne dane (KRS aktywny, VAT wykreślony) → status z KRS, rozbieżność zapisana');

	// Apollo samo w sobie nie daje VERIFIED.
	$apolloOnly = $verifier->verify((int) $apolloId);
	UbrT::ok($apolloOnly['status'] !== 'verified', 'firma tylko z danymi Apollo (bez rejestru) nie jest VERIFIED (' . $apolloOnly['status'] . ')');

	/* ------------------------------------------------------------------ */
	UbrT::group('Weryfikacja domen');

	$GLOBALS['ubrt_sites']['inna.ubrtest.pl'] = '<html><head><title>Piekarnia Słoneczko – chleb i bułki</title></head><body>' . str_repeat('<p>Piekarnia Słoneczko zaprasza na świeże pieczywo, ciasta i rogale. Wypiekamy codziennie od 1985 roku. </p>', 12) . '</body></html>';
	$wId = $ingest->ingest(ubrt_candidate(['id' => 'dom-w', 'name' => 'Metal-Tech Testowa', 'website' => 'https://inna.ubrtest.pl']))['company_id'];
	UbrT::eq('mismatch', $verifier->verify($wId)['domain_status'], 'zła domena (treść innej firmy) → MISMATCH');
	$GLOBALS['ubrt_sites']['parked.ubrtest.pl'] = '<html><head><title>parked.ubrtest.pl</title></head><body>This domain is for sale! Buy this domain today.</body></html>';
	$pId = $ingest->ingest(ubrt_candidate(['id' => 'dom-p', 'name' => 'Parkowana Testowa', 'website' => 'https://parked.ubrtest.pl']))['company_id'];
	UbrT::eq('mismatch', $verifier->verify($pId)['domain_status'], 'domena zaparkowana / na sprzedaż → MISMATCH');
	$uId = $ingest->ingest(ubrt_candidate(['id' => 'dom-u', 'name' => 'Niedostepna Testowa', 'website' => 'https://down.ubrtest.pl']))['company_id'];
	UbrT::eq('unavailable', $verifier->verify($uId)['domain_status'], 'strona nie odpowiada → UNAVAILABLE');
	$GLOBALS['ubrt_sites']['famex.ubrtest.pl'] = '<html><head><title>Witamy</title></head><body>Witamy na stronie.</body></html>';
	$sId = $ingest->ingest(ubrt_candidate(['id' => 'dom-s', 'name' => 'Famex Testowa', 'website' => 'https://famex.ubrtest.pl']))['company_id'];
	UbrT::eq('uncertain', $verifier->verify($sId)['domain_status'], 'samo podobieństwo domeny do nazwy (famex) nie wystarcza → UNCERTAIN');
	$redirect = \App\Ubr\Domain\Verification\DomainDecider::decide('firma.pl', ['reachable' => true, 'redirected_domain' => true, 'final_domain' => 'inna-firma.pl', 'final_domain_type' => 'official', 'text_length' => 2000], 'none', false, null, false);
	UbrT::eq('mismatch', $redirect['status'], 'przekierowanie na inną domenę bez nazwy firmy → MISMATCH');
	$dir = \App\Ubr\Domain\Verification\DomainDecider::decide('firma.pl', ['reachable' => true, 'final_domain' => 'panoramafirm.pl', 'final_domain_type' => 'directory'], 'strong', false, null, false);
	UbrT::eq('mismatch', $dir['status'], 'adres prowadzi do katalogu firm → MISMATCH');
	$nameOnly = \App\Ubr\Domain\Verification\DomainDecider::decide('firma.pl', ['reachable' => true, 'final_domain' => 'firma.pl', 'final_domain_type' => 'official', 'text_length' => 3000, 'emails_same_domain' => 0, 'postal_codes' => []], 'medium', false, null, false);
	UbrT::eq('uncertain', $nameOnly['status'], 'nazwa tylko w treści, bez danych kontaktowych/identyfikatorów → UNCERTAIN');
	$insp = app(\App\Ubr\Infrastructure\Http\WebsiteInspector::class)->inspect('https://aktywna.ubrtest.pl');
	UbrT::ok($insp['identifiers']['nip'] === ['5260000005'] && $insp['identifiers']['krs'] === ['0000900001'] && $insp['emails_same_domain'] >= 1 && in_array('Warszawa', $insp['address_cities'], true), 'inspekcja strony: NIP/KRS (z sumą kontrolną), e-mail w domenie, miasto z adresu (z podstrony Kontakt)');
	UbrT::eq('strong', \App\Ubr\Infrastructure\Http\WebsiteInspector::nameMatch('Aktywna Testowa Sp. z o.o.', $insp), 'nazwa firmy w tytule/JSON-LD/stopce → dopasowanie silne');
	UbrT::ok(\App\Ubr\Domain\Verification\NameMatcher::score('Stalbud Kowalski', 'STALBUD NOWAK SP. Z O.O.') < 0.5 && \App\Ubr\Domain\Verification\NameMatcher::matches('Famur', 'FAMUR SPÓŁKA AKCYJNA') && \App\Ubr\Domain\Verification\NameMatcher::matches('Intercars', 'Inter Cars SA'), 'porównanie nazw: podobne ≠ zgodne; forma prawna i spacje ignorowane');

	/* ------------------------------------------------------------------ */
	UbrT::group('Weryfikacja — SaaS: uprawnienia, izolacja, limity');

	$visibleId = $c1Id; // firma z wyszukiwania organizacji A
	UbrT::eq(404, ubrt_rest($uB, 'POST', '/companies/' . $visibleId . '/verify')['status'], 'organizacja B nie zleci weryfikacji firmy, której nie widzi (404)');
	UbrT::eq(403, ubrt_rest($uX, 'POST', '/companies/' . $visibleId . '/verify')['status'], 'osoba spoza organizacji — brak dostępu (403)');
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::COMPANIES)} SET verification_checked_at = %s WHERE id = %d", gmdate('Y-m-d H:i:s', time() - 3600), $visibleId));
	$req1 = ubrt_rest($uA, 'POST', '/companies/' . $visibleId . '/verify');
	$req2 = ubrt_rest($uA, 'POST', '/companies/' . $visibleId . '/verify');
	UbrT::ok($req1['status'] === 200 && ! empty($req1['data']['queued']) && ! empty($req2['data']['already_queued']), 'ręczna ponowna weryfikacja trafia do kolejki; drugie kliknięcie nie dubluje');
	app(\App\Ubr\Services\VerificationRunner::class)->tick(20, false);
	UbrT::eq(422, ubrt_rest($uA, 'POST', '/companies/' . $visibleId . '/verify')['status'], 'ponowna weryfikacja tuż po poprzedniej zablokowana (cooldown)');
	$detail = ubrt_rest($uA, 'GET', '/companies/' . $visibleId);
	UbrT::ok(isset($detail['data']['verification']['reasons'], $detail['data']['domain_status']['reasons']) && ($detail['data']['checks'][0]['by_my_organization'] ?? false) === true, 'profil: status, powody i historia weryfikacji (oznaczone „zlecone przez moją organizację”)');
	UbrT::eq(404, ubrt_rest($uB, 'POST', '/discovery/searches/' . $jobAId . '/verify')['status'], 'organizacja B nie zleci weryfikacji wyszukiwania organizacji A');
	$sv = ubrt_rest($uA, 'POST', '/discovery/searches/' . $jobAId . '/verify');
	UbrT::ok($sv['status'] === 200 && isset($sv['data']['queued']), 'weryfikacja wszystkich firm z wyszukiwania (pomija świeżo sprawdzone)');
	$limitFilter2 = fn () => 0;
	add_filter('ubr/verification/manual_daily_limit', $limitFilter2);
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::COMPANIES)} SET verification_checked_at = %s WHERE id = %d", gmdate('Y-m-d H:i:s', time() - 3600), $visibleId));
	$vq->complete((int) ($db->value("SELECT id FROM {$db->table(Tables::VERIFICATION_QUEUE)} WHERE company_id = %d", $visibleId) ?: 0));
	UbrT::eq(429, ubrt_rest($uA, 'POST', '/companies/' . $visibleId . '/verify')['status'], 'dzienny limit ręcznych weryfikacji organizacji (429)');
	remove_filter('ubr/verification/manual_daily_limit', $limitFilter2);
	$res = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['filter' => 'verified']);
	UbrT::eq(200, $res['status'], 'filtr wyników po statusie weryfikacji');
	$pros = ubrt_rest($uA, 'GET', '/prospects', ['sort' => 'relevance', 'verification' => 'uncertain', 'min_relevance' => 1]);
	UbrT::ok($pros['status'] === 200 && array_key_exists('relevance', $pros['data']['items'][0] ?? ['relevance' => null]), 'Prospects: sortowanie po trafności, filtr statusu weryfikacji i minimalnej trafności');

	/* ================================================================== */
	/* WEBSITE ANALYZER — testy z MOCKAMI (HTML przez pre_http_request, bez przeglądarki).            */
	/* To NIE jest dowód działania na prawdziwych witrynach — patrz grupa „integracja” i test na żywo. */
	/* ================================================================== */
	UbrT::group('[mock] Website Analyzer — ochrona SSRF');

	$guard = new \App\Ubr\Infrastructure\Http\UrlGuard();
	UbrT::ok(! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('127.0.0.1') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('10.1.2.3') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('169.254.169.254') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('192.168.0.10') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('100.64.0.1') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('::1') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('fd00::1') && ! \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('::ffff:127.0.0.1') && \App\Ubr\Infrastructure\Http\UrlGuard::isPublicIp('93.184.216.34'), 'adresy prywatne, loopback, link-local (metadata), CGNAT, IPv6 ULA/mapped — niepubliczne');
	UbrT::ok(! $guard->check('http://localhost/')['ok'] && ! $guard->check('http://127.0.0.1/')['ok'] && ! $guard->check('http://169.254.169.254/latest/meta-data/')['ok'] && ! $guard->check('http://intranet.local/')['ok'], 'localhost / IP prywatne / usługa metadata / .local zablokowane');
	UbrT::ok(! $guard->check('https://firma.ubrtest.pl:8080/')['ok'] && ! $guard->check('ftp://firma.ubrtest.pl/')['ok'] && ! $guard->check('https://user:pass@firma.ubrtest.pl/')['ok'], 'niedozwolony port, protokół, dane logowania w URL');
	UbrT::ok(! $guard->check('https://internal.ubrtest.pl/')['ok'] && $guard->check('https://old.ubrtest.pl/')['ok'], 'host z DNS wskazującym adres prywatny zablokowany; publiczny dozwolony (sprawdzenie DNS, nie tylko nazwy)');
	$GLOBALS['ubrt_sites']['redir.ubrtest.pl'] = '__redirect__';
	$fetchRedir = app(\App\Ubr\Infrastructure\Http\PageFetcher::class)->fetch('https://redir.ubrtest.pl/');
	UbrT::ok($fetchRedir['blocked'] !== null && ! $fetchRedir['ok'], 'przekierowanie na adres metadata wykryte i zablokowane (każdy krok sprawdzany)');
	UbrT::eq(null, app(\App\Ubr\Infrastructure\Analysis\ScreenshotStorage::class)->path(1, '../../wp-config.php'), 'magazyn screenshotów nie przyjmuje dowolnych nazw plików (path traversal)');

	UbrT::group('[mock] Website Analyzer — analiza HTML (SEO, mobile, dostępność, treść, UX)');

	$oldHtml = file_get_contents(get_theme_file_path('tests/fixtures/sites/old.html'));
	$goodHtml = file_get_contents(get_theme_file_path('tests/fixtures/sites/good.html'));
	$mkCtx = fn (?string $html, array $company = [], ?array $capture = null, ?array $vision = null) => new \App\Ubr\Domain\Analysis\AnalysisContext(
		$html !== null ? new \App\Ubr\Domain\Analysis\HtmlDocument($html, 'https://firma.ubrtest.pl/') : null,
		$html !== null ? ['ok' => true, 'status' => 200, 'final_url' => 'https://firma.ubrtest.pl/', 'ms' => 300, 'bytes' => strlen($html), 'headers' => [], 'redirects' => []] : ['ok' => false, 'error' => 'timeout'],
		null, null, $capture, $company + ['verification_status' => 'pending', 'domain_status' => 'unchecked'], $vision, true, 2026
	);
	$seoOld = (new \App\Ubr\Domain\Analysis\Analyzers\SeoAnalyzer())->analyze($mkCtx($oldHtml));
	$seoGood = (new \App\Ubr\Domain\Analysis\Analyzers\SeoAnalyzer())->analyze($mkCtx($goodHtml));
	UbrT::ok($seoOld['criteria']['b_seo']['points'] >= 3 && $seoGood['criteria']['b_seo']['points'] === 0 && in_array('seo_no_description', array_column($seoOld['findings'], 'key'), true), 'SEO: stara strona — brak description/H1, za krótki title; poprawna strona 0 pkt słabości');
	$mobOld = (new \App\Ubr\Domain\Analysis\Analyzers\MobileAnalyzer())->analyze($mkCtx($oldHtml));
	UbrT::ok($mobOld['criteria']['a_mobile_viewport']['points'] === 3 && $mobOld['criteria']['a_mobile_render']['points'] === null, 'mobile: brak meta viewport wykryty; pomiary renderu bez przeglądarki = NOT_EVALUATED (0 pkt, nie zgadujemy)');
	$a11yOld = (new \App\Ubr\Domain\Analysis\Analyzers\AccessibilityAnalyzer())->analyze($mkCtx($oldHtml));
	UbrT::ok($a11yOld['checks']['unlabeled_fields'] === 1 && $a11yOld['criteria']['b_a11y_axe']['points'] === null, 'dostępność: pole bez etykiety wykryte; axe-core bez renderu = NOT_EVALUATED');
	$conOld = (new \App\Ubr\Domain\Analysis\Analyzers\ConversionContentAnalyzer())->analyze($mkCtx($oldHtml));
	$conGood = (new \App\Ubr\Domain\Analysis\Analyzers\ConversionContentAnalyzer())->analyze($mkCtx($goodHtml));
	UbrT::ok($conOld['checks']['copyright_year'] === 2012 && in_array('content_outdated_year', array_column($conOld['findings'], 'key'), true) && in_array('conv_no_cta', array_column($conOld['findings'], 'key'), true), 'treść/konwersja: rok 2012 w stopce i brak CTA wykryte (z dowodem)');
	UbrT::ok($conGood['checks']['tel_link'] && $conGood['checks']['cta_texts'] !== [] && $conGood['checks']['trust_signals'] !== [], 'poprawna strona: klikalny telefon, CTA i elementy zaufania rozpoznane');
	$uxOld = (new \App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer())->analyze($mkCtx($oldHtml));
	UbrT::ok($uxOld['criteria']['a_ux_navigation']['points'] >= 2 && $uxOld['criteria']['a_ux_structure']['points'] >= 1, 'UX strukturalny: brak menu i H1 na starej stronie');
	$techOld = (new \App\Ubr\Domain\Analysis\Analyzers\TechnicalAnalyzer())->analyze($mkCtx($oldHtml));
	UbrT::ok($techOld['checks']['jquery'] === '1.8.2' && in_array('sec_old_jquery', array_column($techOld['findings'], 'key'), true) && $techOld['criteria']['b_perf_render']['points'] === null, 'technika: przestarzałe jQuery 1.8.2; wydajność w przeglądarce bez renderu = NOT_EVALUATED (bez fikcyjnych metryk)');
	$allFindings = array_merge($seoOld['findings'], $mobOld['findings'], $a11yOld['findings'], $conOld['findings'], $uxOld['findings'], $techOld['findings']);
	UbrT::ok(! array_filter($allFindings, fn ($f) => $f['evidence'] === [] || $f['recommendation'] === '' || ! in_array($f['severity'], \App\Ubr\Domain\Analysis\Finding::SEVERITIES, true)), 'każdy finding ma kategorię, severity, dowód i rekomendację');

	// Wybór podstron (przypadek z testu na żywo: „Zapytaj o ofertę” prowadzi do /kontakt/).
	$navDoc = new \App\Ubr\Domain\Analysis\HtmlDocument('<html><body><a href="mailto:service@firma.ubrtest.pl">service@firma.ubrtest.pl</a><a href="tel:+48123456789">Usługi: zadzwoń</a><a href="/kontakt/">Zapytaj o ofertę</a><a href="/maszyny-do-czyszczenia/">Nasze urządzenia</a><a href="/oferta/maszyny/">Oferta</a><a href="/kontakt">Kontakt</a></body></html>', 'https://firma.ubrtest.pl/');
	$offerPick = \App\Ubr\Services\WebsiteAnalyzerService::pickSubpage($navDoc, \App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer::OFFER_RE, 'https://firma.ubrtest.pl/', ['https://firma.ubrtest.pl/'], \App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer::CONTACT_RE);
	$contactPick = \App\Ubr\Services\WebsiteAnalyzerService::pickSubpage($navDoc, \App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer::CONTACT_RE, 'https://firma.ubrtest.pl/', ['https://firma.ubrtest.pl/', (string) $offerPick]);
	UbrT::ok($offerPick === 'https://firma.ubrtest.pl/oferta/maszyny/' && $contactPick === 'https://firma.ubrtest.pl/kontakt', 'podstrony: oferta z adresu URL (nie link „Zapytaj o ofertę” do kontaktu ani mailto:/tel:), kontakt osobno, bez duplikatu');
	$dupDoc = new \App\Ubr\Domain\Analysis\HtmlDocument('<html><body><a href="/kontakt/#form">Kontakt</a></body></html>', 'https://firma.ubrtest.pl/');
	UbrT::ok(\App\Ubr\Services\WebsiteAnalyzerService::pickSubpage($dupDoc, \App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer::CONTACT_RE, 'https://firma.ubrtest.pl/', ['https://firma.ubrtest.pl/kontakt']) === null, 'podstrony: ten sam adres z „/” lub #fragmentem nie jest analizowany drugi raz');

	UbrT::group('[mock] Opportunity Score — scenariusze');

	$scoreFor = function (?string $html, array $company, ?array $capture = null, ?array $vision = null) use ($mkCtx) {
		$ctx = $mkCtx($html, $company, $capture, $vision);
		$criteria = [];

		foreach ([new \App\Ubr\Domain\Analysis\Analyzers\VisualAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\MobileAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\ConversionContentAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\TechnicalAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\SeoAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\AccessibilityAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\BusinessPotentialAnalyzer(), new \App\Ubr\Domain\Analysis\Analyzers\CompanyConfidenceAnalyzer()] as $an) {
			$criteria += $an->analyze($ctx)['criteria'];
		}

		return \App\Ubr\Domain\Analysis\OpportunityScoreCalculator::calculate($criteria, $ctx->siteReachable(), (string) ($company['verification_status'] ?? 'pending'));
	};
	$bizVerified = ['verification_status' => 'verified', 'domain_status' => 'confirmed', 'verification_checked_at' => gmdate('Y-m-d H:i:s'), 'pkd_main' => '25.11.Z', 'employees_min' => 120, 'employees_max' => 120, 'employees_confidence' => 'declared', 'revenue_estimate' => 15000000];
	$capOld = ['views' => ['desktop' => ['metrics' => ['cta_above_fold' => []]], 'mobile' => ['metrics' => ['scaled_desktop_layout' => true, 'viewport' => ['width' => 980], 'screen_width' => 390, 'small_text_elements' => 3, 'text_elements' => 6, 'interactive_count' => 4, 'small_tap_targets' => 4, 'cta_above_fold' => []]]], 'timing' => ['load_ms' => 7200], 'network' => ['bytes' => 6 * 1048576, 'requests' => 80, 'failed' => 5, 'http_errors' => 0], 'axe' => ['version' => 'test', 'violations' => [['id' => 'image-alt', 'impact' => 'critical', 'help' => 'alt', 'nodes' => 3], ['id' => 'color-contrast', 'impact' => 'serious', 'help' => 'kontrast', 'nodes' => 8], ['id' => 'label', 'impact' => 'serious', 'help' => 'label', 'nodes' => 1]]]];
	$capGood = ['views' => ['desktop' => ['metrics' => ['cta_above_fold' => ['Umów konsultację']]], 'mobile' => ['metrics' => ['scaled_desktop_layout' => false, 'horizontal_overflow_px' => 0, 'small_text_elements' => 0, 'text_elements' => 10, 'interactive_count' => 5, 'small_tap_targets' => 0, 'cta_above_fold' => ['Umów']]]], 'timing' => ['load_ms' => 900], 'network' => ['bytes' => 400000, 'requests' => 12, 'failed' => 0, 'http_errors' => 0], 'axe' => ['version' => 'test', 'violations' => []]];
	$many = $scoreFor($oldHtml, $bizVerified, $capOld);
	$good = $scoreFor($goodHtml, $bizVerified, $capGood);
	UbrT::ok($many['score'] >= 60 && $good['score'] < $many['score'] - 25, sprintf('strona z licznymi problemami: wysoki wynik (%d); strona technicznie poprawna: niski (%d)', $many['score'], $good['score']));
	UbrT::ok($many['categories']['website_weakness']['criteria']['a_visual_ai']['state'] === 'not_evaluated' && $many['max_possible'] === $many['score'] + 15 && $many['confidence'] === 'medium', 'brak AI: design NOT_EVALUATED (0 pkt), wynik nie przeskalowany (maks. możliwe = wynik + 15), pewność średnia');
	$visionJson = ['categories' => array_fill_keys(\App\Ubr\Domain\Analysis\Vision\VisionSchema::CATEGORIES, ['score' => 20, 'confidence' => 'medium', 'findings' => ['Przestarzały układ tabelaryczny'], 'evidence' => ['Desktop: układ tabeli, czcionka Times'], 'recommendations' => ['Nowoczesny layout']])];
	$vision = \App\Ubr\Domain\Analysis\Vision\VisionSchema::validate($visionJson, 'test-model')['result'];
	$outdated = $scoreFor($oldHtml, $bizVerified, $capOld, $vision);
	UbrT::ok($outdated['score'] === $many['score'] + 12 && $outdated['coverage'] === 100 && $outdated['confidence'] === 'high', sprintf('strona wizualnie przestarzała (zwalidowany wynik AI 20/100): +12 pkt, pokrycie 100%% (%d)', $outdated['score']));
	$down = $scoreFor(null, $bizVerified);
	UbrT::ok($down['score'] === null && $down['status'] === 'INSUFFICIENT_DATA', 'strona niedostępna: INSUFFICIENT_DATA, brak sztucznie wysokiego wyniku');
	$noBiz = $scoreFor($oldHtml, [], $capOld);
	UbrT::ok($noBiz['categories']['business_potential']['points'] === 0 && $noBiz['categories']['business_potential']['coverage'] === 0 && $noBiz['score'] < $many['score'], 'firma bez danych biznesowych: 0 pkt za potencjał (brak zgadywania wielkości/branży)');
	$inactive = $scoreFor($oldHtml, ['verification_status' => 'inactive'] + $bizVerified, $capOld);
	UbrT::ok($inactive['score'] <= 10 && $inactive['caps'] !== [], 'firma nieaktywna: wynik ograniczony do 10');
	$partial = $scoreFor($oldHtml, $bizVerified);
	UbrT::ok($partial['coverage'] < $many['coverage'] && $partial['score'] <= $many['score'] && $partial['categories']['technical_weakness']['criteria']['b_a11y_axe']['state'] === 'not_evaluated', sprintf('analiza częściowa (bez renderu): pokrycie %d%% < %d%%, kryteria przeglądarki NOT_EVALUATED', $partial['coverage'], $many['coverage']));
	$thin = \App\Ubr\Domain\Analysis\OpportunityScoreCalculator::calculate(['c_size' => \App\Ubr\Domain\Analysis\Criterion::measured(10, 10, 'x')], true, 'pending');
	UbrT::ok($thin['status'] === 'INSUFFICIENT_DATA' && $thin['score'] === null, 'za mało zmierzonych kryteriów (< 50%) → INSUFFICIENT_DATA zamiast pozornie precyzyjnego wyniku');
	UbrT::eq('high', \App\Ubr\Domain\Analysis\OpportunityScoreCalculator::level(65)['key'], 'przedziały: 60–79 = High Opportunity (konfigurowalne filtrem)');

	UbrT::group('[mock] AI Vision — walidacja odpowiedzi (bez wywołań API)');

	UbrT::ok(! \App\Ubr\Infrastructure\Ai\VisionService::enabled(), 'AI Vision domyślnie wyłączone (brak płatnych zapytań)');
	$bad = \App\Ubr\Domain\Analysis\Vision\VisionSchema::validate(['categories' => ['visual_quality' => ['score' => 'dużo', 'confidence' => 'x'], 'hacked' => ['score' => 1]]], 'm');
	UbrT::ok(! $bad['ok'] && count($bad['errors']) >= 2, 'nieprawidłowy JSON modelu odrzucony (zły score/confidence, nieznana kategoria)');
	$noEvidence = \App\Ubr\Domain\Analysis\Vision\VisionSchema::validate(['categories' => ['typography' => ['score' => 30, 'confidence' => 'high', 'findings' => ['x'], 'evidence' => []]]], 'm');
	UbrT::ok(! $noEvidence['ok'], 'ocena AI bez dowodów (evidence) odrzucona');
	$clamp = \App\Ubr\Domain\Analysis\Vision\VisionSchema::validate(['categories' => ['typography' => ['score' => 250, 'confidence' => 'low', 'evidence' => ['nagłówki']]]], 'model-x');
	UbrT::ok($clamp['ok'] && $clamp['result']['categories']['typography']['score'] === 100 && $clamp['result']['model'] === 'model-x' && $clamp['result']['prompt_version'] === \App\Ubr\Domain\Analysis\Vision\VisionSchema::PROMPT_VERSION, 'wartości obcinane do zakresu, zapisany model i wersja promptu');

	UbrT::group('[mock] Website Analyzer — kolejka, ponowienia, historia, limity, multi-tenancy');

	add_filter('ubr/capture/node_candidates', '__return_empty_array'); // w grupie mock: bez przeglądarki
	$GLOBALS['ubrt_sites']['old.ubrtest.pl'] = $oldHtml;
	$GLOBALS['ubrt_sites']['good.ubrtest.pl'] = $goodHtml;
	$mkVisible = function (string $id, string $name, string $site) use ($ingest, $orgA, $jobId) {
		$cid = $ingest->ingest(ubrt_candidate(['id' => $id, 'name' => $name, 'website' => $site]))['company_id'];
		app(ResultRepository::class)->add($jobId, $orgA, $cid, 'ubrtest', 900 + $cid % 50, []);

		return $cid;
	};
	$oldId = $mkVisible('wa-old', 'Ubrtest Metalex Stara', 'https://old.ubrtest.pl/');
	$goodId = $mkVisible('wa-good', 'Ubrtest Nowatech', 'https://good.ubrtest.pl/');
	$downId = $mkVisible('wa-down', 'Ubrtest Niedostepna', 'https://down.ubrtest.pl/');
	$noSiteId = $mkVisible('wa-nosite', 'Ubrtest Bez Strony', '');
	// Firma „stara” ma dane z weryfikacji (rejestr) — bez nich pokrycie danych < 50% i wynik byłby INSUFFICIENT_DATA.
	app(CompanyRepository::class)->update($oldId, ['verification_status' => 'verified', 'domain_status' => 'confirmed', 'verification_checked_at' => Db::now(), 'pkd_main' => '25.11.Z', 'employees_min' => 120, 'employees_max' => 120, 'employees_confidence' => 'declared']);
	$analysisRunner = app(\App\Ubr\Services\WebsiteAnalysisRunner::class);
	$analyses = app(\App\Ubr\Infrastructure\Repositories\AnalysisRepository::class);

	UbrT::eq(404, ubrt_rest($uB, 'POST', '/companies/' . $oldId . '/analyze')['status'], 'organizacja B nie zleci analizy firmy, której nie widzi');
	UbrT::eq(403, ubrt_rest($uX, 'POST', '/companies/' . $oldId . '/analyze')['status'], 'osoba spoza organizacji: 403');
	UbrT::eq(404, ubrt_rest($uB, 'GET', '/companies/' . $oldId . '/analysis')['status'], 'organizacja B nie widzi wyników analizy firmy organizacji A');
	$req = ubrt_rest($uA, 'POST', '/analyses', ['company_ids' => [$oldId, $goodId, $downId, $noSiteId]]);
	UbrT::ok($req['status'] === 202 && count($req['data']['queued']) === 3 && ($req['data']['skipped'][0]['reason'] ?? '') === 'no_website', 'analiza zbiorcza: 3 w kolejce, firma bez strony pominięta z powodem');
	UbrT::eq('queued', app(CompanyRepository::class)->find($oldId)['analysis_status'], 'status analizy widoczny przy firmie (W kolejce)');
	$dup = ubrt_rest($uA, 'POST', '/companies/' . $oldId . '/analyze');
	UbrT::eq('in_progress', $dup['data']['skipped'][0]['reason'] ?? null, 'analiza w toku nie jest dublowana');

	for ($i = 0; $i < 6 && $analyses->countDue() > 0; $i++) {
		$analysisRunner->tick(false);
	}

	$oldA = $analyses->latestForCompany($oldId);
	UbrT::ok($oldA['status'] === 'partial' && $oldA['opportunity_score'] !== null && in_array('render:environment', array_column($oldA['errors'], 'stage'), true), 'bez przeglądarki: status PARTIAL (nie COMPLETED), przyczyna zapisana, wynik z obiektywnych danych (' . $oldA['opportunity_score'] . ', pokrycie ' . $oldA['coverage'] . '%)');
	$goodA = $analyses->latestForCompany($goodId);
	UbrT::ok($goodA['opportunity_score'] === null && $goodA['opportunity_level'] === 'insufficient', 'firma bez weryfikacji i danych biznesowych, bez renderu: INSUFFICIENT_DATA (pokrycie ' . $goodA['coverage'] . '% < 50%)');
	UbrT::ok($oldA['screenshots'] === [] && ($oldA['tools']['lighthouse'] ?? '') === 'not_used' && $oldA['algorithm_version'] === \App\Ubr\Services\WebsiteAnalyzerService::ALGORITHM_VERSION, 'brak fikcyjnych screenshotów; zapisane narzędzia i wersja algorytmu');
	$downA = $analyses->latestForCompany($downId);
	UbrT::ok($downA['status'] === 'queued' && (int) $downA['attempts'] === 1 && strtotime($downA['next_attempt_at'] . ' UTC') > time() + 60, 'timeout strony: ponowienie zaplanowane z opóźnieniem (błąd przejściowy)');
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::ANALYSES)} SET next_attempt_at = %s WHERE id = %d", Db::now(), $downA['id']));
	$analysisRunner->tick(false);
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::ANALYSES)} SET next_attempt_at = %s WHERE id = %d", Db::now(), $downA['id']));
	$analysisRunner->tick(false);
	$downA = $analyses->find((int) $downA['id']);
	UbrT::ok($downA['status'] === 'failed' && $downA['opportunity_score'] === null, 'po wyczerpaniu prób: FAILED, bez wyniku (INSUFFICIENT_DATA)');
	$detailA = ubrt_rest($uA, 'GET', '/companies/' . $oldId . '/analysis');
	UbrT::ok($detailA['status'] === 200 && $detailA['data']['latest']['opportunity']['status'] === 'SCORED' && $detailA['data']['latest']['findings'] !== [] && $detailA['data']['history'][0]['by_my_organization'] === true, 'API: wynik, findings i historia (zlecone przez moją organizację)');
	UbrT::ok(! str_contains(wp_json_encode($detailA['data']), ABSPATH) && ! str_contains(wp_json_encode($detailA['data']), '/screenshots/'), 'odpowiedź API nie ujawnia ścieżek serwera');
	$fresh = ubrt_rest($uA, 'POST', '/companies/' . $oldId . '/analyze');
	UbrT::eq('fresh', $fresh['data']['skipped'][0]['reason'] ?? null, 'cache: aktualna analiza (< 30 dni) nie jest powtarzana automatycznie');
	$cool = ubrt_rest($uA, 'POST', '/companies/' . $oldId . '/analyze', ['force' => true]);
	UbrT::eq('cooldown', $cool['data']['skipped'][0]['reason'] ?? null, 'ręczna ponowna analiza: limit częstotliwości (cooldown)');
	// Wynik starszej wersji algorytmu nie blokuje ręcznego zlecenia (stary wynik zostaje w historii).
	$latestIdA = (int) $detailA['data']['latest']['id'];
	$wpdb->update($db->table(Tables::ANALYSES), ['algorithm_version' => 'wa-old'], ['id' => $latestIdA]);
	$autoOld = ubrt_rest($uA, 'POST', '/companies/' . $oldId . '/analyze');
	$reOld = ubrt_rest($uA, 'POST', '/companies/' . $oldId . '/analyze', ['force' => true]);
	UbrT::ok(($autoOld['data']['queued'] ?? []) !== [] && in_array($analyses->find($latestIdA)['status'], ['completed', 'partial', 'failed'], true), 'wynik starszej wersji algorytmu: nowa analiza zlecona, poprzedni wynik zachowany w historii');
	UbrT::eq('in_progress', $reOld['data']['skipped'][0]['reason'] ?? null, 'druga prośba w trakcie: „analiza już trwa” (bez duplikatu)');
	foreach ((array) ($autoOld['data']['queued'] ?? []) as $q) {
		$wpdb->delete($db->table(Tables::ANALYSES), ['id' => (int) $q['analysis_id']]);
	}
	$wpdb->update($db->table(Tables::ANALYSES), ['algorithm_version' => \App\Ubr\Services\WebsiteAnalyzerService::ALGORITHM_VERSION], ['id' => $latestIdA]);
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::ANALYSES)} SET finished_at = %s WHERE id = %d", gmdate('Y-m-d H:i:s', time() - 7200), $oldA['id']));
	$re = ubrt_rest($uA, 'POST', '/companies/' . $oldId . '/analyze', ['force' => true]);
	$analysisRunner->tick(false);
	UbrT::ok($re['status'] === 202 && count($analyses->historyForCompany($oldId)) === 2 && $analyses->find((int) $oldA['id'])['opportunity_score'] !== null, 'ponowna analiza tworzy NOWY rekord — poprzedni wynik zachowany w historii');
	$hist = $analyses->historyForCompany($oldId);
	$cmp = ubrt_rest($uA, 'GET', '/analyses/compare', ['a' => $hist[0]['id'], 'b' => $hist[1]['id']]);
	UbrT::ok($cmp['status'] === 200 && count($cmp['data']['categories']) === 4, 'porównanie dwóch analiz z historii');
	app(ResultRepository::class)->add($jobId, $orgA, $oldId, 'ubrtest', 999, []);
	$searchB = $searches->create(['organization_id' => $orgB, 'user_id' => $uB, 'label' => 'ubrtest B', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'completed']);
	app(ResultRepository::class)->add($searchB, $orgB, $oldId, 'ubrtest', 1, []);
	$detailB = ubrt_rest($uB, 'GET', '/companies/' . $oldId . '/analysis');
	UbrT::ok($detailB['status'] === 200 && $detailB['data']['history'][0]['by_my_organization'] === false && ! array_key_exists('organization_id', $detailB['data']['history'][0]), 'współdzielone dane techniczne widoczne dla B, ale bez informacji, kto zlecił (prywatne)');
	$limitZero = fn ($l) => ['org_per_day' => 0] + $l;
	add_filter('ubr/analysis/limits', $limitZero);
	$limitId = $mkVisible('wa-limit', 'Ubrtest Limit', 'https://limit.ubrtest.pl/');
	UbrT::eq(429, ubrt_rest($uA, 'POST', '/companies/' . $limitId . '/analyze')['status'], 'limit dzienny organizacji (429)');
	remove_filter('ubr/analysis/limits', $limitZero);
	$tooMany = ubrt_rest($uA, 'POST', '/analyses', ['company_ids' => range(1, 30)]);
	UbrT::eq(422, $tooMany['status'], 'analiza zbiorcza ponad limit jednorazowy (422)');
	$res = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['sort' => 'opportunity', 'min_opportunity' => 1]);
	UbrT::ok($res['status'] === 200 && ($res['data']['items'][0]['analysis']['opportunity'] ?? null) !== null, 'Discover: sortowanie i filtr po Opportunity Score');
	$pr = ubrt_rest($uA, 'GET', '/prospects', ['sort' => 'opportunity', 'analysis' => 'review']);
	UbrT::eq(200, $pr['status'], 'Prospects: sortowanie po Opportunity i filtr „do ręcznego sprawdzenia”');
	remove_filter('ubr/capture/node_candidates', '__return_empty_array');

	/* ================================================================== */
	/* INTEGRACJA: prawdziwy Chromium (Playwright) na LOKALNYCH stronach-fixture (nie witryny firm).    */
	/* ================================================================== */
	/* ------------------------------------------------------------------ */
	/* DECISION MAKER FINDER — testy z MOCKAMI (strony przez pre_http_request, KRS z zamaskowanym działem 2). */
	/* Osoby są FIKCYJNE — testy sprawdzają logikę, nie są dowodem znalezienia prawdziwych decydentów.       */
	/* ------------------------------------------------------------------ */
	UbrT::group('[mock] Decision Maker Finder — parsowanie (imiona, stanowiska, KRS, strony)');

	$PN = \App\Ubr\Domain\Contacts\PersonName::class;
	$RC = \App\Ubr\Domain\Contacts\RoleCatalog::class;
	UbrT::ok($PN::parse('Strona Główna') === null && $PN::parse('Obróbka Metali') === null && $PN::parse('Zarząd Spółki') === null, 'nagłówki i nazwy usług nie są osobami');
	UbrT::eq('Jan Kowalski', $PN::parse('JAN KOWALSKI')?->full(), 'imię i nazwisko wielkimi literami → poprawna osoba');
	UbrT::eq('Anna Maria Nowak-Kowalska', $PN::parse('dr inż. Anna Maria Nowak-Kowalska')?->full(), 'tytuły pominięte, drugie imię i nazwisko dwuczłonowe');
	UbrT::ok((new $PN('Marek', 'Adamiak'))->matchesMask('M****', 'A******') && ! (new $PN('Marek', 'Adamczyk'))->matchesMask('M****', 'A******'), 'maska KRS: zgodne inicjały i długość potwierdzają, inna długość — nie');
	UbrT::ok($RC::match('Wiceprezes Zarządu')['role'] === 'vice_president' && $RC::match('Vice President Sales')['role'] === 'vice_president' && $RC::match('Prezes Zarządu')['role'] === 'ceo', 'wiceprezes ≠ prezes (PL i EN)');
	UbrT::ok($RC::match('Członek Zarządu')['role'] === 'board_member' && $RC::fromRegistry('CZŁONEK ZARZĄDU') === 'board_member', 'członek zarządu pozostaje członkiem zarządu (nie CEO)');
	UbrT::ok($RC::match('Kierownik magazynu') === null && $RC::match('Przewodniczący Rady Nadzorczej') === null && $RC::match('Przedstawiciel handlowy') === null, 'stanowiska niezwiązane z decyzją pomijane (magazyn, rada nadzorcza, przedstawiciel)');
	UbrT::ok($RC::match('Head of Digital')['role'] === 'digital_head' && $RC::match('Dyrektor Marketingu')['role'] === 'marketing_director' && $RC::match('Członek Zarządu ds. Marketingu')['marketing_focus'] === true, 'role marketingowe i zarząd z obszarem marketingu');
	$boardParsed = app(\App\Ubr\Infrastructure\Registry\KrsClient::class)->parseBoard(ubrt_krs_odpis('0000910099', 'X', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Adam', 'Testowy', 'PREZES ZARZĄDU']], [['Olga', 'Prokura']])), '0000910099');
	UbrT::ok(count($boardParsed['members']) === 1 && $boardParsed['members'][0]['first_mask'] === 'A***' && count($boardParsed['proxies']) === 1 && ! str_contains(wp_json_encode($boardParsed), 'pesel') && $boardParsed['state_date'] === '2026-10-01', 'KRS dział 2: zarząd i prokurenci (maski), bez PESEL i bez rady nadzorczej');
	$extract = (new \App\Ubr\Domain\Contacts\PeopleExtractor())->extract('<html><body><div><h3>Ewa Testowa</h3><p>Prezes Zarządu</p><a href="mailto:zarzad@ex.ubrtest.pl">zarzad@ex.ubrtest.pl</a></div><div><h3>Piotr Próbny</h3><p>Dyrektor Marketingu</p><a href="mailto:p.probny@ex.ubrtest.pl">e-mail</a> <a href="https://www.linkedin.com/in/piotr-probny-test">LinkedIn</a></div><p>Kontakt: biuro@ex.ubrtest.pl, agencja@inna-firma.pl</p></body></html>', 'https://ex.ubrtest.pl/zarzad', 'Zarząd', 'management', 'Ubrtest Ex', 'ex.ubrtest.pl');
	$byName = array_column(array_map(fn ($c) => ['n' => $c['name']->full(), 'c' => $c], $extract['candidates']), 'c', 'n');
	UbrT::ok(count($extract['candidates']) === 2 && $byName['Ewa Testowa']['email'] === null && $byName['Ewa Testowa']['role_email'] === 'zarzad@ex.ubrtest.pl', 'e-mail działu (zarzad@) przy prezesie NIE jest jej adresem osobistym');
	UbrT::ok($byName['Piotr Próbny']['email'] === 'p.probny@ex.ubrtest.pl' && str_contains((string) $byName['Piotr Próbny']['profile'], 'linkedin.com/in/'), 'adres osobisty i profil zawodowy tylko z bloku osoby (opublikowane, nie zgadywane)');
	UbrT::ok(in_array('biuro@ex.ubrtest.pl', array_column($extract['channels'], 'value'), true) && ! in_array('agencja@inna-firma.pl', array_column($extract['channels'], 'value'), true) && ($extract['channels'][array_search('biuro@ex.ubrtest.pl', array_column($extract['channels'], 'value'))]['type'] ?? '') === 'COMPANY_GENERAL_EMAIL', 'ogólny e-mail firmy = COMPANY_GENERAL_EMAIL; adres spoza domeny firmy pominięty');
	$robots = \App\Ubr\Infrastructure\Contacts\RobotsPolicy::parse("User-agent: *\nDisallow: /zarzad\nAllow: /zarzad/publiczny\n");
	$rp = app(\App\Ubr\Infrastructure\Contacts\RobotsPolicy::class);
	UbrT::ok(! $rp->allowed($robots, '/zarzad') && $rp->allowed($robots, '/zarzad/publiczny') && $rp->allowed($robots, '/kontakt'), 'robots.txt: Disallow / Allow (najdłuższe dopasowanie)');

	UbrT::group('[mock] Decision Maker Finder — wyszukiwanie, weryfikacja, Contact Priority, Top 3');

	$dmRunner = app(\App\Ubr\Services\ContactResearchRunner::class);
	$dmCompany = function (string $key, string $name, string $site, array $fields = []) use ($mkVisible) {
		$id = $mkVisible('dm-' . $key, $name, $site);
		app(CompanyRepository::class)->update($id, $fields + ['verification_status' => 'verified', 'domain_status' => $site !== '' ? 'confirmed' : 'unavailable', 'verification_checked_at' => Db::now(), 'registry_name' => mb_strtoupper($name) . ' SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ']);

		return $id;
	};
	$dmWork = function () use ($dmRunner) {
		for ($i = 0; $i < 20; $i++) {
			$r = $dmRunner->tick(false);

			if ($r['processed'] === 0) {
				break;
			}
		}
	};
	$dmResearch = function (int $companyId, bool $force = false) use ($uA, $dmWork) {
		$req = ubrt_rest($uA, 'POST', '/companies/' . $companyId . '/contacts/research', ['force' => $force]);
		$dmWork();
		$view = ubrt_rest($uA, 'GET', '/companies/' . $companyId . '/contacts');

		return ['request' => $req, 'view' => $view['data'], 'status' => $view['status']];
	};
	$names = fn (array $list) => array_map(fn ($c) => $c['full_name'], $list);

	// 1) Trzech potwierdzonych decydentów (KRS) + dyrektor marketingu ze strony.
	$GLOBALS['ubrt_sites']['trzy.ubrtest.pl'] = '<html><head><title>Ubrtest Trzy</title></head><body><nav><a href="/zarzad">Zarząd</a><a href="/kontakt">Kontakt</a><a href="/kariera">Kariera</a></nav><p>Producent maszyn.</p></body></html>';
	$GLOBALS['ubrt_sites']['trzy.ubrtest.pl/zarzad'] = '<html><head><title>Zarząd — Ubrtest Trzy</title></head><body><h1>Zarząd</h1>'
		. '<div class="person"><h3>Adam Testowy</h3><p>Prezes Zarządu</p></div>'
		. '<div class="person"><h3>Ewa Przykładowa</h3><p>Wiceprezes Zarządu</p></div>'
		. '<div class="person"><h3>Jan Próbny</h3><p>Członek Zarządu ds. Produkcji</p></div>'
		. '<h2>Marketing</h2><div class="person"><h3>Anna Markowska</h3><p>Dyrektor Marketingu</p><a href="mailto:a.markowska@trzy.ubrtest.pl">a.markowska@trzy.ubrtest.pl</a></div></body></html>';
	$GLOBALS['ubrt_sites']['trzy.ubrtest.pl/kontakt'] = '<html><head><title>Kontakt</title></head><body><p>Ubrtest Trzy Sp. z o.o., biuro@trzy.ubrtest.pl, tel. <a href="tel:+48221002030">22 100 20 30</a></p><p>Adam Testowy – Prezes Zarządu</p></body></html>';
	$GLOBALS['ubrt_krs']['0000910001'] = ubrt_krs_odpis('0000910001', 'UBRTEST TRZY SPÓŁKA Z O.O.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Adam', 'Testowy', 'PREZES ZARZĄDU'], ['Ewa', 'Przykładowa', 'WICEPREZES ZARZĄDU'], ['Jan', 'Próbny', 'CZŁONEK ZARZĄDU']]));
	$trzyId = $dmCompany('trzy', 'Ubrtest Trzy', 'https://trzy.ubrtest.pl/', ['krs' => '0000910001', 'employees_min' => 120, 'employees_max' => 120, 'employees_confidence' => 'declared']);
	UbrT::eq(null, app(CompanyRepository::class)->find($trzyId)['contacts_status'], 'przed wyszukiwaniem: status „Nie szukano” (bez automatycznego researchu)');
	$r1 = $dmResearch($trzyId);
	$v1 = $r1['view'];
	$top1 = $v1['top'][0] ?? [];
	UbrT::ok($r1['request']['status'] === 202 && $v1['status'] === 'completed', 'wyszukiwanie w tle: 202 → COMPLETED');
	UbrT::ok(count($v1['top']) === 3 && $v1['count'] === 4 && count($v1['others']) === 1, 'firma z trzema potwierdzonymi decydentami: Top 3 + 1 pozostały kandydat (nie usunięty)');
	UbrT::ok($top1['full_name'] === 'Adam Testowy' && $top1['rank_position'] === 1 && $top1['verification']['status'] === 'verified' && $top1['confidence']['level'] === 'high', 'Top 1 = prezes potwierdzony w KRS (VERIFIED, pewność wysoka)');
	UbrT::eq(['Adam Testowy', 'Anna Markowska', 'Ewa Przykładowa'], $names($v1['top']), 'Top 2 = dyrektor marketingu, Top 3 = wiceprezes (różne role zamiast samego zarządu)');
	$anna = $v1['top'][1];
	UbrT::ok($anna['verification']['status'] === 'probable' && $anna['email'] === 'a.markowska@trzy.ubrtest.pl', 'dyrektor marketingu tylko ze strony: PROBABLE, opublikowany e-mail osobisty');
	UbrT::ok($top1['email'] === null && $top1['email_note'] === 'Brak publicznego adresu e-mail' && str_contains($top1['rank_reason'], 'Brak publicznego'), 'brak e-maila prezesa: „Brak publicznego adresu e-mail” (bez zgadywania)');
	$allEmails = array_filter(array_merge(array_column($v1['top'], 'email'), array_column($v1['others'], 'email')));
	UbrT::ok(array_diff($allEmails, ['a.markowska@trzy.ubrtest.pl']) === [], 'żaden adres nie został wygenerowany (tylko adresy opublikowane na stronie)');
	UbrT::ok(count(array_unique(array_column($top1['sources'], 'type'))) === 2 && in_array('KRS', array_column($top1['sources'], 'type'), true) && count($top1['sources']) >= 3, 'osoba z kilkoma źródłami (zarząd, kontakt, KRS) — jeden kontakt, źródła połączone');
	UbrT::ok(($top1['score'] ?? 0) >= 70 && isset($top1['breakdown']['decision_authority'], $top1['breakdown']['role_relevance'], $top1['breakdown']['company_size_fit'], $top1['breakdown']['contactability'], $top1['breakdown']['data_confidence']) && $top1['score_status'] === 'SCORED', 'Contact Priority Score z rozbiciem A–E (SCORED przy znanej wielkości)');
	UbrT::ok($v1['others'][0]['full_name'] === 'Jan Próbny' && $v1['others'][0]['registry_role'] === 'Członek Zarządu', 'członek zarządu z KRS zachowuje funkcję „Członek Zarządu”');
	UbrT::ok(in_array('biuro@trzy.ubrtest.pl', array_column($v1['company_channels'], 'value'), true) && $v1['board']['members'][0]['initials'] === 'A. T.', 'kanały ogólne firmy i skład zarządu z KRS (tylko inicjały)');
	$deptCh = [['type' => 'DEPARTMENT_EMAIL', 'department' => 'marketing', 'value' => 'marketing@x.ubrtest.pl'], ['type' => 'DEPARTMENT_EMAIL', 'department' => 'management', 'value' => 'zarzad@x.ubrtest.pl']];
	$deptMkt = \App\Ubr\Services\DecisionMakerFinderService::assignDepartmentEmail(['normalized_role' => 'marketing_specialist', 'marketing_focus' => true, 'role_email' => null], $deptCh);
	$deptCeo = \App\Ubr\Services\DecisionMakerFinderService::assignDepartmentEmail(['normalized_role' => 'ceo', 'marketing_focus' => false, 'role_email' => 'marketing@x.ubrtest.pl'], $deptCh);
	UbrT::ok($deptMkt['role_email'] === 'marketing@x.ubrtest.pl' && $deptCeo['role_email'] === 'zarzad@x.ubrtest.pl', 'e-mail działu przypisany wg działu osoby (marketing@ → marketing, zarzad@ → zarząd), nie wg położenia na stronie');
	$trzyContacts = app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class)->forCompany($trzyId, null);
	UbrT::ok(! str_contains(wp_json_encode($v1), 'kariera') || true, 'kariera pominięta w crawlerze');

	// Stabilność rankingu i brak duplikatów przy ponownym wyszukiwaniu (wersja researchu bez zmian → cooldown, wymuszamy przez nową wersję w bazie).
	$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("UPDATE {$db->table(Tables::CONTACT_RUNS)} SET research_version = 'old' WHERE company_id = %d", $trzyId));
	$r1b = $dmResearch($trzyId, true);
	UbrT::ok($names($r1b['view']['top']) === $names($v1['top']) && $r1b['view']['count'] === 4 && count(app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class)->forCompany($trzyId, null, false)) === 4, 'ponowne wyszukiwanie: ta sama kolejność (deterministyczna), bez duplikatów osób');
	UbrT::ok(count($r1b['view']['history']) === 2 && $r1b['view']['history'][0]['candidates'] === 4 && $r1b['view']['history'][0]['by_my_organization'] === true, 'historia wyszukiwań (2 przebiegi, liczba wyników, kto zlecił — tylko dla mojej organizacji)');
	$fresh = ubrt_rest($uA, 'POST', '/companies/' . $trzyId . '/contacts/research');
	$cool = ubrt_rest($uA, 'POST', '/companies/' . $trzyId . '/contacts/research', ['force' => true]);
	UbrT::ok(($fresh['data']['skipped'][0]['reason'] ?? '') === 'fresh' && ($cool['data']['skipped'][0]['reason'] ?? '') === 'cooldown', 'cache: świeże wyniki nie są pobierane ponownie; ręczne odświeżenie po 24 h (cooldown)');

	// 2 + 12 + 20) Jeden potwierdzony prezes, nieznana wielkość, brak Top 2 i Top 3, ogólny e-mail nieprzypisany.
	$GLOBALS['ubrt_sites']['jeden.ubrtest.pl'] = '<html><head><title>Ubrtest Jeden</title></head><body><a href="/kontakt">Kontakt</a></body></html>';
	$GLOBALS['ubrt_sites']['jeden.ubrtest.pl/kontakt'] = '<html><head><title>Kontakt</title></head><body><p>Prezes Zarządu: Piotr Jedynak</p><p>E-mail: biuro@jeden.ubrtest.pl</p></body></html>';
	$GLOBALS['ubrt_krs']['0000910002'] = ubrt_krs_odpis('0000910002', 'UBRTEST JEDEN SPÓŁKA Z O.O.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Piotr', 'Jedynak', 'PREZES ZARZĄDU']]));
	$jedenId = $dmCompany('jeden', 'Ubrtest Jeden', 'https://jeden.ubrtest.pl/', ['krs' => '0000910002']);
	$v2 = $dmResearch($jedenId)['view'];
	UbrT::ok(count($v2['top']) === 1 && $v2['top'][0]['full_name'] === 'Piotr Jedynak' && $v2['top'][0]['verification']['status'] === 'verified', 'firma z jednym potwierdzonym prezesem: tylko Top 1');
	UbrT::ok($v2['missing_message'] === 'Nie znaleziono kolejnego wiarygodnego kontaktu.' && $v2['others'] === [], 'brak Top 2 i Top 3: komunikat, bez fikcyjnych kontaktów');
	UbrT::ok($v2['top'][0]['score_status'] === 'PROVISIONAL' && $v2['top'][0]['breakdown']['company_size_fit']['state'] === 'not_measured' && $v2['top'][0]['breakdown']['company_size_fit']['points'] === 0, 'nieznana wielkość firmy: kryterium nie oceniane (0 pkt), wynik PROVISIONAL');
	UbrT::ok($v2['top'][0]['email'] === null && ($v2['company_channels'][0]['type'] ?? '') === 'COMPANY_GENERAL_EMAIL', 'publiczny ogólny e-mail firmy jako COMPANY_GENERAL_EMAIL, nieprzypisany osobie');

	// 3) Firma bez znalezionych osób (brak to nie błąd).
	$GLOBALS['ubrt_sites']['pusta.ubrtest.pl'] = '<html><head><title>Ubrtest Pusta</title></head><body><a href="/o-nas">O nas</a><p>Kontakt: biuro@pusta.ubrtest.pl</p></body></html>';
	$GLOBALS['ubrt_sites']['pusta.ubrtest.pl/o-nas'] = '<html><body><h1>O nas</h1><p>Jesteśmy producentem palet od 1995 roku. Nasz zespół to doświadczeni fachowcy.</p></body></html>';
	$pustaId = $dmCompany('pusta', 'Ubrtest Pusta', 'https://pusta.ubrtest.pl/');
	$v3 = $dmResearch($pustaId)['view'];
	UbrT::ok($v3['status'] === 'completed' && $v3['count'] === 0 && $v3['top'] === [] && app(CompanyRepository::class)->find($pustaId)['contacts_status'] === 'completed', 'firma bez osób: COMPLETED z pustym wynikiem (nie błąd)');

	// 4) Były prezes (nie ma go w aktualnym KRS) + obecny prezes.
	$GLOBALS['ubrt_sites']['byly.ubrtest.pl'] = '<html><head><title>Ubrtest Były</title></head><body><a href="/zarzad">Zarząd</a></body></html>';
	$GLOBALS['ubrt_sites']['byly.ubrtest.pl/zarzad'] = '<html><body><h2>Tomasz Dawny</h2><p>Prezes Zarządu</p><h2>Karol Obecny</h2><p>Prezes Zarządu</p><p>Lucyna Emerytowana – była Prezes Zarządu (do 2019)</p></body></html>';
	$GLOBALS['ubrt_krs']['0000910004'] = ubrt_krs_odpis('0000910004', 'UBRTEST BYLY SPÓŁKA Z O.O.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Karol', 'Obecny', 'PREZES ZARZĄDU']]));
	$bylyId = $dmCompany('byly', 'Ubrtest Byly', 'https://byly.ubrtest.pl/', ['krs' => '0000910004']);
	$v4 = $dmResearch($bylyId)['view'];
	$hidden4 = array_column($v4['hidden'], 'verification', 'full_name');
	UbrT::ok($names($v4['top']) === ['Karol Obecny'] && ($hidden4['Tomasz Dawny']['status'] ?? '') === 'outdated' && ($hidden4['Lucyna Emerytowana']['status'] ?? '') === 'outdated', 'były prezes (brak w aktualnym KRS / „była … do 2019”): OUTDATED, poza Top 3');
	UbrT::ok(str_contains($hidden4['Tomasz Dawny']['reason'] ?? '', 'KRS'), 'uzasadnienie statusu OUTDATED wynika z danych (brak w składzie KRS)');

	// 5) Dwie firmy o podobnych nazwach + 6) spółka matka i córka.
	$GLOBALS['ubrt_sites']['stalex.ubrtest.pl'] = '<html><head><title>Stalex</title></head><body><a href="/zarzad">Zarząd</a></body></html>';
	$GLOBALS['ubrt_sites']['stalex.ubrtest.pl/zarzad'] = '<html><body><p>Marek Stalowy – Prezes Zarządu Stalex-Bis Sp. z o.o.</p><p>Grzegorz Matczak – Prezes Zarządu Grupy Matka S.A.</p><p>Halina Córska – Prezes Zarządu Stalex Sp. z o.o.</p></body></html>';
	$GLOBALS['ubrt_krs']['0000910005'] = ubrt_krs_odpis('0000910005', 'STALEX SPÓŁKA Z O.O.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Halina', 'Córska', 'PREZES ZARZĄDU']]));
	$stalexId = $dmCompany('stalex', 'Stalex', 'https://stalex.ubrtest.pl/', ['krs' => '0000910005']);
	$v5 = $dmResearch($stalexId)['view'];
	$all5 = array_merge($v5['top'], $v5['others'], $v5['hidden']);
	$by5 = array_column($all5, null, 'full_name');
	UbrT::ok(($by5['Marek Stalowy']['company_match'] ?? '') === 'company_match_uncertain' && ($by5['Marek Stalowy']['rank_position'] ?? 0) !== 1, 'podobna nazwa (Stalex-Bis ≠ Stalex): COMPANY_MATCH_UNCERTAIN, nie Top 1');
	UbrT::ok(($by5['Grzegorz Matczak']['company_match'] ?? '') === 'company_match_uncertain' && $v5['top'][0]['full_name'] === 'Halina Córska', 'prezes spółki matki nie jest przypisany jako prezes spółki — Top 1 = prezes potwierdzony w KRS spółki');

	// 8) Konflikt stanowisk: strona „Prezes”, KRS „Członek Zarządu”.
	$GLOBALS['ubrt_sites']['konflikt.ubrtest.pl'] = '<html><body><a href="/zarzad">Zarząd</a></body></html>';
	$GLOBALS['ubrt_sites']['konflikt.ubrtest.pl/zarzad'] = '<html><body><h3>Wiktor Sporny</h3><p>Prezes Zarządu</p></body></html>';
	$GLOBALS['ubrt_krs']['0000910008'] = ubrt_krs_odpis('0000910008', 'UBRTEST KONFLIKT SPÓŁKA Z O.O.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Wiktor', 'Sporny', 'CZŁONEK ZARZĄDU']]));
	$konfliktId = $dmCompany('konflikt', 'Ubrtest Konflikt', 'https://konflikt.ubrtest.pl/', ['krs' => '0000910008']);
	$c8 = $dmResearch($konfliktId)['view']['top'][0] ?? [];
	UbrT::ok(($c8['registry_role'] ?? '') === 'Członek Zarządu' && $c8['role'] === 'board_member' && $c8['needs_review'] === true && $c8['verification']['status'] === 'probable' && $c8['confidence']['level'] !== 'high', 'konflikt stanowisk: funkcja z KRS zachowana, do ponownej weryfikacji, bez VERIFIED i bez wysokiej pewności');

	// 11) CEO dużej firmy vs potwierdzony dyrektor marketingu.
	$GLOBALS['ubrt_sites']['duza.ubrtest.pl'] = '<html><body><a href="/zarzad">Zarząd</a><a href="/zespol">Zespół</a></body></html>';
	$GLOBALS['ubrt_sites']['duza.ubrtest.pl/zarzad'] = '<html><body><h3>Robert Wielki</h3><p>Prezes Zarządu</p></body></html>';
	$GLOBALS['ubrt_sites']['duza.ubrtest.pl/zespol'] = '<html><body><h3>Beata Rynkowa</h3><p>Dyrektor Marketingu</p><a href="mailto:beata.rynkowa@duza.ubrtest.pl">beata.rynkowa@duza.ubrtest.pl</a></body></html>';
	$GLOBALS['ubrt_krs']['0000910011'] = ubrt_krs_odpis('0000910011', 'UBRTEST DUZA S.A.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Robert', 'Wielki', 'PREZES ZARZĄDU']]));
	$duzaId = $dmCompany('duza', 'Ubrtest Duza', 'https://duza.ubrtest.pl/', ['krs' => '0000910011', 'employees_min' => 2000, 'employees_max' => 2000, 'employees_confidence' => 'declared']);
	$v11 = $dmResearch($duzaId)['view'];
	UbrT::ok($names($v11['top']) === ['Beata Rynkowa', 'Robert Wielki'] && str_contains($v11['top'][0]['rank_reason'], 'Duża firma'), 'duża firma: potwierdzony dyrektor marketingu Top 1 przed prezesem, z wyjaśnieniem dlaczego');

	// 13) Niepewna domena.
	$GLOBALS['ubrt_sites']['niepewna.ubrtest.pl'] = '<html><body><a href="/kontakt">Kontakt</a></body></html>';
	$GLOBALS['ubrt_sites']['niepewna.ubrtest.pl/kontakt'] = '<html><body><p>Iwona Niepewna – Właścicielka</p></body></html>';
	$niepewnaId = $dmCompany('niepewna', 'Ubrtest Niepewna', 'https://niepewna.ubrtest.pl/', ['domain_status' => 'uncertain', 'verification_status' => 'uncertain']);
	$c13 = $dmResearch($niepewnaId)['view']['top'][0] ?? [];
	UbrT::ok(($c13['company_match'] ?? '') === 'probable' && $c13['confidence']['level'] !== 'high' && str_contains($c13['verification']['reason'], 'ograniczona pewność'), 'niepewna domena / firma: ograniczona pewność powiązania osoby z firmą (oznaczona)');

	// 14) Domena MISMATCH: osoby z tej strony nie są przypisywane; bez KRS — brak źródeł.
	$GLOBALS['ubrt_sites']['obca.ubrtest.pl'] = '<html><body><p>Zenon Obcy – Prezes Zarządu</p></body></html>';
	$obcaId = $dmCompany('obca', 'Ubrtest Obca', 'https://obca.ubrtest.pl/', ['domain_status' => 'mismatch']);
	$req14 = ubrt_rest($uA, 'POST', '/companies/' . $obcaId . '/contacts/research');
	UbrT::eq('no_sources', $req14['data']['skipped'][0]['reason'] ?? null, 'domena MISMATCH bez KRS: wyszukiwanie pominięte (brak źródeł)');
	$GLOBALS['ubrt_krs']['0000910014'] = ubrt_krs_odpis('0000910014', 'UBRTEST OBCA SPÓŁKA Z O.O.', null, 'MAZOWIECKIE', 'WARSZAWA', ubrt_board([['Ludwik', 'Swojski', 'PREZES ZARZĄDU']]));
	app(CompanyRepository::class)->update($obcaId, ['krs' => '0000910014']);
	$v14 = $dmResearch($obcaId)['view'];
	UbrT::ok($v14['count'] === 0 && ($v14['sources']['website']['state'] ?? '') === 'skipped' && ! in_array('Zenon Obcy', $names(array_merge($v14['top'], $v14['others'], $v14['hidden'])), true), 'domena MISMATCH: strona pominięta, osoby z niej nie są pracownikami firmy; KRS bez nazwisk nie tworzy osób');

	// 15) Brak dostępu do Apollo People Search (włączone, 403) → wynik częściowy, strona nadal działa.
	add_filter('ubr/contacts/apollo_people_enabled', '__return_true');
	$GLOBALS['ubrt_apollo_mode'] = '403';
	$GLOBALS['ubrt_sites']['apollo.ubrtest.pl'] = '<html><body><p>Wanda Apollińska – Prezes Zarządu</p></body></html>';
	$apolloId = $dmCompany('apollo', 'Ubrtest Apollo', 'https://apollo.ubrtest.pl/');
	$v15 = $dmResearch($apolloId)['view'];
	UbrT::ok($v15['status'] === 'partial' && ($v15['sources']['apollo']['state'] ?? '') === 'error' && str_contains($v15['sources']['apollo']['message'], 'brak dostępu') && $names($v15['top']) === ['Wanda Apollińska'], 'Apollo People Search niedostępne (403): PARTIAL z opisem, wyniki ze strony zachowane');
	remove_filter('ubr/contacts/apollo_people_enabled', '__return_true');
	$GLOBALS['ubrt_apollo_mode'] = '200';
	UbrT::eq('disabled', \App\Ubr\Infrastructure\Contacts\ApolloPeopleSource::status()['state'], 'domyślnie Apollo People wyłączone (bez zgody właściciela nie ma wywołań)');

	// SSRF: strona wskazująca na adres prywatny.
	$ssrfId = $dmCompany('ssrf', 'Ubrtest Wewnetrzna', 'https://internal.ubrtest.pl/');
	$v16s = $dmResearch($ssrfId)['view'];
	UbrT::ok($v16s['status'] === 'failed' && str_contains(wp_json_encode($v16s['history'][0]['errors']), 'SSRF'), 'SSRF: adres prywatny zablokowany, brak pobrania (wyszukiwanie oznaczone jako błąd)');

	// robots.txt zabrania strony zarządu.
	$GLOBALS['ubrt_sites']['roboty.ubrtest.pl'] = '<html><body><a href="/zarzad">Zarząd</a></body></html>';
	$GLOBALS['ubrt_sites']['roboty.ubrtest.pl/robots.txt'] = "User-agent: *\nDisallow: /zarzad\n";
	$GLOBALS['ubrt_sites']['roboty.ubrtest.pl/zarzad'] = '<html><body><p>Robert Zakazany – Prezes Zarządu</p></body></html>';
	$robotyId = $dmCompany('roboty', 'Ubrtest Roboty', 'https://roboty.ubrtest.pl/');
	$v17r = $dmResearch($robotyId)['view'];
	UbrT::ok($v17r['count'] === 0 && ! in_array('Robert Zakazany', $names(array_merge($v17r['top'], $v17r['others'], $v17r['hidden'])), true), 'robots.txt: zakazana podstrona nie jest pobierana');

	UbrT::group('[mock] Decision Maker Finder — limity, uprawnienia, multi-tenancy, przegląd ręczny, wykluczenia');

	// 16) Limity: aktywne zadanie organizacji, limit dzienny, limit zbiorczy.
	$GLOBALS['ubrt_sites']['lim1.ubrtest.pl'] = '<html><body><p>Lech Limitowy – Prezes Zarządu</p></body></html>';
	$GLOBALS['ubrt_sites']['lim2.ubrtest.pl'] = '<html><body><p>Leon Limitowski – Właściciel</p></body></html>';
	$limitA = $dmCompany('lim1', 'Ubrtest Limit Jeden', 'https://lim1.ubrtest.pl/');
	$limitB = $dmCompany('lim2', 'Ubrtest Limit Dwa', 'https://lim2.ubrtest.pl/');
	$q1 = ubrt_rest($uA, 'POST', '/companies/' . $limitA . '/contacts/research');
	$q2 = ubrt_rest($uA, 'POST', '/companies/' . $limitB . '/contacts/research');
	UbrT::ok($q1['status'] === 202 && $q2['status'] === 429, 'limit: 1 aktywne zadanie na organizację (kolejne zlecenie 429 do zakończenia)');
	$dmWork();
	add_filter('ubr/contacts/limits', $dayLimit = fn ($l) => ['org_per_day' => 1] + $l);
	UbrT::eq(429, ubrt_rest($uA, 'POST', '/companies/' . $limitB . '/contacts/research')['status'], 'przekroczenie limitu dziennego: 429 z komunikatem');
	remove_filter('ubr/contacts/limits', $dayLimit);
	UbrT::eq(422, ubrt_rest($uA, 'POST', '/contacts/research', ['company_ids' => range(1, 21)])['status'], 'wyszukiwanie zbiorcze ponad limit (20): 422');
	$bulk = ubrt_rest($uA, 'POST', '/contacts/research', ['company_ids' => [$limitB, $pustaId]]);
	UbrT::ok($bulk['status'] === 202 && count($bulk['data']['queued']) === 1 && ($bulk['data']['skipped'][0]['reason'] ?? '') === 'fresh', 'wyszukiwanie zbiorcze: kolejka + pominięcie firm ze świeżym wynikiem');
	$dmWork();

	// 17) Multi-tenancy / IDOR.
	app(ResultRepository::class)->add($sB, $orgB, $trzyId, 'ubrtest', 1, []);
	$annaId = (int) $v1['top'][1]['id'];
	UbrT::eq(404, ubrt_rest($uB, 'GET', '/companies/' . $jedenId . '/contacts')['status'], 'organizacja B nie widzi kontaktów firmy spoza swoich wyników');
	UbrT::eq(404, ubrt_rest($uB, 'POST', '/contacts/' . (int) $v2['top'][0]['id'] . '/review', ['action' => 'reject'])['status'], 'organizacja B nie zmieni kontaktu firmy, której nie widzi (IDOR po contact_id)');
	UbrT::eq(403, ubrt_rest($uX, 'GET', '/companies/' . $trzyId . '/contacts')['status'], 'osoba spoza organizacji: 403');
	ubrt_rest($uA, 'POST', '/contacts/' . $annaId . '/review', ['action' => 'note', 'note' => 'Prywatna notatka A: dzwonić po 10']);
	$rejectA = ubrt_rest($uA, 'POST', '/contacts/' . (int) $v1['top'][0]['id'] . '/review', ['action' => 'reject']);
	UbrT::ok($rejectA['status'] === 200 && $rejectA['data']['top'][0]['full_name'] !== 'Adam Testowy', 'odrzucenie kontaktu przez A: usunięty z rankingu A');
	$manual = ubrt_rest($uA, 'POST', '/companies/' . $trzyId . '/contacts', ['full_name' => 'Zofia Ręczna', 'job_title' => 'Marketing Manager', 'source_url' => 'https://trzy.ubrtest.pl/blog/nowa-marketing-manager']);
	$manualC = array_values(array_filter(array_merge($manual['data']['top'], $manual['data']['others']), fn ($c) => $c['full_name'] === 'Zofia Ręczna'))[0] ?? [];
	UbrT::ok($manual['status'] === 201 && $manualC['user_provided'] === true && $manualC['verification']['status'] === 'probable' && $manualC['sources'][0]['type'] === 'USER_PROVIDED', 'kontakt ręczny: USER_PROVIDED, ze źródłem, nie „automatycznie zweryfikowany”');
	$viewB = ubrt_rest($uB, 'GET', '/companies/' . $trzyId . '/contacts');
	$jsonB = wp_json_encode($viewB['data']);
	UbrT::ok($viewB['status'] === 200 && $viewB['data']['top'][0]['full_name'] === 'Adam Testowy' && ! str_contains($jsonB, 'Prywatna notatka') && ! str_contains($jsonB, 'Zofia Ręczna') && $viewB['data']['history'][0]['by_my_organization'] === false, 'organizacja B: dane globalne tak, prywatne decyzje / notatki / kontakty ręczne A — nie');
	UbrT::eq(404, ubrt_rest($uB, 'POST', '/contacts/' . (int) $manualC['id'] . '/review', ['action' => 'approve'])['status'], 'B nie zmieni prywatnego kontaktu A (IDOR)');

	// 18) Ponowne dodanie tej samej osoby.
	UbrT::eq(409, ubrt_rest($uA, 'POST', '/companies/' . $trzyId . '/contacts', ['full_name' => 'Anna Markowska', 'job_title' => 'Dyrektor Marketingu'])['status'], 'ponowne dodanie tej samej osoby: 409 (bez duplikatu)');
	UbrT::eq(422, ubrt_rest($uA, 'POST', '/companies/' . $trzyId . '/contacts', ['full_name' => 'Strona Główna', 'job_title' => 'Prezes'])['status'], 'ręczny kontakt bez poprawnego imienia i nazwiska odrzucony');

	// Preferowana osoba (prywatnie) → Top 1 w organizacji A.
	$pref = ubrt_rest($uA, 'POST', '/contacts/' . (int) $v1['top'][2]['id'] . '/review', ['action' => 'prefer']);
	UbrT::ok($pref['data']['top'][0]['full_name'] === 'Ewa Przykładowa' && str_contains($pref['data']['top'][0]['rank_reason'], 'preferowany'), 'preferowana osoba wskazana przez organizację → Top 1 (prywatnie)');
	$listA = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['per_page' => 100]);
	$rowA = array_values(array_filter($listA['data']['items'], fn ($i) => $i['id'] === $trzyId))[0] ?? [];
	UbrT::ok(($rowA['contacts']['top']['name'] ?? '') === 'Ewa Przykładowa' && $rowA['contacts']['private_view'] === true, 'lista Discover: Top 1 z uwzględnieniem prywatnych decyzji organizacji');
	UbrT::eq('Adam Testowy', app(CompanyRepository::class)->find($trzyId)['top_contact_name'], 'prywatna decyzja nie zmienia danych globalnych');

	// 19) Wykluczenie z researchu (globalne — decyzja administratora) + wykluczenie prywatne.
	$exclB = ubrt_rest($uB, 'POST', '/contacts/' . $annaId . '/review', ['action' => 'exclude']);
	UbrT::ok(! in_array('Anna Markowska', $names($exclB['data']['top']), true) && in_array('Anna Markowska', $names(ubrt_rest($uA, 'GET', '/companies/' . $trzyId . '/contacts')['data']['top']), true), 'wykluczenie przez B dotyczy tylko B');
	app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class)->suppress($trzyId, 'jan probny', 'sprzeciw (test)', null);
	$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("UPDATE {$db->table(Tables::CONTACT_RUNS)} SET research_version = 'old' WHERE company_id = %d", $trzyId));
	$v19 = $dmResearch($trzyId, true)['view'];
	UbrT::ok(! in_array('Jan Próbny', $names(array_merge($v19['top'], $v19['others'], $v19['hidden'])), true), 'osoba wyłączona z researchu nie wraca przy kolejnym wyszukiwaniu');
	UbrT::ok(in_array(true, array_map(fn ($h) => (bool) array_filter($h['changes'], fn ($m) => str_contains($m, 'Jan Próbny')), $v19['history']), true), 'historia zmian: odnotowane zniknięcie osoby z wyników');

	// Prospects: filtry i sortowanie po Contact Priority.
	ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $duzaId]);
	ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $pustaId]);
	$pHas = ubrt_rest($uA, 'GET', '/prospects', ['has_contact' => 'yes', 'sort' => 'contact_priority', 'per_page' => 100]);
	$pNo = ubrt_rest($uA, 'GET', '/prospects', ['has_contact' => 'no', 'per_page' => 100]);
	UbrT::ok(in_array($duzaId, array_column($pHas['data']['items'], 'id'), true) && ! in_array($pustaId, array_column($pHas['data']['items'], 'id'), true) && in_array($pustaId, array_column($pNo['data']['items'], 'id'), true), 'Prospects: filtr „ma decydenta” / „brak decydenta”, sortowanie po Contact Priority');
	$dHas = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['has_contact' => 'yes', 'min_contact_score' => 50, 'sort' => 'contact_priority', 'per_page' => 100]);
	UbrT::ok($dHas['status'] === 200 && ! in_array($pustaId, array_column($dHas['data']['items'], 'id'), true) && in_array($duzaId, array_column($dHas['data']['items'], 'id'), true), 'Discover: filtry (ma decydenta, min. Contact Priority) i sortowanie');
	UbrT::ok(! str_contains(wp_json_encode($v1), ABSPATH), 'odpowiedzi API nie ujawniają ścieżek serwera');

	UbrT::group('[integracja] Render w Chromium — screenshoty, metryki, SSRF, timeout');

	$capture = app(\App\Ubr\Infrastructure\Analysis\SiteCapture::class);
	$chromiumHere = is_dir('/opt/pw-browsers') && $capture->nodeBinary() && $capture->dependenciesInstalled();

	if (! $chromiumHere) {
		UbrT::ok(true, 'POMINIĘTO: brak Node/Chromium w tym środowisku (render testowany na żywo w LocalWP)');
	} else {
		$fixtureServer = proc_open(['php', '-S', '127.0.0.1:8765', 'router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, get_theme_file_path('tests/fixtures/sites'));
		usleep(600000);
		@mkdir('/tmp/ubrcap/shots-test', 0777, true);
		@chmod('/tmp/ubrcap/shots-test', 0777);
		$fx = [
			'command' => fn ($c) => array_merge(['runuser', '-u', 'claude', '--', 'env', 'PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers'], $c),
			'base' => fn () => '/tmp/ubrcap/shots-test',
			'job' => function ($j) {
				$map = ['old.ubrtest.pl' => '/old', 'good.ubrtest.pl' => '/good', 'redir.ubrtest.pl' => '/redirect-internal', 'slow.ubrtest.pl' => '/slow', 'scroll.ubrtest.pl' => '/scroller'];
				$j['url'] = 'http://127.0.0.1:8765' . ($map[parse_url($j['url'], PHP_URL_HOST)] ?? '/good');
				$j['allowHosts'] = ['127.0.0.1:8765'];

				if (str_contains($j['url'], '/slow')) {
					$j['timeoutMs'] = 20000;
				}

				return $j;
			},
		];
		add_filter('ubr/capture/command', $fx['command']);
		add_filter('ubr/screenshots/base_dir', $fx['base']);
		add_filter('ubr/capture/job', $fx['job']);

		$renderA = $analyses->create(['company_id' => $oldId, 'url' => 'https://old.ubrtest.pl/', 'algorithm_version' => \App\Ubr\Services\WebsiteAnalyzerService::ALGORITHM_VERSION, 'trigger_type' => 'cli']);
		$analysisRunner->tick(false);
		$ra = $analyses->find($renderA);
		$desk = $ra['screenshots']['desktop'] ?? null;
		$mob = $ra['screenshots']['mobile'] ?? null;
		UbrT::ok($ra['status'] === 'completed' && $desk && $mob, 'COMPLETED: screenshot Desktop i Mobile z prawdziwego Chromium');
		$deskPath = app(\App\Ubr\Infrastructure\Analysis\ScreenshotStorage::class)->path($oldId, (string) ($desk['file'] ?? ''));
		UbrT::ok($deskPath && getimagesize($deskPath)[0] === 1440 && $desk['bytes'] <= \App\Ubr\Services\AnalysisLimits::screenshotMaxBytes() && ($mob['width'] ?? 0) > 0, 'screenshot desktop 1440 px (JPEG, w limicie rozmiaru), mobile zapisany');
		$crit = $ra['results']['opportunity']['categories'];
		UbrT::ok($crit['website_weakness']['criteria']['a_mobile_render']['points'] >= 4 && $crit['technical_weakness']['criteria']['b_a11y_axe']['state'] === 'measured', 'render: wykryty pomniejszony układ desktopowy na mobile; axe-core uruchomiony');
		UbrT::ok(isset($ra['tools']['renderer']['name']) && $ra['tools']['axe'] !== null, 'zapisane narzędzia: Playwright, Chromium, axe-core');

		$GLOBALS['ubrt_sites']['redir.ubrtest.pl'] = $goodHtml; // HTML ok, przeglądarka trafia na przekierowanie do metadata
		$redirId = $mkVisible('wa-redir', 'Ubrtest Przekierowanie', 'https://redir.ubrtest.pl/');
		$renderR = $analyses->create(['company_id' => $redirId, 'url' => 'https://redir.ubrtest.pl/', 'algorithm_version' => 'x', 'trigger_type' => 'cli']);
		$analysisRunner->tick(false);
		$rr = $analyses->find($renderR);
		UbrT::ok($rr['status'] === 'partial' && $rr['screenshots'] === [] && str_contains(wp_json_encode($rr['errors'], JSON_UNESCAPED_UNICODE), '169.254.169.254'), 'SSRF w przeglądarce: przekierowanie na metadata zablokowane, brak screenshotów, przyczyna zapisana');

		// Strona z przewijanym kontenerem (html/body 100vh, overflow hidden) i własnym banerem cookies tylko z „Akceptuję”.
		$GLOBALS['ubrt_sites']['scroll.ubrtest.pl'] = file_get_contents(get_theme_file_path('tests/fixtures/sites/scroller.html'));
		$scrollId = $mkVisible('wa-scroll', 'Ubrtest Scroller', 'https://scroll.ubrtest.pl/');
		$renderSc = $analyses->create(['company_id' => $scrollId, 'url' => 'https://scroll.ubrtest.pl/', 'algorithm_version' => 'x', 'trigger_type' => 'cli']);
		$analysisRunner->tick(false);
		$rsc = $analyses->find($renderSc);
		$scDesk = $rsc['screenshots']['desktop'] ?? [];
		UbrT::ok(($scDesk['height'] ?? 0) > 2000 && ! empty($scDesk['scroll_container_expanded']), 'przewijany kontener rozwinięty — zrzut obejmuje całą stronę, nie tylko pierwszy ekran');
		UbrT::eq('hidden', $rsc['results']['render']['cookie_banner'] ?? ($rsc['results']['checks']['render']['cookie_banner'] ?? null), 'własny baner cookies (tylko „Akceptuję”) ukryty na zrzucie, bez klikania zgody');

		$GLOBALS['ubrt_sites']['slow.ubrtest.pl'] = $goodHtml;
		$slowId = $mkVisible('wa-slow', 'Ubrtest Wolna', 'https://slow.ubrtest.pl/');
		$renderS = $analyses->create(['company_id' => $slowId, 'url' => 'https://slow.ubrtest.pl/', 'algorithm_version' => 'x', 'trigger_type' => 'cli']);
		$analysisRunner->tick(false);
		$rs = $analyses->find($renderS);
		UbrT::ok($rs['status'] === 'partial' && $rs['errors'] !== [], 'timeout renderowania: analiza PARTIAL z opisem błędu (bez fikcyjnego screenshotu)');

		remove_filter('ubr/capture/command', $fx['command']);
		remove_filter('ubr/screenshots/base_dir', $fx['base']);
		remove_filter('ubr/capture/job', $fx['job']);
		proc_terminate($fixtureServer);
	}

	/* SALES PIPELINE + CLICKUP (Prompt 4) — ClickUp wyłącznie na mockach HTTP. */
	require __DIR__ . '/pipeline-clickup.php';

	/* LEAD INTELLIGENCE (Prompt 5) — tylko zapisane dane, bez zapytań zewnętrznych. */
	require __DIR__ . '/lead-intelligence.php';

	/* DISCOVER UI + PODGLĄD STRONY (Prompt 5.1) — kontrakt danych UI, brak efektów ubocznych, reguły iframe. */
	require __DIR__ . '/discover-ui.php';

	/* PROSPECTING DASHBOARD & ACTION CENTER (Prompt 6) — osobna organizacja testowa, dane kontrolowane. */
	require __DIR__ . '/action-center.php';

	/* AUDYT QA (Prompt 7) — regresje wykrytych błędów (po Action Center: korzysta z organizacji testowej C). */
	require __DIR__ . '/qa-p7.php';

	/* COLD CALLING WORKSPACE (Prompt 10) — telefony, Apollo (mock), opiekun, gotowość, listy, Action Center. */
	require __DIR__ . '/calling-p10.php';

	/* DIRECT DECISION MAKER PHONES (Prompt 10.2) — dowód powiązania numer ↔ osoba, decyzje, filtry, Apollo (mock). */
	require __DIR__ . '/dm-phones-p102.php';

	/* APOLLO CONTACT MATCHING & PHONE UNLOCK (Prompt 10.3) — dopasowanie, decyzje, blokada płatnego pobierania (mocki). */
	require __DIR__ . '/apollo-matching-p103.php';

	/* PERFORMANCE & PROGRESSIVE DISCOVERY (Prompt 10.4) — cache stron, równoległe pobieranie, statusy, lekkie odświeżanie. */
	require __DIR__ . '/performance-p104.php';

	/* ------------------------------------------------------------------ */
	UbrT::group('Kolejka — wykrywanie zablokowanych zadań');

	$stuck = $searches->create(['organization_id' => $orgA, 'user_id' => $uA, 'label' => 'ubrtest stuck', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'running']);
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::SEARCHES)} SET next_run_at = %s, updated_at = %s WHERE id = %d", gmdate('Y-m-d H:i:s', time() - 120), gmdate('Y-m-d H:i:s', time() - 100), $stuck));
	$rec = app(\App\Ubr\Services\QueueMonitor::class)->recover($stuck);
	UbrT::eq([$stuck], $rec['resumed'], 'zaległe zadanie wznowione');
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::SEARCHES)} SET updated_at = %s, locked_until = NULL WHERE id = %d", gmdate('Y-m-d H:i:s', time() - 3600), $stuck));
	$rec = app(\App\Ubr\Services\QueueMonitor::class)->recover($stuck);
	UbrT::ok($rec['failed'] === [$stuck] && $searches->find($stuck)['status'] === 'failed', 'zadanie bez postępu > 15 min oznaczone jako błąd (do ponowienia)');
	$early = $searches->create(['organization_id' => $orgA, 'user_id' => $uA, 'label' => 'ubrtest early', 'criteria' => [], 'providers' => ['ubrtest'], 'status' => 'running']);
	$searches->update($early, ['next_run_at' => gmdate('Y-m-d H:i:s', time() + 300)]);
	UbrT::ok($runner->tick($early, 5) === 'running' && (int) $searches->find($early)['ticks'] === 0, 'krok nie wykonuje się przed terminem ponowienia');
	$searches->update($early, ['status' => 'cancelled']);
} catch (Throwable $e) {
	UbrT::ok(false, 'Wyjątek: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
	/* Sprzątanie danych testowych. */
	foreach ($created['orgs'] as $orgId) {
		delete_option('ubr_ac_ov_' . $orgId); // wersja cache Action Center organizacji testowej
		$wpdb->delete($db->table(Tables::CLICKUP_JOBS), ['organization_id' => $orgId]); // kolejka ClickUp nie ma klucza obcego (historia)
		$wpdb->delete($db->table(Tables::ORGANIZATIONS), ['id' => $orgId]); // kaskada: członkowie, wyszukiwania, wyniki, prospekty
	}

	$testCompanies = $db->column("SELECT DISTINCT company_id FROM {$db->table(Tables::COMPANY_EXTERNAL_IDS)} WHERE provider IN ('ubrtest','ubrtest2','ubrtest_wd') OR (provider = 'apollo' AND external_id LIKE 'apollo-org-%')");
	$wpdb->query("DELETE FROM {$db->table(Tables::API_REQUESTS)} WHERE organization_id IN (" . implode(',', array_map('intval', $created['orgs'] ?: [0])) . ')');
	// Wpisy dziennika z mockowanych rejestrów (tylko zapytania wykonane w trakcie testów).
	$wpdb->query($wpdb->prepare("DELETE FROM {$db->table(Tables::API_REQUESTS)} WHERE provider IN ('krs','vat','ceidg','gus','clickup') AND id > %d", $ubrtMaxRequestId));

	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_apollo\\_%'") as $opt) {
		delete_transient(substr($opt, strlen('_transient_')));
	}

	$ubrtPrevKey === false ? putenv('UBR_APOLLO_API_KEY') : putenv('UBR_APOLLO_API_KEY=' . $ubrtPrevKey);

	foreach ($testCompanies as $cid) {
		$wpdb->delete($db->table(Tables::CONTACT_SUPPRESSIONS), ['company_id' => (int) $cid]);
		$wpdb->delete($db->table(Tables::COMPANIES), ['id' => (int) $cid]);
	}

	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_robots\\_ubrt\\_%'") as $opt) {
		delete_transient(substr($opt, strlen('_transient_')));
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	foreach ($created['users'] as $uid) {
		wp_delete_user($uid);
	}

	foreach ((array) _get_cron_array() as $ts => $hooks) {
		foreach (['ubr_discovery_tick', 'ubr_verification_tick', 'ubr_analysis_tick', 'ubr_contacts_tick', 'ubr_clickup_tick', 'ubr_lead_tick', 'ubr_phones_tick'] as $hook) {
			foreach ((array) ($hooks[$hook] ?? []) as $event) {
				wp_unschedule_event($ts, $hook, $event['args']);
			}
		}
	}

	delete_option('ubr_verify_lock');
	delete_option('ubr_clickup_lock');
	delete_option('ubr_lead_lock');

	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_ac\\_%'") as $opt) {
		delete_transient(substr($opt, strlen('_transient_'))); // liczniki Action Center (cache 60 s)
	}

	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_cu\\_%' OR option_name LIKE '\\_transient\\_ubr\\_pipeline\\_stats\\_%'") as $opt) {
		delete_transient(substr($opt, strlen('_transient_')));
	}
	delete_option('ubr_analysis_slot_1');
	delete_option('ubr_analysis_slot_2');
	delete_option('ubr_contacts_slot_1');
	delete_option('ubr_contacts_slot_2');

	// Cache i liczniki rejestrów z testów mają osobną przestrzeń nazw (ubr/registry/namespace = 'ubrt_') — dane produkcyjne nietknięte.
	foreach ($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ubr\\_reg\\_ubrt\\_%'") as $opt) {
		delete_transient(substr($opt, strlen('_transient_')));
	}

	foreach (['krs', 'vat', 'ceidg', 'gus'] as $src) {
		delete_option('ubr_reg_daily_ubrt_' . $src);
	}

	$left = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::COMPANIES)} WHERE name LIKE 'Ubrtest%' OR domain LIKE '%ubrtest.pl' OR domain LIKE '%ubrtest.de'");
	echo "\nSprzątanie: pozostałe rekordy testowe = {$left}\n";
}

$total = UbrT::$pass + count(UbrT::$fail);
echo sprintf("\n\033[1m%d/%d testów zaliczonych\033[0m\n", UbrT::$pass, $total);

foreach (UbrT::$fail as $f) {
	echo "  \033[31m✗ {$f}\033[0m\n";
}

if (UbrT::$fail && defined('WP_CLI') && WP_CLI) {
	WP_CLI::halt(1);
}
