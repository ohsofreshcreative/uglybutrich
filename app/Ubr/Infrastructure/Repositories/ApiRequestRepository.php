<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Dziennik zapytań do zewnętrznych API (kontrola kosztów i limitów).
 * Liczba zapytań NIE jest liczbą kredytów — kredyty zapisujemy tylko, jeśli API je raportuje.
 */
class ApiRequestRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function log(array $data): int
	{
		if (isset($data['rate_limit']) && is_array($data['rate_limit'])) {
			$data['rate_limit'] = $data['rate_limit'] ? wp_json_encode($data['rate_limit']) : null;
		}

		if (isset($data['error'])) {
			$data['error'] = mb_substr((string) $data['error'], 0, 500);
		}

		return $this->db->insert(Tables::API_REQUESTS, $data + ['created_at' => Db::now()]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data): void
	{
		$this->db->update(Tables::API_REQUESTS, $data, ['id' => $id]);
	}

	/**
	 * Zapytania, które mogły zostać zafakturowane (wykonane, niecache'owane, nie odrzucone 401/403/429).
	 */
	public function countBillable(string $provider, string $sinceUtc, ?int $organizationId = null, ?int $searchId = null): int
	{
		$sql = "SELECT COUNT(*) FROM {$this->db->table(Tables::API_REQUESTS)} WHERE provider = %s AND billable = 1 AND created_at >= %s";
		$args = [$provider, $sinceUtc];

		if ($organizationId !== null) {
			$sql .= ' AND organization_id = %d';
			$args[] = $organizationId;
		}

		if ($searchId !== null) {
			$sql .= ' AND search_id = %d';
			$args[] = $searchId;
		}

		return (int) $this->db->value($sql, ...$args);
	}

	public function countForSearch(int $searchId, string $provider): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->db->table(Tables::API_REQUESTS)} WHERE search_id = %d AND provider = %s AND cached = 0",
			$searchId,
			$provider
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function stats(string $provider, ?int $organizationId = null): array
	{
		$where = 'provider = %s';
		$args = [$provider];

		if ($organizationId !== null) {
			$where .= ' AND organization_id = %d';
			$args[] = $organizationId;
		}

		$t = $this->db->table(Tables::API_REQUESTS);
		$today = gmdate('Y-m-d 00:00:00');
		$month = gmdate('Y-m-01 00:00:00');

		$row = $this->db->row(
			"SELECT
				SUM(created_at >= %s AND cached = 0) AS requests_today,
				SUM(created_at >= %s AND billable = 1) AS billable_today,
				SUM(created_at >= %s AND cached = 0) AS requests_month,
				SUM(created_at >= %s AND billable = 1) AS billable_month,
				SUM(created_at >= %s AND cached = 1) AS cache_hits_month,
				SUM(created_at >= %s AND error IS NOT NULL) AS errors_month,
				SUM(CASE WHEN created_at >= %s THEN COALESCE(results_count, 0) ELSE 0 END) AS companies_month,
				SUM(CASE WHEN created_at >= %s THEN COALESCE(new_count, 0) ELSE 0 END) AS new_companies_month,
				SUM(CASE WHEN created_at >= %s THEN credits_reported ELSE NULL END) AS credits_reported_month,
				AVG(CASE WHEN created_at >= %s AND cached = 0 THEN duration_ms END) AS avg_ms_month
			FROM {$t} WHERE {$where}",
			...array_merge([$today, $today, $month, $month, $month, $month, $month, $month, $month, $month], $args)
		) ?? [];

		$recent = $this->db->rows(
			"SELECT endpoint, http_status, duration_ms, cached, results_count, new_count, duplicate_count, error, created_at
			FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT 10",
			...$args
		);

		return [
			'requests_today' => (int) ($row['requests_today'] ?? 0),
			'billable_today' => (int) ($row['billable_today'] ?? 0),
			'requests_month' => (int) ($row['requests_month'] ?? 0),
			'billable_month' => (int) ($row['billable_month'] ?? 0),
			'cache_hits_month' => (int) ($row['cache_hits_month'] ?? 0),
			'errors_month' => (int) ($row['errors_month'] ?? 0),
			'companies_month' => (int) ($row['companies_month'] ?? 0),
			'new_companies_month' => (int) ($row['new_companies_month'] ?? 0),
			'credits_reported_month' => isset($row['credits_reported_month']) ? (int) $row['credits_reported_month'] : null,
			'avg_ms_month' => isset($row['avg_ms_month']) ? (int) round((float) $row['avg_ms_month']) : null,
			'recent' => $recent,
		];
	}
}
