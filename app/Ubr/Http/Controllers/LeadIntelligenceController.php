<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\LeadIntelligenceService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;

/**
 * Lead Intelligence — REST. Organizacja zawsze z TenantResolver; widoczność firmy sprawdzana w serwisie (IDOR → 404).
 */
class LeadIntelligenceController
{
	public function __construct(private TenantResolver $tenants, private LeadIntelligenceService $service)
	{
	}

	public function show(WP_REST_Request $request): array
	{
		return $this->service->forCompany($this->tenants->require('companies.view'), (int) $request['id']);
	}

	public function recalculate(WP_REST_Request $request): array
	{
		return $this->service->recalculate($this->tenants->require('companies.view'), (int) $request['id']);
	}

	public function top(WP_REST_Request $request): array
	{
		return ['items' => $this->service->top($this->tenants->require('companies.view'), (int) ($request->get_param('limit') ?: 10))];
	}
}
