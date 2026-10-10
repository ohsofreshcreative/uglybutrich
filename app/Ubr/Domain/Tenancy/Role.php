<?php

namespace App\Ubr\Domain\Tenancy;

/**
 * Role na poziomie organizacji i ich uprawnienia.
 * Autoryzacja w aplikacji opiera się WYŁĄCZNIE na tej macierzy + członkostwie w organizacji.
 */
enum Role: string
{
	case Owner = 'owner';
	case Admin = 'admin';
	case Member = 'member';

	public const ABILITIES = [
		'organization.view' => ['owner', 'admin', 'member'],
		'organization.update' => ['owner', 'admin'],
		'members.view' => ['owner', 'admin', 'member'],
		'members.manage' => ['owner', 'admin'],
		'members.manage_owners' => ['owner'],
		'discovery.view' => ['owner', 'admin', 'member'],
		'discovery.run' => ['owner', 'admin', 'member'],
		'discovery.cancel' => ['owner', 'admin', 'member'],
		'companies.view' => ['owner', 'admin', 'member'],
		'companies.verify' => ['owner', 'admin', 'member'],
		'analysis.view' => ['owner', 'admin', 'member'],
		'analysis.run' => ['owner', 'admin', 'member'],
		'contacts.view' => ['owner', 'admin', 'member'],
		'contacts.run' => ['owner', 'admin', 'member'],
		'contacts.manage' => ['owner', 'admin', 'member'],
		'pipeline.view' => ['owner', 'admin', 'member'],
		'pipeline.manage' => ['owner', 'admin', 'member'],
		'pipeline.moderate' => ['owner', 'admin'],
		'clickup.connect' => ['owner', 'admin', 'member'],
		'clickup.export' => ['owner', 'admin', 'member'],
		'clickup.rules' => ['owner', 'admin'],
		'prospects.view' => ['owner', 'admin', 'member'],
		'prospects.manage' => ['owner', 'admin', 'member'],
		'integrations.view' => ['owner', 'admin', 'member'],
		'integrations.manage' => ['owner', 'admin'],
	];

	public function can(string $ability): bool
	{
		return in_array($this->value, self::ABILITIES[$ability] ?? [], true);
	}

	/**
	 * @return list<string>
	 */
	public function abilities(): array
	{
		return array_values(array_filter(array_keys(self::ABILITIES), fn ($a) => $this->can($a)));
	}

	public function label(): string
	{
		return match ($this) {
			self::Owner => 'Owner',
			self::Admin => 'Admin',
			self::Member => 'Member',
		};
	}

	public function rank(): int
	{
		return match ($this) {
			self::Owner => 3,
			self::Admin => 2,
			self::Member => 1,
		};
	}
}
