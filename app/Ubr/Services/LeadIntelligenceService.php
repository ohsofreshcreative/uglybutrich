<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Lead\InsightRuleRegistry;
use App\Ubr\Domain\Lead\LeadPotentialCalculator;
use App\Ubr\Domain\Lead\LeadRecommender;
use App\Ubr\Domain\Lead\LeadResearchSummaryBuilder;
use App\Ubr\Domain\Lead\RedesignAssessor;
use App\Ubr\Domain\Lead\WebsiteInsightGenerator;
use App\Ubr\Domain\Tenancy\Role;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Support\UbrException;
use Throwable;

/**
 * Lead Intelligence — ocena firmy w kontekście organizacji WYŁĄCZNIE z zapisanych danych (bez analiz, crawlera,
 * Decision Maker Findera, płatnych API). Wynik jest cache'owany w ubr_lead_intelligence i przeliczany po zmianie danych
 * (zdarzenie `ubr/company/changed` → oznaczenie „stale” → worker w tle). Historia: nowy wpis tylko przy zmianie wyniku
 * albo istotnych danych wejściowych (input_hash). Rekomendacja nigdy nie zmienia pipeline, priorytetu ani kwalifikacji.
 */
class LeadIntelligenceService
{
	public const VERSION = 'li-2026-10-v2';

	public const REASONS = [
		'initial' => 'pierwsza ocena',
		'manual' => 'przeliczenie ręczne',
		'algorithm' => 'nowa wersja algorytmu',
		'verification_done' => 'zmiana weryfikacji firmy / domeny',
		'analysis_done' => 'nowa analiza strony',
		'contacts_done' => 'nowe wyniki wyszukiwania decydentów',
		'contacts_reviewed' => 'decyzja organizacji o kontakcie',
		'prospect_added' => 'dodanie do Prospects',
		'pipeline_changed' => 'zmiana w pipeline (status / kwalifikacja)',
		'relevance_changed' => 'zmiana Relevance Score',
		'profile' => 'odświeżenie przy otwarciu profilu',
		'aging' => 'okresowe odświeżenie (wiek danych, np. świeżość analizy)',
	];

	public function __construct(
		private LeadIntelligenceRepository $repo,
		private CompanyRepository $companies,
		private ResultRepository $results,
		private AnalysisRepository $analyses,
		private PipelineRepository $pipeline,
	) {
	}

	/**
	 * Zakładka „Lead Intelligence” w profilu firmy. Brak / nieaktualna ocena → liczona od razu (tylko zapisane dane).
	 *
	 * @return array<string, mixed>
	 */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		if (! $ctx->can('companies.view')) {
			throw UbrException::forbidden();
		}

		if (! $this->companies->find($companyId) || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$row = $this->repo->current($ctx->organizationId, $companyId);

		if (! $row || (int) $row['stale'] || $row['algorithm_version'] !== self::VERSION) {
			$this->evaluate($ctx->organizationId, $companyId, $row ? ((string) ($row['stale_reason'] ?: 'algorithm')) : 'initial');
			$row = $this->repo->current($ctx->organizationId, $companyId);
		}

		return $this->present($ctx, $row);
	}

