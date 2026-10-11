<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\CallFilters;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Infrastructure\Repositories\ProspectRepository;
use App\Ubr\Support\UbrException;

/**
 * Company Ownership (Cold Calling): „Przejmij firmę”, przekazanie, wynik rozmowy z listy.
 *
 * Przejęcie jest atomowe w bazie: INSERT IGNORE prospektu (UNIQUE organizacja + firma) i warunkowy UPDATE
 * „owner = ja WHERE owner IS NULL OR owner = ja” — z dwóch równoczesnych kliknięć wygrywa dokładnie jedno; drugie dostaje
 * 409 z nazwą opiekuna. Firmy innego opiekuna nie da się przejąć przypadkiem — przekazanie tylko przez uprawnienie
 * `prospects.transfer` (PipelineService::update). Wszystko w granicach organizacji (tenant isolation).
 */
class OwnershipService
{
	public function __construct(
		private Db $db,
		private CompanyRepository $companies,
		private ProspectRepository $prospects,
		private PipelineRepository $pipeline,
	) {
	}

	/**
	 * @return array{prospect_id: int, claimed: bool, created: bool, restored: bool, owner_user_id: int}
	 */
	public function claim(TenantContext $ctx, int $companyId, ?int $searchId = null): array
	{
		if (! $ctx->can('prospects.manage')) {
			throw UbrException::forbidden();
		}

		if (! $this->companies->find($companyId) || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$table = $this->db->table(Tables::PROSPECTS);
		$existing = $this->db->row("SELECT id, owner_user_id, archived_at, do_not_contact FROM {$table} WHERE organization_id = %d AND company_id = %d", $ctx->organizationId, $companyId);

		if ($existing && ! $existing['archived_at'] && (int) $existing['do_not_contact'] === 1) {
			throw new UbrException('Firma ma zakaz kontaktu — nie można jej przejąć do kontaktu.', 'ubr_do_not_contact', 409);
		}

		// Zarchiwizowany prospekt nie ma aktywnego opiekuna: zwolnienie przed przywróceniem (historia zostaje).
		if ($existing && $existing['archived_at']) {
			$this->db->execute("UPDATE {$table} SET owner_user_id = NULL WHERE id = %d AND organization_id = %d AND archived_at IS NOT NULL", (int) $existing['id'], $ctx->organizationId);
		}

		if ($searchId !== null && ! app(\App\Ubr\Infrastructure\Repositories\SearchRepository::class)->findForOrganization($searchId, $ctx->organizationId)) {
			$searchId = null;
		}

		$result = $this->prospects->add($ctx->organizationId, $companyId, $ctx->userId, $searchId);
		$id = (int) $result['id'];

		if ($result['created']) {
			$this->pipeline->event($ctx->organizationId, $id, $ctx->userId, 'created', null, 'new');
			$this->pipeline->updateProspect($ctx->organizationId, $id, ['status_changed_at' => Db::now()]);
		} elseif ($result['restored']) {
			$this->pipeline->event($ctx->organizationId, $id, $ctx->userId, 'restored', null, null);
		}

		$changed = $this->assignIfFree($ctx, $id);
		$owner = (int) $this->db->value("SELECT owner_user_id FROM {$table} WHERE id = %d AND organization_id = %d", $id, $ctx->organizationId);

		if ($owner !== $ctx->userId) {
			$user = $owner ? get_userdata($owner) : null;

			throw new UbrException(sprintf('Firmą opiekuje się już %s. Przekazanie wymaga uprawnień właściciela lub administratora organizacji.', $user ? $user->display_name : 'inny pracownik'), 'ubr_assigned_other', 409);
		}

		if ($changed) {
			$this->pipeline->event($ctx->organizationId, $id, $ctx->userId, 'owner', null, (string) $ctx->userId);
		}

		if ($result['created'] || $result['restored']) {
			do_action('ubr/company/changed', $companyId, 'prospect_added', $ctx->organizationId);
		}

		app(PipelineService::class)->forgetStats($ctx->organizationId);

		// Prompt 10.4.3 — przejęcie uruchamia w tle zadanie ClickUp i analizę decydenta (tylko przy faktycznym przejęciu;
		// ponowne kliknięcie nie dubluje zadań). Błąd automatyzacji nigdy nie cofa przejęcia.
		$automation = ($changed || $result['created'] || $result['restored']) ? $this->afterClaim($ctx, $companyId, $id) : null;

		return ['prospect_id' => $id, 'claimed' => $changed, 'created' => (bool) $result['created'], 'restored' => (bool) $result['restored'], 'owner_user_id' => $ctx->userId, 'automation' => $automation];
	}

	/**
	 * @return array{clickup: ?array<string, mixed>, research: ?array<string, mixed>}
	 */
	public function afterClaim(TenantContext $ctx, int $companyId, int $prospectId): array
	{
		$out = ['clickup' => null, 'research' => null];

		if (! apply_filters('ubr/claim/automation', true, $companyId, $ctx->organizationId)) {
			return $out;
		}

		$out['clickup'] = app(ClickUpExportService::class)->queueForClaim($ctx, $companyId, $prospectId);

		try {
			$out['research'] = app(ContactResearchService::class)->requestForClaim($ctx, $companyId);
		} catch (\Throwable $e) {
			error_log('[ubr] Claim research for company #' . $companyId . ' not queued: ' . $e->getMessage());
			$out['research'] = ['queued' => false, 'reason' => 'error', 'message' => 'Nie udało się zlecić analizy decydenta — użyj „Ponów wyszukiwanie decydenta”.'];
		}

		return $out;
	}

	/**
	 * Atomowe przypisanie do bieżącego użytkownika tylko, gdy prospekt nie ma opiekuna. Zwraca true, gdy zmieniono.
	 */
	public function assignIfFree(TenantContext $ctx, int $prospectId): bool
	{
		return $this->db->execute(
			"UPDATE {$this->db->table(Tables::PROSPECTS)} SET owner_user_id = %d, updated_at = %s WHERE id = %d AND organization_id = %d AND archived_at IS NULL AND owner_user_id IS NULL",
			$ctx->userId,
			Db::now(),
			$prospectId,
			$ctx->organizationId
		) > 0;
	}

	/**
	 * Przekazanie firmy innemu członkowi organizacji (reguły uprawnień w PipelineService::update).
	 *
	 * @return array<string, mixed>
	 */
	public function transfer(TenantContext $ctx, int $prospectId, ?int $userId): array
	{
		return app(PipelineService::class)->update($ctx, $prospectId, ['owner_user_id' => $userId]);
	}

	/**
	 * Wynik rozmowy zapisany z listy (świadomie — kliknięcie tel: niczego nie zapisuje). Wolna firma zostaje przejęta
	 * przez osobę, która dzwoniła (bez podwójnych telefonów); firmy innego opiekuna i z zakazem kontaktu — odmowa.
	 * Aktywność trafia do istniejącej historii (ubr_prospect_activities), bez równoległej historii.
	 *
	 * @return array<string, mixed>
	 */
	public function recordCallOutcome(TenantContext $ctx, int $companyId, string $outcome, string $note = '', ?int $contactId = null, ?string $occurredAt = null): array
	{
		if (! $ctx->can('pipeline.manage') || ! $ctx->can('prospects.manage')) {
			throw UbrException::forbidden();
		}

		$outcome = sanitize_key($outcome);

		if (! isset(PipelineCatalog::CALL_OUTCOMES[$outcome])) {
			throw UbrException::invalid('Wybierz wynik rozmowy.');
		}

		if (! $this->companies->find($companyId) || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		if ($this->readiness($ctx, $companyId) === CallReadiness::BLOCKED) {
			throw new UbrException('Kontakt z tą firmą jest zablokowany (zakaz kontaktu lub aktywna blokada).', 'ubr_do_not_contact', 409);
		}

		$claim = $this->claim($ctx, $companyId);
		$pipeline = app(PipelineService::class);
		$pipeline->addActivity($ctx, $claim['prospect_id'], ['type' => 'call', 'outcome' => $outcome, 'description' => $note, 'contact_id' => $contactId, 'occurred_at' => $occurredAt]);

		// Etap pipeline tylko „do przodu” z etapów początkowych (bez cofania i bez zamykania jako „Utracony” bez powodu).
		$status = (string) $this->db->value("SELECT status FROM {$this->db->table(Tables::PROSPECTS)} WHERE id = %d AND organization_id = %d", $claim['prospect_id'], $ctx->organizationId);
		$next = match (true) {
			$outcome === 'meeting_booked' && in_array($status, ['new', 'qualified', 'to_contact', 'contacted', 'follow_up'], true) => 'in_discussion',
			$outcome === 'call_back' && in_array($status, ['new', 'qualified', 'to_contact', 'contacted'], true) => 'follow_up',
			in_array($status, ['new', 'qualified', 'to_contact'], true) && $outcome !== 'wrong_number' => 'contacted',
			default => null,
		};

		return $next ? $pipeline->update($ctx, $claim['prospect_id'], ['status' => $next]) + ['claimed' => $claim['claimed']] : $pipeline->sales($ctx, $companyId) + ['claimed' => $claim['claimed']];
	}

	/**
	 * Gotowość firmy w organizacji — ta sama reguła SQL co na listach.
	 */
	public function readiness(TenantContext $ctx, int $companyId): string
	{
		$org = (string) (int) $ctx->organizationId;

		return (string) $this->db->value(
			'SELECT ' . CallFilters::readiness($org) . " FROM {$this->db->table(Tables::COMPANIES)} c
			LEFT JOIN {$this->db->table(Tables::PROSPECTS)} p ON p.company_id = c.id AND p.organization_id = {$org}"
			. \App\Ubr\Infrastructure\Repositories\LeadFilters::join('c.id', $org, $this->db->table(Tables::LEAD_INTELLIGENCE)) . '
			WHERE c.id = %d',
			$companyId
		);
	}
}
