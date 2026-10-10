<?php

/**
 * Testy: Sales Pipeline + ClickUp (Prompt 4). Dołączane z tests/run.php (wspólne zmienne: $uA, $uB, $uM, $uAd, $uX,
 * $orgA, $orgB, $mkVisible, $db, $wpdb). ClickUp: WYŁĄCZNIE mocki HTTP (pre_http_request dla api.clickup.com) —
 * żadnych prawdziwych zapytań, żadnych prawdziwych zadań. Wynik mocków nie jest dowodem działania z prawdziwym ClickUp.
 */

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Services\ClickUpOAuthService;
use App\Ubr\Services\ClickUpRunner;
use App\Ubr\Services\ClickUpTaskBuilder;

if (! defined('ABSPATH')) {
	exit(1);
}

/* ======================================================================================
 * SALES PIPELINE (1–14)
 * ==================================================================================== */
UbrT::group('[pipeline] Statusy, właściciel, priorytet, historia (prywatne dla organizacji)');

$pl = app(PipelineRepository::class);
$plA = $mkVisible('pl-a', 'Ubrtest Pipeline Alfa', 'https://pl-alfa.ubrtest.pl/');
$plB = $mkVisible('pl-b', 'Ubrtest Pipeline Beta', 'https://pl-beta.ubrtest.pl/');
$plC = $mkVisible('pl-c', 'Ubrtest Pipeline Gamma', 'https://pl-gamma.ubrtest.pl/');
// Ta sama firma widoczna także dla organizacji B.
app(\App\Ubr\Infrastructure\Repositories\ResultRepository::class)->add($sB, $orgB, $plA, 'ubrtest', 50, []);

// 1. Dodanie do Prospects: status NEW, właściciel = dodający, zdarzenie „created”.
$add = ubrt_rest($uM, 'POST', '/prospects', ['company_id' => $plA]);
$pA = $pl->prospectForCompany($orgA, $plA);
UbrT::ok(in_array($add['status'], [200, 201], true) && $pA && $pA['status'] === 'new' && (int) $pA['owner_user_id'] === $uM, '1. nowy prospekt: status NEW, właściciel = osoba dodająca');
UbrT::ok(in_array('created', array_column($pl->events($orgA, (int) $pA['id']), 'type'), true), '1. historia: zdarzenie „created”');

// 2. Zmiana statusu + historia; nieznany status odrzucony.
$r = ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'], ['status' => 'qualified']);
UbrT::ok($r['status'] === 200 && $r['data']['prospect']['status'] === 'qualified' && $r['data']['history'][0]['type'] === 'status', '2. zmiana statusu zapisana z historią (stary → nowy)');
UbrT::eq(422, ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'], ['status' => 'hacked'])['status'], '2. nieznany status odrzucony (422)');

// 3. Status prywatny: organizacja B nie widzi zmiany; globalna firma bez zmian.
ubrt_rest($uB, 'POST', '/prospects', ['company_id' => $plA]);
$pAB = $pl->prospectForCompany($orgB, $plA);
UbrT::ok($pAB && $pAB['status'] === 'new' && ! array_key_exists('status', app(CompanyRepository::class)->find($plA)), '3. status w org A nie zmienia statusu org B ani globalnej firmy');

// 4. Właściciel: tylko członek tej organizacji.
UbrT::eq(422, ubrt_rest($uA, 'POST', '/prospects/' . $pA['id'], ['owner_user_id' => $uB])['status'], '4. nie można przypisać użytkownika spoza organizacji');
$r = ubrt_rest($uA, 'POST', '/prospects/' . $pA['id'], ['owner_user_id' => $uAd]);
UbrT::ok($r['status'] === 200 && $r['data']['prospect']['owner_user_id'] === $uAd, '4. przypisanie członka organizacji');

// 5. Priorytet sprzedażowy niezależny od scoringów.
$before = app(CompanyRepository::class)->find($plA);
$r = ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'], ['priority' => 'urgent']);
$after = app(CompanyRepository::class)->find($plA);
UbrT::ok($r['data']['prospect']['priority'] === 'urgent' && $before['opportunity_score'] === $after['opportunity_score'] && $before['top_contact_score'] === $after['top_contact_score'], '5. priorytet URGENT zapisany, scoringi bez zmian');
UbrT::eq(422, ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'], ['priority' => 'mega'])['status'], '5. nieznany priorytet odrzucony');

// 6. Powód utraty.
$r = ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'], ['status' => 'lost', 'lost_reason' => 'Wybrali inną agencję <b>x</b>']);
UbrT::ok($r['data']['prospect']['status'] === 'lost' && ! str_contains((string) $r['data']['prospect']['lost_reason'], '<b>'), '6. status LOST z powodem (bez HTML)');
ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'], ['status' => 'to_contact']);

UbrT::group('[pipeline] Notatki, aktywności, follow-upy, IDOR');

// 7. Notatki: XSS, edycja tylko autor, usuwanie autor / moderator, soft delete.
$r = ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'] . '/notes', ['body' => 'Rozmowa <script>alert(1)</script>ok PRYWATNA-NOTATKA-XYZ']);
$note = $r['data']['notes'][0] ?? null;
UbrT::ok($r['status'] === 201 && $note && ! str_contains($note['body'], '<script>') && str_contains($note['body'], 'PRYWATNA-NOTATKA-XYZ'), '7. notatka zapisana bez znaczników HTML (XSS)');
UbrT::eq(403, ubrt_rest($uAd, 'POST', '/notes/' . $note['id'], ['body' => 'zmiana'])['status'], '7. edycja cudzej notatki zablokowana');
UbrT::eq(200, ubrt_rest($uM, 'POST', '/notes/' . $note['id'], ['body' => 'PRYWATNA-NOTATKA-XYZ poprawiona'])['status'], '7. autor edytuje swoją notatkę');
$n2 = ubrt_rest($uA, 'POST', '/prospects/' . $pA['id'] . '/notes', ['body' => 'notatka ownera'])['data']['notes'][0];
UbrT::eq(403, ubrt_rest($uM, 'DELETE', '/notes/' . $n2['id'])['status'], '7. Member nie usuwa cudzej notatki');
UbrT::eq(200, ubrt_rest($uA, 'DELETE', '/notes/' . $n2['id'])['status'], '7. Owner (moderator) usuwa notatkę');
UbrT::ok((bool) $db->value("SELECT deleted_at IS NOT NULL FROM {$db->table(Tables::PROSPECT_NOTES)} WHERE id = %d", $n2['id']), '7. usunięcie notatki = soft delete (ślad audytowy)');

// 8. IDOR: organizacja B nie widzi / nie zmienia danych A.
UbrT::eq(404, ubrt_rest($uB, 'POST', '/prospects/' . $pA['id'], ['status' => 'won'])['status'], '8. org B nie zmieni prospektu org A (podmiana ID)');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/notes/' . $note['id'], ['body' => 'x'])['status'], '8. org B nie edytuje notatki org A');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/prospects/' . $pA['id'] . '/notes', ['body' => 'x'])['status'], '8. org B nie dodaje notatki do prospektu org A');
$salesB = ubrt_rest($uB, 'GET', '/companies/' . $plA . '/sales');
UbrT::ok($salesB['status'] === 200 && $salesB['data']['notes'] === [] && $salesB['data']['prospect']['status'] === 'new', '8. org B widzi wyłącznie własną sprzedaż tej samej firmy (bez notatek A)');
UbrT::ok(in_array(ubrt_rest($uX, 'GET', '/companies/' . $plA . '/sales')['status'], [403, 404], true), '8. użytkownik spoza organizacji — brak dostępu');

