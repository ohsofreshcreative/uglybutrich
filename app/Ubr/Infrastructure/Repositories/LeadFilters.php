<?php

namespace App\Ubr\Infrastructure\Repositories;

/**
 * Filtry i sortowanie Lead Intelligence na listach (Discover / Prospects) — po kolumnach tabeli ocen (alias `li`,
 * dołączanej LEFT JOIN po organization_id + company_id). Wartości z whitelist / rzutowane na int — bezpieczne w SQL.
 */
final class LeadFilters
{
	public const CONFIDENCE = ['low', 'medium', 'high'];

	public const REDESIGN = ['strong', 'moderate', 'weak', 'insufficient_data'];

	public const ACTIONS = ['contact_now', 'research_further', 'review_website', 'verify_company', 'find_decision_maker', 'low_priority', 'do_not_contact'];

	/** Smart Ranking: bez blokerów, ranking score, Lead Potential, kompletność, stabilny remis po ID firmy. */
	public const RANK_ORDER = 'li.has_blocker ASC, (li.rank_score IS NULL) ASC, li.rank_score DESC, COALESCE(li.lead_score, -1) DESC, li.coverage DESC, li.company_id ASC';

	public static function join(string $companyColumn, string $orgColumn, string $table): string
	{
		return " LEFT JOIN {$table} li ON li.company_id = {$companyColumn} AND li.organization_id = {$orgColumn}";
	}

	public static function select(): string
	{
		return 'li.lead_score AS li_score, li.score_status AS li_status, li.confidence AS li_confidence, li.coverage AS li_coverage, li.redesign AS li_redesign, li.recommendation AS li_recommendation, li.rank_score AS li_rank, li.has_blocker AS li_blocker, li.stale AS li_stale';
	}

	/**
	 * @param array<string, mixed> $extra
	 * @param list<mixed> $args
	 */
	public static function where(array $extra, array &$args): string
	{
		$where = '';

		if (isset($extra['min_lead']) && (int) $extra['min_lead'] > 0) {
			$where .= ' AND li.lead_score >= %d';
			$args[] = max(0, min(100, (int) $extra['min_lead']));
		}

		if (in_array($extra['lead_confidence'] ?? '', self::CONFIDENCE, true)) {
			$where .= ' AND li.confidence = %s';
			$args[] = $extra['lead_confidence'];
		}

		if (in_array($extra['redesign'] ?? '', self::REDESIGN, true)) {
			$where .= ' AND li.redesign = %s';
			$args[] = $extra['redesign'];
		}

		if (in_array($extra['recommendation'] ?? '', self::ACTIONS, true)) {
			$where .= ' AND li.recommendation = %s';
			$args[] = $extra['recommendation'];
		}

		if (! empty($extra['no_blockers'])) {
			$where .= ' AND (li.id IS NULL OR li.has_blocker = 0)';
		}

		return $where;
	}

	public static function order(string $sort, string $tail): ?string
	{
		return match ($sort) {
			'lead' => 'COALESCE(li.lead_score, -1) DESC, li.coverage DESC, ' . $tail,
			'rank' => 'li.has_blocker ASC, (li.rank_score IS NULL) ASC, li.rank_score DESC, COALESCE(li.lead_score, -1) DESC, li.coverage DESC, ' . $tail,
			'redesign' => "(li.redesign IS NULL) ASC, FIELD(li.redesign, 'strong', 'moderate', 'weak', 'insufficient_data') ASC, li.has_blocker ASC, li.rank_score DESC, " . $tail,
			default => null,
		};
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public static function fromQuery(array $query): array
	{
		return [
			'min_lead' => (int) ($query['min_lead'] ?? 0),
			'lead_confidence' => sanitize_key((string) ($query['lead_confidence'] ?? '')),
			'redesign' => sanitize_key((string) ($query['redesign'] ?? '')),
			'recommendation' => sanitize_key((string) ($query['recommendation'] ?? '')),
			'no_blockers' => ! empty($query['no_blockers']),
		];
	}
}
