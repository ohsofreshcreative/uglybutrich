<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Companies\FitFlags;
use App\Ubr\Domain\Discovery\RelevanceScorer;
use App\Ubr\Domain\Verification\DomainStatus;
use App\Ubr\Domain\Verification\VerificationStatus;
use App\Ubr\Infrastructure\Http\UrlGuard;

/**
 * Jednolity kształt danych firmy dla API (niezależny od widoków).
 */
final class CompanyPresenter
{
	public const PROVIDER_LABELS = [
		'wikidata' => 'Wikidata',
		'osm' => 'OpenStreetMap',
		'apollo' => 'Apollo',
		'website_check' => 'Weryfikacja strony',
		'krs' => 'KRS',
		'vat' => 'Biała lista VAT',
		'ceidg' => 'CEIDG',
		'gus' => 'GUS / REGON',
		'website' => 'Strona WWW',
	];

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function row(array $r): array
	{
		return [
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'domain' => $r['domain'] ?: null,
			'website_url' => $r['website_url'] ?? null,
			'domain_confidence' => $r['domain'] ? ($r['domain_confidence'] ?? Confidence::UNCERTAIN) : Confidence::UNAVAILABLE,
			'website_status' => $r['website_status'] ?? 'unchecked',
			'industry' => $r['industry'] ?: null,
			'city' => $r['city'] ?: null,
			'region' => $r['region'] ?: null,
			'size' => self::size($r),
			'nip' => $r['nip'] ?? null,
			'provider' => $r['provider'] ?? null,
			'provider_label' => isset($r['provider']) ? (self::PROVIDER_LABELS[$r['provider']] ?? $r['provider']) : null,
			'matched_terms' => $r['matched_terms'] ?? null,
			'prospect_id' => ! empty($r['prospect_id']) ? (int) $r['prospect_id'] : null,
			'saved_at' => isset($r['saved_at']) ? gmdate('c', strtotime($r['saved_at'] . ' UTC')) : null,
			'status' => $r['status'] ?? null,
			'location' => [
				'confidence' => ($r['city'] ?? null) || ($r['region'] ?? null) ? ($r['location_confidence'] ?? Confidence::DECLARED) : Confidence::UNAVAILABLE,
				'source' => $r['location_source'] ?? null,
				'match' => $r['location_match'] ?? null,
			],
			'relevance' => isset($r['relevance_score']) && $r['relevance_score'] !== null ? (int) $r['relevance_score'] : null,
			'relevance_details' => self::relevance($r['relevance'] ?? null),
			'verification' => [
				'status' => $r['verification_status'] ?? VerificationStatus::PENDING,
				'label' => VerificationStatus::label($r['verification_status'] ?? null),
				'confidence' => $r['verification_confidence'] ?? null,
				'source' => $r['verification_source'] ?? null,
				'checked_at' => ! empty($r['verification_checked_at']) ? gmdate('c', strtotime($r['verification_checked_at'] . ' UTC')) : null,
			],
			'domain_status' => [
				'status' => $r['domain_status'] ?? DomainStatus::UNCHECKED,
				'label' => DomainStatus::label($r['domain_status'] ?? null),
				'checked_at' => ! empty($r['domain_checked_at']) ? gmdate('c', strtotime($r['domain_checked_at'] . ' UTC')) : null,
			],
			'flags' => FitFlags::present(json_decode((string) ($r['fit_flags'] ?? ''), true) ?: []),
			'analysis' => self::analysis($r),
			'contacts' => ContactResearchService::summary($r),
			'lead' => array_key_exists('li_status', $r) ? self::lead($r) : null,
			'pipeline' => isset($r['pipeline_status']) ? [
				'status' => $r['pipeline_status'],
				'label' => \App\Ubr\Domain\Pipeline\PipelineCatalog::statusLabel($r['pipeline_status']),
				'priority' => $r['priority'] ?? 'medium',
				'priority_label' => \App\Ubr\Domain\Pipeline\PipelineCatalog::PRIORITIES[$r['priority'] ?? 'medium'] ?? null,
				'owner' => app(PipelineService::class)->userBrief(! empty($r['owner_user_id']) ? (int) $r['owner_user_id'] : null),
				'qualification' => $r['qualification'] ?? null,
				'next_action_at' => ! empty($r['next_action_at']) ? gmdate('c', strtotime($r['next_action_at'] . ' UTC')) : null,
				'overdue' => ! empty($r['next_action_at']) && strtotime($r['next_action_at'] . ' UTC') < time(),
				'archived' => ! empty($r['archived_at']),
				'clickup_task_url' => UrlGuard::httpUrl($r['clickup_task_url'] ?? null),
			] : null,
		];
	}