// 9. Aktywności: ręczne, bez dat z przyszłości, aktualizują ostatnią aktywność.
UbrT::eq(422, ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'] . '/activities', ['type' => 'call', 'description' => 'x', 'occurred_at' => gmdate('Y-m-d H:i', time() + 86400 * 3)])['status'], '9. aktywność z przyszłą datą odrzucona');
UbrT::eq(422, ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'] . '/activities', ['type' => 'fax', 'description' => 'x'])['status'], '9. nieznany typ aktywności odrzucony');
$r = ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'] . '/activities', ['type' => 'meeting', 'description' => 'Spotkanie w biurze']);
UbrT::ok($r['status'] === 201 && $r['data']['activities'][0]['type'] === 'meeting' && $r['data']['prospect']['last_activity_at'] !== null, '9. aktywność MEETING zapisana, ostatnia aktywność ustawiona');

// 10. Follow-upy: termin, następne działanie, agenda (zaległe / dziś / nadchodzące), wykonanie.
$r = ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'] . '/followups', ['title' => 'Zadzwonić', 'due_at' => wp_date('Y-m-d H:i', time() - 3600 * 30), 'assigned_user_id' => $uM]);
$fu = $r['data']['followups'][0] ?? null;
UbrT::ok($r['status'] === 201 && $fu && $fu['overdue'] && $r['data']['prospect']['next_action_at'] !== null, '10. follow-up zaległy, następne działanie ustawione');
UbrT::eq(422, ubrt_rest($uM, 'POST', '/prospects/' . $pA['id'] . '/followups', ['title' => 'x', 'due_at' => gmdate('Y-m-d'), 'assigned_user_id' => $uB])['status'], '10. follow-up nie może być przypisany osobie spoza organizacji');
$agenda = ubrt_rest($uM, 'GET', '/pipeline/agenda');
UbrT::ok(in_array($fu['id'], array_column($agenda['data']['overdue'] ?? [], 'id'), true), '10. Dashboard: follow-up w sekcji „zaległe”');
$r = ubrt_rest($uM, 'POST', '/followups/' . $fu['id'], ['status' => 'completed']);
UbrT::ok($r['data']['followups'][0]['status'] === 'completed' && $r['data']['followups'][0]['completed_at'] !== null && $r['data']['prospect']['next_action_at'] === null, '10. wykonanie: data wykonania, następne działanie wyczyszczone');
UbrT::eq(404, ubrt_rest($uB, 'POST', '/followups/' . $fu['id'], ['status' => 'cancelled'])['status'], '10. org B nie zmieni follow-upu org A');

UbrT::group('[pipeline] Tablica, archiwizacja, statystyki, kwalifikacja');

// 11. Tablica: kolumny = statusy, liczniki, lazy load, filtry.
ubrt_rest($uM, 'POST', '/prospects', ['company_id' => $plB]);
ubrt_rest($uM, 'POST', '/prospects', ['company_id' => $plC]);
$board = ubrt_rest($uM, 'GET', '/pipeline/board', ['per_column' => 5]);
$cols = array_column($board['data']['columns'], null, 'key');
UbrT::ok(count($cols) === 10 && $cols['new']['total'] >= 2 && $cols['to_contact']['total'] >= 1, '11. tablica: 10 kolumn statusów z licznikami');
$page = ubrt_rest($uM, 'GET', '/pipeline/board', ['status' => 'new', 'offset' => 1, 'per_column' => 5]);
UbrT::ok(count($page['data']['columns']) === 1 && count($page['data']['columns'][0]['items']) === $cols['new']['total'] - 1, '11. doładowanie kolumny (offset) — lazy load');
$f = ubrt_rest($uM, 'GET', '/pipeline/board', ['priority' => 'urgent']);
UbrT::eq(1, array_sum(array_column($f['data']['columns'], 'total')), '11. filtr priorytetu');
$card = array_values(array_filter($cols['to_contact']['items'], fn ($c) => $c['company_id'] === $plA))[0] ?? null;
UbrT::ok($card && array_key_exists('opportunity', $card) && array_key_exists('relevance', $card) && array_key_exists('top_contact', $card) && $card['owner']['id'] === $uAd, '11. karta: scoringi, Top 1, priorytet, właściciel');

// 12. Archiwizacja zamiast usuwania (historia zachowana) + przywrócenie.
$pB = $pl->prospectForCompany($orgA, $plB);
ubrt_rest($uM, 'POST', '/prospects/' . $pB['id'] . '/notes', ['body' => 'zostaje']);
UbrT::eq(200, ubrt_rest($uM, 'DELETE', '/prospects/' . $pB['id'])['status'], '12. „usuń” z Prospects');
$archived = $pl->prospect($orgA, (int) $pB['id']);
UbrT::ok($archived && $archived['archived_at'] !== null && count($pl->notes($orgA, (int) $pB['id'])) === 1, '12. rekord zarchiwizowany, notatki zachowane');
$list = ubrt_rest($uM, 'GET', '/prospects');
UbrT::ok(! in_array($plB, array_column($list['data']['items'], 'id'), true), '12. zarchiwizowany nie jest na liście aktywnych');
ubrt_rest($uM, 'POST', '/prospects/' . $pB['id'] . '/archive', ['restore' => true]);
UbrT::ok($pl->prospect($orgA, (int) $pB['id'])['archived_at'] === null, '12. przywrócenie z archiwum');

// 13. Statystyki Dashboardu z prawdziwych danych + unieważnianie cache.
$stats = ubrt_rest($uM, 'GET', '/dashboard')['data']['pipeline'];
$realTotal = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND archived_at IS NULL", $orgA);
UbrT::eq($realTotal, $stats['total'], '13. Dashboard: suma = liczba aktywnych prospektów organizacji');
ubrt_rest($uM, 'POST', '/prospects/' . $pB['id'], ['status' => 'won']);
$stats2 = ubrt_rest($uM, 'GET', '/dashboard')['data']['pipeline'];
UbrT::eq(1, array_column($stats2['by_status'], 'count', 'key')['won'], '13. zmiana statusu unieważnia cache statystyk');
UbrT::eq(0, array_sum(array_column(ubrt_rest($uB, 'GET', '/dashboard')['data']['pipeline']['by_status'], 'count')) - (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND archived_at IS NULL", $orgB), '13. statystyki org B liczone tylko z danych B');

// 14. Kwalifikacja: sugestia z danych, decyzja ręczna; niski Opportunity nie blokuje.
app(CompanyRepository::class)->update($plC, ['opportunity_score' => 12]);
$sales = ubrt_rest($uM, 'GET', '/companies/' . $plC . '/sales')['data'];
UbrT::ok(isset($sales['suggestion']['key']) && $sales['prospect']['qualification'] === null, '14. sugestia kwalifikacji bez automatycznej decyzji');
$r = ubrt_rest($uM, 'POST', '/prospects/' . $sales['prospect']['id'], ['qualification' => 'good_candidate', 'status' => 'qualified']);
UbrT::ok($r['status'] === 200 && $r['data']['prospect']['qualification'] === 'good_candidate' && $r['data']['prospect']['status'] === 'qualified', '14. ręczna kwalifikacja „dobry kandydat” mimo niskiego Opportunity');

/* ======================================================================================
 * CLICKUP (15–50) — mocki HTTP
 * ==================================================================================== */
UbrT::group('[mock] ClickUp — konfiguracja i OAuth');

// 15. Brak konfiguracji → Not Configured (o ile stałe nie są zdefiniowane w tym środowisku).
if (! defined('UBR_CLICKUP_CLIENT_ID')) {
	$st = ubrt_rest($uA, 'GET', '/clickup/status');
	UbrT::ok($st['data']['state'] === 'not_configured' && $st['data']['connected'] === false && in_array('UBR_CLICKUP_CLIENT_ID', $st['data']['configuration']['missing'], true), '15. bez Client ID / Secret: stan Not Configured (bez udawania połączenia)');
	UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/oauth/start')['status'], '15. start OAuth bez konfiguracji odrzucony');
	define('UBR_CLICKUP_CLIENT_ID', 'ubrtest-client-id');
	define('UBR_CLICKUP_CLIENT_SECRET', 'ubrtest-SECRET-value-123');
} else {
	UbrT::ok(true, '15. (stałe ClickUp zdefiniowane w tym środowisku — test „Not Configured” pominięty; zapytania i tak mockowane)');
}

if (! defined('UBR_CLICKUP_REDIRECT_URI')) {
	define('UBR_CLICKUP_REDIRECT_URI', 'https://ubrtest.example/app/clickup-callback/');
}

add_filter('ubr/clickup/throttle', '__return_false');
$GLOBALS['ubrt_cu'] = ['calls' => [], 'tasks' => [], 'next' => [], 'seq' => 0, 'revoked' => []];
$cuMock = function ($pre, $args, $url) {
	if (! str_starts_with($url, 'https://api.clickup.com/api/v2')) {
		return $pre;
	}

	$s = &$GLOBALS['ubrt_cu'];
	$method = strtoupper($args['method'] ?? 'GET');
	$path = (string) parse_url(substr($url, strlen('https://api.clickup.com/api/v2')), PHP_URL_PATH);
	$body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
	$auth = (string) ($args['headers']['Authorization'] ?? '');
	$s['calls'][] = ['method' => $method, 'path' => $path, 'body' => $body, 'auth' => $auth];
	$json = fn ($code, $data, $headers = []) => ['headers' => $headers, 'body' => wp_json_encode($data), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

	foreach ($s['next'] as $i => $n) {
		if (str_starts_with($method . ' ' . $path, $n['match'])) {
			array_splice($s['next'], $i, 1);

			if ($n['resp'] === 'timeout') {
				return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds');
			}

			return $json($n['resp'], ['err' => 'mock error', 'ECODE' => $n['ecode'] ?? 'MOCK_' . $n['resp']], $n['headers'] ?? []);
		}
	}

	if ($path === '/oauth/token') {
		return ($body['code'] ?? '') === 'good-code' && ($body['client_secret'] ?? '') !== ''
			? $json(200, ['access_token' => 'ubrt-TOKEN-' . ($s['seq']++) . '-secretish'])
			: $json(401, ['err' => 'Code invalid', 'ECODE' => 'OAUTH_017']);
	}

	$token = substr($auth, 7);

	if (! str_starts_with($auth, 'Bearer ') || in_array($token, $s['revoked'], true)) {
		return $json(401, ['err' => 'Token invalid', 'ECODE' => 'OAUTH_027']);
	}

	return match (true) {
		$path === '/user' => $json(200, ['user' => ['id' => 777, 'username' => 'ubrtest-cu', 'email' => 'cu@example.test']]),
		$path === '/team' => $json(200, ['teams' => [['id' => '901', 'name' => 'Ubrtest WS']]]),
		$path === '/team/901/space' => $json(200, ['spaces' => [['id' => 'S1', 'name' => 'Sales'], ['id' => 'S2', 'name' => 'Pusty']]]),
		$path === '/space/S1/folder' => $json(200, ['folders' => [['id' => 'F1', 'name' => 'Leads']]]),
		$path === '/space/S2/folder' => $json(200, ['folders' => []]),
		$path === '/folder/F1/list' => $json(200, ['lists' => [['id' => 'L1', 'name' => 'Leady']]]),
		$path === '/space/S1/list' => $json(200, ['lists' => [['id' => 'L2', 'name' => 'Bez folderu']]]),
		$path === '/space/S2/list' => $json(200, ['lists' => []]),
		$path === '/list/L1/field' => $json(200, ['fields' => [
			['id' => 'fld-opp', 'name' => 'Opportunity', 'type' => 'number'],
			['id' => 'fld-name', 'name' => 'Company', 'type' => 'short_text'],
			['id' => 'fld-date', 'name' => 'Deadline', 'type' => 'date'],
			['id' => 'fld-status', 'name' => 'Pipeline', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'opt-new', 'name' => 'Nowy'], ['id' => 'opt-q', 'name' => 'Zakwalifikowany']]]],
		]]),
		$path === '/list/L2/field' => $json(200, ['fields' => []]),
		$path === '/list/LF/field' => $json(403, ['err' => 'No access to list', 'ECODE' => 'ACCESS_083']),
		$path === '/list/LX/field', $path === '/list/LX/task' => $json(404, ['err' => 'List not found', 'ECODE' => 'ITEM_013']),
		$method === 'POST' && (bool) preg_match('#^/list/(L\d)/task$#', $path, $m) => (function () use (&$s, $m, $body, $json) {
			$id = 'tsk' . (++$s['seq']);
			$s['tasks'][$id] = ['list' => $m[1], 'name' => $body['name'] ?? '', 'md' => $body['markdown_content'] ?? '', 'fields' => []];

			return $json(200, ['id' => $id, 'url' => 'https://app.clickup.com/t/' . $id, 'name' => $body['name'] ?? '']);
		})(),
		$method === 'GET' && (bool) preg_match('#^/task/(\w+)$#', $path, $m) => isset($s['tasks'][$m[1]])
			? $json(200, ['id' => $m[1], 'url' => 'https://app.clickup.com/t/' . $m[1], 'list' => ['id' => $s['tasks'][$m[1]]['list']], 'markdown_description' => $s['tasks'][$m[1]]['md']])
			: $json(404, ['err' => 'Task not found', 'ECODE' => 'ITEM_015']),
		$method === 'PUT' && (bool) preg_match('#^/task/(\w+)$#', $path, $m) => (function () use (&$s, $m, $body, $json) {
			$s['tasks'][$m[1]]['md'] = $body['markdown_content'] ?? $s['tasks'][$m[1]]['md'];

			return $json(200, ['id' => $m[1]]);
		})(),
		$method === 'POST' && (bool) preg_match('#^/task/(\w+)/field/([\w-]+)$#', $path, $m) => (function () use (&$s, $m, $body, $json) {
			$s['tasks'][$m[1]]['fields'][$m[2]] = $body['value'] ?? null;

			return $json(200, []);
		})(),
		default => $json(404, ['err' => 'unmocked ' . $method . ' ' . $path, 'ECODE' => 'MOCK_404']),
	};
};
add_filter('pre_http_request', $cuMock, 5, 3);
$cuCalls = fn (string $prefix) => count(array_filter($GLOBALS['ubrt_cu']['calls'], fn ($c) => str_starts_with($c['method'] . ' ' . $c['path'], $prefix)));
$cuRunner = app(ClickUpRunner::class);
$cuRepo = app(ClickUpRepository::class);
$cuRun = function () use ($cuRunner) {
	delete_option('ubr_clickup_lock');

	return $cuRunner->tick(false, 30);
};
$cuOauth = app(ClickUpOAuthService::class);
$cuConnect = function (int $userId, string $code = 'good-code') use ($cuOauth) {
	$start = ubrt_rest($userId, 'POST', '/clickup/oauth/start');
	parse_str((string) parse_url((string) ($start['data']['authorize_url'] ?? ''), PHP_URL_QUERY), $q);
	wp_set_current_user($userId);

	return ['start' => $start, 'query' => $q, 'result' => $cuOauth->handleCallback(['code' => $code, 'state' => $q['state'] ?? ''])];
};

// 16. Start OAuth: adres autoryzacji ze state, client_id, redirect_uri; bez sekretów.
$start = ubrt_rest($uA, 'POST', '/clickup/oauth/start');
parse_str((string) parse_url((string) $start['data']['authorize_url'], PHP_URL_QUERY), $q);
UbrT::ok(str_starts_with($start['data']['authorize_url'], 'https://app.clickup.com/api?') && preg_match('/^[a-f0-9]{64}$/', $q['state'] ?? '') && $q['client_id'] === UBR_CLICKUP_CLIENT_ID && $q['redirect_uri'] === UBR_CLICKUP_REDIRECT_URI, '16. start OAuth: state (256 bit), client_id, redirect_uri');
UbrT::ok(! str_contains(wp_json_encode($start['data']), (string) UBR_CLICKUP_CLIENT_SECRET), '16. Client Secret nie trafia do przeglądarki');
UbrT::eq(403, ubrt_rest($uX, 'POST', '/clickup/oauth/start')['status'], '16. użytkownik bez organizacji nie rozpocznie OAuth');

// 17–18. Callback: zły state; inny użytkownik (state jednorazowy).
wp_set_current_user($uA);
UbrT::eq('invalid_state', $cuOauth->handleCallback(['code' => 'good-code', 'state' => str_repeat('a', 64)]), '17. nieznany state odrzucony');
UbrT::eq('invalid_state', $cuOauth->handleCallback(['code' => 'good-code', 'state' => '../x']), '17. state w złym formacie odrzucony');
wp_set_current_user($uB);
UbrT::eq('wrong_user', $cuOauth->handleCallback(['code' => 'good-code', 'state' => $q['state']]), '18. callback innego użytkownika odrzucony (nie można przejąć autoryzacji)');
wp_set_current_user($uA);
UbrT::eq('invalid_state', $cuOauth->handleCallback(['code' => 'good-code', 'state' => $q['state']]), '18. state jednorazowy — po próbie nieważny');
UbrT::ok($cuRepo->connection($orgA, $uA) === null && $cuRepo->connection($orgB, $uB) === null, '18. żadne połączenie nie powstało');

// 19. Poprawny callback: połączenie użytkownika i organizacji z state (nie z URL); token zaszyfrowany.
$c = $cuConnect($uA);
$connA = $cuRepo->connection($orgA, $uA);
$rawToken = 'ubrt-TOKEN-0-secretish';
UbrT::ok($c['result'] === 'connected' && $connA && $connA['status'] === 'connected', '19. callback: połączono konto (mock OAuth)');
UbrT::ok(! str_contains((string) $connA['token_ciphertext'], $rawToken) && str_starts_with((string) $connA['token_ciphertext'], 'v1:') && $cuOauth->token($connA) === $rawToken, '19. token zaszyfrowany w bazie (secretbox), odszyfrowywany tylko na serwerze');
wp_set_current_user($uA);
UbrT::eq('invalid_state', $cuOauth->handleCallback(['code' => 'good-code', 'state' => $c['query']['state'], 'organization_id' => $orgB, 'user_id' => $uB]), '19. ponowne użycie state (replay) z podmienionym org/user odrzucone');

// 20. Brak tokenów / sekretów w odpowiedziach i dzienniku.
$st = ubrt_rest($uA, 'GET', '/clickup/status');
$logs = wp_json_encode($db->rows("SELECT * FROM {$db->table(Tables::API_REQUESTS)} WHERE provider = 'clickup' AND id > %d", $ubrtMaxRequestId));
UbrT::ok($st['data']['state'] === 'connected' && ! str_contains(wp_json_encode($st['data']), 'TOKEN') && ! str_contains(wp_json_encode($st['data']), 'v1:'), '20. status połączenia bez tokenu i szyfrogramu');
UbrT::ok(! str_contains($logs, 'TOKEN') && ! str_contains($logs, 'SECRET') && ! str_contains($logs, 'good-code') && in_array('/team', array_column(json_decode($logs, true), 'endpoint'), true), '20. dziennik zapytań ClickUp bez tokenu, sekretu i kodu autoryzacji');

// 21–22. Błędna wymiana kodu; odmowa.
$bad = $cuConnect($uM, 'bad-code');
UbrT::ok($bad['result'] === 'exchange_failed' && $cuRepo->connection($orgA, $uM) === null, '21. odrzucony kod → exchange_failed, brak połączenia');
$start = ubrt_rest($uM, 'POST', '/clickup/oauth/start');
parse_str((string) parse_url((string) $start['data']['authorize_url'], PHP_URL_QUERY), $q2);
wp_set_current_user($uM);
UbrT::eq('denied', $cuOauth->handleCallback(['error' => 'access_denied', 'state' => $q2['state']]), '22. anulowanie autoryzacji przez użytkownika');

// 23. Test Connection.
$t = ubrt_rest($uA, 'POST', '/clickup/test');
UbrT::ok($t['data']['ok'] === true && $cuCalls('GET /user') >= 2, '23. Test Connection (GET /user) — bez tworzenia czegokolwiek');

UbrT::group('[mock] ClickUp — miejsce eksportu, mapowanie pól');

// 24. Workspace → Space → Folder → List, listy bez folderu, puste.
$o = fn ($level, $parent = '') => ubrt_rest($uA, 'GET', '/clickup/options', ['level' => $level, 'parent_id' => $parent])['data'];
UbrT::ok($o('teams')['items'][0]['id'] === '901' && $o('spaces', '901')['items'][1]['id'] === 'S2' && $o('folders', 'S1')['items'][0]['id'] === 'F1' && $o('folder_lists', 'F1')['items'][0]['id'] === 'L1', '24. hierarchia z API: Workspace → Space → Folder → List');
UbrT::ok($o('folderless_lists', 'S1')['items'][0]['id'] === 'L2' && $o('folderless_lists', 'S2')['empty'] === true, '24. listy bez folderu i pusty Space');

// 25. Zapis miejsca: nieautoryzowany Workspace, brak uprawnień do listy, poprawny zapis.
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/destination', ['team_id' => '999', 'list_id' => 'L1'])['status'], '25. Workspace spoza autoryzacji odrzucony');
$r = ubrt_rest($uA, 'POST', '/clickup/destination', ['team_id' => '901', 'list_id' => 'LF']);
UbrT::ok($r['status'] === 422 && str_contains((string) $r['data']['code'], 'forbidden'), '25. lista bez uprawnień (403) — czytelny błąd');
$r = ubrt_rest($uA, 'POST', '/clickup/destination', ['team_id' => '901', 'team_name' => 'Ubrtest WS', 'space_id' => 'S1', 'folder_id' => 'F1', 'list_id' => 'L1', 'list_name' => 'Leady']);
UbrT::ok($r['status'] === 200 && $r['data']['destination']['list_id'] === 'L1', '25. zapis miejsca eksportu (Default Destination)');

// 26. Mapowanie Custom Fields: tylko istniejące, zgodne typy; bez tworzenia pól.
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/settings', ['field_mapping' => ['opportunity_score' => 'fld-date']])['status'], '26. pole o niezgodnym typie odrzucone');
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/settings', ['field_mapping' => ['opportunity_score' => 'fld-nope']])['status'], '26. nieistniejące pole odrzucone');
$r = ubrt_rest($uA, 'POST', '/clickup/settings', ['field_mapping' => ['opportunity_score' => 'fld-opp', 'company_name' => 'fld-name', 'pipeline_status' => 'fld-status']]);
UbrT::ok($r['status'] === 200 && count($r['data']['settings']['field_mapping']) === 3 && $cuCalls('POST /list/L1/field') === 0, '26. mapowanie zapisane; żadne pole nie zostało utworzone w ClickUp');

// 27. Szablon nazwy.
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/settings', ['task_name_template' => 'Bez nazwy firmy'])['status'], '27. szablon bez {company} odrzucony');
UbrT::eq(200, ubrt_rest($uA, 'POST', '/clickup/settings', ['task_name_template' => '{company} — Redesign'])['status'], '27. szablon nazwy zapisany');

UbrT::group('[mock] ClickUp — eksport, treść, deduplikacja, aktualizacja');

// Dane do treści: kontakt ręczny (niezweryfikowany) + odrzucony + prywatna notatka (już jest w $plA).
ubrt_rest($uA, 'POST', '/companies/' . $plA . '/contacts', ['full_name' => 'Jan Eksportowy', 'job_title' => 'Prezes Zarządu']);
$cv = ubrt_rest($uA, 'POST', '/companies/' . $plA . '/contacts', ['full_name' => 'Ewa Odrzucona', 'job_title' => 'Dyrektor Marketingu'])['data'];
$rej = array_values(array_filter(array_merge($cv['top'] ?? [], $cv['others'] ?? []), fn ($x) => $x['full_name'] === 'Ewa Odrzucona'))[0] ?? null;
$rej && ubrt_rest($uA, 'POST', '/contacts/' . $rej['id'] . '/review', ['action' => 'reject']);

// 28. Eksport w tle: żądanie nie wywołuje ClickUp; runner tworzy zadanie.
$before = count($GLOBALS['ubrt_cu']['calls']);
$r = ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plA]]);
UbrT::ok($r['status'] === 202 && count($r['data']['queued']) === 1 && count($GLOBALS['ubrt_cu']['calls']) === $before, '28. eksport zlecony do kolejki — bez wywołania ClickUp w żądaniu użytkownika');
$cuRun();
$expA = $cuRepo->exportFor($orgA, $plA, 'L1');
$taskA = $GLOBALS['ubrt_cu']['tasks'][$expA['task_id']] ?? null;
UbrT::ok($expA['export_status'] === 'success' && str_starts_with((string) $expA['task_url'], 'https://app.clickup.com/t/') && $taskA && $taskA['name'] === 'Ubrtest Pipeline Alfa — Redesign', '28. zadanie utworzone (mock), zapisane task_id / URL, nazwa z szablonu');
UbrT::ok((int) $expA['organization_id'] === $orgA && (int) $expA['user_id'] === $uA && $expA['workspace_id'] === '901' && $expA['exported_at'] !== null, '28. rekord eksportu: organizacja, użytkownik, workspace, lista, data');

