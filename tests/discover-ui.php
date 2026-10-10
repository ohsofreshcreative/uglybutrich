<?php

/**
 * Testy regresji: Discover UI + wspólny podgląd strony (Prompt 5.1). Dołączane z tests/run.php (wspólne: $uA, $uB, $uX,
 * $orgA, $jobId, $db, $wpdb). Zmiana dotyczy wyłącznie warstwy UI — testy pilnują kontraktu danych, z którego korzysta
 * nowy układ (pola wierszy, uprawnienia), braku efektów ubocznych podglądu oraz reguł bezpieczeństwa iframe w szablonach.
 * Testy wizualne (szerokości 1920–390 px) wykonywane są osobno w przeglądarce (Playwright) — tu nie są deklarowane.
 */

use App\Ubr\Infrastructure\Database\Tables;

if (! defined('ABSPATH')) {
	exit(1);
}

UbrT::group('Discover UI 5.1 — kontrakt danych tabeli i menu akcji');

$duiRes = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['page' => 1, 'per_page' => 50]);
UbrT::eq(200, $duiRes['status'], 'wyniki wyszukiwania dostępne dla właściciela');
$duiRow = $duiRes['data']['items'][0] ?? [];
foreach (['id', 'name', 'domain', 'website_url', 'industry', 'location', 'verification', 'domain_status', 'relevance', 'relevance_details', 'analysis', 'contacts', 'lead', 'prospect_id', 'flags'] as $duiKey) {
	UbrT::ok(array_key_exists($duiKey, $duiRow), "wiersz wyników zawiera pole „{$duiKey}” (kolumny / szczegóły / menu ⋯)");
}
UbrT::ok(is_array($duiRow['lead'] ?? null) || ($duiRow['lead'] ?? null) === null, 'pole lead to obiekt lub null (komponent „Oceny”)');
UbrT::eq(404, ubrt_rest($uB, 'GET', '/discovery/searches/' . $jobId . '/results')['status'], 'nowy układ nie zmienia izolacji: inna organizacja nie widzi wyników');

$duiPage = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['page' => 1, 'per_page' => 2]);
UbrT::ok(count($duiPage['data']['items'] ?? []) <= 2 && (int) ($duiPage['data']['per_page'] ?? 0) === 2, 'paginacja wyników bez zmian');
$duiSorted = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['sort' => 'lead']);
UbrT::eq(200, $duiSorted['status'], 'sortowanie po Lead Potential nadal obsługiwane');
$duiFiltered = ubrt_rest($uA, 'GET', '/discovery/searches/' . $jobId . '/results', ['min_lead' => 60, 'lead_confidence' => 'high']);
UbrT::eq(200, $duiFiltered['status'], 'filtry Lead Intelligence nadal obsługiwane');

$duiPros = ubrt_rest($uA, 'GET', '/prospects');
UbrT::eq(200, $duiPros['status'], 'lista Prospects dostępna (wspólne menu ⋯ i podgląd)');
if (! empty($duiPros['data']['items'])) {
	foreach (['id', 'name', 'domain', 'website_url', 'analysis', 'contacts', 'lead'] as $duiKey) {
		UbrT::ok(array_key_exists($duiKey, $duiPros['data']['items'][0]), "wiersz Prospects zawiera pole „{$duiKey}”");
	}
}

$duiMe = ubrt_rest($uA, 'GET', '/me');
$duiAbilities = (array) ($duiMe['data']['active_organization']['abilities'] ?? []);
foreach (['analysis.run', 'contacts.run', 'prospects.manage'] as $duiAb) {
	UbrT::ok(array_key_exists($duiAb, $duiAbilities) || in_array($duiAb, (array) $duiAbilities, true), "uprawnienie „{$duiAb}” przekazywane do UI (warunkowe akcje menu)");
}

UbrT::group('Podgląd strony 5.1 — brak efektów ubocznych i bezpieczeństwo iframe');

