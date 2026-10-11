<?php

namespace App\Ubr\Infrastructure\Repositories;

use App\Ubr\Domain\Calling\CallReadiness;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Cold Calling na listach (Discover / Prospects): telefony, gotowość do telefonu, opiekun, wielkość firmy, ostatni kontakt.
 *
 * Fragmenty SQL działają na aliasach list: `c` (firma), `p` (prospekt organizacji, może być NULL), `li` (Lead Intelligence).
 * `$org` to WYRAŻENIE kolumny organizacji z zapytania listy (np. „r.organization_id”), nigdy dane użytkownika. Wartości filtrów
 * pochodzą z whitelist / są rzutowane na int — bezpieczne. Filtry działają na całym zbiorze (WHERE), nie na stronie wyników.
 */
final class CallFilters
{
	/**
	 * Filtr telefonu (jeden, bez duplikatów): direct = dowolny bezpośredni numer decydenta (potwierdzony lub prawdopodobny),
	 * dm_verified = potwierdzony, dm_review = do weryfikacji (prawdopodobny / niepewny, bez potwierdzonego),
	 * company_only = tylko numer firmy, no_dm = brak numeru decydenta, company / any / none — jak w Prompt 10.
	 */
	public const PHONE = ['direct', 'dm_verified', 'dm_review', 'company_only', 'no_dm', 'company', 'any', 'none'];

	public const PHONE_SOURCES = ['website', 'manual', 'apollo'];

	public const OWNER = ['free', 'mine', 'others', 'contacted', 'dnc'];

	public const SIZES = \App\Ubr\Domain\Calling\CompanySize::BANDS;

	public const CONTACTED = ['7', '30', '90', 'never'];

	public const SORTS = ['readiness', 'size', 'last_contact', 'last_analysis', 'name_az'];

	private static function t(string $table): string
	{
		return Tables::name($table);
	}

	private static function roles(): string
	{
		return "'" . implode("','", CallReadiness::DECISION_ROLES) . "'";
	}