// 29. Treść: sekcje, bez prywatnych notatek, bez odrzuconych, niepewny oznaczony, bez screenshotów.
$md = (string) ($taskA['md'] ?? '');
UbrT::ok(str_contains($md, ClickUpTaskBuilder::START) && str_contains($md, '**COMPANY**') && str_contains($md, '**SCORES**') && str_contains($md, '**WEBSITE**') && str_contains($md, '**DECISION MAKERS**') && str_contains($md, '**CONTACT**') && str_contains($md, '**LINKS**'), '29. treść: COMPANY / SCORES / WEBSITE / DECISION MAKERS / CONTACT / LINKS');
UbrT::ok(! str_contains($md, 'PRYWATNA-NOTATKA-XYZ') && ! str_contains($md, 'Ewa Odrzucona'), '29. bez prywatnych notatek i odrzuconych kontaktów');
UbrT::ok((bool) preg_match('/Jan Eksportowy[^\n]*\((prawdopodobny|NIEPOTWIERDZONY)/u', $md) && ! preg_match('/Jan Eksportowy[^\n]*\(zweryfikowany/u', $md) && ! preg_match('#/screenshots?/|\.png|\.webp#i', $md), '29. kontakt dodany ręcznie nie jest pokazany jako zweryfikowany; brak screenshotów');
UbrT::ok(($taskA['fields']['fld-opp'] ?? 'x') === 'x' && ($taskA['fields']['fld-name'] ?? '') === 'Ubrtest Pipeline Alfa' && ($taskA['fields']['fld-status'] ?? '') === '', '29. Custom Fields: tylko istniejące dane (brak Opportunity → pole pominięte; dropdown bez pasującej opcji pominięty)');

