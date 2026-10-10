<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\ProspectService;
use App\Ubr\Services\TenantResolver;
use App\Ubr\Support\UbrException;
use WP_REST_Request;
use WP_REST_Response;

class ProspectController
{
	public function __construct(private TenantResolver $tenants, private ProspectService $prospects)
	{
	}

	public function index(WP_REST_Request $request): array
	{
		return $this->prospects->list($this->tenants->require('prospects.view'), $request->get_query_params());
	}

	public function store(WP_REST_Request $request): WP_REST_Response
	{
		$ctx = $this->tenants->require('prospects.manage');
		$companyId = (int) $request->get_param('company_id');

		if ($companyId <= 0) {
			throw UbrException::invalid('Brak firmy.');
		}

		$searchId = (int) $request->get_param('search_id') ?: null;
		$result = $this->prospects->save($ctx, $companyId, $searchId);

		return new WP_REST_Response(['prospect_id' => $result['id'], 'created' => $result['created']], $result['created'] ? 201 : 200);
	}

	public function destroy(WP_REST_Request $request): array
	{
		$this->prospects->remove($this->tenants->require('prospects.manage'), (int) $request['id']);

		return ['deleted' => true];
	}
}
