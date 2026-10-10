<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\OrganizationService;
use App\Ubr\Services\TenantResolver;
use App\Ubr\Support\UbrException;
use WP_REST_Request;

class MeController
{
	public function __construct(private TenantResolver $tenants, private OrganizationService $organizations)
	{
	}

	public function show(WP_REST_Request $request): array
	{
		$user = wp_get_current_user();
		$ctx = $this->tenants->resolve($user->ID);

		return [
			'user' => [
				'id' => $user->ID,
				'name' => $user->display_name,
				'email' => $user->user_email,
				'avatar' => get_avatar_url($user->ID, ['size' => 64]),
			],
			'organizations' => $this->tenants->memberships($user->ID),
			'active_organization' => $ctx ? [
				'id' => $ctx->organizationId,
				'name' => $ctx->organization['name'],
				'role' => $ctx->role->value,
				'abilities' => $ctx->role->abilities(),
			] : null,
			'can_create_organization' => $this->organizations->canCreate($user->ID),
		];
	}

	public function switchOrganization(WP_REST_Request $request): array
	{
		$orgId = (int) $request->get_param('organization_id');

		if ($orgId <= 0) {
			throw UbrException::invalid('Brak organizacji.');
		}

		// Membership jest weryfikowane w TenantResolver::switchTo — sam identyfikator nic nie daje.
		$this->tenants->switchTo(get_current_user_id(), $orgId);

		return $this->show($request);
	}

	public function updateProfile(WP_REST_Request $request): array
	{
		$name = trim(sanitize_text_field((string) $request->get_param('display_name')));

		if ($name === '' || mb_strlen($name) > 120) {
			throw UbrException::invalid('Podaj nazwę wyświetlaną (maks. 120 znaków).');
		}

		$result = wp_update_user(['ID' => get_current_user_id(), 'display_name' => $name]);

		if (is_wp_error($result)) {
			throw UbrException::invalid($result->get_error_message());
		}

		return $this->show($request);
	}
}
