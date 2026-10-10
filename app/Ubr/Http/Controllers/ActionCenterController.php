<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\ActionCenterService;
use App\Ubr\Services\ManualReviewService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;

/**
 * Prospecting Dashboard & Action Center (Prompt 6). Organizacja zawsze z kontekstu zalogowanego użytkownika (TenantResolver),
 * nigdy z parametru żądania; zapis (odłożenie działania) wymaga nonce REST (CSRF) i uprawnienia pipeline.manage.
 */
class ActionCenterController
{
	public function __construct(private TenantResolver $tenants, private ActionCenterService $center)
	{
	}

	public function overview(WP_REST_Request $request): array
	{
		return $this->center->overview($this->tenants->require('companies.view'), $request->get_query_params());
	}

	public function priorities(WP_REST_Request $request): array
	{
		return $this->center->priorities($this->tenants->require('companies.view'), $request->get_query_params());
	}

	public function queue(WP_REST_Request $request): array
	{
		return $this->center->queue($this->tenants->require('companies.view'), (string) $request['key'], $request->get_query_params());
	}

	public function snooze(WP_REST_Request $request): array
	{
		return $this->center->snooze($this->tenants->require('pipeline.manage'), (array) $request->get_json_params());
	}

	public function manualReview(WP_REST_Request $request): array
	{
		return app(ManualReviewService::class)->forCompany($this->tenants->require('companies.view'), (int) $request['id']);
	}

	public function saveManualReview(WP_REST_Request $request): array
	{
		return app(ManualReviewService::class)->save($this->tenants->require('pipeline.manage'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function unsnooze(WP_REST_Request $request): array
	{
		return $this->center->unsnooze($this->tenants->require('pipeline.manage'), $request->get_params());
	}
}
