<?php
/**
 * Status zadania w ClickUp → status w Prospects (+ szkic sekwencji). ClickUp WYŁĄCZNIE na mockach HTTP.
 *
 * Dołączane z tests/run.php po discover-claim-p1043.php (korzysta z $orgF, $uF, $uF1, $fMk, $connF1, $db).
 */

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Services\ClickUpStatusSyncService;

if (! defined('ABSPATH')) {
	exit(1);
}

UbrT::group('ClickUp → Prospects: synchronizacja statusów i szkic sekwencji (mock)');

add_filter('ubr/clickup/throttle', '__return_false');
$GLOBALS['ubrt_cus'] = ['tasks' => [], 'calls' => []];
$cusMock = function ($pre, $args, $url) {
	if (! str_starts_with((string) $url, 'https://api.clickup.com/api/v2')) {
		return $pre;
	}

	$method = strtoupper($args['method'] ?? 'GET');
	$path = (string) parse_url(substr($url, strlen('https://api.clickup.com/api/v2')), PHP_URL_PATH);
	parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
	$GLOBALS['ubrt_cus']['calls'][] = $method . ' ' . $path;
	$json = fn ($code, $data) => ['headers' => [], 'body' => wp_json_encode($data), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

	if ($method === 'GET' && $path === '/list/LS1') {
		return $json(200, ['id' => 'LS1', 'statuses' => [['status' => 'to do', 'color' => '#ccc'], ['status' => 'Cold mail', 'color' => '#0af'], ['status' => 'won', 'color' => '#0f0']]]);
	}

	if ($method === 'GET' && $path === '/list/LS1/task') {
		$since = (int) ($q['date_updated_gt'] ?? 0);
		$tasks = array_values(array_filter($GLOBALS['ubrt_cus']['tasks'], fn ($t) => (int) $t['date_updated'] > $since));

		return $json(200, ['tasks' => $tasks, 'last_page' => true]);
	}

	if ($method !== 'GET') {
		return $json(500, ['err' => 'zapis w ClickUp niedozwolony w tym teście']);
	}

	return $json(404, ['err' => 'unmocked', 'ECODE' => 'MOCK_404']);
};
add_filter('pre_http_request', $cusMock, 2, 3);
$cusTask = function (string $id, string $status, int $ago = 0) {
	$GLOBALS['ubrt_cus']['tasks'][$id] = ['id' => $id, 'name' => 'Zadanie ' . $id, 'status' => ['status' => $status, 'type' => 'custom'], 'date_updated' => (string) ((int) floor(microtime(true) * 1000) - $ago)];
};
$cusSync = function () use ($orgF) {
	return app(ClickUpStatusSyncService::class)->sync($orgF);
};

// Połączenie synchronizacji: konto członka F1 z listą LS1; firma z zadaniem w tej liście i opiekunem F1.
app(\App\Ubr\Infrastructure\Repositories\ClickUpRepository::class)->updateConnection($connF1, ['destination' => ['team_id' => '901', 'team_name' => 'T', 'space_id' => 'S1', 'space_name' => 'S', 'folder_id' => '', 'folder_name' => '', 'list_id' => 'LS1', 'list_name' => 'Leads']]);
$cS = $fMk('cusync', 'Status Sync', 'https://cus-sync.ubrtest.pl/', ['general_email' => 'biuro@cus-sync.ubrtest.pl']);
$cS2 = $fMk('cusync2', 'Status Sync Bez Reguly', 'https://cus-sync2.ubrtest.pl/');
foreach ([$cS, $cS2] as $cid) {
	ubrt_rest($uF1, 'POST', '/prospects', ['company_id' => $cid]);
	$pid = (int) app(PipelineRepository::class)->prospectForCompany($orgF, $cid)['id'];
	ubrt_rest($uF, 'POST', '/prospects/' . $pid, ['owner_user_id' => $uF1]);
}
$now = Db::now();
$db->insert(Tables::CLICKUP_EXPORTS, ['organization_id' => $orgF, 'company_id' => $cS, 'user_id' => $uF1, 'connection_id' => $connF1, 'workspace_id' => '901', 'list_id' => 'LS1', 'task_id' => 'cus1', 'export_status' => 'success', 'created_at' => $now, 'updated_at' => $now]);
$db->insert(Tables::CLICKUP_EXPORTS, ['organization_id' => $orgF, 'company_id' => $cS2, 'user_id' => $uF1, 'connection_id' => $connF1, 'workspace_id' => '901', 'list_id' => 'LS1', 'task_id' => 'cus2', 'export_status' => 'success', 'created_at' => $now, 'updated_at' => $now]);

$camp = ubrt_rest($uF, 'POST', '/sequences/campaigns', ['name' => 'Cold mail ubrtest', 'scope' => 'shared', 'steps' => [['subject' => 'Dzień dobry', 'body' => 'Treść {{company_name}}', 'delay_days' => 0]]]);
$campId = (int) ($camp['data']['id'] ?? 0);
ubrt_rest($uF, 'POST', '/sequences/campaigns/' . $campId, ['status' => 'active']);

UbrT::eq(403, ubrt_rest($uF1, 'POST', '/clickup/status-sync', ['enabled' => true])['status'], 'CUS-1. pracownik nie zmienia ustawień synchronizacji (owner / admin)');
$st = ubrt_rest($uF1, 'GET', '/clickup/status-sync');
UbrT::ok($st['status'] === 200 && in_array('Cold mail', array_column($st['data']['clickup_statuses'], 'name'), true) && $st['data']['enabled'] === false, 'CUS-2. statusy listy pobierane z ClickUp, synchronizacja domyślnie wyłączona');

$orgSettings = (array) (app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class)->find($orgF)['settings'] ?? []);
$orgSettings[ClickUpStatusSyncService::SETTINGS_KEY] = ['enabled' => true, 'connection_id' => $connF1, 'rules' => [['clickup_status' => 'cold mail', 'pipeline_status' => 'mailing', 'campaign_id' => $campId]]];
app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class)->update($orgF, ['settings' => $orgSettings]);
$saved = ubrt_rest($uF, 'POST', '/clickup/status-sync', ['rules' => [['clickup_status' => '  Cold   Mail ', 'pipeline_status' => 'mailing', 'campaign_id' => $campId]]]);
UbrT::ok($saved['status'] === 200 && ($saved['data']['rules'][0]['clickup_status'] ?? '') === 'cold mail', 'CUS-3. reguła zapisana (status ClickUp znormalizowany: wielkość liter, spacje)');