// 30. Ponowne kliknięcie: Already exported, bez drugiego POST.
$posts = $cuCalls('POST /list/L1/task');
$r = ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plA]]);
$cuRun();
UbrT::ok($r['data']['skipped'][0]['reason'] === 'already_exported' && $r['data']['skipped'][0]['task_url'] === $expA['task_url'] && $cuCalls('POST /list/L1/task') === $posts, '30. ponowny eksport: „Already exported” + link, bez duplikatu');
UbrT::ok(in_array('skipped_duplicate', array_column($cuRepo->jobsForCompany($orgA, $plA), 'status'), true), '30. historia: SKIPPED_DUPLICATE');

// 31. Równoległe kliknięcia: unikalny klucz w bazie.
$dupData = ['organization_id' => $orgA, 'company_id' => $plB, 'prospect_id' => null, 'user_id' => $uA, 'connection_id' => (int) $connA['id'], 'workspace_id' => '901', 'list_id' => 'L9', 'list_name' => 'x', 'variant' => 0];
$first = $cuRepo->reserveExport($dupData);
$wpdb->suppress_errors(true);
$second = $cuRepo->reserveExport($dupData);
$wpdb->suppress_errors(false);
UbrT::ok($first !== null && $second === null, '31. dwie równoległe rezerwacje tego samego eksportu → tylko jedna');
$wpdb->delete($db->table(Tables::CLICKUP_EXPORTS), ['id' => $first]);

