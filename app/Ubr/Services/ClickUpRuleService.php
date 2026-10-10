<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Support\UbrException;
use Throwable;

/**
 * Reguły automatycznego eksportu do ClickUp.
 *
 * - Reguła należy do organizacji, ale wykonuje się WYŁĄCZNIE przez połączenie ClickUp użytkownika, który ją utworzył
 *   (tylko on może ją utworzyć, edytować i włączyć — nigdy cudzym tokenem).
 * - DOMYŚLNIE WYŁĄCZONA. Ścieżka: utwórz → wybierz listę → podgląd (Dry Run) → potwierdź włączenie.
 * - Warunki łączone przez AND, oparte wyłącznie na istniejących danych (nie uruchamiamy płatnych analiz).
 * - Po włączeniu działa tylko dla NOWYCH zdarzeń (dodanie do Prospects, weryfikacja, analiza, kontakty, zmiana
 *   statusu). Eksport istniejących firm (backfill) wymaga osobnego potwierdzenia i podlega limitowi dziennemu.
 * - Wykonanie zawsze przez kolejkę (job 'evaluate' → ewentualnie job 'create'), dzienny limit per reguła.
 */
class ClickUpRuleService
{
	public const MAX_DAILY_LIMIT = 100;

	public const PREVIEW_CANDIDATES = 500;

	public const TRIGGERS = ['prospect_added', 'verification_done', 'analysis_done', 'contacts_done', 'pipeline_changed'];

	public function __construct(
		private ClickUpRepository $repo,
		private CompanyRepository $companies,
		private ResultRepository $results,
		private PipelineRepository $pipeline,
		private ClickUpOAuthService $oauth,
		private Db $db,
	) {
	}

	/* ------------------------------ zarządzanie ------------------------------ */

