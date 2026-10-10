<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Lead Intelligence: bieżąca ocena (per organizacja), wnioski z analizy (per firma, publiczne) i historia ocen.
 * Każde zapytanie o ocenę jest filtrowane po organization_id.
 */
class LeadIntelligenceRepository
{
	private const JSON = ['input_snapshot', 'result', 'evidence', 'finding_keys', 'score_breakdown'];

	public function __construct(private Db $db)
	{
	}

	private function t(string $table): string
	{
		return $this->db->table($table);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function current(int $organizationId, int $companyId): ?array
	{
		return $this->decode($this->db->row("SELECT * FROM {$this->t(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d AND company_id = %d", $organizationId, $companyId));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function save(int $organizationId, int $companyId, array $data): void
	{
		$now = Db::now();
		$data = $this->encode($data) + ['stale' => 0, 'stale_reason' => null, 'updated_at' => $now];
		$existing = $this->db->value("SELECT id FROM {$this->t(Tables::LEAD_INTELLIGENCE)} WHERE organization_id = %d AND company_id = %d", $organizationId, $companyId);

		if ($existing) {
			$this->db->update(Tables::LEAD_INTELLIGENCE, $data, ['id' => (int) $existing]);

			return;
		}

		$this->db->insert(Tables::LEAD_INTELLIGENCE, $data + ['organization_id' => $organizationId, 'company_id' => $companyId, 'created_at' => $now]);
	}

	/**
	 * Oznaczenie ocen do przeliczenia (dla jednej organizacji albo wszystkich, które mają ocenę tej firmy).
	 */
	public function markStale(int $companyId, ?int $organizationId, string $reason): int
	{
		$org = $organizationId ? ' AND organization_id = ' . (int) $organizationId : '';

		return $this->db->execute("UPDATE {$this->t(Tables::LEAD_INTELLIGENCE)} SET stale = 1, stale_reason = %s, updated_at = %s WHERE company_id = %d{$org}", mb_substr($reason, 0, 40), Db::now(), $companyId);
	}

	/**
	 * Kolejka przeliczeń: oceny nieaktualne / ze starszą wersją algorytmu, potem firmy widoczne dla organizacji bez oceny.
	 *
	 * @return list<array{organization_id: int, company_id: int, reason: string}>
	 */
	public function pending(string $version, int $limit, ?int $organizationId = null): array
	{
		$li = $this->t(Tables::LEAD_INTELLIGENCE);
		$org = $organizationId ? ' AND organization_id = ' . (int) $organizationId : '';
		$out = [];

		foreach ($this->db->rows("SELECT organization_id, company_id, COALESCE(stale_reason, 'algorithm') AS reason FROM {$li} WHERE (stale = 1 OR algorithm_version <> %s){$org} ORDER BY updated_at ASC, id ASC LIMIT %d", $version, $limit) as $r) {
			$out[] = ['organization_id' => (int) $r['organization_id'], 'company_id' => (int) $r['company_id'], 'reason' => (string) $r['reason']];
		}

		$left = $limit - count($out);

		if ($left > 0) {
			$r = $this->t(Tables::RESULTS);
			$p = $this->t(Tables::PROSPECTS);
			$orgR = $organizationId ? ' AND x.organization_id = ' . (int) $organizationId : '';
			$rows = $this->db->rows(
				"SELECT x.organization_id, x.company_id FROM (
					SELECT organization_id, company_id FROM {$r}
					UNION SELECT organization_id, company_id FROM {$p} WHERE archived_at IS NULL
				) x LEFT JOIN {$li} l ON l.organization_id = x.organization_id AND l.company_id = x.company_id
				WHERE l.id IS NULL{$orgR}
				ORDER BY x.organization_id ASC, x.company_id ASC LIMIT %d",
				$left
			);

			foreach ($rows as $row) {
				$out[] = ['organization_id' => (int) $row['organization_id'], 'company_id' => (int) $row['company_id'], 'reason' => 'initial'];
			}
		}

		return $out;
	}

	/**
	 * Liczba pozycji w kolejce przeliczeń (górny limit 500, przybliżona — służy do decyzji „czy jest praca”).
	 * Wywoływana co minutę (healthcheck) i po każdym zdarzeniu — zamiast pełnego UNION z sortowaniem (pending(500))
	 * używa zapytań z LIMIT i NOT EXISTS po unikalnym indeksie (organization_id, company_id).
	 */
	public function countPending(string $version, ?int $organizationId = null): int
	{
		$li = $this->t(Tables::LEAD_INTELLIGENCE);
		$org = $organizationId ? ' AND organization_id = ' . (int) $organizationId : '';
		$stale = (int) $this->db->value("SELECT COUNT(*) FROM (SELECT 1 FROM {$li} WHERE stale = 1{$org} LIMIT 500) t");
		$stale += (int) $this->db->value("SELECT COUNT(*) FROM (SELECT 1 FROM {$li} WHERE stale = 0 AND algorithm_version <> %s{$org} LIMIT 500) t", $version);

		if ($stale >= 500) {
			return 500;
		}

		$missing = 0;

		foreach ([[Tables::RESULTS, ''], [Tables::PROSPECTS, ' AND x.archived_at IS NULL']] as [$table, $extra]) {
			$orgX = $organizationId ? ' AND x.organization_id = ' . (int) $organizationId : '';
			$missing += (int) $this->db->value(
				"SELECT COUNT(*) FROM (SELECT DISTINCT x.organization_id, x.company_id FROM {$this->t($table)} x
				WHERE NOT EXISTS (SELECT 1 FROM {$li} l WHERE l.organization_id = x.organization_id AND l.company_id = x.company_id){$extra}{$orgX}
				LIMIT 500) t"
			);
		}

		return min(500, $stale + $missing);
	}

