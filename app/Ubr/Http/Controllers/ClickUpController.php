<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\ClickUpDestinationService;
use App\Ubr\Services\ClickUpExportService;
use App\Ubr\Services\ClickUpOAuthService;
use App\Ubr\Services\ClickUpRuleService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Integracja ClickUp — REST. Połączenie jest zawsze połączeniem BIEŻĄCEGO użytkownika w aktywnej organizacji
 * (identyfikatory użytkownika / organizacji z żądania są ignorowane). Odpowiedzi nigdy nie zawierają tokenów.
 */
class ClickUpController
{
	public function __construct(
		private TenantResolver $tenants,
		private ClickUpOAuthService $oauth,
		private ClickUpDestinationService $destinations,
		private ClickUpExportService $exports,
		private ClickUpRuleService $rules,
	) {
	}

	public function status(): array
	{
		return $this->oauth->status($this->tenants->require('clickup.connect'));
	}

	public function start(): array
	{
		return ['authorize_url' => $this->oauth->start($this->tenants->require('clickup.connect'))];
	}

	public function test(): array
	{
		return $this->oauth->test($this->tenants->require('clickup.connect'));
	}

	public function disconnect(): array
	{
		$ctx = $this->tenants->require('clickup.connect');
		$this->oauth->disconnect($ctx);

		return $this->oauth->status($ctx);
	}

	public function options(WP_REST_Request $request): array
	{
		return $this->destinations->options($this->tenants->require('clickup.connect'), sanitize_key((string) $request->get_param('level')), (string) $request->get_param('parent_id'));
	}

	public function saveDestination(WP_REST_Request $request): array
	{
		return $this->destinations->save($this->tenants->require('clickup.connect'), (array) $request->get_json_params());
	}

	public function saveSettings(WP_REST_Request $request): array
	{
		return $this->destinations->saveSettings($this->tenants->require('clickup.connect'), (array) $request->get_json_params());
	}

	/** Prompt 10.4.3 — zadania ClickUp po „Przejmij firmę” (ustawienie organizacji, owner / admin). */
	public function saveClaimSync(WP_REST_Request $request): array
	{
		return app(\App\Ubr\Services\ClickUpExportService::class)->saveClaimSync($this->tenants->require('organization.update'), (array) $request->get_json_params());
	}

	public function export(WP_REST_Request $request): WP_REST_Response
	{
		$ids = (array) ($request->get_param('company_ids') ?? []);

		if ($request->get_param('company_id')) {
			$ids[] = (int) $request->get_param('company_id');
		}

		return new WP_REST_Response($this->exports->request($this->tenants->require('clickup.export'), $ids, sanitize_key((string) ($request->get_param('mode') ?: 'create'))), 202);
	}

	public function reconcile(WP_REST_Request $request): array
	{
		return $this->exports->reconcile($this->tenants->require('clickup.export'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function company(WP_REST_Request $request): array
	{
		return $this->exports->forCompany($this->tenants->require('pipeline.view'), (int) $request['id']);
	}

	public function batch(WP_REST_Request $request): array
	{
		return $this->exports->batch($this->tenants->require('clickup.export'), (string) $request['batch']);
	}

	public function recent(): array
	{
		return $this->exports->recent($this->tenants->require('clickup.export'));
	}

	public function rules(): array
	{
		return $this->rules->list($this->tenants->require('clickup.export'));
	}

	public function createRule(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->rules->create($this->tenants->require('clickup.rules'), (array) $request->get_json_params()), 201);
	}

	public function updateRule(WP_REST_Request $request): array
	{
		return $this->rules->update($this->tenants->require('clickup.rules'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function deleteRule(WP_REST_Request $request): array
	{
		$this->rules->delete($this->tenants->require('clickup.export'), (int) $request['id']);

		return ['deleted' => true];
	}

	public function previewRule(WP_REST_Request $request): array
	{
		$conditions = $request->get_param('conditions');

		return $this->rules->preview($this->tenants->require('clickup.export'), (int) $request['id'], is_array($conditions) ? $conditions : null);
	}

	public function enableRule(WP_REST_Request $request): array
	{
		return $this->rules->enable($this->tenants->require('clickup.rules'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function disableRule(WP_REST_Request $request): array
	{
		return $this->rules->disable($this->tenants->require('clickup.export'), (int) $request['id']);
	}

	public function backfillRule(WP_REST_Request $request): array
	{
		return $this->rules->backfill($this->tenants->require('clickup.rules'), (int) $request['id'], (array) $request->get_json_params());
	}
}
