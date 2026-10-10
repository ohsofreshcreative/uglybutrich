<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\AnalysisService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

class AnalysisController
{
	public function __construct(private TenantResolver $tenants, private AnalysisService $analyses)
	{
	}

	public function analyzeCompany(WP_REST_Request $request): WP_REST_Response
	{
		$result = $this->analyses->request($this->tenants->require('analysis.run'), [(int) $request['id']], (bool) $request->get_param('force'));

		return new WP_REST_Response($result, $result['queued'] ? 202 : 200);
	}

	public function bulk(WP_REST_Request $request): WP_REST_Response
	{
		$ids = array_map('intval', (array) $request->get_param('company_ids'));
		$result = $this->analyses->request($this->tenants->require('analysis.run'), $ids, (bool) $request->get_param('force'), 'bulk');

		return new WP_REST_Response($result, $result['queued'] ? 202 : 200);
	}

	public function forCompany(WP_REST_Request $request): array
	{
		return $this->analyses->forCompany($this->tenants->require('analysis.view'), (int) $request['id']);
	}

	public function show(WP_REST_Request $request): array
	{
		return $this->analyses->get($this->tenants->require('analysis.view'), (int) $request['id']);
	}

	public function compare(WP_REST_Request $request): array
	{
		return $this->analyses->compare($this->tenants->require('analysis.view'), (int) $request->get_param('a'), (int) $request->get_param('b'));
	}

	public function status(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('integrations.view');

		// Test środowiska uruchamia proces Node — tylko Owner/Admin.
		return $this->analyses->environment($ctx, (bool) $request->get_param('check') && $ctx->can('integrations.manage'));
	}
}
