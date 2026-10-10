<?php

namespace App\Ubr\Infrastructure\Discovery;

use App\Ubr\Domain\Discovery\DiscoveryProvider;
use App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider;
use App\Ubr\Infrastructure\Discovery\Providers\OverpassProvider;
use App\Ubr\Infrastructure\Discovery\Providers\WikidataProvider;

/**
 * Rejestr dostawców Discovery. Kolejni dostawcy: filtr `ubr/discovery/providers`.
 */
class ProviderRegistry
{
	/** @var array<string, DiscoveryProvider>|null */
	private ?array $providers = null;

	public function __construct(
		private ApolloProvider $apollo,
		private WikidataProvider $wikidata,
		private OverpassProvider $overpass,
	) {
	}

	/**
	 * @return array<string, DiscoveryProvider>
	 */
	public function all(): array
	{
		if ($this->providers === null) {
			$list = apply_filters('ubr/discovery/providers', [$this->apollo, $this->wikidata, $this->overpass]);
			$this->providers = [];

			foreach ($list as $provider) {
				if ($provider instanceof DiscoveryProvider) {
					$this->providers[$provider->key()] = $provider;
				}
			}
		}

		return $this->providers;
	}

	public function get(string $key): ?DiscoveryProvider
	{
		return $this->all()[$key] ?? null;
	}

	/**
	 * @return list<string>
	 */
	public function availableKeys(): array
	{
		return array_values(array_keys(array_filter($this->all(), fn (DiscoveryProvider $p) => $p->isAvailable())));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function describe(): array
	{
		return array_values(array_map(fn (DiscoveryProvider $p) => [
			'key' => $p->key(),
			'label' => $p->label(),
			'available' => $p->isAvailable(),
			'description' => $p->description(),
			'primary' => $p->key() === 'apollo',
		], $this->all()));
	}
}