// 32. Deduplikacja nie po nazwie: inna firma o tej samej nazwie = osobne zadanie.
$twin = $mkVisible('pl-twin', 'Ubrtest Pipeline Alfa', 'https://pl-alfa-inna.ubrtest.pl/');
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$twin]]);
$cuRun();
UbrT::ok(($cuRepo->exportFor($orgA, $twin, 'L1')['export_status'] ?? '') === 'success' && $cuRepo->exportFor($orgA, $twin, 'L1')['task_id'] !== $expA['task_id'], '32. firma o tej samej nazwie (inna firma) — osobne zadanie');

// 33. Update existing task: tylko blok + pola; tekst spoza bloku zostaje.
$GLOBALS['ubrt_cu']['tasks'][$expA['task_id']]['md'] = "Notatka handlowca w ClickUp\n\n" . $md . "\n\nKomentarz pod spodem";
app(CompanyRepository::class)->update($plA, ['opportunity_score' => 66]);
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plA], 'mode' => 'update']);
$cuRun();
$md2 = $GLOBALS['ubrt_cu']['tasks'][$expA['task_id']]['md'];
UbrT::ok(str_contains($md2, 'Notatka handlowca w ClickUp') && str_contains($md2, 'Komentarz pod spodem') && str_contains($md2, '66/100') && substr_count($md2, ClickUpTaskBuilder::START) === 1, '33. aktualizacja: nowa sekcja Ugly but Rich, treść spoza sekcji nietknięta');
UbrT::ok(($GLOBALS['ubrt_cu']['tasks'][$expA['task_id']]['fields']['fld-opp'] ?? null) === 66 && $cuRepo->exportFor($orgA, $plA, 'L1')['last_synced_at'] !== null, '33. zmapowane pole zaktualizowane, Last Sync ustawiony');
UbrT::eq(0, count(array_filter($GLOBALS['ubrt_cu']['calls'], fn ($c) => $c['method'] === 'PUT' && (isset($c['body']['status']) || isset($c['body']['name']) || isset($c['body']['assignees'])))), '33. PUT nie zmienia statusu, nazwy ani przypisań zadania');