// Pierwsze sprawdzenie = punkt startowy: wcześniejsze statusy nie są stosowane wstecz.
$cusTask('cus1', 'Cold mail', 5000);
$cusSync();
$p1 = app(PipelineRepository::class)->prospectForCompany($orgF, $cS);
UbrT::ok($p1['status'] !== 'mailing', 'CUS-4. pierwsze sprawdzenie ustawia punkt startowy — bez zmian wstecz');

// Zmiana statusu w ClickUp → status w Prospects + szkic sekwencji dla opiekuna.
$cusTask('cus1', 'Cold mail');
$cusTask('cus2', 'Cold mail');
$db->delete(Tables::CLICKUP_EXPORTS, ['organization_id' => $orgF, 'task_id' => 'cus2']); // zadanie bez powiązania z firmą → ignorowane
$r = $cusSync();
$p1 = app(PipelineRepository::class)->prospectForCompany($orgF, $cS);
$seq = $db->row("SELECT * FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE organization_id = %d AND company_id = %d", $orgF, $cS);
UbrT::ok($p1['status'] === 'mailing' && $r['applied'] === 1, 'CUS-5. status „Cold mail” w ClickUp → „Mailing” w Prospects');
UbrT::ok($seq && $seq['status'] === 'draft' && (int) $seq['owner_user_id'] === $uF1 && (int) $seq['campaign_id'] === $campId && $seq['recipient_email'] === 'biuro@cus-sync.ubrtest.pl', 'CUS-6. szkic sekwencji z kampanii dla opiekuna firmy (odbiorca: ogólny e-mail), bez uruchomienia wysyłki');
UbrT::ok(app(PipelineRepository::class)->prospectForCompany($orgF, $cS2)['status'] !== 'mailing', 'CUS-7. zadanie bez powiązania z firmą nie zmienia żadnego prospektu');
$ev = $db->row("SELECT * FROM {$db->table(Tables::PROSPECT_EVENTS)} WHERE organization_id = %d AND prospect_id = %d AND type = 'clickup_status' ORDER BY id DESC LIMIT 1", $orgF, (int) $p1['id']);
UbrT::ok($ev && $ev['new_value'] === 'cold mail', 'CUS-8. historia prospektu: zapis statusu z ClickUp');

// Ręczna zmiana w aplikacji nie jest nadpisywana przy kolejnej edycji zadania (status w ClickUp bez zmian).
ubrt_rest($uF1, 'POST', '/prospects/' . (int) $p1['id'], ['status' => 'contacted']);
$cusTask('cus1', 'Cold mail');
$cusSync();
$seqCount = (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE organization_id = %d AND company_id = %d", $orgF, $cS);
UbrT::ok(app(PipelineRepository::class)->prospectForCompany($orgF, $cS)['status'] === 'contacted' && $seqCount === 1, 'CUS-9. edycja zadania bez zmiany statusu: ręczny status zostaje, brak drugiego szkicu');

// Status bez reguły → tylko historia; ponowny powrót do „Cold mail” nie tworzy duplikatu szkicu.
$cusTask('cus1', 'to do');
$cusSync();
$cusTask('cus1', 'Cold mail');
$cusSync();
UbrT::ok(app(PipelineRepository::class)->prospectForCompany($orgF, $cS)['status'] === 'mailing' && (int) $db->value("SELECT COUNT(*) FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE organization_id = %d AND company_id = %d", $orgF, $cS) === 1, 'CUS-10. ponowna zmiana na „Cold mail” → znów Mailing, bez duplikatu szkicu');
UbrT::ok(! in_array(true, array_map(fn ($c) => ! str_starts_with($c, 'GET '), $GLOBALS['ubrt_cus']['calls']), true), 'CUS-11. synchronizacja tylko CZYTA z ClickUp (bez zmian zadań i statusów)');

$db->delete(Tables::CLICKUP_EXPORTS, ['organization_id' => $orgF, 'task_id' => 'cus1']);
$db->execute("DELETE FROM {$db->table(Tables::SEQ_MESSAGES)} WHERE organization_id = %d", $orgF);
$db->execute("DELETE FROM {$db->table(Tables::SEQ_SEQUENCES)} WHERE organization_id = %d", $orgF);
$db->execute("DELETE FROM {$db->table(Tables::SEQ_CAMPAIGN_STEPS)} WHERE campaign_id = %d", $campId);
$db->delete(Tables::SEQ_CAMPAIGNS, ['id' => $campId]);
delete_option(ClickUpStatusSyncService::STATE_OPTION . $orgF);
remove_filter('pre_http_request', $cusMock, 2);
remove_filter('ubr/clickup/throttle', '__return_false');
