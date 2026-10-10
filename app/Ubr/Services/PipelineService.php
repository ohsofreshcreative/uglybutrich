<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Infrastructure\Repositories\ProspectRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Support\UbrException;

/**
 * Sales Pipeline organizacji: status, priorytet, właściciel, kwalifikacja, wybrany kontakt, notatki, aktywności,
 * follow-upy, tablica (Board View), agenda i statystyki Dashboard.
 *
 * Wszystko jest PRYWATNE dla organizacji — globalny rekord firmy nie jest zmieniany. Każda operacja sprawdza,
 * że prospekt / notatka / działanie należy do aktywnej organizacji (404 zamiast ujawniania cudzych danych).
 */
class PipelineService
{
	public const CARDS_PER_COLUMN = 20;

	public function __construct(
		private PipelineRepository $pipeline,
		private ProspectRepository $prospects,
		private CompanyRepository $companies,
		private MemberRepository $members,
		private ResultRepository $results,
	) {
	}

	/* ------------------------------------ tablica ------------------------------------ */

	/**
	 * Tablica: liczniki wszystkich kolumn + pierwsza strona kart każdej kolumny (albo jednej kolumny przy doładowaniu).
	 *
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public function board(TenantContext $ctx, array $query): array
	{
		$this->assert($ctx, 'pipeline.view');
		$filters = $this->filters($query);
		$limit = max(5, min(50, (int) ($query['per_column'] ?? self::CARDS_PER_COLUMN)));
		$only = sanitize_key((string) ($query['status'] ?? ''));
		$offset = max(0, (int) ($query['offset'] ?? 0));
		$counts = $this->pipeline->statusCounts($ctx->organizationId, $filters);
		$columns = [];

		foreach (PipelineCatalog::statuses($ctx->organizationId) as $key => $def) {
			if ($only !== '' && $only !== $key) {
				continue;
			}

			$page = $this->pipeline->column($ctx->organizationId, $key, $filters, $only === $key ? $offset : 0, $limit);
			$columns[] = ['key' => $key, 'label' => $def['label'], 'description' => $def['description'], 'closed' => $def['closed'], 'total' => $counts[$key] ?? 0, 'items' => array_map(fn ($r) => $this->card($r), $page['items'])];
		}

		return ['columns' => $columns, 'members' => $this->memberOptions($ctx), 'priorities' => PipelineCatalog::PRIORITIES];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function card(array $r): array
	{
		return [
			'prospect_id' => (int) $r['prospect_id'],
			'company_id' => (int) $r['id'],
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'domain' => $r['domain'] ?: null,
			'website_url' => $r['website_url'] ?: null,
			'status' => $r['pipeline_status'],
			'priority' => $r['priority'],
			'priority_label' => PipelineCatalog::PRIORITIES[$r['priority']] ?? $r['priority'],
			'owner' => $this->userBrief($r['owner_user_id'] ? (int) $r['owner_user_id'] : null),
			'qualification' => $r['qualification'],
			'next_action_at' => $r['next_action_at'] ? gmdate('c', strtotime($r['next_action_at'] . ' UTC')) : null,
			'overdue' => $r['next_action_at'] && strtotime($r['next_action_at'] . ' UTC') < time(),
			'opportunity' => $r['opportunity_score'] !== null ? (int) $r['opportunity_score'] : null,
			'relevance' => $r['relevance_score'] !== null ? (int) $r['relevance_score'] : null,
			'top_contact' => $r['top_contact_name'] ? ['name' => $r['top_contact_name'], 'title' => $r['top_contact_title'], 'score' => $r['top_contact_score'] !== null ? (int) $r['top_contact_score'] : null] : null,
		];
	}

	/* ------------------------------------ zmiany prospektu ------------------------------------ */

	/**
	 * Zmiana pól sprzedażowych prospektu (status, priorytet, właściciel, kwalifikacja, kontakt, powód utraty) z historią.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> widok sprzedaży (sales)
	 */
	public function update(TenantContext $ctx, int $prospectId, array $input): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$p = $this->prospectOr404($ctx, $prospectId);
		$changes = [];
		$events = [];

