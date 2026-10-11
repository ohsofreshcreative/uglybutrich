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

	/**
	 * Prompt 10.2 — JEDEN kontrolowany test Apollo People API Search na istniejącej firmie (0 kredytów wg dokumentacji,
	 * bez e-maili i telefonów, bez zapisu osób, bez włączania funkcji). Wymaga potwierdzenia; maks. 1 test na godzinę.
	 */
	public function peopleTest(WP_REST_Request $request): array
	{
		$ctx = $this->tenants->require('integrations.manage');
		$params = (array) $request->get_json_params();

		if (empty($params['confirm'])) {
			throw \App\Ubr\Support\UbrException::invalid('Test wymaga potwierdzenia (confirm).');
		}

		$companyId = (int) ($params['company_id'] ?? 0);
		$companies = app(\App\Ubr\Infrastructure\Repositories\CompanyRepository::class);
		$company = $companies->find($companyId);

		if (! $company || ! $companies->isVisibleTo($companyId, $ctx->organizationId) || empty($company['domain'])) {
			throw \App\Ubr\Support\UbrException::notFound('Nie znaleziono firmy z domeną.');
		}

		$lock = 'ubr_apollo_people_test_' . $ctx->organizationId;

		if (get_transient($lock)) {
			throw \App\Ubr\Support\UbrException::invalid('Test People Search był wykonany w ciągu ostatniej godziny.');
		}

		set_transient($lock, 1, HOUR_IN_SECONDS);
		$db = app(\App\Ubr\Infrastructure\Database\Db::class);
		$apolloOrg = $db->value('SELECT external_id FROM ' . $db->table(\App\Ubr\Infrastructure\Database\Tables::COMPANY_EXTERNAL_IDS) . " WHERE company_id = %d AND provider = 'apollo' LIMIT 1", $companyId);

		return ['company' => ['id' => $companyId, 'name' => $company['name'], 'domain' => $company['domain']], 'endpoint' => 'POST ' . ApolloProvider::BASE_URL . \App\Ubr\Infrastructure\Contacts\ApolloPeopleSource::ENDPOINT, 'credits_note' => 'Wg dokumentacji Apollo: 0 kredytów; bez e-maili i numerów telefonów. Nic nie zostało zapisane.']
			+ app(\App\Ubr\Infrastructure\Contacts\ApolloPeopleSource::class)->controlledTest((string) $company['domain'], $ctx->organizationId, $apolloOrg ? (string) $apolloOrg : null);
	}
}