	/**
	 * @return array<string, mixed>
	 */
	public function list(TenantContext $ctx): array
	{
		$this->assertView($ctx);

		return [
			'rules' => array_map(fn ($r) => $this->present($ctx, $r), $this->repo->rules($ctx->organizationId)),
			'can_manage' => $ctx->can('clickup.rules'),
			'conditions' => $this->conditionCatalog(),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function create(TenantContext $ctx, array $input): array
	{
		if (! $ctx->can('clickup.rules')) {
			throw UbrException::forbidden();
		}

		[$connection] = $this->ownConnection($ctx);
		$destination = $this->destination($connection, $input);
		$id = $this->repo->createRule([
			'organization_id' => $ctx->organizationId,
			'created_by' => $ctx->userId,
			'connection_id' => (int) $connection['id'],
			'name' => $this->name($input),
			'conditions' => $this->conditions((array) ($input['conditions'] ?? [])),
			'workspace_id' => $destination['workspace_id'],
			'list_id' => $destination['list_id'],
			'list_name' => $destination['list_name'],
			'enabled' => 0,
			'daily_limit' => $this->dailyLimit($input['daily_limit'] ?? 10),
		]);

		return $this->present($ctx, $this->repo->rule($ctx->organizationId, $id));
	}

	/**
	 * Edycja (tylko autor). Zmiana warunków lub listy wyłącza regułę — trzeba ją ponownie przejrzeć i potwierdzić.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update(TenantContext $ctx, int $ruleId, array $input): array
	{
		$rule = $this->ownRule($ctx, $ruleId);
		[$connection] = $this->ownConnection($ctx);
		$data = [];

		if (isset($input['name'])) {
			$data['name'] = $this->name($input);
		}

		if (isset($input['conditions'])) {
			$data['conditions'] = $this->conditions((array) $input['conditions']);
		}

		if (isset($input['daily_limit'])) {
			$data['daily_limit'] = $this->dailyLimit($input['daily_limit']);
		}

		if (isset($input['list_id'])) {
			$destination = $this->destination($connection, $input);
			$data += ['workspace_id' => $destination['workspace_id'], 'list_id' => $destination['list_id'], 'list_name' => $destination['list_name'], 'connection_id' => (int) $connection['id']];
		}

		if (isset($data['conditions']) || isset($data['list_id'])) {
			$data += ['enabled' => 0, 'activated_at' => null, 'backfill_confirmed_at' => null];
		}

		if ($data) {
			$this->repo->updateRule((int) $rule['id'], $data);
		}

		return $this->present($ctx, $this->repo->rule($ctx->organizationId, (int) $rule['id']));
	}

	public function delete(TenantContext $ctx, int $ruleId): void
	{
		$rule = $this->manageableRule($ctx, $ruleId);
		$this->repo->deleteRule($ctx->organizationId, (int) $rule['id']);
	}

	/**
	 * Dry Run: które firmy organizacji spełniają warunki dziś (nic nie jest eksportowane).
	 *
	 * @param array<string, mixed>|null $conditions warunki do sprawdzenia przed zapisem (opcjonalnie)
	 * @return array<string, mixed>
	 */
	public function preview(TenantContext $ctx, int $ruleId, ?array $conditions = null): array
	{
		$this->assertView($ctx);
		$rule = $this->repo->rule($ctx->organizationId, $ruleId) ?? throw UbrException::notFound('Nie znaleziono reguły.');
		$conditions = $conditions !== null ? $this->conditions($conditions) : (array) $rule['conditions'];
		$candidates = $this->candidates($ctx->organizationId, $conditions);
		$matching = [];
		$alreadyExported = 0;

		foreach ($candidates['ids'] as $companyId) {
			$check = $this->matches($ctx, $companyId, $conditions);

			if (! $check['match']) {
				continue;
			}

			$exported = $this->repo->exportFor($ctx->organizationId, $companyId, (string) $rule['list_id']);

			if ($exported) {
				$alreadyExported++;
			}

			$matching[] = ['company_id' => $companyId, 'name' => $check['name'], 'domain' => $check['domain'], 'already_exported' => (bool) $exported];
		}

		$this->repo->updateRule((int) $rule['id'], ['last_run_at' => Db::now()]);
		$new = count($matching) - $alreadyExported;

		return [
			'rule_id' => (int) $rule['id'],
			'dry_run' => true,
			'matching' => count($matching),
			'already_exported' => $alreadyExported,
			'would_export' => $new,
			'truncated' => $candidates['truncated'],
			'sample' => array_slice($matching, 0, 15),
			'daily_limit' => (int) $rule['daily_limit'],
			'message' => sprintf('Dry Run: warunki spełnia %d firm (%d już wyeksportowanych do tej listy). Włączenie reguły NIE eksportuje ich automatycznie — działa tylko dla nowych zdarzeń.', count($matching), $alreadyExported),
		];
	}

	/**
	 * Włączenie (tylko autor, z potwierdzeniem). Od tej chwili reguła reaguje na nowe zdarzenia.
	 *
	 * @param array<string, mixed> $input confirm: true
	 * @return array<string, mixed>
	 */
	public function enable(TenantContext $ctx, int $ruleId, array $input): array
	{
		$rule = $this->ownRule($ctx, $ruleId);
		$this->ownConnection($ctx);

		if (empty($input['confirm'])) {
			throw UbrException::invalid('Potwierdź włączenie reguły (najpierw wykonaj podgląd).');
		}

		if ((int) $rule['connection_id'] !== (int) $this->repo->connection($ctx->organizationId, $ctx->userId)['id']) {
			throw UbrException::invalid('Reguła wskazuje poprzednie połączenie ClickUp — wybierz listę ponownie.');
		}

		$this->repo->updateRule((int) $rule['id'], ['enabled' => 1, 'activated_at' => Db::now()]);

		return $this->present($ctx, $this->repo->rule($ctx->organizationId, (int) $rule['id']));
	}

	/**
	 * Wyłączenie: autor albo właściciel / administrator organizacji (np. gdy autor jest niedostępny).
	 *
	 * @return array<string, mixed>
	 */
	public function disable(TenantContext $ctx, int $ruleId): array
	{
		$rule = $this->manageableRule($ctx, $ruleId);
		$this->repo->updateRule((int) $rule['id'], ['enabled' => 0]);

		return $this->present($ctx, $this->repo->rule($ctx->organizationId, (int) $rule['id']));
	}

	/**
	 * Eksport istniejących firm spełniających warunki — osobna, świadoma decyzja; maks. limit dzienny reguły.
	 *
	 * @param array<string, mixed> $input confirm: true
	 * @return array<string, mixed>
	 */
	public function backfill(TenantContext $ctx, int $ruleId, array $input): array
	{
		$rule = $this->ownRule($ctx, $ruleId);
		$this->ownConnection($ctx);

		if (! (int) $rule['enabled']) {
			throw UbrException::invalid('Najpierw włącz regułę.');
		}

		if (empty($input['confirm'])) {
			throw UbrException::invalid('Eksport istniejących firm wymaga osobnego potwierdzenia.');
		}

		$preview = $this->preview($ctx, $ruleId);
		$left = max(0, (int) $rule['daily_limit'] - $this->repo->countNewTasksSince($ctx->organizationId, gmdate('Y-m-d 00:00:00'), (int) $rule['id']));
		$queued = 0;

		foreach ($this->candidates($ctx->organizationId, (array) $rule['conditions'])['ids'] as $companyId) {
			if ($queued >= $left) {
				break;
			}

			if ($this->repo->exportFor($ctx->organizationId, $companyId, (string) $rule['list_id']) || ! $this->matches($ctx, $companyId, (array) $rule['conditions'])['match']) {
				continue;
			}

			if ($this->enqueue($rule, $companyId, 'backfill', 'backfill')) {
				$queued++;
			}
		}

		$this->repo->updateRule((int) $rule['id'], ['backfill_confirmed_at' => Db::now()]);

		if ($queued) {
			app(ClickUpRunner::class)->kick();
		}

		return [
			'queued' => $queued,
			'remaining' => max(0, $preview['would_export'] - $queued),
			'message' => sprintf('Zlecono sprawdzenie i eksport %d firm (limit dzienny reguły: %d).', $queued, (int) $rule['daily_limit']) . ($preview['would_export'] > $queued ? ' Pozostałe można wyeksportować w kolejnych dniach.' : ''),
		];
	}

	/* ------------------------------ zdarzenia i wykonanie ------------------------------ */

	/**
	 * Zdarzenie dotyczące firmy → zadania 'evaluate' dla włączonych reguł organizacji, które ją widzą.
	 * Nic nie jest wysyłane do ClickUp w tym miejscu (tylko kolejka).
	 */
	public function trigger(int $companyId, string $reason, ?int $organizationId = null): int
	{
		if (! in_array($reason, self::TRIGGERS, true)) {
			return 0;
		}

		try {
			$rules = $this->repo->enabledRules($organizationId);
		} catch (Throwable) {
			return 0; // tabela niezmigrowana — nie blokujemy głównego procesu
		}

		$queued = 0;

		foreach ($rules as $rule) {
			if (! $rule['activated_at'] || ! $this->companies->isVisibleTo($companyId, (int) $rule['organization_id'])) {
				continue;
			}

			if ($this->enqueue($rule, $companyId, 'event', $reason)) {
				$queued++;
			}
		}

		if ($queued) {
			app(ClickUpRunner::class)->kick();
		}

		return $queued;
	}

	/**
	 * Wykonanie zadania 'evaluate' (z kolejki).
	 *
	 * @param array<string, mixed> $job
	 * @return array{status: string, message: string}
	 */
	public function evaluate(array $job): array
	{
		$orgId = (int) $job['organization_id'];
		$companyId = (int) $job['company_id'];
		$rule = $job['rule_id'] ? $this->repo->rule($orgId, (int) $job['rule_id']) : null;

		if (! $rule || ! (int) $rule['enabled'] || ! $rule['activated_at']) {
			return ['status' => 'cancelled', 'message' => 'Reguła wyłączona lub usunięta.'];
		}

		if ($job['trigger_type'] === 'backfill' && ! $rule['backfill_confirmed_at']) {
			return ['status' => 'cancelled', 'message' => 'Brak potwierdzenia eksportu istniejących firm.'];
		}

		// Tylko połączenie autora reguły w tej organizacji.
		$connection = $this->repo->connectionById((int) $rule['connection_id']);

		if (! $connection || (int) $connection['organization_id'] !== $orgId || (int) $connection['user_id'] !== (int) $rule['created_by'] || $connection['status'] !== 'connected' || ! $this->oauth->token($connection)) {
			$this->repo->incrementRule((int) $rule['id'], 'error_count');

			return ['status' => 'failed', 'message' => 'Połączenie ClickUp autora reguły jest nieaktywne — połącz ponownie.'];
		}

		$ctx = $this->contextFor($orgId, (int) $rule['created_by']);

		if (! $ctx || ! $ctx->can('clickup.export')) {
			return ['status' => 'cancelled', 'message' => 'Autor reguły nie należy już do organizacji.'];
		}

		if (! $this->companies->isVisibleTo($companyId, $orgId)) {
			return ['status' => 'skipped', 'message' => 'Firma niedostępna dla organizacji.'];
		}

		$prospect = $this->pipeline->prospectForCompany($orgId, $companyId);

		if ($prospect && $prospect['archived_at']) {
			return ['status' => 'skipped', 'message' => 'Firma zarchiwizowana w Prospects.'];
		}

		$check = $this->matches($ctx, $companyId, (array) $rule['conditions']);

		if (! $check['match']) {
			return ['status' => 'skipped', 'message' => 'Nie spełnia warunków: ' . implode('; ', $check['failed'])];
		}

		$existing = $this->repo->exportFor($orgId, $companyId, (string) $rule['list_id']);

		if ($existing) {
			return ['status' => 'skipped_duplicate', 'message' => 'Firma już wyeksportowana do tej listy (status: ' . $existing['export_status'] . ').'];
		}

		$today = gmdate('Y-m-d 00:00:00');

		if ($this->repo->countNewTasksSince($orgId, $today, (int) $rule['id']) >= (int) $rule['daily_limit']) {
			return ['status' => 'skipped', 'message' => sprintf('Dzienny limit reguły (%d) wyczerpany.', (int) $rule['daily_limit'])];
		}

		if ($this->repo->countNewTasksSince($orgId, $today) >= ClickUpExportService::dailyLimit($orgId)) {
			return ['status' => 'skipped', 'message' => 'Dzienny limit nowych zadań ClickUp organizacji wyczerpany.'];
		}

		$exportId = $this->repo->reserveExport([
			'organization_id' => $orgId, 'company_id' => $companyId, 'prospect_id' => $prospect ? (int) $prospect['id'] : null,
			'user_id' => (int) $rule['created_by'], 'connection_id' => (int) $connection['id'], 'workspace_id' => (string) $rule['workspace_id'],
			'list_id' => (string) $rule['list_id'], 'list_name' => mb_substr((string) $rule['list_name'], 0, 255), 'variant' => 0, 'rule_id' => (int) $rule['id'],
		]);

		if ($exportId === null) {
			return ['status' => 'skipped_duplicate', 'message' => 'Eksport tej firmy został już zlecony.'];
		}

		$this->repo->createJob([
			'organization_id' => $orgId, 'user_id' => (int) $rule['created_by'], 'connection_id' => (int) $connection['id'], 'export_id' => $exportId,
			'rule_id' => (int) $rule['id'], 'company_id' => $companyId, 'operation' => 'create', 'trigger_type' => 'rule',
		]);
		$this->repo->updateRule((int) $rule['id'], ['last_run_at' => Db::now()]);

		return ['status' => 'success', 'message' => 'Warunki spełnione — zlecono eksport do listy „' . ($rule['list_name'] ?: $rule['list_id']) . '”.'];
	}

	/**
	 * Sprawdzenie warunków dla firmy w kontekście organizacji (AND).
	 *
	 * @param array<string, mixed> $conditions
	 * @return array{match: bool, failed: list<string>, name: string, domain: ?string}
	 */
	public function matches(TenantContext $ctx, int $companyId, array $conditions): array
	{
		$c = $this->companies->find($companyId);

		if (! $c) {
			return ['match' => false, 'failed' => ['firma nie istnieje'], 'name' => '', 'domain' => null];
		}

		$failed = [];

		if (! empty($conditions['verified']) && ($c['verification_status'] ?? '') !== 'verified') {
			$failed[] = 'firma niezweryfikowana';
		}

		if (! empty($conditions['domain_confirmed']) && ($c['domain_status'] ?? '') !== 'confirmed') {
			$failed[] = 'domena niepotwierdzona';
		}

		if (isset($conditions['min_relevance'])) {
			$best = $this->results->bestRelevanceForCompany($companyId, $ctx->organizationId);

			if (! $best || $best['relevance_score'] === null || (int) $best['relevance_score'] < (int) $conditions['min_relevance']) {
				$failed[] = 'Relevance < ' . (int) $conditions['min_relevance'];
			}
		}

		if (isset($conditions['min_opportunity']) && ($c['opportunity_score'] === null || (int) $c['opportunity_score'] < (int) $conditions['min_opportunity'])) {
			$failed[] = 'Opportunity < ' . (int) $conditions['min_opportunity'];
		}

		$needsContacts = isset($conditions['min_contact_score']) || isset($conditions['min_contacts']);

		if ($needsContacts) {
			// Widok organizacji: odrzuceni / wykluczeni / nieaktualni nie są liczeni.
			try {
				$view = app(ContactResearchService::class)->forCompany($ctx, $companyId);
				$top = (array) ($view['top'] ?? []);
			} catch (Throwable) {
				$top = [];
			}

			if (isset($conditions['min_contact_score'])) {
				$best = $top ? max(array_map(fn ($t) => (int) ($t['score'] ?? 0), $top)) : null;

				if ($best === null || $best < (int) $conditions['min_contact_score']) {
					$failed[] = 'Contact Priority < ' . (int) $conditions['min_contact_score'];
				}
			}

			if (isset($conditions['min_contacts']) && count($top) < (int) $conditions['min_contacts']) {
				$failed[] = 'mniej niż ' . (int) $conditions['min_contacts'] . ' decydentów';
			}
		}

		if (! empty($conditions['prospect_statuses']) || ! empty($conditions['priorities'])) {
			$p = $this->pipeline->prospectForCompany($ctx->organizationId, $companyId);

			if (! $p || $p['archived_at']) {
				$failed[] = 'firma nie jest w Prospects';
			} else {
				if (! empty($conditions['prospect_statuses']) && ! in_array($p['status'], (array) $conditions['prospect_statuses'], true)) {
					$failed[] = 'status pipeline: ' . PipelineCatalog::statusLabel((string) $p['status']);
				}

				if (! empty($conditions['priorities']) && ! in_array($p['priority'], (array) $conditions['priorities'], true)) {
					$failed[] = 'priorytet: ' . (PipelineCatalog::PRIORITIES[$p['priority']] ?? $p['priority']);
				}
			}
		}

		return ['match' => $failed === [], 'failed' => $failed, 'name' => (string) $c['name'], 'domain' => $c['domain'] ?: null];
	}

	/* ------------------------------ pomocnicze ------------------------------ */

	/**
	 * Walidacja warunków (co najmniej jeden).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function conditions(array $input): array
	{
		$out = [];
		$bool = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);

		foreach (['verified', 'domain_confirmed'] as $k) {
			if (isset($input[$k]) && $bool($input[$k])) {
				$out[$k] = true;
			}
		}

		foreach (['min_relevance', 'min_opportunity', 'min_contact_score'] as $k) {
			if (isset($input[$k]) && $input[$k] !== '' && $input[$k] !== null) {
				if (! is_numeric($input[$k]) || (int) $input[$k] < 0 || (int) $input[$k] > 100) {
					throw UbrException::invalid('Próg musi być liczbą 0–100.');
				}

				$out[$k] = (int) $input[$k];
			}
		}

		if (isset($input['min_contacts']) && $input['min_contacts'] !== '' && $input['min_contacts'] !== null) {
			if (! in_array((int) $input['min_contacts'], [1, 2], true)) {
				throw UbrException::invalid('Minimalna liczba decydentów: 1 albo 2.');
			}

			$out['min_contacts'] = (int) $input['min_contacts'];
		}

		if (! empty($input['prospect_statuses'])) {
			$statuses = array_values(array_unique(array_map('sanitize_key', (array) $input['prospect_statuses'])));

			foreach ($statuses as $s) {
				if (! PipelineCatalog::isStatus($s)) {
					throw UbrException::invalid('Nieznany status pipeline.');
				}
			}

			$out['prospect_statuses'] = $statuses;
		}

		if (! empty($input['priorities'])) {
			$priorities = array_values(array_unique(array_map('sanitize_key', (array) $input['priorities'])));

			foreach ($priorities as $p) {
				if (! isset(PipelineCatalog::PRIORITIES[$p])) {
					throw UbrException::invalid('Nieznany priorytet.');
				}
			}

			$out['priorities'] = $priorities;
		}

		if ($out === []) {
			throw UbrException::invalid('Reguła musi mieć co najmniej jeden warunek.');
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function conditionCatalog(): array
	{
		return [
			'verified' => 'Firma zweryfikowana w rejestrze (VERIFIED)',
			'domain_confirmed' => 'Domena potwierdzona (CONFIRMED)',
			'min_relevance' => 'Relevance Score ≥',
			'min_opportunity' => 'Opportunity Score ≥',
			'min_contact_score' => 'Contact Priority Score (Top 1) ≥',
			'min_contacts' => 'Min. liczba decydentów (1 lub 2)',
			'prospect_statuses' => 'Status pipeline',
			'priorities' => 'Priorytet',
			'statuses' => array_map(fn ($s) => $s['label'], PipelineCatalog::STATUSES),
			'priority_options' => PipelineCatalog::PRIORITIES,
		];
	}

	/**
	 * Kandydaci (firmy widoczne dla organizacji), wstępnie odfiltrowani w SQL po danych globalnych.
	 *
	 * @param array<string, mixed> $conditions
	 * @return array{ids: list<int>, truncated: bool}
	 */
	private function candidates(int $orgId, array $conditions): array
	{
		$c = $this->db->table(Tables::COMPANIES);
		$r = $this->db->table(Tables::RESULTS);
		$p = $this->db->table(Tables::PROSPECTS);
		$where = [];

		if (! empty($conditions['verified'])) {
			$where[] = "c.verification_status = 'verified'";
		}

		if (! empty($conditions['domain_confirmed'])) {
			$where[] = "c.domain_status = 'confirmed'";
		}

		if (isset($conditions['min_opportunity'])) {
			$where[] = 'c.opportunity_score >= ' . (int) $conditions['min_opportunity'];
		}

		if (isset($conditions['min_contact_score'])) {
			$where[] = 'c.top_contact_score IS NOT NULL';
		}

		if (isset($conditions['min_contacts'])) {
			$where[] = 'c.contacts_count >= ' . (int) $conditions['min_contacts'];
		}

		$sql = "SELECT c.id FROM {$c} c WHERE c.id IN (SELECT company_id FROM {$r} WHERE organization_id = %d UNION SELECT company_id FROM {$p} WHERE organization_id = %d AND archived_at IS NULL)"
			. ($where ? ' AND ' . implode(' AND ', $where) : '')
			. ' ORDER BY c.id DESC LIMIT ' . (self::PREVIEW_CANDIDATES + 1);
		$ids = array_map(fn ($row) => (int) $row['id'], $this->db->rows($sql, $orgId, $orgId));

		return ['ids' => array_slice($ids, 0, self::PREVIEW_CANDIDATES), 'truncated' => count($ids) > self::PREVIEW_CANDIDATES];
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private function enqueue(array $rule, int $companyId, string $trigger, string $reason): bool
	{
		$orgId = (int) $rule['organization_id'];

		if ($this->repo->hasActiveJob($orgId, $companyId, 'evaluate', null, (int) $rule['id'])) {
			return false;
		}

		// Już wyeksportowana do listy reguły — nie zaśmiecamy kolejki.
		if ($this->repo->exportFor($orgId, $companyId, (string) $rule['list_id'])) {
			return false;
		}

		$this->repo->createJob([
			'organization_id' => $orgId, 'user_id' => (int) $rule['created_by'], 'connection_id' => (int) $rule['connection_id'],
			'rule_id' => (int) $rule['id'], 'company_id' => $companyId, 'operation' => 'evaluate', 'trigger_type' => $trigger,
			'result' => ['reason' => $reason],
		]);

		return true;
	}

	/**
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	private function ownConnection(TenantContext $ctx): array
	{
		$connection = $this->repo->connection($ctx->organizationId, $ctx->userId);

		if (! $connection || $connection['status'] !== 'connected') {
			throw UbrException::invalid('Reguły wykonują się przez Twoje połączenie ClickUp — najpierw połącz konto.');
		}

		$token = $this->oauth->token($connection) ?? throw UbrException::invalid('Połączenie ClickUp wymaga ponownej autoryzacji.');

		return [$connection, $token];
	}

	/**
	 * Lista docelowa: z danych wejściowych (musi być w autoryzowanym Workspace) albo domyślna z połączenia.
	 *
	 * @param array<string, mixed> $connection
	 * @param array<string, mixed> $input
	 * @return array{workspace_id: string, list_id: string, list_name: string}
	 */
	private function destination(array $connection, array $input): array
	{
		$dest = (array) $connection['destination'];
		$clean = fn ($v) => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $v) ?? '';
		$listId = $clean($input['list_id'] ?? '');

		if ($listId === '' || $listId === (string) ($dest['list_id'] ?? '')) {
			if (empty($dest['list_id'])) {
				throw UbrException::invalid('Wybierz listę ClickUp dla reguły (albo ustaw domyślne miejsce eksportu).');
			}

			return ['workspace_id' => (string) $dest['team_id'], 'list_id' => (string) $dest['list_id'], 'list_name' => mb_substr((string) ($dest['list_name'] ?? ''), 0, 255)];
		}

		$teamId = $clean($input['workspace_id'] ?? $input['team_id'] ?? '');

		if ($teamId === '' || ! in_array($teamId, array_map('strval', array_column((array) $connection['authorized_teams'], 'id')), true)) {
			throw UbrException::invalid('Ten Workspace nie jest autoryzowany dla Twojego konta ClickUp.');
		}

		$token = $this->oauth->token($connection) ?? throw UbrException::invalid('Połączenie ClickUp wymaga ponownej autoryzacji.');
		$res = app(\App\Ubr\Infrastructure\ClickUp\ClickUpClient::class)->listFields($token, $listId);

		if ($res['error']) {
			throw UbrException::invalid('Lista niedostępna: ' . $res['error']->getMessage());
		}

		return ['workspace_id' => $teamId, 'list_id' => $listId, 'list_name' => mb_substr(sanitize_text_field((string) ($input['list_name'] ?? '')), 0, 255)];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function ownRule(TenantContext $ctx, int $ruleId): array
	{
		if (! $ctx->can('clickup.rules')) {
			throw UbrException::forbidden();
		}

		$rule = $this->repo->rule($ctx->organizationId, $ruleId) ?? throw UbrException::notFound('Nie znaleziono reguły.');

		if ((int) $rule['created_by'] !== $ctx->userId) {
			throw UbrException::forbidden('Regułę może edytować i włączać tylko jej autor (działa przez jego połączenie ClickUp).');
		}

		return $rule;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function manageableRule(TenantContext $ctx, int $ruleId): array
	{
		$rule = $this->repo->rule($ctx->organizationId, $ruleId) ?? throw UbrException::notFound('Nie znaleziono reguły.');

		if ((int) $rule['created_by'] !== $ctx->userId && ! $ctx->can('clickup.rules')) {
			throw UbrException::forbidden();
		}

		return $rule;
	}

	private function assertView(TenantContext $ctx): void
	{
		if (! $ctx->can('clickup.export')) {
			throw UbrException::forbidden();
		}
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function name(array $input): string
	{
		$name = mb_substr(trim(sanitize_text_field((string) ($input['name'] ?? ''))), 0, 190);

		if ($name === '') {
			throw UbrException::invalid('Podaj nazwę reguły.');
		}

		return $name;
	}

	private function dailyLimit(mixed $value): int
	{
		$v = (int) $value;

		if ($v < 1 || $v > self::MAX_DAILY_LIMIT) {
			throw UbrException::invalid(sprintf('Limit dzienny reguły: 1–%d.', self::MAX_DAILY_LIMIT));
		}

		return $v;
	}

	private function contextFor(int $orgId, int $userId): ?TenantContext
	{
		$role = app(MemberRepository::class)->role($orgId, $userId);

		if (! $role) {
			return null;
		}

		return new TenantContext($userId, $orgId, $role, app(OrganizationRepository::class)->find($orgId) ?? ['id' => $orgId]);
	}

	/**
	 * @param array<string, mixed>|null $rule
	 * @return array<string, mixed>
	 */
	private function present(TenantContext $ctx, ?array $rule): array
	{
		if (! $rule) {
			return [];
		}

		$author = app(PipelineService::class)->userBrief((int) $rule['created_by']);
		$connection = $this->repo->connectionById((int) $rule['connection_id']);
		$connectionOk = $connection && (int) $connection['user_id'] === (int) $rule['created_by'] && $connection['status'] === 'connected';
		$today = gmdate('Y-m-d 00:00:00');

		return [
			'id' => (int) $rule['id'],
			'name' => (string) $rule['name'],
			'conditions' => (array) $rule['conditions'],
			'conditions_text' => $this->describe((array) $rule['conditions']),
			'list_id' => (string) $rule['list_id'],
			'list_name' => (string) ($rule['list_name'] ?: $rule['list_id']),
			'workspace_id' => (string) $rule['workspace_id'],
			'enabled' => (bool) (int) $rule['enabled'],
			'daily_limit' => (int) $rule['daily_limit'],
			'today_count' => $this->repo->countNewTasksSince($ctx->organizationId, $today, (int) $rule['id']),
			'activated_at' => $rule['activated_at'],
			'backfill_confirmed_at' => $rule['backfill_confirmed_at'],
			'last_run_at' => $rule['last_run_at'],
			'exported_count' => (int) $rule['exported_count'],
			'error_count' => (int) $rule['error_count'],
			'author' => $author,
			'is_author' => (int) $rule['created_by'] === $ctx->userId,
			'connection_ok' => $connectionOk,
			'can_edit' => (int) $rule['created_by'] === $ctx->userId && $ctx->can('clickup.rules'),
			'can_disable' => (int) $rule['created_by'] === $ctx->userId || $ctx->can('clickup.rules'),
		];
	}

	/**
	 * @param array<string, mixed> $c
	 * @return list<string>
	 */
	private function describe(array $c): array
	{
		$out = [];

		if (! empty($c['verified'])) {
			$out[] = 'zweryfikowana (VERIFIED)';
		}

		if (! empty($c['domain_confirmed'])) {
			$out[] = 'domena potwierdzona';
		}

		foreach (['min_relevance' => 'Relevance', 'min_opportunity' => 'Opportunity', 'min_contact_score' => 'Contact Priority'] as $k => $label) {
			if (isset($c[$k])) {
				$out[] = $label . ' ≥ ' . (int) $c[$k];
			}
		}

		if (isset($c['min_contacts'])) {
			$out[] = 'min. ' . (int) $c['min_contacts'] . ' decydent(ów)';
		}

		if (! empty($c['prospect_statuses'])) {
			$out[] = 'status: ' . implode(' / ', array_map(fn ($s) => PipelineCatalog::statusLabel($s), (array) $c['prospect_statuses']));
		}

		if (! empty($c['priorities'])) {
			$out[] = 'priorytet: ' . implode(' / ', array_map(fn ($p) => PipelineCatalog::PRIORITIES[$p] ?? $p, (array) $c['priorities']));
		}

		return $out;
	}
}
