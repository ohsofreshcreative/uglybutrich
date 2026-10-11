<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\ApolloContactService;
use App\Ubr\Services\ApolloPhoneService;
use App\Ubr\Services\CompanyPhoneService;
use App\Ubr\Services\DecisionPhoneService;
use App\Ubr\Services\OwnershipService;
use App\Ubr\Services\PipelineService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Cold Calling Workspace — REST: numery firmy, „Odblokuj telefon” (Apollo), przejęcie / przekazanie firmy, zakaz kontaktu,
 * wynik rozmowy. Organizacja zawsze z TenantResolver; identyfikatory z URL sprawdzane w serwisach (IDOR).
 */
class CallingController
{
	public function __construct(
		private TenantResolver $tenants,
		private CompanyPhoneService $phones,
		private ApolloPhoneService $apollo,
		private OwnershipService $ownership,
		private PipelineService $pipeline,
		private DecisionPhoneService $decisionPhones,
	) {
	}

	/** Prompt 10.2: telefony decydentów ze strony firmy (pobranie ograniczonych stron; `dry_run` — bez zapisu). */
	public function scanDecisionPhones(WP_REST_Request $request): array
	{
		return $this->decisionPhones->scan($this->tenants->require('contacts.run'), (int) $request['id'], (bool) $request->get_param('dry_run'));
	}

	/** Prompt 10.3: kandydaci Apollo firmy (tylko odczyt z bazy — bez zapytań do Apollo). */
	public function apolloCandidates(WP_REST_Request $request): array
	{
		return app(ApolloContactService::class)->candidates($this->tenants->require('contacts.view'), (int) $request['id']);
	}

	/** „Szukaj decydentów w Apollo” — zapytanie sieciowe tylko przy włączonej funkcji i `confirm` (zgoda użytkownika). */
	public function apolloSearch(WP_REST_Request $request): array
	{
		return app(ApolloContactService::class)->search($this->tenants->require('contacts.run'), (int) $request['id'], ! empty(((array) $request->get_json_params())['confirm']));
	}

	public function apolloDecide(WP_REST_Request $request): array
	{
		$p = (array) $request->get_json_params();

		return app(ApolloContactService::class)->decide($this->tenants->require('contacts.manage'), sanitize_key((string) ($p['action'] ?? '')), (int) $request['id'], 0, 0, (string) ($p['note'] ?? ''));
	}

	public function apolloLink(WP_REST_Request $request): array
	{
		$p = (array) $request->get_json_params();

		return app(ApolloContactService::class)->decide($this->tenants->require('contacts.manage'), 'link', 0, (int) $request['id'], (int) ($p['contact_id'] ?? 0), (string) ($p['note'] ?? ''));
	}

	public function apolloHistory(WP_REST_Request $request): array
	{
		return ['history' => app(ApolloContactService::class)->historyFor($this->tenants->require('contacts.view'), (int) $request['id'])];
	}

	public function contactPhoneLinks(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('contacts.view');
		$contact = app(\App\Ubr\Infrastructure\Repositories\ContactRepository::class)->find((int) $request['id']);

		if (! $contact || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true) || ! app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class)->isVisibleTo((int) $contact['company_id'], $ctx->organizationId)) {
			throw \App\Ubr\Support\UbrException::notFound('Nie znaleziono kontaktu.');
		}

		return $this->decisionPhones->linksPayload($ctx, (int) $request['id']);
	}

	public function decidePhoneLink(WP_REST_Request $request): array
	{
		return $this->decisionPhones->decide($this->tenants->require('contacts.manage'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function removePhoneLink(WP_REST_Request $request): array
	{
		return $this->decisionPhones->remove($this->tenants->require('contacts.manage'), (int) $request['id'], (int) $request['link_id']);
	}

	public function phones(WP_REST_Request $request): array
	{
		return $this->phones->forCompany($this->tenants->require('companies.view'), (int) $request['id']);
	}

	public function refreshPhones(WP_REST_Request $request): array
	{
		return $this->phones->refresh($this->tenants->require('analysis.run'), (int) $request['id']);
	}

	public function addPhone(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->phones->addManual($this->tenants->require('pipeline.manage'), (int) $request['id'], (array) $request->get_json_params()), 201);
	}

	public function deletePhone(WP_REST_Request $request): array
	{
		return $this->phones->deleteManual($this->tenants->require('pipeline.manage'), (int) $request['id'], (int) $request['phone_id']);
	}

	public function revealPreflight(WP_REST_Request $request): array
	{
		return $this->apollo->preflight($this->tenants->require('contacts.view'), (int) $request['id'], (bool) $request->get_param('refresh'));
	}

	public function reveal(WP_REST_Request $request): array
	{
		$body = (array) $request->get_json_params();

		return $this->apollo->reveal($this->tenants->require('contacts.reveal'), (int) $request['id'], (string) ($body['confirm_token'] ?? ''), ! empty($body['acknowledge_unknown_cost']), ! empty($body['refresh']));
	}

	/** Prompt 10.4.3 — status automatyzacji przejętej firmy: analiza decydenta + synchronizacja ClickUp. */
	public function claimStatus(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('companies.view');
		$id = (int) $request['id'];

		return [
			'research' => app(\App\Ubr\Services\ContactResearchService::class)->claimStatus($ctx, $id),
			'clickup' => $ctx->can('pipeline.view') ? app(\App\Ubr\Services\ClickUpExportService::class)->claimStatus($ctx, $id) : null,
		];
	}

	/** Prompt 10.4.3 — „Ponów wyszukiwanie decydenta” (zabezpieczone przed wielokrotnym uruchomieniem). */
	public function claimResearch(WP_REST_Request $request): \WP_REST_Response
	{
		$ctx = $this->tenants->require('companies.view');
		$result = app(\App\Ubr\Services\ContactResearchService::class)->requestForClaim($ctx, (int) $request['id'], true);

		return new \WP_REST_Response($result + ['status' => app(\App\Ubr\Services\ContactResearchService::class)->claimStatus($ctx, (int) $request['id'])], $result['queued'] ? 202 : ($result['reason'] === 'not_found' ? 404 : 200));
	}

	public function claim(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('prospects.manage');
		$searchId = $request->get_param('search_id');
		$result = $this->ownership->claim($ctx, (int) $request['id'], $searchId ? (int) $searchId : null);

		return $result + ['sales' => $this->pipeline->sales($ctx, (int) $request['id'])];
	}

	public function transfer(WP_REST_Request $request): array
	{
		$userId = $request->get_param('user_id');

		return $this->ownership->transfer($this->tenants->require('pipeline.manage'), (int) $request['id'], $userId === null || $userId === '' ? null : (int) $userId);
	}

	public function doNotContact(WP_REST_Request $request): array
	{
		$body = (array) $request->get_json_params();

		return $this->pipeline->setDoNotContact($this->tenants->require('pipeline.manage'), (int) $request['id'], ! empty($body['value']), isset($body['reason']) ? (string) $body['reason'] : null);
	}

	public function callOutcome(WP_REST_Request $request): WP_REST_Response
	{
		$body = (array) $request->get_json_params();

		return new WP_REST_Response($this->ownership->recordCallOutcome(
			$this->tenants->require('pipeline.manage'),
			(int) $request['id'],
			(string) ($body['outcome'] ?? ''),
			(string) ($body['note'] ?? ''),
			! empty($body['contact_id']) ? (int) $body['contact_id'] : null,
			isset($body['occurred_at']) ? (string) $body['occurred_at'] : null
		), 201);
	}
}
