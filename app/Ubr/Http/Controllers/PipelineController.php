<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\PipelineService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Sales Pipeline — REST. Organizacja zawsze z TenantResolver; identyfikatory z URL są sprawdzane w serwisie (IDOR).
 */
class PipelineController
{
	public function __construct(private TenantResolver $tenants, private PipelineService $pipeline)
	{
	}

	public function board(WP_REST_Request $request): array
	{
		return $this->pipeline->board($this->tenants->require('pipeline.view'), $request->get_query_params());
	}

	public function sales(WP_REST_Request $request): array
	{
		return $this->pipeline->sales($this->tenants->require('pipeline.view'), (int) $request['id']);
	}

	public function update(WP_REST_Request $request): array
	{
		return $this->pipeline->update($this->tenants->require('pipeline.manage'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function archive(WP_REST_Request $request): array
	{
		return $this->pipeline->archive($this->tenants->require('prospects.manage'), (int) $request['id'], (bool) $request->get_param('restore'));
	}

	public function addNote(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->pipeline->addNote($this->tenants->require('pipeline.manage'), (int) $request['id'], (string) $request->get_param('body')), 201);
	}

	public function updateNote(WP_REST_Request $request): array
	{
		return $this->pipeline->updateNote($this->tenants->require('pipeline.manage'), (int) $request['id'], (string) $request->get_param('body'));
	}

	public function deleteNote(WP_REST_Request $request): array
	{
		return $this->pipeline->deleteNote($this->tenants->require('pipeline.manage'), (int) $request['id']);
	}

	public function addActivity(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->pipeline->addActivity($this->tenants->require('pipeline.manage'), (int) $request['id'], (array) $request->get_json_params()), 201);
	}

	public function addFollowup(WP_REST_Request $request): WP_REST_Response
	{
		return new WP_REST_Response($this->pipeline->addFollowup($this->tenants->require('pipeline.manage'), (int) $request['id'], (array) $request->get_json_params()), 201);
	}

	public function updateFollowup(WP_REST_Request $request): array
	{
		return $this->pipeline->updateFollowup($this->tenants->require('pipeline.manage'), (int) $request['id'], (array) $request->get_json_params());
	}

	public function agenda(WP_REST_Request $request): array
	{
		return $this->pipeline->agenda($this->tenants->require('pipeline.view'), (bool) $request->get_param('mine'));
	}
}
