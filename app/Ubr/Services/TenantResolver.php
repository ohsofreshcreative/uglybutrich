<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Support\UbrException;

/**
 * Ustala aktywną organizację użytkownika na podstawie członkostwa zapisanego w bazie.
 *
 * Aktywna organizacja jest preferencją użytkownika (user meta) i jest ZAWSZE weryfikowana
 * z tabelą członków. Identyfikator organizacji z żądania nie daje dostępu sam w sobie.
 */
class TenantResolver
{
	public const ACTIVE_META = 'ubr_active_organization_id';

	/** @var array<int, TenantContext|false> */
	private array $cache = [];

	public function __construct(
		private OrganizationRepository $organizations,
		private MemberRepository $members,
	) {
	}

	public function resolve(?int $userId = null): ?TenantContext
	{
		$userId ??= get_current_user_id();

		if ($userId <= 0) {
			return null;
		}

		if (array_key_exists($userId, $this->cache)) {
			return $this->cache[$userId] ?: null;
		}

		$memberships = $this->organizations->forUser($userId);

		if ($memberships === []) {
			$this->cache[$userId] = false;

			return null;
		}

		$activeId = (int) get_user_meta($userId, self::ACTIVE_META, true);
		$active = null;

		foreach ($memberships as $org) {
			if ($org['id'] === $activeId) {
				$active = $org;
				break;
			}
		}

		$active ??= $memberships[0];
		$role = Role::tryFrom((string) $active['member_role']) ?? Role::Member;
		unset($active['member_role']);

		return $this->cache[$userId] = new TenantContext($userId, $active['id'], $role, $active);
	}

	/**
	 * Wymaga kontekstu i uprawnienia — rzuca 401/403.
	 */
	public function require(string $ability, ?int $userId = null): TenantContext
	{
		$userId ??= get_current_user_id();

		if ($userId <= 0) {
			throw new UbrException('Musisz być zalogowany.', 'ubr_unauthenticated', 401);
		}

		$context = $this->resolve($userId);

		if (! $context) {
			throw UbrException::forbidden('Nie należysz do żadnej organizacji.');
		}

		if (! $context->can($ability)) {
			throw UbrException::forbidden();
		}

		return $context;
	}

	public function switchTo(int $userId, int $organizationId): TenantContext
	{
		if (! $this->members->role($organizationId, $userId)) {
			// Ten sam komunikat co dla nieistniejącej organizacji — nie ujawniamy, że istnieje.
			throw UbrException::notFound('Nie znaleziono organizacji.');
		}

		update_user_meta($userId, self::ACTIVE_META, $organizationId);
		$this->forget($userId);

		return $this->resolve($userId);
	}

	public function forget(?int $userId = null): void
	{
		if ($userId === null) {
			$this->cache = [];

			return;
		}

		unset($this->cache[$userId]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function memberships(int $userId): array
	{
		return array_map(fn ($o) => [
			'id' => $o['id'],
			'name' => $o['name'],
			'slug' => $o['slug'],
			'role' => $o['member_role'],
		], $this->organizations->forUser($userId));
	}
}