	/**
	 * Powiązanie numer ↔ decydent (Prompt 10.2) widziane przez organizację: decyzja organizacji wygrywa z powiązaniem
	 * automatycznym; osoba aktywna, z rolą decydenta, nie była / nie z innej firmy, nieodrzucona przez organizację.
	 *
	 * @param list<string> $statuses
	 */
	public static function link(string $org, array $statuses, ?string $source = null): string
	{
		$ct = self::t(Tables::CONTACTS);
		$lk = self::t(Tables::CONTACT_PHONE_LINKS);
		$rv = self::t(Tables::CONTACT_REVIEWS);
		$roles = self::roles();
		$in = "'" . implode("','", array_intersect($statuses, ['VERIFIED', 'LIKELY', 'UNCERTAIN', 'REJECTED'])) . "'";
		$src = match ($source) {
			'website' => " AND l.source_type = 'WEBSITE'",
			'manual' => " AND l.source_type = 'MANUAL'",
			default => '',
		};

		return "EXISTS (SELECT 1 FROM {$lk} l INNER JOIN {$ct} lc ON lc.id = l.contact_id WHERE l.company_id = c.id AND l.org_key IN (0, {$org}) AND l.link_status IN ({$in}){$src}
			AND (l.org_key = {$org} OR NOT EXISTS (SELECT 1 FROM {$lk} lo WHERE lo.contact_id = l.contact_id AND lo.e164 = l.e164 AND lo.org_key = {$org}))
			AND lc.active = 1 AND lc.org_key IN (0, {$org}) AND lc.normalized_role IN ({$roles})
			AND COALESCE(lc.employment_status, '') <> 'former' AND COALESCE(lc.company_match_status, '') <> 'mismatch'
			AND NOT EXISTS (SELECT 1 FROM {$rv} lr WHERE lr.contact_id = lc.id AND lr.organization_id = {$org} AND lr.status IN ('rejected', 'excluded', 'outdated')))";
	}

	/** Numer decydenta pobrany przez organizację z Apollo (identyfikator osoby), bez numerów odrzuconych przez organizację. */
	public static function apollo(string $org): string
	{
		$ct = self::t(Tables::CONTACTS);
		$xp = self::t(Tables::CONTACT_PHONES);
		$rv = self::t(Tables::CONTACT_REVIEWS);
		$lk = self::t(Tables::CONTACT_PHONE_LINKS);
		$roles = self::roles();

		return "EXISTS (SELECT 1 FROM {$xp} xp INNER JOIN {$ct} xc ON xc.id = xp.contact_id WHERE xp.organization_id = {$org} AND xp.company_id = c.id
				AND xp.phone_type IN ('MOBILE', 'DIRECT', 'OTHER') AND xc.active = 1 AND xc.normalized_role IN ({$roles})
				AND NOT EXISTS (SELECT 1 FROM {$lk} xl WHERE xl.contact_id = xp.contact_id AND xl.e164 = xp.e164 AND xl.org_key = {$org} AND xl.link_status = 'REJECTED')
				AND NOT EXISTS (SELECT 1 FROM {$rv} rvx WHERE rvx.contact_id = xc.id AND rvx.organization_id = {$org} AND rvx.status IN ('rejected', 'excluded', 'outdated')))";
	}

	/**
	 * POTWIERDZONY bezpośredni numer decydenta (podstawa READY_DIRECT): powiązanie VERIFIED (strona firmy z jednoznacznym
	 * dowodem albo potwierdzenie ręczne organizacji) lub numer z Apollo. Numer prawdopodobny (LIKELY) nie daje READY_DIRECT.
	 */
	public static function direct(string $org): string
	{
		return '(' . self::link($org, ['VERIFIED']) . ' OR ' . self::apollo($org) . ')';
	}

	/** Dowolny bezpośredni numer decydenta — potwierdzony lub prawdopodobny (filtr „Ma bezpośredni telefon decydenta”). */
	public static function anyDirect(string $org): string
	{
		return '(' . self::link($org, ['VERIFIED', 'LIKELY']) . ' OR ' . self::apollo($org) . ')';
	}

	/** Ogólny numer firmy: numer główny ze strony lub numer dodany ręcznie przez organizację. */
	public static function company(string $org): string
	{
		$cp = self::t(Tables::COMPANY_PHONES);

		return "(c.main_phone_e164 IS NOT NULL OR EXISTS (SELECT 1 FROM {$cp} cpm WHERE cpm.company_id = c.id AND cpm.org_key = {$org}))";
	}

	/** Twarda blokada (Lead Intelligence) lub zakaz kontaktu / „nie pasuje” w organizacji — najwyższy priorytet. */
	public static function blocked(): string
	{
		return "(COALESCE(li.has_blocker, 0) = 1 OR (p.id IS NOT NULL AND p.archived_at IS NULL AND (p.do_not_contact = 1 OR p.status = 'not_a_fit' OR p.qualification = 'not_a_fit')))";
	}

	/** Dane firmy niepotwierdzone (weryfikacja / domena) — bez numeru: najpierw research, nie szukanie telefonu. */
	public static function uncertain(): string
	{
		return "(COALESCE(c.verification_status, 'pending') <> 'verified' OR c.domain_status IN ('mismatch', 'uncertain'))";
	}

	public static function readiness(string $org): string
	{
		// Prompt 10.2: numer decydenta tylko „prawdopodobny” (bez numeru firmy) = dane do sprawdzenia (RESEARCH).
		// Prompt 10.4: nieaktywna w rejestrze → BLOCKED; strona innego podmiotu (MISMATCH) → RESEARCH (nigdy „gotowa”).
		return 'CASE WHEN ' . self::blocked() . " OR c.verification_status IN ('inactive', 'suspended') THEN 'BLOCKED' WHEN c.domain_status = 'mismatch' THEN 'RESEARCH' WHEN " . self::direct($org) . " THEN 'READY_DIRECT' WHEN " . self::company($org) . " THEN 'CALL_COMPANY' WHEN " . self::uncertain() . ' OR ' . self::link($org, ['LIKELY']) . " THEN 'RESEARCH' ELSE 'FIND_PHONE' END";
	}

	/** Osoba z bezpośrednim numerem (potwierdzonym przed prawdopodobnym) do pokazania na liście. */
	public static function phoneContact(string $org): string
	{
		$ct = self::t(Tables::CONTACTS);
		$xp = self::t(Tables::CONTACT_PHONES);
		$rv = self::t(Tables::CONTACT_REVIEWS);
		$lk = self::t(Tables::CONTACT_PHONE_LINKS);
		$roles = self::roles();
		$eff = fn (string $a, string $statuses) => "EXISTS (SELECT 1 FROM {$lk} {$a} WHERE {$a}.contact_id = ct2.id AND {$a}.org_key IN (0, {$org}) AND {$a}.link_status IN ({$statuses})
				AND ({$a}.org_key = {$org} OR NOT EXISTS (SELECT 1 FROM {$lk} {$a}o WHERE {$a}o.contact_id = {$a}.contact_id AND {$a}o.e164 = {$a}.e164 AND {$a}o.org_key = {$org})))";

		return "(SELECT ct2.id FROM {$ct} ct2 WHERE ct2.company_id = c.id AND ct2.org_key IN (0, {$org}) AND ct2.active = 1 AND ct2.normalized_role IN ({$roles})
			AND COALESCE(ct2.employment_status, '') <> 'former' AND COALESCE(ct2.company_match_status, '') <> 'mismatch'
			AND NOT EXISTS (SELECT 1 FROM {$rv} rv2 WHERE rv2.contact_id = ct2.id AND rv2.organization_id = {$org} AND rv2.status IN ('rejected', 'excluded', 'outdated'))
			AND (" . $eff('pl1', "'VERIFIED','LIKELY'") . " OR EXISTS (SELECT 1 FROM {$xp} xp2 WHERE xp2.organization_id = {$org} AND xp2.contact_id = ct2.id))
			ORDER BY (" . $eff('pl2', "'VERIFIED'") . " OR EXISTS (SELECT 1 FROM {$xp} xp3 WHERE xp3.organization_id = {$org} AND xp3.contact_id = ct2.id)) DESC,
				(ct2.rank_position IS NULL) ASC, ct2.rank_position ASC, ct2.contact_priority_score DESC, ct2.id ASC LIMIT 1)";
	}

	/**
	 * Kolumny Cold Calling dla wierszy strony listy. Liczone OSOBNYM zapytaniem tylko dla identyfikatorów bieżącej strony
	 * (CallPresenter) — w zapytaniu listy podzapytania skorelowane byłyby liczone dla całego zbioru przed sortowaniem.
	 */
	public static function select(string $org): string
	{
		return self::readiness($org) . ' AS call_readiness, ' . self::phoneContact($org) . ' AS phone_contact_id, '
			. 'p.owner_user_id AS p_owner_user_id, p.status AS p_status, p.qualification AS p_qualification, p.archived_at AS p_archived_at, p.do_not_contact AS p_dnc, p.do_not_contact_reason AS p_dnc_reason, p.last_contact_at AS p_last_contact_at, p.last_contact_outcome AS p_last_contact_outcome';
	}

	/**
	 * @param array<string, mixed> $f
	 * @param list<mixed> $args
	 */
	public static function where(array $f, array &$args, string $org, int $userId): string
	{
		$w = '';

		$w .= match ($f['phone'] ?? '') {
			'direct' => ' AND ' . self::anyDirect($org),
			'dm_verified' => ' AND ' . self::direct($org),
			'dm_review' => ' AND ' . self::link($org, ['LIKELY', 'UNCERTAIN']) . ' AND NOT ' . self::direct($org),
			'company_only' => ' AND ' . self::company($org) . ' AND NOT ' . self::anyDirect($org),
			'no_dm' => ' AND NOT ' . self::anyDirect($org),
			'company' => ' AND ' . self::company($org),
			'any' => ' AND (' . self::anyDirect($org) . ' OR ' . self::company($org) . ')',
			'none' => ' AND NOT (' . self::anyDirect($org) . ' OR ' . self::company($org) . ')',
			default => '',
		};

		$w .= match ($f['phone_source'] ?? '') {
			'website' => ' AND ' . self::link($org, ['VERIFIED', 'LIKELY'], 'website'),
			'manual' => ' AND ' . self::link($org, ['VERIFIED', 'LIKELY'], 'manual'),
			'apollo' => ' AND ' . self::apollo($org),
			default => '',
		};

		// Stanowisko decydenta: firma ma aktywną osobę o tej roli (nieodrzuconą przez organizację).
		if (in_array($f['dm_role'] ?? '', CallReadiness::DECISION_ROLES, true)) {
			$w .= " AND EXISTS (SELECT 1 FROM " . self::t(Tables::CONTACTS) . " dr WHERE dr.company_id = c.id AND dr.org_key IN (0, {$org}) AND dr.active = 1 AND dr.normalized_role = %s
				AND COALESCE(dr.employment_status, '') <> 'former' AND COALESCE(dr.company_match_status, '') <> 'mismatch'
				AND NOT EXISTS (SELECT 1 FROM " . self::t(Tables::CONTACT_REVIEWS) . " drr WHERE drr.contact_id = dr.id AND drr.organization_id = {$org} AND drr.status IN ('rejected', 'excluded', 'outdated')))";
			$args[] = $f['dm_role'];
		}

		if (in_array($f['readiness'] ?? '', CallReadiness::STATES, true)) {
			$w .= ' AND (' . self::readiness($org) . ') = %s';
			$args[] = $f['readiness'];
		}

		$active = 'p.id IS NOT NULL AND p.archived_at IS NULL';
		$w .= match ($f['call_owner'] ?? '') {
			'free' => " AND NOT ({$active} AND p.owner_user_id IS NOT NULL)",
			'mine' => " AND {$active} AND p.owner_user_id = " . (int) $userId,
			'others' => " AND {$active} AND p.owner_user_id IS NOT NULL AND p.owner_user_id <> " . (int) $userId,
			'contacted' => " AND {$active} AND p.last_contact_at IS NOT NULL",
			'dnc' => " AND {$active} AND p.do_not_contact = 1",
			default => '',
		};

		if (isset(self::SIZES[$f['size'] ?? ''])) {
			[$min, $max] = self::SIZES[$f['size']];
			$w .= ' AND COALESCE(c.employees_max, c.employees_min) BETWEEN %d AND %d';
			array_push($args, $min, min($max, 100000000));
		} elseif (($f['size'] ?? '') === 'unknown') {
			$w .= ' AND c.employees_max IS NULL AND c.employees_min IS NULL';
		}

		if (($f['contacted'] ?? '') === 'never') {
			$w .= " AND NOT ({$active} AND p.last_contact_at IS NOT NULL)";
		} elseif (in_array($f['contacted'] ?? '', ['7', '30', '90'], true)) {
			$w .= " AND {$active} AND p.last_contact_at >= %s";
			$args[] = gmdate('Y-m-d H:i:s', time() - (int) $f['contacted'] * DAY_IN_SECONDS);
		}

		if (($f['pipeline_stage'] ?? '') !== '' && \App\Ubr\Domain\Pipeline\PipelineCatalog::isStatus((string) $f['pipeline_stage'])) {
			$w .= " AND {$active} AND p.status = %s";
			$args[] = $f['pipeline_stage'];
		}

		if (in_array($f['analysis_state'] ?? '', ['analyzed', 'not_analyzed', 'failed', 'active'], true)) {
			$w .= match ($f['analysis_state']) {
				'analyzed' => " AND (c.analyzed_at IS NOT NULL OR c.analysis_status IN ('completed','partial'))",
				'not_analyzed' => ' AND c.analyzed_at IS NULL AND c.analysis_status IS NULL',
				'failed' => " AND c.analysis_status = 'failed'",
				'active' => " AND c.analysis_status IN ('queued','running')",
			};
		}

		if (($f['industry_q'] ?? '') !== '') {
			$w .= ' AND c.industry LIKE %s';
			$args[] = '%' . $GLOBALS['wpdb']->esc_like((string) $f['industry_q']) . '%';
		}

		if (($f['location_q'] ?? '') !== '') {
			$w .= ' AND (c.city LIKE %s OR c.region LIKE %s)';
			$like = '%' . $GLOBALS['wpdb']->esc_like((string) $f['location_q']) . '%';
			array_push($args, $like, $like);
		}

		// Widok handlowca: „Wolne firmy z telefonem” (bez opiekuna, z dowolnym numerem, bez blokad i zakazu kontaktu).
		if (! empty($f['free_with_phone'])) {
			$w .= " AND NOT ({$active} AND p.owner_user_id IS NOT NULL) AND (" . self::anyDirect($org) . ' OR ' . self::company($org) . ') AND NOT ' . self::blocked();
		}

		return $w;
	}

	public static function order(string $sort, string $org, string $tail): ?string
	{
		return match ($sort) {
			'readiness' => 'FIELD(' . self::readiness($org) . ", 'READY_DIRECT', 'CALL_COMPANY', 'FIND_PHONE', 'RESEARCH', 'BLOCKED') ASC, COALESCE(li.lead_score, -1) DESC, " . $tail,
			'size' => 'COALESCE(c.employees_max, c.employees_min, -1) DESC, ' . $tail,
			'last_contact' => '(p.last_contact_at IS NULL) ASC, p.last_contact_at DESC, ' . $tail,
			'last_analysis' => '(c.analyzed_at IS NULL) ASC, c.analyzed_at DESC, ' . $tail,
			'name_az' => 'c.name ASC, ' . $tail,
			default => null,
		};
	}

	/**
	 * @param array<string, mixed> $q
	 * @return array<string, mixed>
	 */
	public static function fromQuery(array $q): array
	{
		return [
			'phone' => in_array($q['phone'] ?? '', self::PHONE, true) ? $q['phone'] : '',
			'phone_source' => in_array($q['phone_source'] ?? '', self::PHONE_SOURCES, true) ? $q['phone_source'] : '',
			'dm_role' => in_array($q['dm_role'] ?? '', CallReadiness::DECISION_ROLES, true) ? $q['dm_role'] : '',
			'readiness' => in_array($q['readiness'] ?? '', CallReadiness::STATES, true) ? $q['readiness'] : '',
			'call_owner' => in_array($q['call_owner'] ?? '', self::OWNER, true) ? $q['call_owner'] : '',
			'size' => isset(self::SIZES[$q['size'] ?? '']) || ($q['size'] ?? '') === 'unknown' ? $q['size'] : '',
			'contacted' => in_array((string) ($q['contacted'] ?? ''), self::CONTACTED, true) ? (string) $q['contacted'] : '',
			'pipeline_stage' => sanitize_key((string) ($q['pipeline_stage'] ?? '')),
			'analysis_state' => sanitize_key((string) ($q['analysis_state'] ?? '')),
			'industry_q' => mb_substr(sanitize_text_field((string) ($q['industry_q'] ?? '')), 0, 80),
			'location_q' => mb_substr(sanitize_text_field((string) ($q['location_q'] ?? '')), 0, 80),
			'free_with_phone' => ! empty($q['free_with_phone']),
		];
	}
}