		if (array_key_exists('status', $input)) {
			$status = sanitize_key((string) $input['status']);

			if (! PipelineCatalog::isStatus($status)) {
				throw UbrException::invalid('Nieznany status pipeline.');
			}

			if ($status !== $p['status']) {
				$changes['status'] = $status;
				$changes['status_changed_at'] = Db::now();
				$events[] = ['status', $p['status'], $status];
			}
		}

		if (array_key_exists('lost_reason', $input)) {
			$reason = mb_substr(sanitize_textarea_field((string) $input['lost_reason']), 0, 500) ?: null;

			if ($reason !== $p['lost_reason']) {
				$changes['lost_reason'] = $reason;
				$events[] = ['lost_reason', $p['lost_reason'], $reason];
			}
		}

		if (array_key_exists('priority', $input)) {
			$priority = sanitize_key((string) $input['priority']);

			if (! isset(PipelineCatalog::PRIORITIES[$priority])) {
				throw UbrException::invalid('Nieznany priorytet.');
			}

			if ($priority !== $p['priority']) {
				$changes['priority'] = $priority;
				$events[] = ['priority', $p['priority'], $priority];
			}
		}

		if (array_key_exists('owner_user_id', $input)) {
			$owner = $input['owner_user_id'] === null || $input['owner_user_id'] === '' ? null : (int) $input['owner_user_id'];

			// Właściciel musi być członkiem TEJ organizacji.
			if ($owner !== null && ! $this->members->role($ctx->organizationId, $owner)) {
				throw UbrException::invalid('Można przypisać tylko członka Twojej organizacji.');
			}

			if ($owner !== ($p['owner_user_id'] ? (int) $p['owner_user_id'] : null)) {
				$changes['owner_user_id'] = $owner;
				$events[] = ['owner', $p['owner_user_id'], $owner];
			}
		}

		if (array_key_exists('qualification', $input)) {
			$q = $input['qualification'] === null || $input['qualification'] === '' ? null : sanitize_key((string) $input['qualification']);

			if ($q !== null && ! isset(PipelineCatalog::QUALIFICATIONS[$q])) {
				throw UbrException::invalid('Nieznana kwalifikacja.');
			}

			if ($q !== $p['qualification']) {
				$changes['qualification'] = $q;
				$events[] = ['qualification', $p['qualification'], $q];
			}
		}