// 34. Sekcja zmieniona ręcznie → nie nadpisujemy.
$GLOBALS['ubrt_cu']['tasks'][$expA['task_id']]['md'] = str_replace('**SCORES**', '**SCORES (edytowane ręcznie)**', $md2);
$puts = $cuCalls('PUT /task/');
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plA], 'mode' => 'update']);
$cuRun();
$lastJob = $cuRepo->jobsForCompany($orgA, $plA)[0];
UbrT::ok($cuCalls('PUT /task/') === $puts && str_contains(wp_json_encode($lastJob['result']), 'zmieniona r'), '34. ręcznie edytowana sekcja nie jest nadpisywana (informacja w historii)');

// 35. Export as new task.
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plA], 'mode' => 'new']);
$cuRun();
$variants = array_filter($cuRepo->exportsForCompany($orgA, $plA), fn ($e) => $e['list_id'] === 'L1');
UbrT::ok(count($variants) === 2 && in_array(1, array_map(fn ($e) => (int) $e['variant'], $variants), true), '35. „Export as new task” tworzy świadomie drugi wariant');

UbrT::group('[mock] ClickUp — błędy, ponowienia, stan UNKNOWN');

// 36. Timeout po POST → UNKNOWN, bez automatycznego ponowienia.
$GLOBALS['ubrt_cu']['next'][] = ['match' => 'POST /list/L1/task', 'resp' => 'timeout'];
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plB]]);
$posts = $cuCalls('POST /list/L1/task');
$cuRun();
$cuRun();
$expB = $cuRepo->exportFor($orgA, $plB, 'L1');
UbrT::ok($expB['export_status'] === 'unknown' && $cuCalls('POST /list/L1/task') === $posts + 1, '36. timeout po wysłaniu → UNKNOWN, brak automatycznego ponowienia POST');
$r = ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plB]]);
UbrT::eq('unknown', $r['data']['skipped'][0]['reason'] ?? null, '36. ponowny eksport zablokowany do rekoncyliacji');

// 37. Rekoncyliacja: wskazanie zadania (z weryfikacją listy) / potwierdzenie, że nie powstało.
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/exports/' . $expB['id'] . '/reconcile', ['action' => 'set_task', 'task' => 'nieistnieje1'])['status'], '37. wskazanie nieistniejącego zadania odrzucone');
UbrT::eq(200, ubrt_rest($uA, 'POST', '/clickup/exports/' . $expB['id'] . '/reconcile', ['action' => 'mark_not_created'])['status'], '37. „zadanie nie powstało” → można eksportować ponownie');
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plB]]);
$cuRun();
$expB = $cuRepo->exportFor($orgA, $plB, 'L1');
UbrT::ok($expB['export_status'] === 'success', '37. eksport po rekoncyliacji zakończony sukcesem');
$wpdb->update($db->table(Tables::CLICKUP_EXPORTS), ['export_status' => 'unknown', 'task_id' => null, 'task_url' => null], ['id' => $expB['id']]);
$r = ubrt_rest($uA, 'POST', '/clickup/exports/' . $expB['id'] . '/reconcile', ['action' => 'set_task', 'task' => 'https://app.clickup.com/t/' . array_key_last($GLOBALS['ubrt_cu']['tasks'])]);
UbrT::ok($r['status'] === 200 && $cuRepo->export($orgA, (int) $expB['id'])['export_status'] === 'success', '37. wskazanie istniejącego zadania z tej listy → SUCCESS');

// 38. 429 + Retry-After → ponowienie po wskazanym czasie.
$GLOBALS['ubrt_cu']['next'][] = ['match' => 'POST /list/L1/task', 'resp' => 429, 'headers' => ['retry-after' => '42']];
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plC]]);
$cuRun();
$job = $cuRepo->jobsForCompany($orgA, $plC)[0];
$delay = strtotime($job['next_attempt_at'] . ' UTC') - time();
UbrT::ok($job['status'] === 'queued' && $delay >= 35 && $delay <= 45 && $job['error_kind'] === 'rate_limited', '38. 429 → ponowienie zgodnie z Retry-After (42 s)');
$wpdb->update($db->table(Tables::CLICKUP_JOBS), ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $job['id']]);
$cuRun();
UbrT::eq('success', $cuRepo->exportFor($orgA, $plC, 'L1')['export_status'], '38. po odczekaniu — sukces');

