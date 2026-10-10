<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;
use App\Ubr\Services\ApolloBudget;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;

/**
 * Settings → Integrations → Apollo. Klucz należy do operatora SaaS (wp-config.php) — nigdy nie jest zwracany w API.
 */
class IntegrationController
{
	public function __construct(
		private TenantResolver $tenants,
		private ApolloProvider $apollo,
		private ApolloBudget $budget,
		private ApiRequestRepository $requests,
	) {
	}

	public function apollo(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('integrations.view');

		return [
			'configured' => ApolloProvider::apiKey() !== null,
			// Tylko informacja SKĄD pochodzi klucz — bez jakiegokolwiek fragmentu jego wartości.
			'key_source' => ApolloProvider::keySource(),
			'can_manage' => $ctx->can('integrations.manage'),
			'endpoint' => 'POST ' . ApolloProvider::BASE_URL . ApolloProvider::SEARCH_ENDPOINT,
			'pricing_note' => 'Wg dokumentacji Apollo wyszukiwanie firm zużywa 1 kredyt za stronę wyników (do 100 firm na stronę). Zużycie kredytów zależy od planu Apollo i rodzaju wykonywanych operacji — Apollo nie raportuje zużytych kredytów w odpowiedzi API.',
			'budget' => $this->budget->remaining($ctx->organizationId),
			'stats_organization' => $this->requests->stats('apollo', $ctx->organizationId),
			// Zużycie całej instalacji (wszystkie organizacje) widzi tylko operator SaaS (administrator WordPressa).
			'stats_global' => user_can($ctx->userId, 'manage_options') ? $this->requests->stats('apollo') : null,
		];
	}

	public function test(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('integrations.manage');
		$health = $this->apollo->testConnection($ctx->organizationId);
		$usage = $health['ok'] ? $this->apollo->usageStats($ctx->organizationId) : null;

		return ['health' => $health, 'usage' => $usage];
	}

	public function testSearch(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('integrations.manage');

		return $this->apollo->testSearchAccess($ctx->organizationId);
	}
}
