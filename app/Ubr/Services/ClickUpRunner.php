<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\ClickUp\ClickUpClient;
use App\Ubr\Infrastructure\ClickUp\ClickUpError;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Support\Redactor;
use Throwable;

/**
 * Kolejka operacji ClickUp (ClickUpExportJob / ClickUpUpdateJob / ClickUpRuleEvaluationJob) — worker loopback
 * (`ubr_job_worker`, zadanie 'clickup'), WP-Cron `ubr_clickup_tick`, healthcheck. Jedna operacja na krok, blokada
 * rekordu, limit prób (3) z Retry-After przy 429, bez automatycznego ponawiania niejednoznacznego POST (UNKNOWN).
 */
class ClickUpRunner
{
	public const CRON_HOOK = 'ubr_clickup_tick';

	public const MAX_ATTEMPTS = 3;

	public function __construct(
		private ClickUpRepository $repo,
		private ClickUpClient $client,
		private ClickUpOAuthService $oauth,
		private ClickUpTaskBuilder $builder,
		private PipelineRepository $pipeline,
		private AsyncWorker $worker,
	) {
	}

	/**
	 * @return array{processed: int, status: ?string, remaining_due: int}
	 */
	public function tick(bool $chain = true, int $max = 5): array
	{
		if (! add_option('ubr_clickup_lock', time() + 120, '', false)) {
			if ((int) get_option('ubr_clickup_lock') > time()) {
				return ['processed' => 0, 'status' => null, 'remaining_due' => $this->repo->countDue()];
			}

			update_option('ubr_clickup_lock', time() + 120, false);
		}

		$processed = 0;
		$status = null;

		try {
			while ($processed < $max && ($job = $this->repo->claim(90))) {
				$processed++;

				try {
					$status = $this->process($job);
				} catch (Throwable $e) {
					Redactor::log('ClickUp job #' . $job['id'] . ' failed: ' . $e->getMessage());
					$status = $this->fail($job, new ClickUpError(ClickUpError::INVALID, 'Nieoczekiwany błąd operacji.'));
				}
			}
		} finally {
			delete_option('ubr_clickup_lock');
		}

		$due = $this->repo->countDue();

		if ($chain && $due > 0) {
			$this->worker->dispatchJob('clickup', 0);
		}

		return ['processed' => $processed, 'status' => $status, 'remaining_due' => $due];
	}

	public function kick(): bool
	{
		if ($this->repo->countDue() === 0) {
			return false;
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 60, self::CRON_HOOK);
		}

		return $this->worker->dispatchJob('clickup', 0);
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function process(array $job): string
	{
		if ($job['operation'] === 'evaluate') {
			$r = app(ClickUpRuleService::class)->evaluate($job);
			$this->repo->updateJob((int) $job['id'], ['status' => $r['status'], 'error_message' => $r['message'], 'finished_at' => Db::now(), 'locked_until' => null]);

			return $r['status'];
		}

		$export = $job['export_id'] ? $this->repo->export((int) $job['organization_id'], (int) $job['export_id']) : null;

		if (! $export) {
			return $this->finish($job, 'cancelled', 'Rekord eksportu nie istnieje.');
		}

		// Połączenie musi należeć do użytkownika zadania w tej organizacji (bez użycia cudzego tokenu).
		$connection = $job['connection_id'] ? $this->repo->connectionById((int) $job['connection_id']) : null;

		if (! $connection || (int) $connection['organization_id'] !== (int) $job['organization_id'] || (int) $connection['user_id'] !== (int) $job['user_id']) {
			$this->repo->updateExport((int) $export['id'], ['export_status' => $job['operation'] === 'create' ? 'failed' : $export['export_status'], 'last_error' => 'Połączenie ClickUp nie istnieje.']);

			return $this->finish($job, 'failed', 'Połączenie ClickUp nie istnieje (rozłączone).', 'auth');
		}

		$token = $this->oauth->token($connection);

		if (! $token) {
			$this->repo->updateExport((int) $export['id'], ['export_status' => $job['operation'] === 'create' ? 'failed' : $export['export_status'], 'last_error' => 'Połączenie wymaga ponownej autoryzacji.']);

			return $this->finish($job, 'failed', 'Połączenie ClickUp wymaga ponownej autoryzacji.', 'auth');
		}

		$ctx = $this->context($job);

		if (! $ctx) {
			return $this->finish($job, 'cancelled', 'Użytkownik nie należy już do organizacji.');
		}

		return $job['operation'] === 'update'
			? $this->update($job, $export, $connection, $token, $ctx)
			: $this->create($job, $export, $connection, $token, $ctx);
	}

