<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Support\UbrException;
use App\Ubr\Infrastructure\Http\UrlGuard;

/**
 * Eksport firm do ClickUp — zlecenia (wykonanie zawsze w tle przez ClickUpRunner).
 *
 * Deduplikacja: rekord eksportu jest unikalny dla (organizacja, firma, lista ClickUp, wariant). Domyślnie firma
 * już wyeksportowana do tej listy = SKIPPED_DUPLICATE z linkiem do zadania; świadome opcje: „Aktualizuj zadanie”
 * albo „Eksportuj jako nowe” (kolejny wariant). Stan UNKNOWN (timeout po POST) blokuje ponowny eksport do czasu
 * rekoncyliacji przez użytkownika. Równoległe kliknięcia rozstrzyga unikalny klucz w bazie.
 */
class ClickUpExportService
{
	public const BULK_MAX = 20;

	public function __construct(
		private ClickUpRepository $repo,
		private CompanyRepository $companies,
		private PipelineRepository $pipeline,
		private ClickUpOAuthService $oauth,
		private ClickUpRunner $runner,
	) {
	}

	public static function dailyLimit(?int $organizationId = null): int
	{
		$limit = defined('UBR_CLICKUP_DAILY_NEW_TASKS') ? (int) UBR_CLICKUP_DAILY_NEW_TASKS : 100;

		return max(1, (int) apply_filters('ubr/clickup/daily_new_tasks', $limit, $organizationId));
	}

