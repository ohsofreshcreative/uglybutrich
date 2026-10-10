<?php

namespace App\Ubr\Infrastructure\Repositories;

/**
 * Filtry Decision Maker Findera na listach (Discover / Prospects) — po zdenormalizowanych kolumnach firmy (alias `c`).
 * Wartości z whitelist / rzutowane na int — bezpieczne w SQL.
 */
final class ContactFilters
{
	public const ROLE_GROUPS = [
		'leadership' => ['owner', 'founder', 'ceo', 'managing_director'],
		'board' => ['vice_president', 'board_member', 'proxy'],
		'marketing' => ['marketing_director', 'digital_head', 'marketing_manager', 'marketing_specialist'],
	];

	/**
	 * @param array<string, mixed> $extra
	 * @param list<mixed> $args
	 */
	public static function where(array $extra, array &$args): string
	{
		$where = '';
		$has = (string) ($extra['has_contact'] ?? '');

		if ($has === 'yes') {
			$where .= ' AND c.top_contact_id IS NOT NULL';
		} elseif ($has === 'no') {
			$where .= " AND c.contacts_status IN ('completed','partial') AND c.top_contact_id IS NULL";
		} elseif ($has === 'not_researched') {
			$where .= ' AND c.contacts_status IS NULL';
		}

		if (! empty($extra['min_contacts'])) {
			$where .= ' AND c.contacts_count >= %d';
			$args[] = max(0, min(50, (int) $extra['min_contacts']));
		}

		if (! empty($extra['min_contact_score'])) {
			$where .= ' AND c.top_contact_score >= %d';
			$args[] = max(0, min(100, (int) $extra['min_contact_score']));
		}

		if (in_array($extra['contact_confidence'] ?? '', ['low', 'medium', 'high'], true)) {
			$where .= ' AND c.top_contact_confidence = %s';
			$args[] = $extra['contact_confidence'];
		}

		$group = (string) ($extra['contact_role'] ?? '');

		if (isset(self::ROLE_GROUPS[$group])) {
			$where .= " AND c.top_contact_role IN ('" . implode("','", self::ROLE_GROUPS[$group]) . "')";
		}

		return $where;
	}

	public const ORDER = 'COALESCE(c.top_contact_score, -1) DESC, COALESCE(c.contacts_count, 0) DESC';
}
