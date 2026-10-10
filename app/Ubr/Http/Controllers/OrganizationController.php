<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\TenantResolver;
use App\Ubr\Support\UbrException;
use WP_REST_Request;
use WP_REST_Response;

class OrganizationController
{
	public function __construct(
		private TenantResolver $tenants,
		private OrganizationService $organizations,
		private MemberRepository $members,
	) {
	}

	public function show(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('organization.view');

		return [
			'id' => $ctx->organizationId,
			'name' => $ctx->organization['name'],
			'slug' => $ctx->organization['slug'],
			'plan' => $ctx->organization['plan'],
			'created_at' => gmdate('c', strtotime($ctx->organization['created_at'] . ' UTC')),
			'role' => $ctx->role->value,
			'abilities' => $ctx->role->abilities(),
			'members' => array_map(fn ($m) => [
				'user_id' => (int) $m['user_id'],
				'name' => $m['display_name'] ?: '(usunięty użytkownik)',
				// E-maile członków widzą tylko osoby zarządzające członkami.
				'email' => $ctx->can('members.manage') || (int) $m['user_id'] === $ctx->userId ? $m['user_email'] : null,
				'role' => $m['role'],
				'joined_at' => gmdate('c', strtotime($m['created_at'] . ' UTC')),
				'is_me' => (int) $m['user_id'] === $ctx->userId,
			], $this->members->list($ctx->organizationId)),
		];
	}

	public function update(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('organization.update');
		$this->organizations->rename($ctx, (string) $request->get_param('name'));
		$this->tenants->forget();

		return $this->show($request);
	}

	public function store(WP_REST_Request $request): WP_REST_Response
	{
		$userId = get_current_user_id();

		if (! $this->organizations->canCreate($userId)) {
			throw UbrException::forbidden('Nie możesz tworzyć nowych organizacji.');
		}

		$id = $this->organizations->create((string) $request->get_param('name'), $userId);
		$this->tenants->switchTo($userId, $id);

		return new WP_REST_Response(['id' => $id], 201);
	}

	public function addMember(WP_REST_Request $request): WP_REST_Response
	{
		$ctx = $this->tenants->require('members.manage');
		$role = Role::tryFrom((string) $request->get_param('role')) ?? throw UbrException::invalid('Nieprawidłowa rola.');

		return new WP_REST_Response($this->organizations->addMember($ctx, (string) $request->get_param('email'), $role), 201);
	}

	public function updateMember(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('members.manage');
		$role = Role::tryFrom((string) $request->get_param('role')) ?? throw UbrException::invalid('Nieprawidłowa rola.');
		$this->organizations->changeRole($ctx, (int) $request['user_id'], $role);

		return ['updated' => true];
	}

	public function removeMember(WP_REST_Request $request): array
	{
		// Uprawnienie sprawdzane w serwisie: usunięcie innych wymaga members.manage, odejście z organizacji — tylko członkostwa.
		$ctx = $this->tenants->require('organization.view');
		$this->organizations->removeMember($ctx, (int) $request['user_id']);

		return ['deleted' => true];
	}
}
