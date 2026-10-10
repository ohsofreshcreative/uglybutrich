<?php

namespace App\Ubr\Infrastructure\Database;

/**
 * Nazwy tabel aplikacji (prefiks WordPressa + prefiks aplikacji `ubr_`).
 */
final class Tables
{
	public const ORGANIZATIONS = 'ubr_organizations';
	public const MEMBERS = 'ubr_organization_members';
	public const COMPANIES = 'ubr_companies';
	public const COMPANY_EXTERNAL_IDS = 'ubr_company_external_ids';
	public const COMPANY_EVIDENCE = 'ubr_company_evidence';
	public const PROSPECTS = 'ubr_organization_prospects';
	public const SEARCHES = 'ubr_discovery_searches';
	public const RESULTS = 'ubr_discovery_results';
	public const EVENTS = 'ubr_discovery_events';
	public const USAGE = 'ubr_usage_events';
	public const API_REQUESTS = 'ubr_api_requests';
	public const COMPANY_CHECKS = 'ubr_company_checks';
	public const VERIFICATION_QUEUE = 'ubr_verification_queue';
	public const ANALYSES = 'ubr_website_analyses';
	public const CONTACT_RUNS = 'ubr_contact_research_runs';
	public const CONTACTS = 'ubr_contacts';
	public const CONTACT_SOURCES = 'ubr_contact_sources';
	public const CONTACT_SCORE_HISTORY = 'ubr_contact_score_history';
	public const CONTACT_REVIEWS = 'ubr_contact_reviews';
	public const CONTACT_SUPPRESSIONS = 'ubr_contact_suppressions';
	public const PROSPECT_EVENTS = 'ubr_prospect_events';
	public const PROSPECT_NOTES = 'ubr_prospect_notes';
	public const PROSPECT_ACTIVITIES = 'ubr_prospect_activities';
	public const PROSPECT_FOLLOWUPS = 'ubr_prospect_followups';
	public const CLICKUP_CONNECTIONS = 'ubr_clickup_connections';
	public const CLICKUP_EXPORTS = 'ubr_clickup_exports';
	public const CLICKUP_JOBS = 'ubr_clickup_jobs';
	public const CLICKUP_RULES = 'ubr_clickup_rules';

	public static function name(string $table): string
	{
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * @return list<string>
	 */
	public static function all(): array
	{
		return [
			self::ORGANIZATIONS,
			self::MEMBERS,
			self::COMPANIES,
			self::COMPANY_EXTERNAL_IDS,
			self::COMPANY_EVIDENCE,
			self::PROSPECTS,
			self::SEARCHES,
			self::RESULTS,
			self::EVENTS,
			self::USAGE,
			self::API_REQUESTS,
			self::COMPANY_CHECKS,
			self::VERIFICATION_QUEUE,
			self::ANALYSES,
			self::CONTACT_RUNS,
			self::CONTACTS,
			self::CONTACT_SOURCES,
			self::CONTACT_SCORE_HISTORY,
			self::CONTACT_REVIEWS,
			self::CONTACT_SUPPRESSIONS,
			self::PROSPECT_EVENTS,
			self::PROSPECT_NOTES,
			self::PROSPECT_ACTIVITIES,
			self::PROSPECT_FOLLOWUPS,
			self::CLICKUP_CONNECTIONS,
			self::CLICKUP_EXPORTS,
			self::CLICKUP_JOBS,
			self::CLICKUP_RULES,
		];
	}
}