$duiCompanyId = (int) $mkVisible('dui-preview', 'Ubrtest Podgląd Strony', 'https://podglad.ubrtest.pl/');
$duiCount = fn (string $t) => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$db->table($t)}");
$duiCron = count(_get_cron_array() ?: [], COUNT_RECURSIVE);
$duiBefore = [$duiCount(Tables::ANALYSES), $duiCount(Tables::CONTACT_RUNS), $duiCount(Tables::API_REQUESTS), $duiCount(Tables::USAGE)];
$duiPv = ubrt_rest($uA, 'GET', '/companies/' . $duiCompanyId . '/analysis');
UbrT::eq(200, $duiPv['status'], 'dane podglądu (zapisane screenshoty) pobierane istniejącym endpointem analizy');
UbrT::eq($duiBefore, [$duiCount(Tables::ANALYSES), $duiCount(Tables::CONTACT_RUNS), $duiCount(Tables::API_REQUESTS), $duiCount(Tables::USAGE)], 'otwarcie podglądu nie tworzy analizy, wyszukiwania decydentów ani zapytań do API (Apollo)');
UbrT::eq($duiCron, count(_get_cron_array() ?: [], COUNT_RECURSIVE), 'podgląd nie planuje zadań w tle (WP-Cron bez zmian)');
UbrT::ok(in_array(ubrt_rest($uB, 'GET', '/companies/' . $duiCompanyId . '/analysis')['status'], [403, 404], true), 'podgląd firmy spoza organizacji zablokowany');

$duiView = fn (string $rel) => (string) file_get_contents(get_theme_file_path('resources/views/ubr/' . $rel));
$duiModal = $duiView('partials/preview-modal.blade.php');
preg_match('/<iframe[^>]*>/s', $duiModal, $duiIframe);
$duiIframe = $duiIframe[0] ?? '';
UbrT::ok($duiIframe !== '', 'wspólny modal zawiera iframe trybu LIVE');
UbrT::ok(str_contains($duiIframe, 'sandbox="') && ! str_contains($duiIframe, 'allow-top-navigation') && ! str_contains($duiIframe, 'allow-modals'), 'iframe: sandbox bez nawigacji okna nadrzędnego i bez okien modalnych');
foreach (['camera', 'microphone', 'geolocation'] as $duiPerm) {
	UbrT::ok(str_contains($duiIframe, "{$duiPerm} 'none'"), "iframe: brak dostępu do {$duiPerm}");
}
UbrT::ok(str_contains($duiIframe, 'liveUrl') && ! preg_match('/:src="[^"]*(proxy|admin-ajax|ubr\/v1)/', $duiIframe), 'iframe ładuje wyłącznie zwalidowany adres firmy — bez proxy');
UbrT::ok(str_contains($duiModal, 'rel="noopener noreferrer"') && str_contains($duiModal, 'target="_blank"'), '„Otwórz stronę” w nowej karcie z noopener noreferrer');
UbrT::ok(str_contains($duiModal, 'Niektóre strony blokują podgląd w aplikacji.'), 'komunikat o stronach blokujących osadzanie');
UbrT::ok(! str_contains($duiModal, 'onload'), 'stan ładowania nie opiera się na iframe.onload');

$duiJs = (string) file_get_contents(get_theme_file_path('resources/js/ubr/ui.js'));
UbrT::ok(str_contains($duiJs, "['http:', 'https:']") && str_contains($duiJs, 'u.username || u.password'), 'walidacja adresu podglądu: tylko http/https, bez danych logowania w URL');

$duiDiscover = $duiView('pages/discover.blade.php');
UbrT::ok(str_contains($duiDiscover, 'table-fixed') && ! preg_match('/<table[^>]*min-w-\[/', $duiDiscover) && ! str_contains($duiDiscover, 'overflow-x-hidden'), 'tabela Discover: table-fixed, bez min-width i bez ukrywania przepełnienia');
UbrT::ok(str_contains($duiDiscover, "@include('ubr.partials.row-actions'"), 'Discover używa wspólnego menu ⋯');
UbrT::ok(str_contains($duiView('pages/prospects.blade.php'), "@include('ubr.partials.row-actions'"), 'Prospects używa wspólnego menu ⋯');
$duiMenu = $duiView('partials/row-actions.blade.php');
UbrT::ok(str_contains($duiMenu, 'x-teleport="body"') && str_contains($duiMenu, 'role="menu"') && str_contains($duiMenu, 'aria-haspopup="menu"'), 'menu ⋯ renderowane w <body> (nie jest obcinane), z rolami ARIA');
foreach (["can('analysis.run')", "can('contacts.run')", "can('prospects.manage')"] as $duiCan) {
	UbrT::ok(str_contains($duiMenu, $duiCan), "menu ⋯ respektuje uprawnienie {$duiCan}");
}
UbrT::ok(str_contains($duiView('partials/analysis-tab.blade.php'), "openPreview("), 'karta firmy (Website Analysis) otwiera ten sam modal podglądu');
