<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ProspectRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Support\UbrException;

/**
 * Prospekty organizacji i szczegóły firm (widoczność zawężona do organizacji).
 */
class ProspectService
{
	public function __construct(
		private ProspectRepository $prospects,
		private CompanyRepository $companies,
		private ResultRepository $results,
	) {
	}

	/**
	 * @return array{id: int, created: bool}
	 */
	public function save(TenantContext $ctx, int $companyId, ?int $searchId = null): array
	{
		$this->assert($ctx, 'prospects.manage');

		// Organizacja może zapisać tylko firmę, którą sama odkryła (lub już ma) — bez enumeracji globalnej bazy.
		if (! $this->companies->find($companyId) || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		// search_id tylko z wyszukiwań tej organizacji (inaczej zapis cudzego identyfikatora).
		if ($searchId !== null && ! app(\App\Ubr\Infrastructure\Repositories\SearchRepository::class)->findForOrganization($searchId, $ctx->organizationId)) {
			$searchId = null;
		}

		$result = $this->prospects->add($ctx->organizationId, $companyId, $ctx->userId, $searchId);
		$pipeline = app(\App\Ubr\Infrastructure\Repositories\PipelineRepository::class);

		if ($result['created']) {
			$pipeline->event($ctx->organizationId, $result['id'], $ctx->userId, 'created', null, 'new');
			$pipeline->updateProspect($ctx->organizationId, $result['id'], ['status_changed_at' => \App\Ubr\Infrastructure\Database\Db::now()]);
			// Domyślny właściciel = osoba, która dodała prospekt — atomowo, tylko gdy nikt go w międzyczasie nie przejął.
			app(OwnershipService::class)->assignIfFree($ctx, (int) $result['id']);
		} elseif ($result['restored'] ?? false) {
			$pipeline->event($ctx->organizationId, $result['id'], $ctx->userId, 'restored', null, null);
		}

		if ($result['created'] || ($result['restored'] ?? false)) {
			do_action('ubr/company/changed', $companyId, 'prospect_added', $ctx->organizationId);
		}

		app(PipelineService::class)->forgetStats($ctx->organizationId);

		return $result;
	}

	public function remove(TenantContext $ctx, int $prospectId): void
	{
		$this->assert($ctx, 'prospects.manage');

		// Jedna ścieżka archiwizacji (jak POST /prospects/{id}/archive): zdarzenie pipeline, odświeżenie liczników
		// Dashboard / Action Center i przeliczenie Lead Intelligence. Globalna firma, analizy i notatki zostają.
		app(PipelineService::class)->archive($ctx, $prospectId);
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public function list(TenantContext $ctx, array $query): array
	{
		$this->assert($ctx, 'prospects.view');
		$page = max(1, (int) ($query['page'] ?? 1));
		$perPage = max(5, min(100, (int) ($query['per_page'] ?? 25)));

		$data = $this->prospects->paginate(
			$ctx->organizationId,
			[
				'search' => sanitize_text_field((string) ($query['search'] ?? '')),
				'region' => sanitize_text_field((string) ($query['region'] ?? '')),
				'industry' => sanitize_text_field((string) ($query['industry'] ?? '')),
				'has_domain' => sanitize_key((string) ($query['has_domain'] ?? '')),
				'verification' => sanitize_key((string) ($query['verification'] ?? '')),
				'domain_status' => sanitize_key((string) ($query['domain_status'] ?? '')),
				'min_relevance' => (int) ($query['min_relevance'] ?? 0),
				'min_opportunity' => (int) ($query['min_opportunity'] ?? 0),
				'analysis' => sanitize_key((string) ($query['analysis'] ?? '')),
				'has_contact' => sanitize_key((string) ($query['has_contact'] ?? '')),
				'pipeline_status' => sanitize_key((string) ($query['pipeline_status'] ?? '')),
				'priority' => sanitize_key((string) ($query['priority'] ?? '')),
				'owner' => sanitize_key((string) ($query['owner'] ?? '')),
				'archived' => sanitize_key((string) ($query['archived'] ?? '')),
				'min_contact_score' => (int) ($query['min_contact_score'] ?? 0),
				'contact_confidence' => sanitize_key((string) ($query['contact_confidence'] ?? '')),
				'contact_role' => sanitize_key((string) ($query['contact_role'] ?? '')),
				'min_contacts' => (int) ($query['min_contacts'] ?? 0),
			] + \App\Ubr\Infrastructure\Repositories\LeadFilters::fromQuery($query) + \App\Ubr\Infrastructure\Repositories\CallFilters::fromQuery($query) + ['user_id' => $ctx->userId],
			sanitize_key((string) ($query['sort'] ?? 'created_at')),
			sanitize_key((string) ($query['dir'] ?? 'desc')),
			$page,
			$perPage
		);

		if (array_filter($data['items'], fn ($r) => ($r['li_status'] ?? null) === null || ! empty($r['li_stale']))) {
			app(LeadIntelligenceRunner::class)->kick();
		}

		return [
			'items' => app(CallPresenter::class)->attach($ctx, app(ContactResearchService::class)->applyOrganizationView($ctx, array_map(fn ($r) => CompanyPresenter::row($r), $data['items'])), $data['items']),
			'total' => $data['total'],
			'page' => $page,
			'per_page' => $perPage,
			'pages' => (int) ceil($data['total'] / $perPage),
			'regions' => $this->prospects->regions($ctx->organizationId),
			'members' => app(PipelineService::class)->memberOptions($ctx),
			'statuses' => array_map(fn ($k, $d) => ['key' => $k, 'label' => $d['label']], array_keys(\App\Ubr\Domain\Pipeline\PipelineCatalog::statuses($ctx->organizationId)), \App\Ubr\Domain\Pipeline\PipelineCatalog::statuses($ctx->organizationId)),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function company(TenantContext $ctx, int $companyId): array
	{
		$this->assert($ctx, 'companies.view');
		$company = $this->companies->find($companyId);

		if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$data = CompanyPresenter::row($company + ['prospect_id' => $this->prospects->idForCompany($ctx->organizationId, $companyId, false)]);
		$verification = json_decode((string) ($company['verification_summary'] ?? ''), true) ?: [];
		$domainSummary = json_decode((string) ($company['domain_summary'] ?? ''), true) ?: [];
		$queue = app(\App\Ubr\Infrastructure\Repositories\VerificationQueueRepository::class)->forCompany($companyId);

		// Najlepsze dopasowanie firmy do wyszukiwań TEJ organizacji (trafność jest per wyszukiwanie).
		$best = $this->results->bestRelevanceForCompany($companyId, $ctx->organizationId);

		if ($best) {
			$data['relevance'] = $best['relevance_score'] !== null ? (int) $best['relevance_score'] : null;
			$data['relevance_details'] = CompanyPresenter::relevance($best['relevance']);
			$data['relevance_search'] = ['id' => (int) $best['search_id'], 'label' => $best['label']];
		}

		$data['verification'] += [
			'reasons' => array_values((array) ($verification['reasons'] ?? [])),
			'discrepancies' => array_values((array) ($verification['discrepancies'] ?? [])),
			'errors' => array_values((array) ($verification['errors'] ?? [])),
			'records' => array_map(fn ($r) => array_intersect_key((array) $r, array_flip(['source', 'name', 'nip', 'krs', 'regon', 'legal_form', 'status', 'status_detail', 'city', 'region', 'pkd_main', 'pkd_description', 'source_url', 'origin', 'status_vat'])), array_values((array) ($verification['records'] ?? []))),
			'identifiers_on_website' => array_values((array) ($verification['identifiers_on_website'] ?? [])),
			'queue' => $queue ? ['status' => $queue['status'], 'attempts' => (int) $queue['attempts'], 'next_attempt_at' => gmdate('c', strtotime($queue['next_attempt_at'] . ' UTC')), 'last_error' => $queue['last_error']] : null,
			'can_reverify' => $ctx->can('companies.verify'),
		];
		$data['domain_status'] += [
			'reasons' => array_values((array) ($domainSummary['reasons'] ?? [])),
			'signals' => array_intersect_key((array) ($domainSummary['signals'] ?? []), array_flip(['http_code', 'final_url', 'final_domain', 'redirected_domain', 'final_domain_type', 'title', 'parked', 'placeholder', 'name_match', 'identifiers', 'emails_same_domain', 'address_cities', 'pages', 'error'])),
		];

		return $data + [
			'registry_name' => $company['registry_name'] ?? null,
			'legal_form' => $company['legal_form'] ?? null,
			'pkd_main' => $company['pkd_main'] ?? null,
			'pkd_description' => $company['pkd_description'] ?? null,
			'checks' => app(\App\Ubr\Infrastructure\Repositories\CompanyCheckRepository::class)->forCompany($companyId, $ctx->organizationId, 10),
			'description' => $company['description'],
			'street' => $company['street'],
			'postal_code' => $company['postal_code'],
			'latitude' => $company['latitude'] !== null ? (float) $company['latitude'] : null,
			'longitude' => $company['longitude'] !== null ? (float) $company['longitude'] : null,
			'regon' => $company['regon'],
			'krs' => $company['krs'],
			'domain_source' => $company['domain_source'],
			'website_http_code' => $company['website_http_code'] !== null ? (int) $company['website_http_code'] : null,
			'website_final_url' => $company['website_final_url'],
			'website_title' => $company['website_title'],
			'website_checked_at' => $company['website_checked_at'] ? gmdate('c', strtotime($company['website_checked_at'] . ' UTC')) : null,
			'first_seen_at' => gmdate('c', strtotime($company['first_seen_at'] . ' UTC')),
			'sources' => array_map(fn ($s) => $s + ['provider_label' => CompanyPresenter::PROVIDER_LABELS[$s['provider']] ?? $s['provider']], $this->companies->externalIds($companyId)),
			'evidence' => array_map(fn ($e) => $e + ['provider_label' => CompanyPresenter::PROVIDER_LABELS[$e['provider']] ?? $e['provider']], $this->companies->evidence($companyId)),
			'findings' => $this->results->findingsForCompany($companyId, $ctx->organizationId),
		];
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}
}
