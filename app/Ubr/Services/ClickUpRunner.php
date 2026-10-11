<?php

namespace App\Ubr\Services;

use App\Ubr\Support\Lock;
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
use App\Ubr\Infrastructure\Http\UrlGuard;

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
		// Jeden worker ClickUp naraz (throttling API): blokada MySQL, atomowa i zwalniana po śmierci procesu.
		if (! Lock::acquire('ubr_clickup_lock')) {
			return ['processed' => 0, 'status' => null, 'remaining_due' => $this->repo->countDue()];
		}

		$processed = 0;
		$status = null;

		try {
			// Zadania przerwane twardo po wyczerpaniu prób: create z eksportem „running” → UNKNOWN (bez duplikatu).
			foreach ($this->repo->failExhausted(self::MAX_ATTEMPTS + 1) as $dead) {
				$this->fail($dead, new ClickUpError(ClickUpError::NETWORK, 'Zadanie przerwane — proces workera został zakończony po kilku próbach.'));
			}

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
			Lock::release('ubr_clickup_lock');
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

		return match ($job['operation']) {
			'update' => $this->update($job, $export, $connection, $token, $ctx),
			'reconcile' => $this->reconcile($job, $export, $connection, $token, $ctx),
			default => $this->create($job, $export, $connection, $token, $ctx),
		};
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

		// Eksport wciąż „running” na początku próby = poprzedni worker przerwano (timeout, restart PHP) po wysłaniu
		// lub w trakcie wysyłania POST. Zadanie mogło powstać — ponowny POST tworzyłby duplikat. Stan UNKNOWN.
		if ($export['export_status'] === 'running') {
			// Prompt 10.4.3 — najpierw ustalenie, czy zadanie powstało (znacznik ubr-ref w opisie), bez ponownego POST.
			return $this->scheduleReconcile($job, $export, 'Poprzednia próba eksportu została przerwana — sprawdzam, czy zadanie powstało w ClickUp.');
		}

		$this->repo->updateExport((int) $export['id'], ['export_status' => 'running']);
		$content = $this->builder->build($ctx, (int) $export['company_id'], (array) $connection['settings']);
		$payload = ['name' => $content['name'], 'markdown_content' => $content['markdown'] . "\n\n" . self::ref($export)];
		$notes = [];

		// Prompt 10.4.3 — przypisanie pracownika: znany jest tylko identyfikator ClickUp właściciela połączenia.
		if (($job['trigger_type'] ?? '') === 'claim') {
			$synced = json_decode((string) ($export['synced_fields'] ?? ''), true) ?: [];
			$claimer = (int) ($synced['__claimer'] ?? $job['user_id']);

			if ($claimer === (int) $connection['user_id'] && ! empty($connection['clickup_user_id']) && ctype_digit((string) $connection['clickup_user_id'])) {
				$payload['assignees'] = [(int) $connection['clickup_user_id']];
			} else {
				$notes[] = 'Brak mapowania pracownika na użytkownika ClickUp — zadanie bez przypisanej osoby (opiekun w opisie).';
			}
		}

		$res = $this->client->createTask($token, (string) $export['list_id'], $payload, (int) $job['organization_id']);

		if ($res['error']) {
			return $this->handleError($job, $export, $connection, $res['error']);
		}

		$taskId = (string) ($res['data']['id'] ?? '');

		if ($taskId === '') {
			// 2xx bez identyfikatora — nie wiemy, czy zadanie powstało: bezpiecznie UNKNOWN.
			return $this->handleError($job, $export, $connection, new ClickUpError(ClickUpError::AMBIGUOUS, 'ClickUp nie zwrócił identyfikatora zadania.'));
		}

		$url = mb_substr(UrlGuard::httpUrl($res['data']['url'] ?? null) ?? 'https://app.clickup.com/t/' . rawurlencode($taskId), 0, 255);
		$this->repo->updateExport((int) $export['id'], ['export_status' => 'success', 'task_id' => $taskId, 'task_url' => $url, 'exported_at' => Db::now(), 'last_synced_at' => Db::now(), 'managed_hash' => $content['hash'], 'last_error' => null]);
		$set = $this->syncFields($token, $taskId, (array) ($content['mapped'] ?? []), null, $export, (int) $job['organization_id']);
		$notes = array_merge($notes, $set);

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

		$notes = array_merge($notes, $this->syncFields($token, (string) $export['task_id'], (array) ($content['mapped'] ?? []), (array) ($task['data']['custom_fields'] ?? []), $export, (int) $job['organization_id']));
		$this->repo->updateExport((int) $export['id'], ['last_synced_at' => Db::now(), 'managed_hash' => $newHash, 'last_error' => null]);

		return $this->finish($job, 'success', null, null, ['notes' => $notes]);
	}

	/** Znacznik w opisie zadania — jednoznaczne powiązanie z eksportem (organizacja, firma, rekord eksportu). */
	public static function ref(array $export): string
	{
		return 'ubr-ref: o' . (int) $export['organization_id'] . '-c' . (int) $export['company_id'] . '-e' . (int) $export['id'];
	}

	/**
	 * Prompt 10.4.3 — ustalenie wyniku tworzenia zadania po timeoucie / przerwaniu: oczekiwanie, wyszukanie na liście
	 * zadania ze znacznikiem `ubr-ref` w opisie (nie po nazwie ani domenie). Znalezione → powiązanie; po dwóch
	 * negatywnych sprawdzeniach — jednorazowe ponowne utworzenie, potem stan nieznany do ręcznego rozstrzygnięcia.
	 *
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $export
	 */
	private function scheduleReconcile(array $job, array $export, string $message): string
	{
		$this->repo->updateExport((int) $export['id'], ['export_status' => 'unknown', 'last_error' => $message]);
		$this->repo->createJob([
			'organization_id' => (int) $job['organization_id'], 'user_id' => (int) $job['user_id'], 'connection_id' => (int) $job['connection_id'],
			'export_id' => (int) $export['id'], 'company_id' => (int) $export['company_id'], 'operation' => 'reconcile',
			'trigger_type' => (string) ($job['trigger_type'] ?? 'manual'), 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 30),
		]);

		return $this->finish($job, 'unknown', $message, ClickUpError::AMBIGUOUS);
	}

	/**
	 * @param array<string, mixed> $job
	 * @param array<string, mixed> $export
	 * @param array<string, mixed> $connection
	 */
	private function reconcile(array $job, array $export, array $connection, string $token, TenantContext $ctx): string
	{
		if ($export['export_status'] !== 'unknown') {
			return $this->finish($job, 'skipped', 'Stan eksportu został już rozstrzygnięty.');
		}

		$since = (strtotime((string) $export['created_at'] . ' UTC') - 600) * 1000;
		$res = $this->client->listTasks($token, (string) $export['list_id'], ['date_created_gt' => $since], (int) $job['organization_id']);

		if ($res['error']) {
			return $this->handleError($job, $export, $connection, $res['error'], false);
		}

		$ref = self::ref($export);

		foreach ((array) ($res['data']['tasks'] ?? []) as $task) {
			$text = (string) ($task['markdown_description'] ?? '') . "\n" . (string) ($task['description'] ?? '') . "\n" . (string) ($task['text_content'] ?? '');

			if (! str_contains($text, $ref) || empty($task['id'])) {
				continue;
			}

			$taskId = (string) $task['id'];
			$url = mb_substr(UrlGuard::httpUrl($task['url'] ?? null) ?? 'https://app.clickup.com/t/' . rawurlencode($taskId), 0, 255);
			$block = self::extractBlock((string) ($task['markdown_description'] ?? ''));
			$this->repo->updateExport((int) $export['id'], ['export_status' => 'success', 'task_id' => $taskId, 'task_url' => $url, 'exported_at' => Db::now(), 'last_synced_at' => Db::now(), 'managed_hash' => $block !== null ? ClickUpTaskBuilder::hash($block) : null, 'last_error' => null]);

			// Pola nie zostały ustawione (odpowiedź na POST nie dotarła) — uzupełnienie z ochroną ręcznych zmian.
			$content = $this->builder->build($ctx, (int) $export['company_id'], (array) $connection['settings']);
			$notes = array_merge(['Zadanie odnalezione po znaczniku ' . $ref . ' — powiązano bez tworzenia duplikatu.'], $this->syncFields($token, $taskId, (array) ($content['mapped'] ?? []), (array) ($task['custom_fields'] ?? []), $export, (int) $job['organization_id']));

			return $this->finish($job, 'success', null, null, ['task_url' => $url, 'notes' => $notes]);
		}

		$checks = (int) $job['attempts'];

		if ($checks < 2) {
			$this->repo->updateJob((int) $job['id'], ['status' => 'queued', 'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 60), 'locked_until' => null, 'error_message' => 'Zadania jeszcze nie widać na liście — kolejne sprawdzenie za minutę.']);

			return 'queued';
		}

		$synced = json_decode((string) ($export['synced_fields'] ?? ''), true) ?: [];

		if (empty($synced['__recreated'])) {
			$synced['__recreated'] = true;
			$this->repo->updateExport((int) $export['id'], ['export_status' => 'queued', 'synced_fields' => wp_json_encode($synced), 'last_error' => null]);
			$this->repo->createJob(['organization_id' => (int) $job['organization_id'], 'user_id' => (int) $job['user_id'], 'connection_id' => (int) $job['connection_id'], 'export_id' => (int) $export['id'], 'company_id' => (int) $export['company_id'], 'operation' => 'create', 'trigger_type' => (string) $job['trigger_type']]);

			return $this->finish($job, 'success', null, null, ['notes' => ['Zadanie nie powstało (dwukrotne sprawdzenie listy) — jednorazowe ponowne utworzenie.']]);
		}

		$message = 'Nie udało się ustalić, czy zadanie powstało — sprawdź listę w ClickUp i rozstrzygnij w karcie firmy.';
		$this->repo->updateExport((int) $export['id'], ['last_error' => $message]);

		return $this->finish($job, 'unknown', $message, ClickUpError::AMBIGUOUS);
	}

	private static function extractBlock(string $markdown): ?string
	{
		$start = mb_strpos($markdown, ClickUpTaskBuilder::START);
		$end = mb_strpos($markdown, ClickUpTaskBuilder::END);

		return $start === false || $end === false || $end < $start ? null : mb_substr($markdown, $start, $end - $start + mb_strlen(ClickUpTaskBuilder::END));
	}

	/**
	 * Prompt 10.4.3 — Custom Fields z ochroną ręcznych zmian:
	 * - pusta wartość w ClickUp → zapis,
	 * - wartość równa ostatnio zapisanej przez aplikację → zapis nowej (pracownik jej nie zmieniał),
	 * - inna, niepusta wartość (zmieniona ręcznie lub nieznanego pochodzenia) → BEZ nadpisania, zapis konfliktu,
	 * - brak nowej wartości → pole bez zmian (aplikacja nigdy nie czyści danych).
	 * `$current` = null przy tworzeniu zadania (pola puste).
	 *
	 * @param array<string, array{field_id: string, value: mixed, type: string}> $mapped
	 * @param list<array<string, mixed>>|null $current custom_fields z GET /task
	 * @param array<string, mixed> $export
	 * @return list<string>
	 */
	private function syncFields(string $token, string $taskId, array $mapped, ?array $current, array $export, int $organizationId): array
	{
		$fresh = $this->repo->export($organizationId, (int) $export['id']) ?? $export;
		$synced = json_decode((string) ($fresh['synced_fields'] ?? ''), true) ?: [];
		$conflicts = json_decode((string) ($fresh['conflicts'] ?? ''), true) ?: [];
		$byId = [];

		foreach ((array) $current as $cf) {
			if (is_array($cf) && isset($cf['id'])) {
				$byId[(string) $cf['id']] = $cf;
			}
		}

		$notes = [];
		$set = 0;

		foreach ($mapped as $key => $m) {
			$new = self::norm($m['value']);
			$now = isset($byId[$m['field_id']]) ? self::currentValue($byId[$m['field_id']]) : '';
			$last = array_key_exists($key, $synced) ? (string) $synced[$key] : null;

			if ($now === $new) {
				$synced[$key] = $new;
				continue;
			}

			if ($now !== '' && ($last === null || $now !== $last)) {
				$conflicts[] = ['field' => $key, 'clickup' => mb_substr($now, 0, 120), 'proposed' => mb_substr($new, 0, 120), 'at' => gmdate('c')];
				$notes[] = sprintf('Pole „%s” zmienione ręcznie w ClickUp — nie nadpisano (konflikt zapisany).', ClickUpDestinationService::MAPPABLE[$key]['label'] ?? $key);
				continue;
			}

			$r = $this->client->setField($token, $taskId, $m['field_id'], $m['value'], $organizationId);

			if ($r['error']) {
				$notes[] = 'Pole ' . (ClickUpDestinationService::MAPPABLE[$key]['label'] ?? $key) . ': ' . $r['error']->getMessage();
				continue;
			}

			$synced[$key] = $new;
			$set++;
		}

		if ($set) {
			$notes[] = 'Ustawiono zmapowane Custom Fields: ' . $set . '.';
		}

		$this->repo->updateExport((int) $export['id'], ['synced_fields' => wp_json_encode($synced), 'conflicts' => $conflicts ? wp_json_encode(array_slice($conflicts, -10)) : ($fresh['conflicts'] ?? null)]);

		return $notes;
	}

	/** Wartość pola w ClickUp sprowadzona do porównywalnej postaci (drop_down: id opcji; telefon: cyfry z „+”). */
	private static function currentValue(array $cf): string
	{
		$value = $cf['value'] ?? null;

		if ($value === null || $value === '' || $value === []) {
			return '';
		}

		if (($cf['type'] ?? '') === 'drop_down') {
			foreach ((array) ($cf['type_config']['options'] ?? []) as $o) {
				if ((string) ($o['orderindex'] ?? '') === (string) $value || (string) ($o['id'] ?? '') === (string) $value) {
					return (string) $o['id'];
				}
			}
		}

		return self::norm($value);
	}

	private static function norm(mixed $value): string
	{
		if (is_array($value)) {
			$value = implode(',', array_map('strval', $value));
		}

		$value = trim((string) $value);

		if (preg_match('/^\+?[\d\s\-().]{7,}$/', $value)) {
			return preg_replace('/[^\d+]/', '', $value) ?? $value;
		}

		return mb_strtolower($value);
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
			// Nie wysyłamy ponownie POST — najpierw ustalenie, czy zadanie powstało (Prompt 10.4.3).
			return $this->scheduleReconcile($job, $export, $e->getMessage() . ' Sprawdzam, czy zadanie powstało w ClickUp.');
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
