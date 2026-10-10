<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\ContactResearchService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Decision Maker Finder — REST. Uprawnienia sprawdza RestRegistrar (ability) i serwis (firma widoczna dla organizacji).
 */
class ContactController
{
	public function __construct(private TenantResolver $tenants, private ContactResearchService $contacts)
	{
	}

	public function forCompany(WP_REST_Request $request): array
	{
		return $this->contacts->forCompany($this->tenants->require('contacts.view'), (int) $request['id']);
	}

	public function researchCompany(WP_REST_Request $request): WP_REST_Response
	{
		$result = $this->contacts->request($this->tenants->require('contacts.run'), [(int) $request['id']], (bool) $request->get_param('force'));

		return new WP_REST_Response($result, $result['queued'] ? 202 : 200);
	}

	public function bulk(WP_REST_Request $request): WP_REST_Response
	{
		$ids = array_map('intval', (array) $request->get_param('company_ids'));
		$result = $this->contacts->request($this->tenants->require('contacts.run'), $ids, (bool) $request->get_param('force'), 'bulk');

		return new WP_REST_Response($result, $result['queued'] ? 202 : 200);
	}

	public function review(WP_REST_Request $request): array
	{
		return $this->contacts->review($this->tenants->require('contacts.manage'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function store(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->contacts->addManual($this->tenants->require('contacts.manage'), (int) $request['id'], (array) $request->get_json_params()), 201);
	}

	public function status(WP_REST_Request $request): array
	{
		return $this->contacts->environment($this->tenants->require('contacts.view'));
	}
}