	public function recalculate(TenantContext $ctx, int $companyId): array
	{
		if (! $ctx->can('companies.view')) {
			throw UbrException::forbidden();
		}

		if (! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$this->evaluate($ctx->organizationId, $companyId, 'manual');

		return $this->present($ctx, $this->repo->current($ctx->organizationId, $companyId));
	}

	/**
	 * Smart Lead Ranking organizacji (top N, bez blokerów).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function top(TenantContext $ctx, int $limit = 10): array
	{
		if (! $ctx->can('companies.view')) {
			throw UbrException::forbidden();
		}

		$i = 0;

		return array_map(function ($r) use (&$i) {
			$i++;

			return [
				'position' => $i,
				'company' => ['id' => (int) $r['company_id'], 'name' => $r['name'], 'domain' => $r['domain']],
				'lead_score' => $r['lead_score'] !== null ? (int) $r['lead_score'] : null,
				'confidence' => $r['confidence'],
				'coverage' => (int) $r['coverage'],
				'redesign' => $r['redesign'],
				'redesign_label' => RedesignAssessor::LABELS[$r['redesign']] ?? $r['redesign'],
				'recommendation' => $r['recommendation'],
				'recommendation_label' => LeadRecommender::ACTIONS[$r['recommendation']] ?? $r['recommendation'],
				'rank_score' => $r['rank_score'] !== null ? (float) $r['rank_score'] : null,
			];
		}, $this->repo->top($ctx->organizationId, max(1, min(50, $limit))));
	}

	/**
	 * Zmiana danych firmy → oceny do przeliczenia (worker w tle). Nie uruchamia żadnej analizy.
	 */
	public function invalidate(int $companyId, string $reason, ?int $organizationId = null): void
	{
		try {
			$this->repo->markStale($companyId, $organizationId, isset(self::REASONS[$reason]) ? $reason : 'manual');
		} catch (Throwable) {
			// Tabela niezmigrowana — nie blokujemy głównego procesu.
		}
	}

	/**
	 * Ocena + zapis (bieżąca, wnioski, historia). Zwraca wynik obliczeń.
	 *
	 * @return array<string, mixed>
	 */
	public function evaluate(int $organizationId, int $companyId, string $reason = 'manual'): array
	{
		$snapshot = $this->snapshot($organizationId, $companyId);
		$result = $this->compute($snapshot);
		$hash = self::hash($snapshot);
		$analysisId = (int) ($snapshot['analysis']['id'] ?? 0);

		$this->repo->replaceInsights($companyId, $analysisId, $result['insights'], WebsiteInsightGenerator::VERSION);
		$this->repo->save($organizationId, $companyId, [
			'lead_score' => $result['lead']['score'],
			'score_status' => $result['lead']['status'],
			'confidence' => $result['confidence'],
			'coverage' => $result['lead']['coverage']['percent'],
			'redesign' => $result['assessment']['key'],
			'recommendation' => $result['recommendation']['key'],
			'rank_score' => $result['rank']['score'],
			'has_blocker' => $result['blockers'] ? 1 : 0,
			'analysis_id' => $analysisId ?: null,
			'input_hash' => $hash,
			'input_snapshot' => $snapshot,
			'result' => array_diff_key($result, ['insights' => true]),
			'algorithm_version' => self::VERSION,
			'calculated_at' => Db::now(),
		]);
		ActionCenterService::bumpOrganization($organizationId); // Action Center: nowa ocena → nowe liczniki i kolejki

		// Historia tylko przy zmianie wyniku lub istotnych danych wejściowych.
		$last = $this->repo->lastHistory($organizationId, $companyId);
		$score = $result['lead']['score'];

		if (! $last || $last['input_hash'] !== $hash || ($last['lead_score'] === null ? null : (int) $last['lead_score']) !== $score || $last['recommendation'] !== $result['recommendation']['key'] || $last['algorithm_version'] !== self::VERSION) {
			$this->repo->addHistory([
				'organization_id' => $organizationId,
				'company_id' => $companyId,
				'lead_score' => $score,
				'previous_score' => $last && $last['lead_score'] !== null ? (int) $last['lead_score'] : null,
				'score_status' => $result['lead']['status'],
				'confidence' => $result['confidence'],
				'coverage' => $result['lead']['coverage']['percent'],
				'redesign' => $result['assessment']['key'],
				'recommendation' => $result['recommendation']['key'],
				'reason' => isset(self::REASONS[$reason]) ? $reason : 'manual',
				'input_hash' => $hash,
				'input_snapshot' => $snapshot,
				'score_breakdown' => array_map(fn ($c) => ['points' => $c['points'], 'measured_max' => $c['measured_max'], 'max' => $c['max']], $result['lead']['components']),
				'algorithm_version' => self::VERSION,
				'calculated_at' => Db::now(),
			]);
		}

		return $result;
	}

	/**
	 * Czyste obliczenie z danych wejściowych (deterministyczne: te same dane → ten sam wynik).
	 *
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	public function compute(array $s): array
	{
		$insights = WebsiteInsightGenerator::generate($s['analysis'] ?? null);
		$lead = LeadPotentialCalculator::calculate($s, $insights);
		$assessment = RedesignAssessor::assess($s['analysis'] ?? null, $insights);
		$blockers = LeadRecommender::blockers($s);
		$warnings = LeadRecommender::warnings($s, $lead);
		$confidence = LeadRecommender::confidence($s, $lead);
		$recommendation = LeadRecommender::recommend($s, $lead, $assessment, $blockers, $confidence);
		$rank = LeadRecommender::rank($s, $lead, $blockers, $confidence);

		return [
			'version' => self::VERSION,
			'lead' => $lead,
			'insights' => $insights,
			'assessment' => $assessment,
			'blockers' => $blockers,
			'warnings' => $warnings,
			'confidence' => $confidence,
			'recommendation' => $recommendation,
			'rank' => $rank,
			'why' => LeadResearchSummaryBuilder::why($s, $lead, $assessment),
			'summary' => LeadResearchSummaryBuilder::summary($s, $lead, $insights, $assessment, $recommendation, $blockers, $warnings),
			'talking_points' => LeadResearchSummaryBuilder::talkingPoints($insights, $assessment),
		];
	}

	/**
	 * Dane wejściowe oceny (tylko istniejące dane: firma, Relevance organizacji, ostatnia zakończona analiza,
	 * kontakty w widoku organizacji, prospekt organizacji). Bez pól zmiennych w czasie poza kubełkiem aktualności analizy.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(int $organizationId, int $companyId): array
	{
		$c = $this->companies->find($companyId) ?? throw UbrException::notFound('Nie znaleziono firmy.');
		$best = $this->results->bestRelevanceForCompany($companyId, $organizationId);
		$codes = json_decode((string) ($c['industry_codes'] ?? ''), true) ?: [];
		$summary = json_decode((string) ($c['verification_summary'] ?? ''), true) ?: [];

		$analysis = null;
		// Ostatnia ZAKOŃCZONA analiza (completed/partial); nieudana tylko wtedy, gdy innej nie ma (ostrzeżenie, bez pomiarów).
		$finished = $this->analyses->latestForCompany($companyId, true);

		if (! $finished) {
			$latest = $this->analyses->latestForCompany($companyId);
			$finished = $latest && $latest['status'] === 'failed' ? $latest : null;
		}

		if ($finished && in_array($finished['status'], ['completed', 'partial', 'failed'], true)) {
			$criteria = [];

			foreach (['website_weakness', 'technical_weakness'] as $cat) {
				foreach ((array) ($finished['results']['opportunity']['categories'][$cat]['criteria'] ?? []) as $k => $cr) {
					$criteria[$k] = ['points' => $cr['points'] ?? null, 'max' => (int) ($cr['max'] ?? 0)];
				}
			}

			ksort($criteria);
			$finishedTs = strtotime(((string) $finished['finished_at']) . ' UTC') ?: 0;
			$analysis = [
				'id' => (int) $finished['id'],
				'status' => $finished['status'],
				'finished_at' => $finishedTs ? gmdate('c', $finishedTs) : null,
				'fresh' => $finishedTs > 0 && (time() - $finishedTs) <= LeadPotentialCalculator::FRESH_DAYS * 86400,
				'rendered' => ! empty($finished['results']['render']['available']),
				'ai_evaluated' => ($finished['results']['visual']['status'] ?? 'NOT_EVALUATED') === 'evaluated',
				'algorithm_version' => (string) $finished['algorithm_version'],
				'criteria' => $criteria,
				'findings' => array_map(fn ($f) => [
					'key' => (string) ($f['key'] ?? ''),
					'category' => (string) ($f['category'] ?? ''),
					'severity' => (string) ($f['severity'] ?? 'info'),
					'source' => (string) ($f['source'] ?? 'automated'),
					'title' => (string) ($f['title'] ?? ''),
					'description' => (string) ($f['description'] ?? ''),
					'evidence' => array_values(array_map('strval', (array) ($f['evidence'] ?? []))),
					'recommendation' => (string) ($f['recommendation'] ?? ''),
				], (array) ($finished['findings'] ?? [])),
			];
		}

		$prospect = $this->pipeline->prospectForCompany($organizationId, $companyId);

		return [
			'company' => [
				'id' => (int) $c['id'],
				'name' => (string) $c['name'],
				'domain' => $c['domain'] ?: null,
				'verification_status' => (string) ($c['verification_status'] ?: 'pending'),
				'domain_status' => (string) ($c['domain_status'] ?: 'unchecked'),
				'pkd_main' => $c['pkd_main'] ?: null,
				'sic' => (string) (($codes['sic'] ?? [])[0] ?? '') ?: null,
				'employees' => (int) ($c['employees_min'] ?? 0) ?: (int) ($c['employees_max'] ?? 0),
				'fit_flags' => array_values((array) (json_decode((string) ($c['fit_flags'] ?? ''), true) ?: [])),
				'discrepancies' => count((array) ($summary['discrepancies'] ?? [])),
			],
			'relevance' => $best && $best['relevance_score'] !== null ? (int) $best['relevance_score'] : null,
			'analysis' => $analysis,
			'contacts' => $this->contactsSnapshot($organizationId, $c),
			'prospect' => $prospect ? ['status' => (string) $prospect['status'], 'qualification' => $prospect['qualification'], 'archived' => (bool) $prospect['archived_at']] : null,
		];
	}

	/**
	 * Kontakty w widoku organizacji (odrzucone / wykluczone / nieaktualne decyzją organizacji nie są liczone).
	 *
	 * @param array<string, mixed> $company
	 * @return array<string, mixed>
	 */
	private function contactsSnapshot(int $organizationId, array $company): array
	{
		$researched = in_array($company['contacts_status'] ?? '', ['completed', 'partial'], true);
		$people = [];
		$channels = count((array) (json_decode((string) ($company['company_channels'] ?? ''), true) ?: []));

		if ($researched || (int) ($company['contacts_count'] ?? 0) > 0) {
			try {
				$ctx = new TenantContext(0, $organizationId, Role::Member, app(OrganizationRepository::class)->find($organizationId) ?? ['id' => $organizationId]);
				$view = app(ContactResearchService::class)->forCompany($ctx, (int) $company['id']);

				foreach (array_merge((array) ($view['top'] ?? []), (array) ($view['others'] ?? [])) as $p) {
					$people[] = [
						'name' => (string) $p['full_name'],
						'title' => (string) ($p['job_title'] ?? ''),
						'status' => (string) ($p['verification']['status'] ?? ''),
						'confidence' => (string) ($p['confidence']['level'] ?? ''),
						'score' => $p['score'] !== null ? (int) $p['score'] : null,
						'email' => ! empty($p['email']),
						'phone' => ! empty($p['phone']),
					];
				}

				$researched = $researched || $people !== [];
			} catch (Throwable) {
				$people = [];
			}
		}

		return ['researched' => $researched, 'status' => $company['contacts_status'] ?: null, 'people' => $people, 'channels' => $channels];
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	public static function hash(array $snapshot): string
	{
		return hash('sha256', (string) wp_json_encode($snapshot) . '|' . self::VERSION);
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, mixed>
	 */
	public function present(TenantContext $ctx, ?array $row): array
	{
		if (! $row) {
			return ['available' => false];
		}

		$r = (array) $row['result'];
		$companyId = (int) $row['company_id'];
		$insights = array_map(fn ($i) => [
			'id' => (int) $i['id'],
			'company_id' => (int) $i['company_id'],
			'key' => $i['insight_key'],
			'category' => $i['category'],
			'category_label' => InsightRuleRegistry::CATEGORIES[$i['category']] ?? $i['category'],
			'severity' => $i['severity'],
			'title' => $i['title'],
			'description' => $i['description'],
			'evidence' => $i['evidence'],
			'source' => $i['source'],
			'recommendation' => $i['recommendation'],
			'confidence' => $i['confidence'],
			'generated_at' => gmdate('c', strtotime($i['generated_at'] . ' UTC')),
			'algorithm_version' => $i['algorithm_version'],
		], (int) ($row['analysis_id'] ?? 0) ? $this->repo->insights($companyId) : []);

		$history = array_map(fn ($h) => [
			'id' => (int) $h['id'],
			'score' => $h['lead_score'] !== null ? (int) $h['lead_score'] : null,
			'previous' => $h['previous_score'] !== null ? (int) $h['previous_score'] : null,
			'delta' => $h['lead_score'] !== null && $h['previous_score'] !== null ? (int) $h['lead_score'] - (int) $h['previous_score'] : null,
			'status' => $h['score_status'],
			'confidence' => $h['confidence'],
			'coverage' => (int) $h['coverage'],
			'redesign' => $h['redesign'],
			'recommendation' => $h['recommendation'],
			'recommendation_label' => LeadRecommender::ACTIONS[$h['recommendation']] ?? $h['recommendation'],
			'reason' => self::REASONS[$h['reason']] ?? $h['reason'],
			'version' => $h['algorithm_version'],
			'at' => gmdate('c', strtotime($h['calculated_at'] . ' UTC')),
		], $this->repo->history($ctx->organizationId, $companyId));

		$s = (array) $row['input_snapshot'];

		return [
			'available' => true,
			'company_id' => $companyId,
			'lead_score' => $row['lead_score'] !== null ? (int) $row['lead_score'] : null,
			'status' => $row['score_status'],
			'confidence' => $row['confidence'],
			'confidence_label' => LeadRecommender::CONFIDENCE[$row['confidence']] ?? $row['confidence'],
			'coverage' => (int) $row['coverage'],
			'coverage_detail' => $r['lead']['coverage'] ?? null,
			'redesign' => $r['assessment'] ?? null,
			'recommendation' => $r['recommendation'] ?? null,
			'rank' => $r['rank'] ?? null,
			'blockers' => $r['blockers'] ?? [],
			'warnings' => $r['warnings'] ?? [],
			'why' => $r['why'] ?? [],
			'summary' => $r['summary'] ?? [],
			'talking_points' => $r['talking_points'] ?? [],
			'breakdown' => [
				'components' => array_values($r['lead']['components'] ?? []),
				'points' => $r['lead']['points'] ?? null,
				'measured_max' => $r['lead']['measured_max'] ?? null,
				'cap' => $r['lead']['cap'] ?? null,
				'capped' => $r['lead']['capped'] ?? false,
				'explanation' => $r['lead']['explanation'] ?? '',
			],
			'insights' => $insights,
			'decision_makers' => array_slice((array) ($s['contacts']['people'] ?? []), 0, 5),
			'contacts_researched' => (bool) ($s['contacts']['researched'] ?? false),
			'analysis' => isset($s['analysis']['id']) ? ['id' => $s['analysis']['id'], 'status' => $s['analysis']['status'], 'finished_at' => $s['analysis']['finished_at'], 'fresh' => $s['analysis']['fresh'], 'rendered' => $s['analysis']['rendered']] : null,
			'history' => $history,
			'calculated_at' => gmdate('c', strtotime($row['calculated_at'] . ' UTC')),
			'algorithm_version' => $row['algorithm_version'],
			'versions' => ['lead' => LeadPotentialCalculator::VERSION, 'insights' => WebsiteInsightGenerator::VERSION, 'service' => self::VERSION],
			'notes' => [
				'Lead Intelligence liczy wyłącznie zapisane dane — nie uruchamia analiz, crawlera ani płatnych API.',
				'Rekomendacja to sugestia: nie zmienia statusu, priorytetu, właściciela ani kwalifikacji.',
				'Wygląd strony nie jest oceniany (AI Vision wyłączone).',
			],
		];
	}
}