	public function markAged(int $days): int
	{
		return $this->db->execute("UPDATE {$this->t(Tables::LEAD_INTELLIGENCE)} SET stale = 1, stale_reason = 'aging' WHERE stale = 0 AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS));
	}

	/**
	 * Przesuwa ocenę na koniec kolejki po nieudanym przeliczeniu — jedna „zepsuta” firma nie blokuje pozostałych.
	 */
	public function deferFailed(int $organizationId, int $companyId): void
	{
		$this->db->execute("UPDATE {$this->t(Tables::LEAD_INTELLIGENCE)} SET updated_at = %s WHERE organization_id = %d AND company_id = %d", Db::now(), $organizationId, $companyId);
	}

	/**
	 * @param list<array<string, mixed>> $insights
	 */
	public function replaceInsights(int $companyId, int $analysisId, array $insights, string $version): void
	{
		$current = (int) $this->db->value("SELECT COALESCE(MAX(analysis_id), 0) FROM {$this->t(Tables::LEAD_INSIGHTS)} WHERE company_id = %d", $companyId);
		$currentVersion = (string) $this->db->value("SELECT algorithm_version FROM {$this->t(Tables::LEAD_INSIGHTS)} WHERE company_id = %d LIMIT 1", $companyId);
		$count = (int) $this->db->value("SELECT COUNT(*) FROM {$this->t(Tables::LEAD_INSIGHTS)} WHERE company_id = %d", $companyId);

		// Ta sama analiza i wersja reguł → wnioski już zapisane (bez przepisywania przy każdym przeliczeniu).
		if ($current === $analysisId && $currentVersion === $version && $count === count($insights) && ($count > 0 || $analysisId === 0)) {
			return;
		}

		$this->db->delete(Tables::LEAD_INSIGHTS, ['company_id' => $companyId]);
		$now = Db::now();

		foreach ($insights as $i) {
			$this->db->insert(Tables::LEAD_INSIGHTS, $this->encode([
				'company_id' => $companyId,
				'analysis_id' => $analysisId,
				'insight_key' => mb_substr($i['key'], 0, 40),
				'category' => $i['category'],
				'severity' => $i['severity'],
				'title' => mb_substr($i['title'], 0, 190),
				'description' => $i['description'],
				'evidence' => $i['evidence'],
				'source' => mb_substr($i['source'], 0, 60),
				'recommendation' => $i['recommendation'],
				'confidence' => $i['confidence'],
				'finding_keys' => $i['finding_keys'],
				'algorithm_version' => $version,
				'generated_at' => $now,
			]));
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function insights(int $companyId): array
	{
		return array_map(fn ($r) => $this->decode($r), $this->db->rows("SELECT * FROM {$this->t(Tables::LEAD_INSIGHTS)} WHERE company_id = %d ORDER BY FIELD(severity, 'critical','high','medium','low','info'), id ASC", $companyId));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function addHistory(array $data): int
	{
		return $this->db->insert(Tables::LEAD_HISTORY, $this->encode($data));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function lastHistory(int $organizationId, int $companyId): ?array
	{
		return $this->db->row("SELECT id, lead_score, input_hash, recommendation, redesign, confidence, coverage, algorithm_version FROM {$this->t(Tables::LEAD_HISTORY)} WHERE organization_id = %d AND company_id = %d ORDER BY id DESC LIMIT 1", $organizationId, $companyId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function history(int $organizationId, int $companyId, int $limit = 20): array
	{
		return $this->db->rows("SELECT id, lead_score, previous_score, score_status, confidence, coverage, redesign, recommendation, reason, algorithm_version, calculated_at FROM {$this->t(Tables::LEAD_HISTORY)} WHERE organization_id = %d AND company_id = %d ORDER BY id DESC LIMIT %d", $organizationId, $companyId, $limit);
	}

	/**
	 * Smart Lead Ranking organizacji (aktywne prospekty + wyniki wyszukiwań), deterministyczne remisy.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function top(int $organizationId, int $limit = 10): array
	{
		return $this->db->rows(
			"SELECT li.company_id, li.lead_score, li.confidence, li.coverage, li.redesign, li.recommendation, li.rank_score, c.name, c.domain
			FROM {$this->t(Tables::LEAD_INTELLIGENCE)} li
			INNER JOIN {$this->t(Tables::COMPANIES)} c ON c.id = li.company_id
			WHERE li.organization_id = %d AND li.has_blocker = 0 AND li.rank_score IS NOT NULL
			ORDER BY " . LeadFilters::RANK_ORDER . "
			LIMIT %d",
			$organizationId,
			$limit
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function encode(array $data): array
	{
		foreach (self::JSON as $key) {
			if (array_key_exists($key, $data) && is_array($data[$key])) {
				$data[$key] = wp_json_encode($data[$key]);
			}
		}

		return $data;
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, mixed>|null
	 */
	private function decode(?array $row): ?array
	{
		if (! $row) {
			return null;
		}

		foreach (self::JSON as $key) {
			if (isset($row[$key]) && is_string($row[$key])) {
				$row[$key] = json_decode($row[$key], true) ?: [];
			}
		}

		return $row;
	}
}