	/**
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $export
	 * @param array<string, mixed> $connection
	 */
	private function create(array $job, array $export, array $connection, string $token, TenantContext $ctx): string
	{
		if ($export['export_status'] === 'success' && $export['task_id']) {
			return $this->finish($job, 'skipped_duplicate', 'Already exported — zadanie istnieje.');
		}

		if ($export['export_status'] === 'unknown') {
			return $this->finish($job, 'cancelled', 'Eksport ma stan nieznany — wymaga rekoncyliacji.');
		}

		$this->repo->updateExport((int) $export['id'], ['export_status' => 'running']);
		$content = $this->builder->build($ctx, (int) $export['company_id'], (array) $connection['settings']);
		$res = $this->client->createTask($token, (string) $export['list_id'], ['name' => $content['name'], 'markdown_content' => $content['markdown']], (int) $job['organization_id']);

		if ($res['error']) {
			return $this->handleError($job, $export, $connection, $res['error']);
		}

		$taskId = (string) ($res['data']['id'] ?? '');

		if ($taskId === '') {
			// 2xx bez identyfikatora — nie wiemy, czy zadanie powstało: bezpiecznie UNKNOWN.
			return $this->handleError($job, $export, $connection, new ClickUpError(ClickUpError::AMBIGUOUS, 'ClickUp nie zwrócił identyfikatora zadania.'));
		}

		$url = mb_substr((string) ($res['data']['url'] ?? 'https://app.clickup.com/t/' . $taskId), 0, 255);
		$this->repo->updateExport((int) $export['id'], ['export_status' => 'success', 'task_id' => $taskId, 'task_url' => $url, 'exported_at' => Db::now(), 'last_synced_at' => Db::now(), 'managed_hash' => $content['hash'], 'last_error' => null]);
		$notes = $this->setFields($token, $taskId, $content['fields'], (int) $job['organization_id']);

		if ($export['prospect_id']) {
			$this->pipeline->event((int) $job['organization_id'], (int) $export['prospect_id'], (int) $job['user_id'], 'clickup_export', null, $url);
		}

		if ($job['rule_id']) {
			$this->repo->incrementRule((int) $job['rule_id'], 'exported_count');
		}

		app(PipelineService::class)->forgetStats((int) $job['organization_id']);
		$this->repo->updateConnection((int) $connection['id'], ['last_error' => null]);

		return $this->finish($job, 'success', null, null, ['task_url' => $url, 'notes' => $notes]);
	}

