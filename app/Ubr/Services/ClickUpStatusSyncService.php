<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\ClickUp\ClickUpClient;
use App\Ubr\Infrastructure\ClickUp\ClickUpError;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Services\Sequences\CampaignService;
use App\Ubr\Services\Sequences\SequenceService;
use App\Ubr\Support\Lock;
use App\Ubr\Support\Redactor;
use App\Ubr\Support\UbrException;
use Throwable;

/**
 * Status zadania w ClickUp → status w Pipeline (kierunek ClickUp → Ugly but Rich, bez pętli: aplikacja nigdy nie
 * zmienia statusu zadania w ClickUp).
 *
 * - Odczyt w tle (co ~5 min): zadania list, do których organizacja eksportuje firmy, zmienione od ostatniego sprawdzenia
 *   (`date_updated_gt`). Zadanie ↔ firma wyłącznie przez zapisane powiązanie eksportu (`task_id`), nigdy po nazwie.
 * - Ostatni widziany status zadania = zdarzenie `clickup_status` w historii prospektu — reguła działa tylko przy
 *   ZMIANIE statusu w ClickUp, więc ręczna zmiana w aplikacji nie jest nadpisywana przy każdej edycji zadania.
 * - Reguła: status ClickUp → status pipeline + opcjonalnie SZKIC sekwencji z kampanii (nigdy uruchomienie wysyłki).
 * - Pierwsze uruchomienie ustawia punkt startowy (bez zmian wstecz).
 */
class ClickUpStatusSyncService
{
	public const SETTINGS_KEY = 'clickup_status_sync';

	public const STATE_OPTION = 'ubr_cu_status_sync_';

	public const INTERVAL = 300;

	private const MAX_PAGES = 5;

	public function __construct(
		private Db $db,
		private ClickUpRepository $repo,
		private ClickUpClient $client,
		private ClickUpOAuthService $oauth,
		private OrganizationRepository $orgs,
		private PipelineRepository $pipeline,
		private AsyncWorker $worker,
	) {
	}

	/* ------------------------------------------------------------------ konfiguracja (Settings) */