// 39. 5xx: przy POST tworzącym → UNKNOWN; przy odczycie (update) → ponowienie.
$GLOBALS['ubrt_cu']['next'][] = ['match' => 'GET /task/', 'resp' => 503];
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plC], 'mode' => 'update']);
$cuRun();
$job = $cuRepo->jobsForCompany($orgA, $plC)[0];
UbrT::ok($job['status'] === 'queued' && $job['error_kind'] === 'server', '39. 503 przy odczycie zadania → ponowienie (tymczasowy)');
$wpdb->update($db->table(Tables::CLICKUP_JOBS), ['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $job['id']]);
$cuRun();
UbrT::eq('success', $cuRepo->job((int) $job['id'])['status'], '39. ponowienie zakończone sukcesem');

// 40. Ograniczona liczba prób.
for ($i = 0; $i < 3; $i++) {
	$GLOBALS['ubrt_cu']['next'][] = ['match' => 'GET /task/', 'resp' => 502];
}

ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plC], 'mode' => 'update']);

for ($i = 0; $i < 3; $i++) {
	$wpdb->query($wpdb->prepare("UPDATE {$db->table(Tables::CLICKUP_JOBS)} SET next_attempt_at = %s WHERE organization_id = %d AND status = 'queued'", gmdate('Y-m-d H:i:s', time() - 1), $orgA));
	$cuRun();
}

$job = $cuRepo->jobsForCompany($orgA, $plC)[0];
UbrT::ok($job['status'] === 'failed' && (int) $job['attempts'] === 3, '40. maks. 3 próby, potem FAILED (bez nieskończonych ponowień)');
UbrT::ok(! str_contains((string) $job['error_message'], 'mock error') && ! str_contains((string) $job['error_message'], '{'), '40. komunikat błędu bez surowego payloadu API');

// 41. Usunięta lista (404) → błąd trwały, bez ponowień.
$plD = $mkVisible('pl-d', 'Ubrtest Pipeline Delta', 'https://pl-delta.ubrtest.pl/');
$wpdb->update($db->table(Tables::CLICKUP_CONNECTIONS), ['destination' => wp_json_encode(['team_id' => '901', 'list_id' => 'LX', 'list_name' => 'Usunięta'])], ['id' => $connA['id']]);
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plD]]);
$cuRun();
$job = $cuRepo->jobsForCompany($orgA, $plD)[0];
UbrT::ok($job['status'] === 'failed' && $job['error_kind'] === 'not_found' && (int) $job['attempts'] === 1 && $cuRepo->exportFor($orgA, $plD, 'LX')['export_status'] === 'failed', '41. lista usunięta (404) → FAILED bez ponowień');
$wpdb->update($db->table(Tables::CLICKUP_CONNECTIONS), ['destination' => wp_json_encode(['team_id' => '901', 'team_name' => 'Ubrtest WS', 'list_id' => 'L1', 'list_name' => 'Leady'])], ['id' => $connA['id']]);

// 42. 401 w trakcie (odebrany dostęp) → Reconnect Required.
$GLOBALS['ubrt_cu']['next'][] = ['match' => 'POST /list/L1/task', 'resp' => 401, 'ecode' => 'OAUTH_027'];
ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plD]]);
$cuRun();
UbrT::ok($cuRepo->connection($orgA, $uA)['status'] === 'reconnect_required' && $cuRepo->exportFor($orgA, $plD, 'L1')['export_status'] === 'failed', '42. 401 → połączenie „Expired — Reconnect Required”, eksport FAILED');
UbrT::eq('reconnect_required', ubrt_rest($uA, 'GET', '/clickup/status')['data']['state'], '42. UI: stan Reconnect Required');
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$plD]])['status'], '42. eksport bez aktywnego połączenia odrzucony');
$cuConnect($uA);
$connA = $cuRepo->connection($orgA, $uA);

UbrT::group('[mock] ClickUp — multi-tenancy, eksport zbiorczy, limity');

// 43. Izolacja organizacji.
UbrT::eq(404, ubrt_rest($uB, 'POST', '/clickup/exports/' . $expB['id'] . '/reconcile', ['action' => 'mark_not_created'])['status'], '43. org B nie rozstrzyga eksportu org A');
$cuB = ubrt_rest($uB, 'GET', '/companies/' . $plA . '/clickup');
UbrT::ok($cuB['status'] === 200 && $cuB['data']['exports'] === [] && $cuB['data']['history'] === [], '43. org B nie widzi eksportów org A tej samej firmy');
UbrT::eq(404, ubrt_rest($uB, 'GET', '/companies/' . $plB . '/clickup')['status'], '43. org B nie widzi firmy znanej tylko org A');

// 44. Brak przejęcia cudzego tokenu.
UbrT::eq(422, ubrt_rest($uM, 'POST', '/clickup/exports', ['company_ids' => [$plA]])['status'], '44. członek bez własnego połączenia nie eksportuje (token Ownera nie jest używany)');
$fakeExp = $cuRepo->reserveExport(['organization_id' => $orgA, 'company_id' => $plD, 'prospect_id' => null, 'user_id' => $uM, 'connection_id' => (int) $connA['id'], 'workspace_id' => '901', 'list_id' => 'L2', 'list_name' => 'x', 'variant' => 0]);
$fakeJob = $cuRepo->createJob(['organization_id' => $orgA, 'user_id' => $uM, 'connection_id' => (int) $connA['id'], 'export_id' => $fakeExp, 'company_id' => $plD, 'operation' => 'create']);
$posts = $cuCalls('POST /list/L2/task');
$cuRun();
UbrT::ok($cuRepo->job($fakeJob)['status'] === 'failed' && $cuCalls('POST /list/L2/task') === $posts, '44. zadanie z połączeniem innego użytkownika odrzucone w workerze');
UbrT::ok(ubrt_rest($uB, 'GET', '/clickup/status')['data']['connected'] === false && ubrt_rest($uM, 'GET', '/clickup/status')['data']['connected'] === false, '44. połączenie jest per użytkownik i organizacja — inni członkowie / organizacje go nie widzą');

// 45. Eksport zbiorczy w tle z postępem; limit rozmiaru.
$bulk = [];

foreach (['e1', 'e2', 'e3'] as $k) {
	$bulk[] = $mkVisible('pl-' . $k, 'Ubrtest Bulk ' . $k, 'https://pl-' . $k . '.ubrtest.pl/');
}

$r = ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => $bulk]);
$batch = $r['data']['batch_id'];
$p0 = ubrt_rest($uA, 'GET', '/clickup/batches/' . $batch)['data'];
$cuRun();
$p1 = ubrt_rest($uA, 'GET', '/clickup/batches/' . $batch)['data'];
UbrT::ok($batch && $p0['done'] === false && ($p0['counts']['queued'] ?? 0) === 3 && $p1['done'] === true && ($p1['counts']['success'] ?? 0) === 3, '45. eksport zbiorczy: kolejka → postęp → 3 sukcesy');
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => range(1, 21)])['status'], '45. maks. 20 firm w jednym zleceniu');