		if (array_key_exists('selected_contact_id', $input)) {
			$contactId = $input['selected_contact_id'] ? (int) $input['selected_contact_id'] : null;

			if ($contactId !== null) {
				$contact = app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class)->find($contactId);

				// Kontakt tej firmy: globalny albo prywatny tej organizacji.
				if (! $contact || (int) $contact['company_id'] !== (int) $p['company_id'] || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true)) {
					throw UbrException::invalid('Wybrany kontakt nie należy do tej firmy.');
				}
			}

			if ($contactId !== ($p['selected_contact_id'] ? (int) $p['selected_contact_id'] : null)) {
				$changes['selected_contact_id'] = $contactId;
				$events[] = ['contact', $p['selected_contact_id'], $contactId];
			}
		}

		if ($changes) {
			$this->pipeline->updateProspect($ctx->organizationId, $prospectId, $changes);

			foreach ($events as [$type, $old, $new]) {
				$this->pipeline->event($ctx->organizationId, $prospectId, $ctx->userId, $type, $old !== null ? (string) $old : null, $new !== null ? (string) $new : null);
			}

			$this->forgetStats($ctx->organizationId);

			if (isset($changes['status']) || isset($changes['priority'])) {
				do_action('ubr/company/changed', (int) $p['company_id'], 'pipeline_changed', $ctx->organizationId);
			} elseif (array_key_exists('qualification', $changes)) {
				// „Not a fit” to hard blocker Lead Intelligence — ocena musi się przeliczyć (bez wyzwalania reguł ClickUp).
				$this->refreshLead((int) $p['company_id'], $ctx->organizationId);
			}
		}

		return $this->sales($ctx, (int) $p['company_id']);
	}

	public function archive(TenantContext $ctx, int $prospectId, bool $restore = false): array
	{
		$this->assert($ctx, 'prospects.manage');
		$p = $this->prospectOr404($ctx, $prospectId, true);

		if ($restore && $p['archived_at']) {
			$this->pipeline->updateProspect($ctx->organizationId, $prospectId, ['archived_at' => null, 'archived_by' => null]);
			$this->pipeline->event($ctx->organizationId, $prospectId, $ctx->userId, 'restored', null, null);
		} elseif (! $restore && ! $p['archived_at']) {
			$this->prospects->remove($ctx->organizationId, $prospectId, $ctx->userId);
			$this->pipeline->event($ctx->organizationId, $prospectId, $ctx->userId, 'archived', null, null);
		}

		$this->forgetStats($ctx->organizationId);
		$this->refreshLead((int) $p['company_id'], $ctx->organizationId); // pula firm organizacji się zmieniła

		return $this->sales($ctx, (int) $p['company_id']);
	}

	/* ------------------------------------ notatki ------------------------------------ */

	public function addNote(TenantContext $ctx, int $prospectId, string $body): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$p = $this->prospectOr404($ctx, $prospectId);
		$body = $this->cleanText($body, 5000);
		$id = $this->pipeline->addNote($ctx->organizationId, $prospectId, $ctx->userId, $body);
		$this->pipeline->event($ctx->organizationId, $prospectId, $ctx->userId, 'note_added', null, (string) $id);
		$this->touch($ctx, $prospectId);

		return $this->sales($ctx, (int) $p['company_id']);
	}

	public function updateNote(TenantContext $ctx, int $noteId, string $body): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$note = $this->pipeline->note($ctx->organizationId, $noteId) ?? throw UbrException::notFound('Nie znaleziono notatki.');

		// Edycja tylko własnej notatki.
		if ((int) $note['author_id'] !== $ctx->userId) {
			throw UbrException::forbidden('Możesz edytować tylko własne notatki.');
		}

		$this->pipeline->updateNote($ctx->organizationId, $noteId, $this->cleanText($body, 5000));
		$this->pipeline->event($ctx->organizationId, (int) $note['prospect_id'], $ctx->userId, 'note_edited', null, (string) $noteId);
		$p = $this->prospectOr404($ctx, (int) $note['prospect_id'], true);

		return $this->sales($ctx, (int) $p['company_id']);
	}

	public function deleteNote(TenantContext $ctx, int $noteId): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$note = $this->pipeline->note($ctx->organizationId, $noteId) ?? throw UbrException::notFound('Nie znaleziono notatki.');

		// Usuwa autor albo owner / admin organizacji.
		if ((int) $note['author_id'] !== $ctx->userId && ! $ctx->can('pipeline.moderate')) {
			throw UbrException::forbidden('Notatkę może usunąć autor albo administrator organizacji.');
		}

		$this->pipeline->deleteNote($ctx->organizationId, $noteId, $ctx->userId);
		$this->pipeline->event($ctx->organizationId, (int) $note['prospect_id'], $ctx->userId, 'note_deleted', null, (string) $noteId);
		$p = $this->prospectOr404($ctx, (int) $note['prospect_id'], true);

		return $this->sales($ctx, (int) $p['company_id']);
	}

	/* ------------------------------------ aktywności ------------------------------------ */

	/**
	 * Ręczny rejestr działań (telefon, e-mail, LinkedIn, spotkanie…) — nic nie jest wysyłane automatycznie.
	 *
	 * @param array<string, mixed> $input
	 */
	public function addActivity(TenantContext $ctx, int $prospectId, array $input): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$p = $this->prospectOr404($ctx, $prospectId);
		$type = sanitize_key((string) ($input['type'] ?? ''));

		if (! isset(PipelineCatalog::ACTIVITY_TYPES[$type])) {
			throw UbrException::invalid('Nieznany typ działania.');
		}

		$contactId = ! empty($input['contact_id']) ? (int) $input['contact_id'] : null;

		if ($contactId !== null) {
			$contact = app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class)->find($contactId);

			if (! $contact || (int) $contact['company_id'] !== (int) $p['company_id'] || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true)) {
				throw UbrException::invalid('Kontakt nie należy do tej firmy.');
			}
		}

		$occurred = $this->parseDate($input['occurred_at'] ?? null) ?? Db::now();

		if (strtotime($occurred . ' UTC') > time() + 300) {
			throw UbrException::invalid('Data działania nie może być w przyszłości — zaplanuj follow-up.');
		}

		$this->pipeline->addActivity([
			'organization_id' => $ctx->organizationId,
			'prospect_id' => $prospectId,
			'user_id' => $ctx->userId,
			'type' => $type,
			'description' => $this->cleanText((string) ($input['description'] ?? ''), 3000, false),
			'contact_id' => $contactId,
			'occurred_at' => $occurred,
		]);
		$this->touch($ctx, $prospectId, $occurred);

		return $this->sales($ctx, (int) $p['company_id']);
	}

	/* ------------------------------------ follow-upy ------------------------------------ */

	/**
	 * @param array<string, mixed> $input
	 */
	public function addFollowup(TenantContext $ctx, int $prospectId, array $input): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$p = $this->prospectOr404($ctx, $prospectId);
		$title = mb_substr(sanitize_text_field((string) ($input['title'] ?? '')), 0, 200);

		if ($title === '') {
			throw UbrException::invalid('Podaj tytuł działania.');
		}

		$due = $this->parseDate($input['due_at'] ?? null) ?? throw UbrException::invalid('Podaj termin działania.');
		$assignee = ! empty($input['assigned_user_id']) ? (int) $input['assigned_user_id'] : $ctx->userId;

		if (! $this->members->role($ctx->organizationId, $assignee)) {
			throw UbrException::invalid('Można przypisać tylko członka Twojej organizacji.');
		}

		$id = $this->pipeline->addFollowup([
			'organization_id' => $ctx->organizationId,
			'prospect_id' => $prospectId,
			'title' => $title,
			'description' => $this->cleanText((string) ($input['description'] ?? ''), 2000, false),
			'due_at' => $due,
			'assigned_user_id' => $assignee,
			'created_by' => $ctx->userId,
		]);
		$this->pipeline->refreshNextAction($ctx->organizationId, $prospectId);
		$this->pipeline->event($ctx->organizationId, $prospectId, $ctx->userId, 'followup_added', null, (string) $id);
		$this->forgetStats($ctx->organizationId);

		return $this->sales($ctx, (int) $p['company_id']);
	}

	/**
	 * @param array<string, mixed> $input status (open|completed|cancelled), due_at, assigned_user_id
	 */
	public function updateFollowup(TenantContext $ctx, int $id, array $input): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$f = $this->pipeline->followup($ctx->organizationId, $id) ?? throw UbrException::notFound('Nie znaleziono działania.');
		$data = [];

		if (isset($input['status'])) {
			$status = sanitize_key((string) $input['status']);

			if (! isset(PipelineCatalog::FOLLOWUP_STATUSES[$status])) {
				throw UbrException::invalid('Nieznany status działania.');
			}

			$data['status'] = $status;
			$data['completed_at'] = $status === 'completed' ? Db::now() : null;
		}

		if (isset($input['due_at'])) {
			$data['due_at'] = $this->parseDate($input['due_at']) ?? throw UbrException::invalid('Nieprawidłowy termin.');
		}

		if (isset($input['assigned_user_id'])) {
			$assignee = (int) $input['assigned_user_id'];

			if (! $this->members->role($ctx->organizationId, $assignee)) {
				throw UbrException::invalid('Można przypisać tylko członka Twojej organizacji.');
			}

			$data['assigned_user_id'] = $assignee;
		}

		if ($data) {
			$this->pipeline->updateFollowup($ctx->organizationId, $id, $data);
			$this->pipeline->refreshNextAction($ctx->organizationId, (int) $f['prospect_id']);
			$this->pipeline->event($ctx->organizationId, (int) $f['prospect_id'], $ctx->userId, 'followup_' . ($data['status'] ?? 'updated'), null, (string) $id);

			if (($data['status'] ?? null) === 'completed') {
				$this->touch($ctx, (int) $f['prospect_id']);
			}

			$this->forgetStats($ctx->organizationId);
		}

		$p = $this->prospectOr404($ctx, (int) $f['prospect_id'], true);

		return $this->sales($ctx, (int) $p['company_id']);
	}

	/* ------------------------------------ widok sprzedaży ------------------------------------ */

	/**
	 * Sekcja „Sprzedaż” w karcie firmy (dla firmy widocznej dla organizacji; prospekt może nie istnieć).
	 *
	 * @return array<string, mixed>
	 */
	public function sales(TenantContext $ctx, int $companyId): array
	{
		$this->assert($ctx, 'pipeline.view');
		$company = $this->companies->find($companyId);

		if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$p = $this->pipeline->prospectForCompany($ctx->organizationId, $companyId);
		$best = $this->results->bestRelevanceForCompany($companyId, $ctx->organizationId);
		$relevance = $best && $best['relevance_score'] !== null ? (int) $best['relevance_score'] : null;
		$base = [
			'company_id' => $companyId,
			'is_prospect' => $p && ! $p['archived_at'],
			'statuses' => array_map(fn ($k, $d) => ['key' => $k, 'label' => $d['label'], 'closed' => $d['closed']], array_keys(PipelineCatalog::statuses($ctx->organizationId)), PipelineCatalog::statuses($ctx->organizationId)),
			'priorities' => PipelineCatalog::PRIORITIES,
			'qualifications' => PipelineCatalog::QUALIFICATIONS,
			'activity_types' => PipelineCatalog::ACTIVITY_TYPES,
			'reason_statuses' => PipelineCatalog::REASON_STATUSES,
			'members' => $this->memberOptions($ctx),
			'suggestion' => PipelineCatalog::suggestQualification($company, $relevance),
			'can_manage' => $ctx->can('pipeline.manage'),
			'can_moderate' => $ctx->can('pipeline.moderate'),
			'me' => $ctx->userId,
		];

		if (! $p) {
			return $base + ['prospect' => null];
		}

		$pid = (int) $p['id'];

		return $base + [
			'prospect' => [
				'id' => $pid,
				'status' => $p['status'],
				'status_label' => PipelineCatalog::statusLabel($p['status']),
				'priority' => $p['priority'],
				'owner_user_id' => $p['owner_user_id'] ? (int) $p['owner_user_id'] : null,
				'owner' => $this->userBrief($p['owner_user_id'] ? (int) $p['owner_user_id'] : null),
				'qualification' => $p['qualification'],
				'selected_contact_id' => $p['selected_contact_id'] ? (int) $p['selected_contact_id'] : null,
				'lost_reason' => $p['lost_reason'],
				'created_at' => gmdate('c', strtotime($p['created_at'] . ' UTC')),
				'status_changed_at' => $p['status_changed_at'] ? gmdate('c', strtotime($p['status_changed_at'] . ' UTC')) : null,
				'last_activity_at' => $p['last_activity_at'] ? gmdate('c', strtotime($p['last_activity_at'] . ' UTC')) : null,
				'next_action_at' => $p['next_action_at'] ? gmdate('c', strtotime($p['next_action_at'] . ' UTC')) : null,
				'archived_at' => $p['archived_at'] ? gmdate('c', strtotime($p['archived_at'] . ' UTC')) : null,
			],
			'notes' => array_map(fn ($n) => [
				'id' => (int) $n['id'],
				'body' => $n['body'],
				'author' => $this->userBrief((int) $n['author_id']),
				'mine' => (int) $n['author_id'] === $ctx->userId,
				'created_at' => gmdate('c', strtotime($n['created_at'] . ' UTC')),
				'updated_at' => $n['updated_at'] !== $n['created_at'] ? gmdate('c', strtotime($n['updated_at'] . ' UTC')) : null,
			], $this->pipeline->notes($ctx->organizationId, $pid)),
			'activities' => array_map(fn ($a) => [
				'id' => (int) $a['id'],
				'type' => $a['type'],
				'type_label' => PipelineCatalog::ACTIVITY_TYPES[$a['type']] ?? $a['type'],
				'description' => $a['description'],
				'user' => $this->userBrief((int) $a['user_id']),
				'contact_id' => $a['contact_id'] ? (int) $a['contact_id'] : null,
				'occurred_at' => gmdate('c', strtotime($a['occurred_at'] . ' UTC')),
			], $this->pipeline->activities($ctx->organizationId, $pid)),
			'followups' => array_map(fn ($f) => [
				'id' => (int) $f['id'],
				'title' => $f['title'],
				'description' => $f['description'],
				'due_at' => gmdate('c', strtotime($f['due_at'] . ' UTC')),
				'overdue' => $f['status'] === 'open' && strtotime($f['due_at'] . ' UTC') < time(),
				'status' => $f['status'],
				'status_label' => PipelineCatalog::FOLLOWUP_STATUSES[$f['status']] ?? $f['status'],
				'assigned' => $this->userBrief($f['assigned_user_id'] ? (int) $f['assigned_user_id'] : null),
				'completed_at' => $f['completed_at'] ? gmdate('c', strtotime($f['completed_at'] . ' UTC')) : null,
			], $this->pipeline->followups($ctx->organizationId, $pid)),
			'history' => array_map(fn ($e) => $this->presentEvent($e), $this->pipeline->events($ctx->organizationId, $pid)),
		];
	}

	/**
	 * @param array<string, mixed> $e
	 * @return array<string, mixed>
	 */
	public function presentEvent(array $e): array
	{
		$label = fn ($type, $v) => match ($type) {
			'status' => PipelineCatalog::statusLabel($v),
			'priority' => PipelineCatalog::PRIORITIES[$v] ?? $v,
			'qualification' => $v ? (PipelineCatalog::QUALIFICATIONS[$v] ?? $v) : '—',
			'owner' => $v ? ($this->userBrief((int) $v)['name'] ?? '—') : 'brak',
			default => $v,
		};
		$text = match ($e['type']) {
			'created' => 'Dodano do Prospects',
			'archived' => 'Zarchiwizowano (usunięto z Prospects)',
			'restored' => 'Przywrócono do Prospects',
			'status' => 'Status: ' . $label('status', $e['old_value']) . ' → ' . $label('status', $e['new_value']),
			'priority' => 'Priorytet: ' . $label('priority', $e['old_value']) . ' → ' . $label('priority', $e['new_value']),
			'owner' => 'Właściciel: ' . $label('owner', $e['old_value']) . ' → ' . $label('owner', $e['new_value']),
			'qualification' => 'Kwalifikacja: ' . $label('qualification', $e['old_value']) . ' → ' . $label('qualification', $e['new_value']),
			'contact' => 'Zmieniono wybrany kontakt',
			'lost_reason' => 'Powód: ' . ($e['new_value'] ?: '—'),
			'note_added' => 'Dodano notatkę',
			'note_edited' => 'Edytowano notatkę',
			'note_deleted' => 'Usunięto notatkę',
			'followup_added' => 'Zaplanowano działanie',
			'followup_completed' => 'Wykonano działanie',
			'followup_cancelled' => 'Anulowano działanie',
			'followup_open', 'followup_updated' => 'Zmieniono działanie',
			'clickup_export' => 'Eksport do ClickUp: ' . ($e['new_value'] ?? ''),
			default => $e['type'],
		};

		return ['type' => $e['type'], 'text' => $text, 'user' => $this->userBrief($e['user_id'] ? (int) $e['user_id'] : null), 'at' => gmdate('c', strtotime($e['created_at'] . ' UTC'))];
	}

	/* ------------------------------------ Dashboard ------------------------------------ */

	/**
	 * Statystyki z rzeczywistych danych organizacji (jedno zapytanie GROUP BY + liczniki agendy; cache 60 s).
	 *
	 * @return array<string, mixed>
	 */
	public function stats(TenantContext $ctx): array
	{
		$this->assert($ctx, 'pipeline.view');
		$key = 'ubr_pipeline_stats_' . $ctx->organizationId;
		$cached = get_transient($key);

		if (is_array($cached)) {
			return $cached;
		}

		$counts = $this->pipeline->statusCounts($ctx->organizationId);
		$agenda = $this->pipeline->agendaCounts($ctx->organizationId);
		$data = [
			'total' => array_sum($counts),
			'by_status' => array_map(fn ($k, $d) => ['key' => $k, 'label' => $d['label'], 'count' => $counts[$k] ?? 0], array_keys(PipelineCatalog::STATUSES), PipelineCatalog::STATUSES),
			'overdue' => $agenda['overdue'],
			'today' => $agenda['today'],
			'upcoming' => $agenda['upcoming'],
			'exported' => $this->pipeline->countExported($ctx->organizationId),
			'generated_at' => gmdate('c'),
		];
		set_transient($key, $data, 60);

		return $data;
	}

	/**
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function agenda(TenantContext $ctx, bool $mine = false): array
	{
		$this->assert($ctx, 'pipeline.view');
		$present = fn ($f) => [
			'id' => (int) $f['id'],
			'title' => $f['title'],
			'due_at' => gmdate('c', strtotime($f['due_at'] . ' UTC')),
			'company' => ['id' => (int) $f['company_id'], 'name' => $f['company_name'], 'domain' => $f['domain']],
			'assigned' => $this->userBrief($f['assigned_user_id'] ? (int) $f['assigned_user_id'] : null),
		];
		$user = $mine ? $ctx->userId : null;

		return [
			'overdue' => array_map($present, $this->pipeline->agenda($ctx->organizationId, 'overdue', $user)),
			'today' => array_map($present, $this->pipeline->agenda($ctx->organizationId, 'today', $user)),
			'upcoming' => array_map($present, $this->pipeline->agenda($ctx->organizationId, 'upcoming', $user, 10)),
		];
	}

	/* ------------------------------------ pomocnicze ------------------------------------ */

	/**
	 * @return array<string, mixed>
	 */
	private function prospectOr404(TenantContext $ctx, int $prospectId, bool $allowArchived = false): array
	{
		$p = $this->pipeline->prospect($ctx->organizationId, $prospectId);

		if (! $p || (! $allowArchived && $p['archived_at'])) {
			throw UbrException::notFound('Nie znaleziono prospekta.');
		}

		return $p;
	}

	private function touch(TenantContext $ctx, int $prospectId, ?string $at = null): void
	{
		$this->pipeline->updateProspect($ctx->organizationId, $prospectId, ['last_activity_at' => $at ?? Db::now()]);
		$this->forgetStats($ctx->organizationId);
	}

	/**
	 * Przeliczenie Lead Intelligence po zmianie, która nie jest wyzwalaczem reguł ClickUp (kwalifikacja, archiwizacja).
	 */
	public function refreshLead(int $companyId, int $organizationId): void
	{
		app(LeadIntelligenceService::class)->invalidate($companyId, 'pipeline_changed', $organizationId);
		app(LeadIntelligenceRunner::class)->kick();
	}

	public function forgetStats(int $organizationId): void
	{
		delete_transient('ubr_pipeline_stats_' . $organizationId);
		ActionCenterService::bumpOrganization($organizationId); // Action Center: nowe liczniki po zmianie pipeline / follow-upów
	}

	/**
	 * Treść notatek / opisów: czysty tekst (bez HTML) — wyświetlana z escapowaniem (x-text). Ochrona przed XSS.
	 */
	private function cleanText(string $text, int $max, bool $required = true): string
	{
		$text = trim(wp_strip_all_tags(str_replace("\r\n", "\n", $text)));
		$text = mb_substr($text, 0, $max);

		if ($required && $text === '') {
			throw UbrException::invalid('Treść nie może być pusta.');
		}

		return $text;
	}

	private function parseDate(mixed $value): ?string
	{
		if (! is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			// Wartość z formularza (czas lokalny witryny) lub ISO z offsetem → UTC.
			$dt = new \DateTimeImmutable($value, wp_timezone());
		} catch (\Exception) {
			return null;
		}

		return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	private function filters(array $query): array
	{
		return [
			'search' => sanitize_text_field((string) ($query['search'] ?? '')),
			'owner' => sanitize_key((string) ($query['owner'] ?? '')),
			'priority' => sanitize_key((string) ($query['priority'] ?? '')),
			'qualification' => sanitize_key((string) ($query['qualification'] ?? '')),
			'overdue' => sanitize_key((string) ($query['overdue'] ?? '')),
			'archived' => ! empty($query['archived']),
		];
	}

	/**
	 * @return list<array{id: int, name: string}>
	 */
	public function memberOptions(TenantContext $ctx): array
	{
		return array_values(array_map(fn ($m) => ['id' => (int) $m['user_id'], 'name' => $this->userBrief((int) $m['user_id'])['name'] ?? ('#' . $m['user_id'])], $this->members->list($ctx->organizationId)));
	}

	/**
	 * @return array{id: int, name: string}|null
	 */
	public function userBrief(?int $userId): ?array
	{
		if (! $userId) {
			return null;
		}

		$u = get_userdata($userId);

		return ['id' => $userId, 'name' => $u ? ($u->display_name ?: $u->user_login) : 'Użytkownik usunięty'];
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}
}