	public function status(TenantContext $ctx): array
	{
		$cfg = $this->config($ctx->organizationId);
		$connection = $cfg['connection_id'] ? $this->repo->connectionById($cfg['connection_id']) : null;
		$own = $this->repo->connection($ctx->organizationId, $ctx->userId);
		$state = $this->state($ctx->organizationId);
		$statuses = [];
		$statusError = null;

		// Statusy listy docelowej (do wyboru w regułach) — odczyt połączeniem synchronizacji albo własnym.
		$source = $connection && $connection['status'] === 'connected' ? $connection : ($own && $own['status'] === 'connected' ? $own : null);
		$listId = (string) ($source['destination']['list_id'] ?? '');

		if ($source && $listId !== '' && ($token = $this->oauth->token($source))) {
			$r = $this->client->getList($token, $listId, $ctx->organizationId);

			if ($r['error']) {
				$statusError = $r['error']->getMessage();
			} else {
				foreach ((array) ($r['data']['statuses'] ?? []) as $st) {
					$statuses[] = ['name' => (string) ($st['status'] ?? ''), 'color' => (string) ($st['color'] ?? '')];
				}
			}
		}

		return [
			'enabled' => $cfg['enabled'],
			'rules' => $cfg['rules'],
			'connection' => $connection ? ['id' => (int) $connection['id'], 'user' => get_userdata((int) $connection['user_id'])?->display_name, 'status' => $connection['status'], 'is_mine' => $own && (int) $own['id'] === (int) $connection['id']] : null,
			'can_use_mine' => (bool) ($own && $own['status'] === 'connected'),
			'can_manage' => $ctx->can('organization.update'),
			'clickup_statuses' => $statuses,
			'clickup_statuses_error' => $statusError,
			'pipeline_statuses' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v['label']], array_keys(PipelineCatalog::STATUSES), PipelineCatalog::STATUSES),
			'campaigns' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name']], array_values(array_filter(app(CampaignService::class)->list($ctx, ['status' => 'active']), fn ($c) => ($c['status'] ?? '') === 'active'))),
			'last_run_at' => $state['last_run_at'] ? gmdate('c', (int) $state['last_run_at']) : null,
			'last_error' => $state['last_error'],
			'applied' => (int) $state['applied'],
			'drafts' => (int) $state['drafts'],
		];
	}

	/** @param array<string, mixed> $input enabled, use_my_connection, rules[{clickup_status, pipeline_status, campaign_id}] */
	public function save(TenantContext $ctx, array $input): array
	{
		if (! $ctx->can('organization.update')) {
			throw UbrException::forbidden('Synchronizację statusów ustawia właściciel lub administrator organizacji.');
		}

		$cfg = $this->config($ctx->organizationId);

		if (array_key_exists('use_my_connection', $input) && $input['use_my_connection']) {
			$own = $this->repo->connection($ctx->organizationId, $ctx->userId);

			if (! $own || $own['status'] !== 'connected') {
				throw UbrException::invalid('Najpierw połącz własne konto ClickUp.');
			}

			$cfg['connection_id'] = (int) $own['id'];
		}

		if (isset($input['rules']) && is_array($input['rules'])) {
			$rules = [];

			foreach (array_slice($input['rules'], 0, 20) as $r) {
				$status = self::norm((string) ($r['clickup_status'] ?? ''));
				$pipeline = sanitize_key((string) ($r['pipeline_status'] ?? ''));

				if ($status === '') {
					continue;
				}

				if ($pipeline !== '' && ! PipelineCatalog::isStatus($pipeline)) {
					throw UbrException::invalid('Nieznany status Pipeline w regule „' . $status . '”.');
				}

				$campaignId = (int) ($r['campaign_id'] ?? 0);

				if ($campaignId > 0) {
					$campaign = app(CampaignService::class)->visible($ctx, $campaignId);

					if (($campaign['status'] ?? '') !== 'active') {
						throw UbrException::invalid('Kampania w regule „' . $status . '” nie jest aktywna.');
					}
				}

				$rules[$status] = ['clickup_status' => $status, 'pipeline_status' => $pipeline ?: null, 'campaign_id' => $campaignId ?: null];
			}

			$cfg['rules'] = array_values($rules);
		}

		if (array_key_exists('enabled', $input)) {
			$cfg['enabled'] = (bool) $input['enabled'];

			if ($cfg['enabled'] && ! $cfg['connection_id']) {
				throw UbrException::invalid('Wybierz połączenie ClickUp („Używaj mojego połączenia”).');
			}
		}

		$settings = (array) ($this->orgs->find($ctx->organizationId)['settings'] ?? []);
		$settings[self::SETTINGS_KEY] = $cfg;
		$this->orgs->update($ctx->organizationId, ['settings' => $settings]);

		return $this->status($ctx);
	}

	/** „Sprawdź teraz” — zlecenie w tle (żądanie użytkownika niczego nie pobiera z ClickUp poza statusami listy). */
	public function runSoon(TenantContext $ctx): array
	{
		if (! $ctx->can('organization.update')) {
			throw UbrException::forbidden();
		}

		$state = $this->state($ctx->organizationId);
		$state['due_now'] = true;
		update_option(self::STATE_OPTION . $ctx->organizationId, $state, false);
		$this->worker->dispatchJob('clickup_status', 0);

		return ['queued' => true, 'message' => 'Sprawdzanie statusów ClickUp zlecone — wynik pojawi się za chwilę.'];
	}

	/* ------------------------------------------------------------------ worker */

	/** Healthcheck: zlecenie, gdy któraś organizacja ma zaległe sprawdzenie. */
	public function kick(): bool
	{
		foreach ($this->enabledOrganizations() as $orgId) {
			if ($this->isDue($orgId)) {
				return $this->worker->dispatchJob('clickup_status', 0);
			}
		}

		return false;
	}

	/** @return array{processed: int, applied: int, drafts: int} */
	public function tick(): array
	{
		$out = ['processed' => 0, 'applied' => 0, 'drafts' => 0];

		if (! Lock::acquire('ubr_cu_status_sync')) {
			return $out;
		}

		try {
			foreach ($this->enabledOrganizations() as $orgId) {
				if (! $this->isDue($orgId)) {
					continue;
				}

				$r = $this->sync($orgId);
				$out['processed'] += $r['processed'];
				$out['applied'] += $r['applied'];
				$out['drafts'] += $r['drafts'];
			}
		} finally {
			Lock::release('ubr_cu_status_sync');
		}

		return $out;
	}

	/** @return array{processed: int, applied: int, drafts: int} */
	public function sync(int $orgId): array
	{
		$out = ['processed' => 0, 'applied' => 0, 'drafts' => 0];
		$cfg = $this->config($orgId);
		$state = $this->state($orgId);
		$state['last_run_at'] = time();
		$state['due_now'] = false;
		$state['last_error'] = null;
		$connection = $cfg['connection_id'] ? $this->repo->connectionById($cfg['connection_id']) : null;
		$token = $connection && (int) $connection['organization_id'] === $orgId && $connection['status'] === 'connected' ? $this->oauth->token($connection) : null;
		$ctx = $connection ? $this->context($orgId, (int) $connection['user_id']) : null;

		if (! $cfg['enabled'] || ! $token || ! $ctx) {
			$state['last_error'] = 'Połączenie ClickUp synchronizacji jest niedostępne (rozłączone lub wymaga ponownej autoryzacji).';
			$this->saveState($orgId, $state);

			return $out;
		}

		$rules = [];

		foreach ($cfg['rules'] as $r) {
			$rules[$r['clickup_status']] = $r;
		}

		foreach ($this->repo->exportLists($orgId) as $listId) {
			$cursor = (int) ($state['cursors'][$listId] ?? 0);

			// Pierwsze sprawdzenie listy = punkt startowy (bez zmian wstecz).
			if ($cursor === 0) {
				$state['cursors'][$listId] = (int) floor(microtime(true) * 1000);
				continue;
			}

			$max = $cursor;

			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$r = $this->client->listTasks($token, $listId, ['date_updated_gt' => $cursor - 60000, 'order_by' => 'updated', 'reverse' => 'false', 'include_markdown_description' => 'false', 'page' => $page], $orgId);

				if ($r['error']) {
					$this->handleError($connection, $r['error'], $state);

					// Lista usunięta / bez dostępu → pomijamy tylko ją; token lub limit → koniec tego przebiegu.
					if (in_array($r['error']->kind, [ClickUpError::NOT_FOUND, ClickUpError::FORBIDDEN], true)) {
						continue 2;
					}

					break 2;
				}

				$tasks = (array) ($r['data']['tasks'] ?? []);

				foreach ($tasks as $task) {
					$max = max($max, (int) ($task['date_updated'] ?? 0));
					$out['processed']++;

					try {
						$res = $this->applyTask($ctx, $orgId, $task, $rules);
						$out['applied'] += $res['applied'];
						$out['drafts'] += $res['draft'];
					} catch (Throwable $e) {
						Redactor::log('ClickUp status sync task failed: ' . $e->getMessage());
						$state['last_error'] = 'Nie udało się przetworzyć części zadań: ' . mb_substr($e->getMessage(), 0, 200);
					}
				}

				if (count($tasks) < 100 || ! empty($r['data']['last_page'])) {
					break;
				}
			}

			$state['cursors'][$listId] = $max;
		}

		$state['applied'] = (int) $state['applied'] + $out['applied'];
		$state['drafts'] = (int) $state['drafts'] + $out['drafts'];
		$this->saveState($orgId, $state);

		return $out;
	}

	/**
	 * @param array<string, mixed> $task
	 * @param array<string, array{clickup_status: string, pipeline_status: ?string, campaign_id: ?int}> $rules
	 * @return array{applied: int, draft: int}
	 */
	public function applyTask(TenantContext $ctx, int $orgId, array $task, array $rules): array
	{
		$res = ['applied' => 0, 'draft' => 0];
		$taskId = (string) ($task['id'] ?? '');
		$status = self::norm((string) ($task['status']['status'] ?? ''));
		$export = $taskId !== '' ? $this->repo->exportByTask($orgId, $taskId) : null;

		if (! $export || $status === '') {
			return $res;
		}

		$companyId = (int) $export['company_id'];
		$prospect = $this->pipeline->prospectForCompany($orgId, $companyId);

		if (! $prospect || $prospect['archived_at']) {
			return $res;
		}

		$prospectId = (int) $prospect['id'];
		$last = $this->db->value("SELECT new_value FROM {$this->db->table(Tables::PROSPECT_EVENTS)} WHERE organization_id = %d AND prospect_id = %d AND type = 'clickup_status' ORDER BY id DESC LIMIT 1", $orgId, $prospectId);

		if ($last === $status) {
			return $res; // zadanie zmienione, ale status ten sam — nic nie robimy
		}

		$this->pipeline->event($orgId, $prospectId, null, 'clickup_status', $last !== null ? (string) $last : null, $status, ['task_id' => $taskId]);
		$rule = $rules[$status] ?? null;

		if (! $rule) {
			return $res;
		}

		if ($rule['pipeline_status'] && $rule['pipeline_status'] !== $prospect['status']) {
			app(PipelineService::class)->update($ctx, $prospectId, ['status' => $rule['pipeline_status']]);
			$res['applied'] = 1;
		}

		if ($rule['campaign_id'] && ! (int) $prospect['do_not_contact']) {
			$res['draft'] = $this->draft($orgId, $companyId, $prospect, (int) $rule['campaign_id'], $ctx) ? 1 : 0;
		}

		return $res;
	}

	/**
	 * Szkic sekwencji z kampanii dla opiekuna firmy — tylko gdy firma nie ma szkicu ani trwającej sekwencji.
	 * Odbiorca: pierwszy decydent z e-mailem bez blokad, inaczej ogólny e-mail firmy; brak = szkic bez odbiorcy.
	 */
	private function draft(int $orgId, int $companyId, array $prospect, int $campaignId, TenantContext $fallback): bool
	{
		$existing = (int) $this->db->value("SELECT COUNT(*) FROM {$this->db->table(Tables::SEQ_SEQUENCES)} WHERE organization_id = %d AND company_id = %d AND status IN ('draft','scheduled','active','paused','error')", $orgId, $companyId);

		if ($existing) {
			return false;
		}

		$ownerCtx = $prospect['owner_user_id'] ? $this->context($orgId, (int) $prospect['owner_user_id']) : null;
		$ctx = $ownerCtx ?? $fallback;
		$sequences = app(SequenceService::class);
		$recipient = null;

		foreach ($sequences->recipients($ctx, $companyId)['options'] as $o) {
			if (! $o['blockers'] && ! $o['conflict']) {
				$recipient = ['source' => $o['source'], 'email' => $o['email'], 'contact_id' => $o['contact_id']];

				if ($o['source'] === 'contact') {
					break;
				}
			}
		}

		$sequences->create($ctx, $companyId, ['campaign_id' => $campaignId] + ($recipient ? ['recipient' => $recipient] : []));

		return true;
	}

	/* ------------------------------------------------------------------ pomocnicze */

	public static function norm(string $status): string
	{
		return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $status) ?? ''));
	}

	/** @return array{enabled: bool, connection_id: int, rules: list<array{clickup_status: string, pipeline_status: ?string, campaign_id: ?int}>} */
	public function config(int $orgId): array
	{
		$raw = (array) (($this->orgs->find($orgId)['settings'] ?? [])[self::SETTINGS_KEY] ?? []);

		return ['enabled' => ! empty($raw['enabled']), 'connection_id' => (int) ($raw['connection_id'] ?? 0), 'rules' => array_values((array) ($raw['rules'] ?? []))];
	}

	private function state(int $orgId): array
	{
		return (array) get_option(self::STATE_OPTION . $orgId, []) + ['cursors' => [], 'last_run_at' => null, 'last_error' => null, 'applied' => 0, 'drafts' => 0, 'due_now' => false];
	}

	private function saveState(int $orgId, array $state): void
	{
		update_option(self::STATE_OPTION . $orgId, $state, false);
	}

	private function isDue(int $orgId): bool
	{
		$s = $this->state($orgId);

		return ! empty($s['due_now']) || ! $s['last_run_at'] || (int) $s['last_run_at'] <= time() - self::INTERVAL;
	}

	/** @return list<int> */
	private function enabledOrganizations(): array
	{
		$ids = $this->db->column("SELECT id FROM {$this->db->table(Tables::ORGANIZATIONS)} WHERE settings LIKE %s", '%' . $this->db->wpdb()->esc_like('"' . self::SETTINGS_KEY . '"') . '%');

		return array_values(array_filter(array_map('intval', $ids), fn ($id) => $this->config($id)['enabled']));
	}

	private function context(int $orgId, int $userId): ?TenantContext
	{
		$role = app(MemberRepository::class)->role($orgId, $userId);

		return $role ? new TenantContext($userId, $orgId, $role, $this->orgs->find($orgId) ?? ['id' => $orgId]) : null;
	}

	private function handleError(array $connection, ClickUpError $e, array &$state): void
	{
		if ($e->needsReconnect()) {
			$this->oauth->markReconnect((int) $connection['id'], $e->getMessage());
		}

		$state['last_error'] = $e->getMessage();
	}
}
