<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Infrastructure\Repositories\ProspectRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Services\DiscoveryService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;

class DashboardController
{
	public function __construct(
		private TenantResolver $tenants,
		private DiscoveryService $discovery,
		private ResultRepository $results,
		private ProspectRepository $prospects,
	) {
	}

	public function show(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('organization.view');

		return [
			'stats' => [
				'discovered_companies' => $this->results->countDistinctCompaniesForOrganization($ctx->organizationId),
				'prospects' => $this->prospects->count($ctx->organizationId),
			],
			'pipeline' => $ctx->can('pipeline.view') ? app(\App\Ubr\Services\PipelineService::class)->stats($ctx) : null,
			'agenda' => $ctx->can('pipeline.view') ? app(\App\Ubr\Services\PipelineService::class)->agenda($ctx) : null,
			'top_leads' => $ctx->can('companies.view') ? app(\App\Ubr\Services\LeadIntelligenceService::class)->top($ctx, 5) : [],
			'recent_searches' => $this->discovery->recent($ctx, 6),
			'recent_companies' => array_map(fn ($r) => [
				'id' => (int) $r['id'],
				'name' => $r['name'],
				'domain' => $r['domain'],
				'city' => $r['city'],
				'region' => $r['region'],
				'industry' => $r['industry'],
				'prospect_id' => $r['prospect_id'] ? (int) $r['prospect_id'] : null,
				'found_at' => gmdate('c', strtotime($r['found_at'] . ' UTC')),
			], $this->results->recentCompaniesForOrganization($ctx->organizationId, 8)),
		];
	}
}