	/**
	 * Lead Intelligence na listach (z kolumn ubr_lead_intelligence dołączonych w zapytaniu — bez liczenia w żądaniu).
	 *
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function lead(array $r): array
	{
		if ($r['li_status'] === null) {
			return ['available' => false, 'pending' => true];
		}

		return [
			'available' => true,
			'pending' => (bool) $r['li_stale'],
			'score' => $r['li_score'] !== null ? (int) $r['li_score'] : null,
			'status' => $r['li_status'],
			'confidence' => $r['li_confidence'],
			'coverage' => (int) $r['li_coverage'],
			'redesign' => $r['li_redesign'],
			'redesign_label' => \App\Ubr\Domain\Lead\RedesignAssessor::LABELS[$r['li_redesign']] ?? $r['li_redesign'],
			'recommendation' => $r['li_recommendation'],
			'recommendation_label' => \App\Ubr\Domain\Lead\LeadRecommender::ACTIONS[$r['li_recommendation']] ?? $r['li_recommendation'],
			'rank' => $r['li_rank'] !== null ? (float) $r['li_rank'] : null,
			'blocker' => (bool) $r['li_blocker'],
		];
	}

	public const ANALYSIS_LABELS = [
		'' => 'Nieanalizowana',
		'queued' => 'W kolejce',
		'running' => 'Analizowanie',
		'completed' => 'Gotowa',
		'partial' => 'Częściowa',
		'failed' => 'Błąd',
	];

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function analysis(array $r): array
	{
		$status = $r['analysis_status'] ?? null;

		return [
			'status' => $status,
			'label' => self::ANALYSIS_LABELS[(string) $status] ?? 'Nieanalizowana',
			'analysis_id' => ! empty($r['last_analysis_id']) ? (int) $r['last_analysis_id'] : null,
			'opportunity' => isset($r['opportunity_score']) && $r['opportunity_score'] !== null ? (int) $r['opportunity_score'] : null,
			'level' => $r['opportunity_level'] ?? null,
			'confidence' => $r['opportunity_confidence'] ?? null,
			'coverage' => isset($r['opportunity_coverage']) && $r['opportunity_coverage'] !== null ? (int) $r['opportunity_coverage'] : null,
			'findings_count' => isset($r['findings_count']) && $r['findings_count'] !== null ? (int) $r['findings_count'] : null,
			'analyzed_at' => ! empty($r['analyzed_at']) ? gmdate('c', strtotime($r['analyzed_at'] . ' UTC')) : null,
			'can_preview' => ! empty($r['last_analysis_id']),
		];
	}

	/**
	 * Zwięzłe szczegóły trafności dla UI (bez danych wewnętrznych).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function relevance(?string $json): ?array
	{
		$data = $json ? json_decode($json, true) : null;

		if (! is_array($data)) {
			return null;
		}

		$criteria = [];

		foreach ((array) ($data['criteria'] ?? []) as $key => $c) {
			$criteria[] = [
				'key' => $key,
				'label' => ['industry' => 'Branża', 'keywords' => 'Słowa kluczowe', 'location' => 'Lokalizacja', 'size' => 'Wielkość'][$key] ?? $key,
				'state' => $c['state'] ?? 'unknown',
				'state_label' => RelevanceScorer::STATE_LABELS[$c['state'] ?? 'unknown'] ?? ($c['state'] ?? ''),
				'points' => (int) ($c['points'] ?? 0),
				'max' => (int) ($c['max'] ?? 0),
				'detail' => (string) ($c['detail'] ?? ''),
			];
		}

		return [
			'criteria' => $criteria,
			'penalties' => array_values((array) ($data['penalties'] ?? [])),
			'incomplete' => array_values((array) ($data['incomplete'] ?? [])),
		];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array{label: ?string, confidence: string}
	 */
	public static function size(array $r): array
	{
		$min = isset($r['employees_min']) ? (int) $r['employees_min'] : null;
		$max = isset($r['employees_max']) ? (int) $r['employees_max'] : null;

		if (! $min && ! $max) {
			return ['label' => null, 'confidence' => Confidence::UNAVAILABLE];
		}

		$label = $min === $max ? number_format_i18n((float) $min) . ' os.' : trim(($min ?: '?') . '–' . ($max ?: '?')) . ' os.';

		return ['label' => $label, 'confidence' => $r['employees_confidence'] ?? Confidence::UNCERTAIN];
	}
}