// 46. Dzienny limit nowych zadań.
$limitF = fn () => 1;
add_filter('ubr/clickup/daily_new_tasks', $limitF);
$extra = $mkVisible('pl-lim', 'Ubrtest Limit', 'https://pl-lim.ubrtest.pl/');
$r = ubrt_rest($uA, 'POST', '/clickup/exports', ['company_ids' => [$extra]]);
remove_filter('ubr/clickup/daily_new_tasks', $limitF);
UbrT::eq('limit', $r['data']['skipped'][0]['reason'] ?? null, '46. dzienny limit nowych zadań respektowany');

UbrT::group('[mock] ClickUp — reguły automatycznego eksportu');

// 47. Reguły: domyślnie wyłączone, uprawnienia, walidacja, Dry Run, potwierdzenie.
UbrT::eq(403, ubrt_rest($uM, 'POST', '/clickup/rules', ['name' => 'x', 'conditions' => ['verified' => true]])['status'], '47. Member nie tworzy reguł');
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/rules', ['name' => 'Pusta', 'conditions' => []])['status'], '47. reguła bez warunków odrzucona');
$rule = ubrt_rest($uA, 'POST', '/clickup/rules', ['name' => 'Ubrtest HIGH', 'daily_limit' => 2, 'conditions' => ['priorities' => ['high'], 'prospect_statuses' => ['qualified', 'to_contact']]]);
$ruleId = $rule['data']['id'] ?? 0;
UbrT::ok($rule['status'] === 201 && $rule['data']['enabled'] === false && $rule['data']['list_id'] === 'L1', '47. nowa reguła jest WYŁĄCZONA (DISABLED BY DEFAULT)');
// Historyczna firma pasująca do reguły (przed włączeniem).
$hist = $mkVisible('pl-hist', 'Ubrtest Historyczna', 'https://pl-hist.ubrtest.pl/');
ubrt_rest($uA, 'POST', '/prospects', ['company_id' => $hist]);
$pH = $pl->prospectForCompany($orgA, $hist);
ubrt_rest($uA, 'POST', '/prospects/' . $pH['id'], ['priority' => 'high', 'status' => 'qualified']);
$jobsBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE organization_id = %d AND operation = 'evaluate'", $orgA);
UbrT::eq(0, $jobsBefore, '47. wyłączona reguła nie reaguje na zdarzenia');
$pv = ubrt_rest($uA, 'POST', '/clickup/rules/' . $ruleId . '/preview');
UbrT::ok($pv['data']['dry_run'] === true && $pv['data']['matching'] >= 1 && in_array($hist, array_column($pv['data']['sample'], 'company_id'), true) && ! $cuRepo->exportFor($orgA, $hist, 'L1'), '47. Dry Run: lista pasujących firm, nic nie eksportuje');
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/rules/' . $ruleId . '/enable', [])['status'], '47. włączenie wymaga potwierdzenia');
UbrT::eq(403, ubrt_rest($uAd, 'POST', '/clickup/rules/' . $ruleId . '/enable', ['confirm' => true])['status'], '47. tylko autor reguły może ją włączyć (jego połączenie)');
UbrT::ok(ubrt_rest($uA, 'POST', '/clickup/rules/' . $ruleId . '/enable', ['confirm' => true])['data']['enabled'] === true, '47. autor włącza regułę po potwierdzeniu');
$cuRun();
UbrT::ok(! $cuRepo->exportFor($orgA, $hist, 'L1'), '47. włączenie nie eksportuje historycznych firm (tylko nowe zdarzenia)');

// 48. Zdarzenia → kolejka → eksport; niepasujące pominięte; bez płatnych analiz.
$ev = $mkVisible('pl-ev', 'Ubrtest Zdarzenie', 'https://pl-ev.ubrtest.pl/');
ubrt_rest($uM, 'POST', '/prospects', ['company_id' => $ev]);
$pE = $pl->prospectForCompany($orgA, $ev);
$cuRun();
UbrT::ok(! $cuRepo->exportFor($orgA, $ev, 'L1') && in_array('skipped', array_column($db->rows("SELECT status FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE company_id = %d AND operation = 'evaluate'", $ev), 'status'), true), '48. zdarzenie dla niepasującej firmy → SKIPPED (warunki AND)');
$analysesBefore = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}");
ubrt_rest($uM, 'POST', '/prospects/' . $pE['id'], ['priority' => 'high', 'status' => 'to_contact']);
$cuRun();
$expE = $cuRepo->exportFor($orgA, $ev, 'L1');
UbrT::ok($expE && $expE['export_status'] === 'success' && (int) $expE['rule_id'] === $ruleId && (int) $expE['user_id'] === $uA, '48. zmiana statusu/priorytetu → reguła eksportuje przez połączenie autora');
UbrT::eq($analysesBefore, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::ANALYSES)}"), '48. reguła nie uruchamia analiz (płatnych ani żadnych)');
do_action('ubr/company/changed', $ev, 'pipeline_changed', $orgA);
$cuRun();
UbrT::eq(1, count(array_filter($cuRepo->exportsForCompany($orgA, $ev), fn ($e) => $e['list_id'] === 'L1')), '48. kolejne zdarzenie nie tworzy duplikatu');
UbrT::ok(ubrt_rest($uA, 'GET', '/clickup/rules')['data']['rules'][0]['exported_count'] >= 1, '48. licznik Exported Count reguły');

// 49. Backfill: osobne potwierdzenie, limit dzienny reguły.
UbrT::eq(422, ubrt_rest($uA, 'POST', '/clickup/rules/' . $ruleId . '/backfill', [])['status'], '49. eksport istniejących firm wymaga osobnego potwierdzenia');
$bf = ubrt_rest($uA, 'POST', '/clickup/rules/' . $ruleId . '/backfill', ['confirm' => true]);
$cuRun();
UbrT::ok($bf['status'] === 200 && $bf['data']['queued'] <= 1 && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CLICKUP_EXPORTS)} WHERE rule_id = %d AND created_at >= %s", $ruleId, gmdate('Y-m-d 00:00:00')) <= 2, '49. backfill w granicach dziennego limitu reguły (2)');
UbrT::ok(($cuRepo->exportFor($orgA, $hist, 'L1')['export_status'] ?? '') === 'success', '49. po potwierdzeniu historyczna firma wyeksportowana');

// 50. Rozłączenie: usuwa token, wyłącza reguły; dalszy eksport niemożliwy.
UbrT::eq(200, ubrt_rest($uA, 'POST', '/clickup/disconnect')['status'], '50. Disconnect');
UbrT::ok($cuRepo->connection($orgA, $uA) === null && ubrt_rest($uA, 'GET', '/clickup/status')['data']['state'] === 'not_connected', '50. token usunięty, stan Not Connected');
UbrT::ok((int) $cuRepo->rule($orgA, $ruleId)['enabled'] === 0, '50. reguły korzystające z połączenia wyłączone');
do_action('ubr/company/changed', $hist, 'pipeline_changed', $orgA);
UbrT::eq(0, (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::CLICKUP_JOBS)} WHERE organization_id = %d AND status = 'queued'", $orgA), '50. po rozłączeniu brak nowych zadań w kolejce');

remove_filter('pre_http_request', $cuMock, 5);
remove_filter('ubr/clickup/throttle', '__return_false');