	/**
	 * Aktualizacja: tylko blok opisu zarządzany przez integrację (jeśli nie zmieniony ręcznie) + zmapowane pola.
	 *
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $export
	 * @param array<string, mixed> $connection
	 */
	private function update(array $job, array $export, array $connection, string $token, TenantContext $ctx): string
	{
		$task = $this->client->getTask($token, (string) $export['task_id'], (int) $job['organization_id']);

		if ($task['error']) {
			if ($task['error']->kind === ClickUpError::NOT_FOUND) {
				$this->repo->updateExport((int) $export['id'], ['last_error' => 'Zadanie nie istnieje w ClickUp (usunięte lub brak dostępu).']);
			}

			return $this->handleError($job, $export, $connection, $task['error'], false);
		}

		$content = $this->builder->build($ctx, (int) $export['company_id'], (array) $connection['settings']);
		$current = (string) ($task['data']['markdown_description'] ?? $task['data']['description'] ?? '');
		$replaced = ClickUpTaskBuilder::replaceBlock($current, $content['block'], $export['managed_hash']);
		$notes = [];
		$newHash = $export['managed_hash'];

		if ($replaced === null) {
			$notes[] = str_contains($current, ClickUpTaskBuilder::START)
				? 'Sekcja Ugly but Rich w opisie została zmieniona ręcznie w ClickUp — opisu nie nadpisano.'
				: 'W opisie nie ma sekcji Ugly but Rich (usunięta lub zmieniona) — opisu nie nadpisano.';
		} else {
			$put = $this->client->updateTask($token, (string) $export['task_id'], ['markdown_content' => $replaced], (int) $job['organization_id']);

			if ($put['error']) {
				return $this->handleError($job, $export, $connection, $put['error'], false);
			}

			$newHash = $content['hash'];
			$notes[] = 'Zaktualizowano sekcję Ugly but Rich w opisie (reszta opisu, komentarze, checklisty, załączniki i status bez zmian).';
		}

		$notes = array_merge($notes, $this->setFields($token, (string) $export['task_id'], $content['fields'], (int) $job['organization_id']));
		$this->repo->updateExport((int) $export['id'], ['last_synced_at' => Db::now(), 'managed_hash' => $newHash, 'last_error' => null]);

		return $this->finish($job, 'success', null, null, ['notes' => $notes]);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return list<string>
	 */
	private function setFields(string $token, string $taskId, array $fields, int $organizationId): array
	{
		$notes = [];

		foreach ($fields as $fieldId => $value) {
			$r = $this->client->setField($token, $taskId, (string) $fieldId, $value, $organizationId);

			if ($r['error']) {
				$notes[] = 'Pole ' . $fieldId . ': ' . $r['error']->getMessage();
			}
		}

		if ($fields && ! $notes) {
			$notes[] = 'Ustawiono zmapowane Custom Fields: ' . count($fields) . '.';
		}

		return $notes;
	}

	/**
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $export
	 * @param array<string, mixed> $connection
	 */
	private function handleError(array $job, array $export, array $connection, ClickUpError $e, bool $isCreate = true): string
	{
		if ($e->kind === ClickUpError::AMBIGUOUS && $isCreate) {
			// Nie ponawiamy automatycznie — mogłoby powstać drugie zadanie.
			$this->repo->updateExport((int) $export['id'], ['export_status' => 'unknown', 'last_error' => $e->getMessage()]);

			return $this->finish($job, 'unknown', $e->getMessage() . ' Sprawdź listę w ClickUp i rozstrzygnij w karcie firmy.', $e->kind, [], $e->httpStatus);
		}

		if ($e->needsReconnect()) {
			$this->oauth->markReconnect((int) $connection['id'], $e->getMessage());
		}

		if ($e->temporary() && (int) $job['attempts'] < self::MAX_ATTEMPTS) {
			$delay = $e->kind === ClickUpError::RATE_LIMITED ? $e->retryAfter : 60 * (2 ** max(0, (int) $job['attempts'] - 1));

			if ($isCreate) {
				$this->repo->updateExport((int) $export['id'], ['export_status' => 'queued', 'last_error' => $e->getMessage()]);
			}

			$this->repo->updateJob((int) $job['id'], ['status' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + max(5, $delay)), 'locked_until' => null, 'error_kind' => $e->kind, 'error_message' => $e->getMessage() . ' Ponowienie automatyczne.', 'http_status' => $e->httpStatus]);

			return 'queued';
		}

		if ($isCreate) {
			$this->repo->updateExport((int) $export['id'], ['export_status' => 'failed', 'last_error' => $e->getMessage()]);
		}

		if ($job['rule_id']) {
			$this->repo->incrementRule((int) $job['rule_id'], 'error_count');
		}

		return $this->finish($job, 'failed', $e->getMessage(), $e->kind, [], $e->httpStatus);
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private function fail(array $job, ClickUpError $e): string
	{
		if ($job['export_id'] && $job['operation'] === 'create') {
			$export = $this->repo->export((int) $job['organization_id'], (int) $job['export_id']);

			// Wyjątek po wysłaniu POST mógł nastąpić po utworzeniu zadania — przy statusie 'running' bezpieczniej UNKNOWN.
			if ($export && $export['export_status'] === 'running') {
				$this->repo->updateExport((int) $export['id'], ['export_status' => 'unknown', 'last_error' => 'Nieoczekiwany błąd w trakcie eksportu — sprawdź listę w ClickUp.']);

				return $this->finish($job, 'unknown', 'Nieoczekiwany błąd w trakcie eksportu — sprawdź listę w ClickUp.', 'ambiguous');
			}
		}

		return $this->finish($job, 'failed', $e->getMessage(), $e->kind);
	}

	/**
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $result
	 */
	private function finish(array $job, string $status, ?string $message, ?string $kind = null, array $result = [], ?int $http = null): string
	{
		$this->repo->updateJob((int) $job['id'], ['status' => $status, 'error_message' => $message ? mb_substr(Redactor::redact($message), 0, 500) : null, 'error_kind' => $kind, 'http_status' => $http, 'result' => $result ?: null, 'finished_at' => Db::now(), 'locked_until' => null]);

		return $status;
	}

	/**
	 * Kontekst organizacji dla zadania w tle (rola z bazy — użytkownik musi nadal być członkiem).
	 *
	 * @param array<string, mixed> $job
	 */
	private function context(array $job): ?TenantContext
	{
		$userId = (int) $job['user_id'];
		$orgId = (int) $job['organization_id'];
		$role = app(MemberRepository::class)->role($orgId, $userId);

		if (! $role) {
			return null;
		}

		$org = app(OrganizationRepository::class)->find($orgId) ?? ['id' => $orgId];

		return new TenantContext($userId, $orgId, $role, $org);
	}
}