	/**
	 * @param list<int> $companyIds
	 * @param string $mode create | update | new
	 * @return array<string, mixed>
	 */
	public function request(TenantContext $ctx, array $companyIds, string $mode = 'create'): array
	{
		$this->assert($ctx, 'clickup.export');
		$companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));

		if ($companyIds === []) {
			throw UbrException::invalid('Nie wybrano firm.');
		}

		if (count($companyIds) > self::BULK_MAX) {
			throw UbrException::invalid(sprintf('Jednorazowo można wyeksportować maks. %d firm.', self::BULK_MAX));
		}

		if (! in_array($mode, ['create', 'update', 'new'], true)) {
			throw UbrException::invalid('Nieznany tryb eksportu.');
		}

		$connection = $this->repo->connection($ctx->organizationId, $ctx->userId);

		if (! $connection || $connection['status'] !== 'connected' || ! $this->oauth->token($connection)) {
			throw UbrException::invalid('Połącz swoje konto ClickUp w Settings → Integrations → ClickUp.');
		}

		$dest = (array) $connection['destination'];

		if (empty($dest['list_id'])) {
			throw UbrException::invalid('Wybierz listę ClickUp (miejsce eksportu) w Settings.');
		}

		$batch = count($companyIds) > 1 ? wp_generate_uuid4() : null;
		$queued = [];
		$skipped = [];
		$newCount = 0;
		$left = self::dailyLimit($ctx->organizationId) - $this->repo->countNewTasksSince($ctx->organizationId, gmdate('Y-m-d 00:00:00'));

		foreach ($companyIds as $companyId) {
			$company = $this->companies->find($companyId);

			if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
				if (count($companyIds) === 1) {
					throw UbrException::notFound('Nie znaleziono firmy.');
				}

				$skipped[] = ['company_id' => $companyId, 'reason' => 'not_found'];
				continue;
			}

			$prospect = $this->pipeline->prospectForCompany($ctx->organizationId, $companyId);

			if ($prospect && $prospect['archived_at']) {
				$skipped[] = ['company_id' => $companyId, 'name' => $company['name'], 'reason' => 'archived', 'message' => 'Firma zarchiwizowana w Prospects — nie eksportujemy.'];
				continue;
			}

			$existing = $this->repo->exportFor($ctx->organizationId, $companyId, (string) $dest['list_id']);
			$result = $this->plan($ctx, $connection, $company, $prospect, $existing, $mode, $batch, $newCount, $left);

			if (isset($result['job_id'])) {
				$queued[] = $result;
			} else {
				$skipped[] = $result;
			}
		}

		if ($queued) {
			$this->runner->kick();
		}

		$labels = ['already_exported' => 'już wyeksportowane', 'in_progress' => 'eksport w toku', 'unknown' => 'stan nieznany — wymaga sprawdzenia', 'not_exported' => 'brak wcześniejszego eksportu', 'archived' => 'zarchiwizowane', 'not_found' => 'firma niedostępna', 'limit' => 'dzienny limit nowych zadań'];
		$summary = [];

		foreach ($skipped as $s) {
			$summary[$s['reason']] = ($summary[$s['reason']] ?? 0) + 1;
		}

		return [
			'queued' => $queued,
			'skipped' => $skipped,
			'batch_id' => $batch,
			'message' => trim(sprintf('Zlecono operacji ClickUp: %d.', count($queued)) . ($summary ? ' Pominięto: ' . implode(', ', array_map(fn ($k, $n) => ($labels[$k] ?? $k) . ' (' . $n . ')', array_keys($summary), $summary)) . '.' : '')),
		];
	}

	/**
	 * @param array<string, mixed> $connection
	 * @param array<string, mixed> $company
	 * @param array<string, mixed>|null $prospect
	 * @param array<string, mixed>|null $existing
	 * @return array<string, mixed>
	 */
	private function plan(TenantContext $ctx, array $connection, array $company, ?array $prospect, ?array $existing, string $mode, ?string $batch, int &$newCount, int $left): array
	{
		$companyId = (int) $company['id'];
		$dest = (array) $connection['destination'];
		$base = ['company_id' => $companyId, 'name' => $company['name']];
		$history = fn (string $status, string $message, ?int $exportId = null) => $this->repo->createJob([
			'organization_id' => $ctx->organizationId, 'user_id' => $ctx->userId, 'connection_id' => (int) $connection['id'], 'export_id' => $exportId,
			'company_id' => $companyId, 'operation' => $mode === 'update' ? 'update' : 'create', 'trigger_type' => $batch ? 'bulk' : 'manual', 'batch_id' => $batch,
			'status' => $status, 'error_message' => $message, 'finished_at' => Db::now(),
		]);

		if ($existing && in_array($existing['export_status'], ['queued', 'running'], true)) {
			return $base + ['reason' => 'in_progress', 'message' => 'Eksport tej firmy do tej listy już trwa.', 'export_id' => (int) $existing['id']];
		}

		if ($mode === 'update') {
			if (! $existing || $existing['export_status'] !== 'success' || ! $existing['task_id']) {
				return $base + ['reason' => 'not_exported', 'message' => 'Firma nie ma wyeksportowanego zadania na tej liście.'];
			}

			if ($this->repo->hasActiveJob($ctx->organizationId, $companyId, 'update', (int) $existing['id'])) {
				return $base + ['reason' => 'in_progress', 'message' => 'Aktualizacja już trwa.'];
			}

			$jobId = $this->repo->createJob(['organization_id' => $ctx->organizationId, 'user_id' => $ctx->userId, 'connection_id' => (int) $connection['id'], 'export_id' => (int) $existing['id'], 'company_id' => $companyId, 'operation' => 'update', 'trigger_type' => $batch ? 'bulk' : 'manual', 'batch_id' => $batch]);

			return $base + ['job_id' => $jobId, 'export_id' => (int) $existing['id'], 'operation' => 'update'];
		}

		if ($existing && $existing['export_status'] === 'unknown' && $mode !== 'new') {
			return $base + ['reason' => 'unknown', 'message' => 'Poprzedni eksport ma stan nieznany (brak odpowiedzi ClickUp). Sprawdź w ClickUp i rozstrzygnij w karcie firmy.', 'export_id' => (int) $existing['id']];
		}

		if ($existing && $existing['export_status'] === 'success' && $mode === 'create') {
			$history('skipped_duplicate', 'Already exported — zadanie istnieje: ' . $existing['task_url'], (int) $existing['id']);

			return $base + ['reason' => 'already_exported', 'message' => 'Already exported', 'task_url' => $existing['task_url'], 'export_id' => (int) $existing['id']];
		}

		if ($newCount >= $left) {
			return $base + ['reason' => 'limit', 'message' => sprintf('Dzienny limit nowych zadań ClickUp (%d) wyczerpany.', self::dailyLimit($ctx->organizationId))];
		}

		$data = [
			'organization_id' => $ctx->organizationId, 'company_id' => $companyId, 'prospect_id' => $prospect ? (int) $prospect['id'] : null,
			'user_id' => $ctx->userId, 'connection_id' => (int) $connection['id'], 'workspace_id' => (string) $dest['team_id'], 'list_id' => (string) $dest['list_id'],
			'list_name' => mb_substr((string) ($dest['list_name'] ?? ''), 0, 255),
		];

		if ($existing && $existing['export_status'] === 'failed' && $mode === 'create') {
			// Poprzednia próba zakończyła się jednoznacznym błędem — ten sam rekord, nowa próba.
			$this->repo->updateExport((int) $existing['id'], ['export_status' => 'queued', 'last_error' => null, 'user_id' => $ctx->userId, 'connection_id' => (int) $connection['id']]);
			$exportId = (int) $existing['id'];
		} else {
			$exportId = $this->repo->reserveExport($data + ['variant' => $existing && $mode === 'new' ? (int) $existing['variant'] + 1 : 0]);

			if ($exportId === null) {
				return $base + ['reason' => 'in_progress', 'message' => 'Eksport tej firmy został już zlecony (równoległe kliknięcie).'];
			}
		}

		$newCount++;
		$jobId = $this->repo->createJob(['organization_id' => $ctx->organizationId, 'user_id' => $ctx->userId, 'connection_id' => (int) $connection['id'], 'export_id' => $exportId, 'company_id' => $companyId, 'operation' => 'create', 'trigger_type' => $batch ? 'bulk' : 'manual', 'batch_id' => $batch]);

		return $base + ['job_id' => $jobId, 'export_id' => $exportId, 'operation' => 'create'];
	}

	/**
	 * Rekoncyliacja stanu UNKNOWN: użytkownik sprawdził w ClickUp i wskazuje zadanie albo potwierdza, że nie powstało.
	 *
	 * @param array<string, mixed> $input action: mark_not_created | set_task (task_id lub URL zadania)
	 * @return array<string, mixed>
	 */
	public function reconcile(TenantContext $ctx, int $exportId, array $input): array
	{
		$this->assert($ctx, 'clickup.export');
		$export = $this->repo->export($ctx->organizationId, $exportId) ?? throw UbrException::notFound('Nie znaleziono eksportu.');

		if ($export['export_status'] !== 'unknown') {
			throw UbrException::invalid('Rekoncyliacja dotyczy tylko eksportów o stanie nieznanym.');
		}

		$action = sanitize_key((string) ($input['action'] ?? ''));

		if ($action === 'mark_not_created') {
			$this->repo->updateExport($exportId, ['export_status' => 'failed', 'last_error' => 'Użytkownik potwierdził, że zadanie nie powstało — można eksportować ponownie.']);
		} elseif ($action === 'set_task') {
			$raw = trim((string) ($input['task'] ?? ''));
			$taskId = preg_match('#/t/([A-Za-z0-9]+)#', $raw, $m) ? $m[1] : (preg_match('/^[A-Za-z0-9]{3,20}$/', $raw) ? $raw : '');

			if ($taskId === '') {
				throw UbrException::invalid('Podaj ID zadania albo link https://app.clickup.com/t/…');
			}

			$connection = $this->repo->connection($ctx->organizationId, $ctx->userId) ?? throw UbrException::invalid('Konto ClickUp nie jest połączone.');
			$token = $this->oauth->token($connection) ?? throw UbrException::invalid('Połączenie ClickUp wymaga ponownej autoryzacji.');
			$res = app(\App\Ubr\Infrastructure\ClickUp\ClickUpClient::class)->getTask($token, $taskId, $ctx->organizationId);

			if ($res['error']) {
				throw UbrException::invalid($res['error']->getMessage());
			}

			if ((string) ($res['data']['list']['id'] ?? '') !== (string) $export['list_id']) {
				throw UbrException::invalid('To zadanie jest na innej liście niż eksport.');
			}

			$this->repo->updateExport($exportId, ['export_status' => 'success', 'task_id' => $taskId, 'task_url' => mb_substr(UrlGuard::httpUrl($res['data']['url'] ?? null) ?? 'https://app.clickup.com/t/' . rawurlencode($taskId), 0, 255), 'exported_at' => Db::now(), 'last_error' => null]);
		} else {
			throw UbrException::invalid('Nieznana akcja.');
		}

		return $this->forCompany($ctx, (int) $export['company_id']);
	}

	/**
	 * Stan ClickUp dla firmy (karta firmy / sekcja Sprzedaż): połączenie, eksporty, historia operacji.
	 *
	 * @return array<string, mixed>
	 */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		if (! $ctx->can('pipeline.view')) {
			throw UbrException::forbidden();
		}

		if (! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$connection = $ctx->can('clickup.connect') ? $this->repo->connection($ctx->organizationId, $ctx->userId) : null;
		$listId = (string) ($connection['destination']['list_id'] ?? '');
		$current = $listId !== '' ? $this->repo->exportFor($ctx->organizationId, $companyId, $listId) : null;

		return [
			'configured' => ClickUpOAuthService::configuration()['configured'],
			'connection' => $connection ? ['status' => $connection['status'], 'list' => $connection['destination']['list_name'] ?? null, 'list_id' => $listId ?: null, 'username' => $connection['clickup_username']] : null,
			'current' => $current ? $this->presentExport($current) : null,
			'exports' => array_map(fn ($e) => $this->presentExport($e), $this->repo->exportsForCompany($ctx->organizationId, $companyId)),
			'history' => array_map(fn ($j) => $this->presentJob($j), $this->repo->jobsForCompany($ctx->organizationId, $companyId)),
			'can_export' => $ctx->can('clickup.export'),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function batch(TenantContext $ctx, string $batchId): array
	{
		$this->assert($ctx, 'clickup.export');
		$counts = $this->repo->batchCounts($ctx->organizationId, preg_replace('/[^a-f0-9-]/', '', $batchId) ?? '');
		$active = ($counts['queued'] ?? 0) + ($counts['running'] ?? 0);

		return ['counts' => $counts, 'done' => $active === 0, 'total' => array_sum($counts)];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent(TenantContext $ctx): array
	{
		$this->assert($ctx, 'clickup.export');

		return array_map(fn ($j) => $this->presentJob($j) + ['company' => ['id' => (int) $j['company_id'], 'name' => $j['company_name']]], $this->repo->recentJobs($ctx->organizationId));
	}

	/** Prompt 10.4.3 — automatyczne zadanie ClickUp po przejęciu firmy (UBR_CLICKUP_ON_CLAIM, ustawienie organizacji). */
	public static function claimEnabled(int $organizationId): bool
	{
		$org = app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class)->find($organizationId);
		$setting = $org['settings']['clickup_on_claim'] ?? null;
		// Domyślnie WYŁĄCZONE — zadania w ClickUp po przejęciu dopiero po włączeniu przez właściciela / admina organizacji
		// (Settings → ClickUp) albo stałą UBR_CLICKUP_ON_CLAIM. Wdrożenie nie zaczyna samo tworzyć zadań w ClickUp.
		$default = defined('UBR_CLICKUP_ON_CLAIM') && constant('UBR_CLICKUP_ON_CLAIM');

		return (bool) apply_filters('ubr/clickup/on_claim', $setting === null ? $default : (bool) $setting, $organizationId);
	}

	/**
	 * Połączenie do zadania po przejęciu: własne połączenie pracownika z wybraną listą, a gdy go brak — połączenie
	 * wskazane przez właściciela organizacji (`settings.clickup_claim_connection_id`).
	 *
	 * @return array<string, mixed>|null
	 */
	public function claimConnection(TenantContext $ctx): ?array
	{
		$ready = fn (?array $c) => $c && $c['status'] === 'connected' && ! empty($c['destination']['list_id']) && (int) $c['organization_id'] === $ctx->organizationId;
		$own = $this->repo->connection($ctx->organizationId, $ctx->userId);

		if ($ready($own)) {
			return $own;
		}

		$org = app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class)->find($ctx->organizationId);
		$fallbackId = (int) ($org['settings']['clickup_claim_connection_id'] ?? 0);
		$fallback = $fallbackId > 0 ? $this->repo->connectionById($fallbackId) : null;

		return $ready($fallback) ? $fallback : null;
	}

	/**
	 * Ustawienie organizacji: zadania ClickUp po „Przejmij firmę” (+ opcjonalnie połączenie dla pracowników bez własnego).
	 *
	 * @return array{enabled: bool, org_setting: ?bool, fallback: bool, fallback_is_mine: bool, can_manage: bool}
	 */
	public function claimSyncStatus(TenantContext $ctx): array
	{
		$org = app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class)->find($ctx->organizationId);
		$setting = $org['settings']['clickup_on_claim'] ?? null;
		$fallbackId = (int) ($org['settings']['clickup_claim_connection_id'] ?? 0);
		$own = $this->repo->connection($ctx->organizationId, $ctx->userId);

		return [
			'enabled' => self::claimEnabled($ctx->organizationId),
			'org_setting' => $setting === null ? null : (bool) $setting,
			'fallback' => $fallbackId > 0,
			'fallback_is_mine' => $fallbackId > 0 && $own && (int) $own['id'] === $fallbackId,
			'can_manage' => $ctx->can('organization.update'),
		];
	}

	/**
	 * @param array<string, mixed> $input enabled (bool), use_my_connection (bool — moje połączenie dla przejęć pracowników bez własnego)
	 * @return array<string, mixed>
	 */
	public function saveClaimSync(TenantContext $ctx, array $input): array
	{
		if (! $ctx->can('organization.update')) {
			throw \App\Ubr\Support\UbrException::forbidden();
		}

		$orgs = app(\App\Ubr\Infrastructure\Repositories\OrganizationRepository::class);
		$settings = (array) ($orgs->find($ctx->organizationId)['settings'] ?? []);

		if (array_key_exists('enabled', $input)) {
			$settings['clickup_on_claim'] = (bool) $input['enabled'];
		}

		if (array_key_exists('use_my_connection', $input)) {
			$own = $this->repo->connection($ctx->organizationId, $ctx->userId);

			if ($input['use_my_connection']) {
				if (! $own || $own['status'] !== 'connected' || empty($own['destination']['list_id'])) {
					throw \App\Ubr\Support\UbrException::invalid('Najpierw połącz własne konto ClickUp i wybierz listę.');
				}

				$settings['clickup_claim_connection_id'] = (int) $own['id'];
			} elseif ($own && (int) ($settings['clickup_claim_connection_id'] ?? 0) === (int) $own['id']) {
				unset($settings['clickup_claim_connection_id']);
			}
		}

		$orgs->update($ctx->organizationId, ['settings' => $settings]);

		return $this->claimSyncStatus($ctx);
	}

	/**
	 * Prompt 10.4.3 — ETAP 1 synchronizacji: zadanie ClickUp po przejęciu firmy (w tle). Nigdy nie rzuca wyjątku —
	 * brak konfiguracji lub błąd ClickUp nie może zablokować przejęcia. Istniejące powiązanie → aktualizacja tego zadania.
	 *
	 * @return array{status: string, message: string, job_id?: int}
	 */
	public function queueForClaim(TenantContext $ctx, int $companyId, ?int $prospectId = null): array
	{
		try {
			if (! ClickUpOAuthService::configuration()['configured']) {
				return ['status' => 'needs_config', 'message' => 'Integracja ClickUp nie jest skonfigurowana.'];
			}

			if (! self::claimEnabled($ctx->organizationId)) {
				return ['status' => 'disabled', 'message' => 'Automatyczne zadania ClickUp po przejęciu są wyłączone.'];
			}

			if (! $ctx->can('clickup.export')) {
				return ['status' => 'needs_config', 'message' => 'Brak uprawnienia do eksportu ClickUp.'];
			}

			$connection = $this->claimConnection($ctx);

			if (! $connection) {
				return ['status' => 'needs_config', 'message' => 'Połącz ClickUp i wybierz listę w Ustawieniach (albo poproś właściciela organizacji o wskazanie połączenia dla przejęć).'];
			}

			$dest = (array) $connection['destination'];
			$existing = $this->repo->exportFor($ctx->organizationId, $companyId, (string) $dest['list_id']);
			// Token należy do właściciela połączenia — zadanie działa w jego imieniu (własne połączenie pracownika albo wskazane przez właściciela organizacji).
			$job = fn (string $operation, int $exportId) => $this->repo->createJob(['organization_id' => $ctx->organizationId, 'user_id' => (int) $connection['user_id'], 'connection_id' => (int) $connection['id'], 'export_id' => $exportId, 'company_id' => $companyId, 'operation' => $operation, 'trigger_type' => 'claim']);

			if ($existing && $existing['export_status'] === 'success' && $existing['task_id']) {
				$this->markClaim((int) $existing['id'], $existing, $ctx->userId);

				if (! $this->repo->hasActiveJob($ctx->organizationId, $companyId, 'update', (int) $existing['id'])) {
					$id = $job('update', (int) $existing['id']);
					$this->runner->kick();

					return ['status' => 'linked', 'message' => 'Firma ma już zadanie ClickUp — zostanie zaktualizowane.', 'job_id' => $id];
				}

				return ['status' => 'linked', 'message' => 'Firma ma już zadanie ClickUp.'];
			}

			if ($existing && in_array($existing['export_status'], ['queued', 'running', 'unknown'], true)) {
				return ['status' => 'in_progress', 'message' => 'Synchronizacja z ClickUp już trwa.'];
			}

			if ($this->repo->countNewTasksSince($ctx->organizationId, gmdate('Y-m-d 00:00:00')) >= self::dailyLimit($ctx->organizationId)) {
				return ['status' => 'limit', 'message' => sprintf('Dzienny limit nowych zadań ClickUp (%d) wyczerpany — zadanie można wysłać jutro z karty firmy.', self::dailyLimit($ctx->organizationId))];
			}

			if ($existing && $existing['export_status'] === 'failed') {
				$this->repo->updateExport((int) $existing['id'], ['export_status' => 'queued', 'last_error' => null, 'user_id' => (int) $connection['user_id'], 'connection_id' => (int) $connection['id']]);
				$exportId = (int) $existing['id'];
			} else {
				$exportId = $this->repo->reserveExport([
					'organization_id' => $ctx->organizationId, 'company_id' => $companyId, 'prospect_id' => $prospectId, 'user_id' => (int) $connection['user_id'],
					'connection_id' => (int) $connection['id'], 'workspace_id' => (string) $dest['team_id'], 'list_id' => (string) $dest['list_id'],
					'list_name' => mb_substr((string) ($dest['list_name'] ?? ''), 0, 255), 'variant' => 0,
				]);

				if ($exportId === null) {
					return ['status' => 'in_progress', 'message' => 'Synchronizacja z ClickUp już trwa.'];
				}
			}

			$this->markClaim($exportId, $this->repo->export($ctx->organizationId, $exportId), $ctx->userId);
			$id = $job('create', $exportId);
			$this->runner->kick();

			return ['status' => 'queued', 'message' => 'Zadanie ClickUp zostanie utworzone w tle.', 'job_id' => $id];
		} catch (\Throwable $e) {
			error_log('[ubr] ClickUp claim export for company #' . $companyId . ' not queued: ' . $e->getMessage());

			return ['status' => 'error', 'message' => 'Nie udało się zlecić zadania ClickUp — spróbuj z karty firmy.'];
		}
	}

	/**
	 * Prompt 10.4.3 — ETAP 2: po zakończeniu analizy decydenta aktualizacja TEGO SAMEGO zadania (bez tworzenia nowego).
	 * Dotyczy zadań utworzonych lub powiązanych przy przejęciu firmy.
	 */
	public function queueClaimUpdate(int $companyId): int
	{
		$queued = 0;

		foreach ($this->repo->exportsForCompanyAllOrgs($companyId) as $export) {
			$synced = json_decode((string) ($export['synced_fields'] ?? ''), true) ?: [];

			if (empty($synced['__claim']) || $export['export_status'] !== 'success' || ! $export['task_id']) {
				continue;
			}

			if ($this->repo->hasActiveJob((int) $export['organization_id'], $companyId, 'update', (int) $export['id'])) {
				continue;
			}

			$this->repo->createJob(['organization_id' => (int) $export['organization_id'], 'user_id' => (int) $export['user_id'], 'connection_id' => (int) $export['connection_id'], 'export_id' => (int) $export['id'], 'company_id' => $companyId, 'operation' => 'update', 'trigger_type' => 'claim']);
			$queued++;
		}

		if ($queued) {
			$this->runner->kick();
		}

		return $queued;
	}

	/**
	 * Status synchronizacji ClickUp w karcie przejętej firmy.
	 *
	 * @return array{key: string, label: string, task_url: ?string, conflicts: list<array<string, mixed>>, message: ?string}
	 */
	public function claimStatus(TenantContext $ctx, int $companyId): array
	{
		$connection = $this->claimConnection($ctx);
		$out = fn (string $key, string $label, ?string $url = null, array $conflicts = [], ?string $message = null) => ['key' => $key, 'label' => $label, 'task_url' => $url, 'conflicts' => $conflicts, 'message' => $message];

		$enabled = self::claimEnabled($ctx->organizationId);
		$disabled = fn () => $out('disabled', 'Wyłączone', null, [], 'Zadania ClickUp po przejęciu są wyłączone — włącza je właściciel lub administrator (Ustawienia → ClickUp).');

		if (! ClickUpOAuthService::configuration()['configured'] || ! $connection) {
			return $enabled ? $out('needs_config', 'Wymaga konfiguracji', null, [], 'Połącz ClickUp i wybierz listę w Ustawieniach.') : $disabled();
		}

		$export = $this->repo->exportFor($ctx->organizationId, $companyId, (string) $connection['destination']['list_id']);

		if (! $export) {
			return $enabled ? $out('none', 'Oczekuje na synchronizację', null) : $disabled();
		}

		$url = UrlGuard::httpUrl($export['task_url'] ?? null);
		$conflicts = array_slice((array) (json_decode((string) ($export['conflicts'] ?? ''), true) ?: []), -5);
		$jobs = $this->repo->jobsForCompany($ctx->organizationId, $companyId, 10);
		$active = array_filter($jobs, fn ($j) => in_array($j['status'], ['queued', 'running'], true) && (int) $j['export_id'] === (int) $export['id']);

		if ($active) {
			$op = array_values($active)[0]['operation'];

			return $out('running', $op === 'reconcile' ? 'Sprawdzanie, czy zadanie powstało' : ($export['export_status'] === 'queued' ? 'Oczekuje na synchronizację' : 'Synchronizacja w toku'), $url, $conflicts);
		}

		return match ($export['export_status']) {
			'success' => (function () use ($out, $url, $conflicts, $jobs, $export) {
				$updated = array_filter($jobs, fn ($j) => $j['operation'] === 'update' && $j['status'] === 'success' && (int) $j['export_id'] === (int) $export['id']);

				return $out($updated ? 'updated' : 'added', $updated ? 'Zaktualizowano ClickUp' : 'Dodano do ClickUp', $url, $conflicts);
			})(),
			'queued' => $out('queued', 'Oczekuje na synchronizację', $url, $conflicts),
			'running' => $out('running', 'Synchronizacja w toku', $url, $conflicts),
			default => $out('error', 'Błąd synchronizacji', $url, $conflicts, $export['last_error'] ?: null),
		};
	}

	/** @param array<string, mixed>|null $export */
	private function markClaim(int $exportId, ?array $export, int $claimerId): void
	{
		$synced = json_decode((string) ($export['synced_fields'] ?? ''), true) ?: [];

		if (empty($synced['__claim']) || (int) ($synced['__claimer'] ?? 0) !== $claimerId) {
			$synced['__claim'] = true;
			$synced['__claimer'] = $claimerId;
			$this->repo->updateExport($exportId, ['synced_fields' => wp_json_encode($synced)]);
		}
	}

	public const STATUS_LABELS = [
		'queued' => 'W kolejce', 'running' => 'W toku', 'success' => 'Sukces', 'failed' => 'Błąd', 'unknown' => 'Stan nieznany',
		'skipped_duplicate' => 'Pominięto (duplikat)', 'cancelled' => 'Anulowano', 'skipped' => 'Pominięto',
	];

	/**
	 * @param array<string, mixed> $e
	 * @return array<string, mixed>
	 */
	private function presentExport(array $e): array
	{
		return [
			'id' => (int) $e['id'],
			'status' => $e['export_status'],
			'status_label' => self::STATUS_LABELS[$e['export_status']] ?? $e['export_status'],
			'list_id' => $e['list_id'],
			'list_name' => $e['list_name'],
			'variant' => (int) $e['variant'],
			'task_url' => UrlGuard::httpUrl($e['task_url'] ?? null),
			'exported_at' => $e['exported_at'] ? gmdate('c', strtotime($e['exported_at'] . ' UTC')) : null,
			'last_synced_at' => $e['last_synced_at'] ? gmdate('c', strtotime($e['last_synced_at'] . ' UTC')) : null,
			'error' => $e['last_error'],
			'by_rule' => $e['rule_id'] !== null,
		];
	}

	/**
	 * @param array<string, mixed> $j
	 * @return array<string, mixed>
	 */
	private function presentJob(array $j): array
	{
		return [
			'id' => (int) $j['id'],
			'operation' => $j['operation'],
			'operation_label' => ['create' => 'Eksport', 'update' => 'Aktualizacja zadania', 'evaluate' => 'Sprawdzenie reguły', 'reconcile' => 'Ustalenie wyniku eksportu'][$j['operation']] ?? $j['operation'],
			'status' => $j['status'],
			'status_label' => self::STATUS_LABELS[$j['status']] ?? $j['status'],
			'trigger' => $j['trigger_type'],
			'attempts' => (int) $j['attempts'],
			'user' => app(PipelineService::class)->userBrief($j['user_id'] ? (int) $j['user_id'] : null),
			'error' => $j['error_message'],
			'notes' => array_values((array) (($j['result']['notes'] ?? null) ?: [])),
			'at' => gmdate('c', strtotime(($j['finished_at'] ?: $j['created_at']) . ' UTC')),
		];
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}
}
