<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Support\UbrException;

class OrganizationService
{
	public function __construct(
		private OrganizationRepository $organizations,
		private MemberRepository $members,
		private TenantResolver $tenants,
	) {
	}

	/**
	 * Kto może zakładać nowe organizacje. Domyślnie: administratorzy WP (capability, nie is_admin()).
	 * Docelowo (self-service SaaS) można to otworzyć filtrem `ubr/can_create_organization`.
	 */
	public function canCreate(int $userId): bool
	{
		return (bool) apply_filters('ubr/can_create_organization', user_can($userId, 'manage_options'), $userId);
	}

	public function create(string $name, int $ownerUserId, ?string $slug = null): int
	{
		$name = trim(wp_strip_all_tags($name));

		if ($name === '' || mb_strlen($name) > 190) {
			throw UbrException::invalid('Nazwa organizacji jest wymagana (maks. 190 znaków).');
		}

		$id = $this->organizations->create($name, $this->uniqueSlug($slug ?: $name), $ownerUserId ?: null);

		if ($ownerUserId > 0) {
			$this->members->add($id, $ownerUserId, Role::Owner);
			$this->tenants->forget($ownerUserId);
		}

		return $id;
	}

	public function rename(TenantContext $ctx, string $name): void
	{
		$this->assert($ctx, 'organization.update');
		$name = trim(wp_strip_all_tags($name));

		if ($name === '' || mb_strlen($name) > 190) {
			throw UbrException::invalid('Nazwa organizacji jest wymagana (maks. 190 znaków).');
		}

		$this->organizations->update($ctx->organizationId, ['name' => $name]);
	}

	public function addMember(TenantContext $ctx, string $email, Role $role): array
	{
		$this->assert($ctx, 'members.manage');
		$this->assertCanAssign($ctx, $role);

		$user = get_user_by('email', sanitize_email($email));

		if (! $user) {
			throw UbrException::invalid('Nie ma konta WordPress z tym adresem e-mail. Utwórz najpierw użytkownika.');
		}

		if ($this->members->role($ctx->organizationId, $user->ID)) {
			throw UbrException::invalid('Ten użytkownik już należy do organizacji.');
		}

		$this->members->add($ctx->organizationId, $user->ID, $role);
		$this->tenants->forget($user->ID);

		return ['user_id' => $user->ID, 'role' => $role->value];
	}

	public function changeRole(TenantContext $ctx, int $userId, Role $role): void
	{
		$this->assert($ctx, 'members.manage');
		$current = $this->members->role($ctx->organizationId, $userId);

		if (! $current) {
			throw UbrException::notFound('Użytkownik nie należy do organizacji.');
		}

		// Zmiana dotycząca właściciela (z/na Owner) wymaga roli Owner.
		if ($current === Role::Owner || $role === Role::Owner) {
			$this->assert($ctx, 'members.manage_owners');
		}

		if ($current === Role::Owner && $role !== Role::Owner && $this->members->countOwners($ctx->organizationId) <= 1) {
			throw UbrException::invalid('Organizacja musi mieć co najmniej jednego właściciela.');
		}

		$this->members->updateRole($ctx->organizationId, $userId, $role);
		$this->tenants->forget($userId);
	}

	public function removeMember(TenantContext $ctx, int $userId): void
	{
		$isSelf = $userId === $ctx->userId;

		if (! $isSelf) {
			$this->assert($ctx, 'members.manage');
		}

		$current = $this->members->role($ctx->organizationId, $userId);

		if (! $current) {
			throw UbrException::notFound('Użytkownik nie należy do organizacji.');
		}

		if ($current === Role::Owner) {
			if (! $isSelf) {
				$this->assert($ctx, 'members.manage_owners');
			}

			if ($this->members->countOwners($ctx->organizationId) <= 1) {
				throw UbrException::invalid('Nie można usunąć ostatniego właściciela organizacji.');
			}
		}

		$this->members->remove($ctx->organizationId, $userId);

		if ((int) get_user_meta($userId, TenantResolver::ACTIVE_META, true) === $ctx->organizationId) {
			delete_user_meta($userId, TenantResolver::ACTIVE_META);
		}

		$this->tenants->forget($userId);
	}

	private function assertCanAssign(TenantContext $ctx, Role $role): void
	{
		if ($role === Role::Owner) {
			$this->assert($ctx, 'members.manage_owners');
		}
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}

	private function uniqueSlug(string $source): string
	{
		$base = sanitize_title($source) ?: 'organizacja';
		$base = substr($base, 0, 180);
		$slug = $base;
		$i = 2;

		while ($this->organizations->slugExists($slug)) {
			$slug = $base . '-' . $i++;
		}

		return $slug;
	}
}
