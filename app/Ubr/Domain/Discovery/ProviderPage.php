<?php

namespace App\Ubr\Domain\Discovery;

final class ProviderPage
{
	/**
	 * @param list<CompanyCandidate> $candidates
	 * @param array<string, mixed>|null $nextCursor null = koniec wyników
	 * @param array<string, mixed> $meta
	 */
	public function __construct(
		public readonly array $candidates,
		public readonly ?array $nextCursor = null,
		public readonly array $meta = [],
	) {
	}
}
