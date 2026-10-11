<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\DiscoveryService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

class DiscoveryController
{
	public function __construct(private TenantResolver $tenants, private DiscoveryService $discovery)
	{
	}

	public function options(WP_REST_Request $request): array
	{
		return $this->discovery->options($this->tenants->require('discovery.view'));
	}

	public function index(WP_REST_Request $request): array
	{
		return ['items' => $this->discovery->recent($this->tenants->require('discovery.view'), 30)];
	}

	public function store(WP_REST_Request $request): WP_REST_Response
	{
		$ctx = $this->tenants->require('discovery.run');
		$input = (array) ($request->get_json_params() ?: $request->get_body_params());

		// Ignorujemy ewentualne organization_id z żądania — organizacja pochodzi z kontekstu.
		unset($input['organization_id']);

		return new WP_REST_Response($this->discovery->start($ctx, $input), 201);
	}

	public function show(WP_REST_Request $request): array
	{
		return $this->discovery->get($this->tenants->require('discovery.view'), (int) $request['id']);
	}

	public function cancel(WP_REST_Request $request): array
	{
		return $this->discovery->cancel($this->tenants->require('discovery.cancel'), (int) $request['id']);
	}

	public function retry(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->discovery->retry($this->tenants->require('discovery.run'), (int) $request['id']), 201);
	}

	public function results(WP_REST_Request $request): array
	{
		return $this->discovery->results(
			$this->tenants->require('discovery.view'),
			(int) $request['id'],
			(int) ($request->get_param('page') ?: 1),
			(int) ($request->get_param('per_page') ?: 25),
			sanitize_key((string) $request->get_param('filter')) ?: 'all',
			sanitize_key((string) $request->get_param('sort')) ?: 'relevance',
			[
				'min_opportunity' => (int) $request->get_param('min_opportunity'),
				'confidence' => sanitize_key((string) $request->get_param('confidence')),
				'min_findings' => (int) $request->get_param('min_findings'),
				'has_contact' => sanitize_key((string) $request->get_param('has_contact')),
				'min_contacts' => (int) $request->get_param('min_contacts'),
				'min_contact_score' => (int) $request->get_param('min_contact_score'),
				'contact_confidence' => sanitize_key((string) $request->get_param('contact_confidence')),
				'contact_role' => sanitize_key((string) $request->get_param('contact_role')),
				'site_age' => sanitize_key((string) $request->get_param('site_age')),
				// Prompt 10.4: lekkie odświeżanie (identyfikatory widocznych firm + czas ostatniego odświeżenia, UTC).
				'ids' => array_slice(array_map('intval', array_filter(explode(',', (string) $request->get_param('ids')))), 0, 100),
				'changed_since' => (string) $request->get_param('changed_since'),
			] + \App\Ubr\Infrastructure\Repositories\LeadFilters::fromQuery($request->get_params()) + \App\Ubr\Infrastructure\Repositories\CallFilters::fromQuery($request->get_params())
		);
	}

	public function verify(WP_REST_Request $request): array
	{
		return app(\App\Ubr\Services\VerificationService::class)->requestSearch(
			$this->tenants->require('companies.verify'),
			(int) $request['id'],
			(bool) $request->get_param('force')
		);
	}
}
