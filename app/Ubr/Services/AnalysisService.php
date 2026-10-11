<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Analysis\OpportunityScoreCalculator;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Ai\VisionService;
use App\Ubr\Infrastructure\Analysis\SiteCapture;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Support\UbrException;

/**
 * Przypadki użycia Website Analyzera dla organizacji: zlecenie (pojedyncze / zbiorcze), wyniki, historia, porównanie.
 *
 * Zasady dostępu: organizacja analizuje i ogląda tylko firmy widoczne dla niej (jej wyniki Discovery / prospekty).
 * Adres strony pochodzi WYŁĄCZNIE z rekordu firmy (domena z Discovery/weryfikacji) — użytkownik nie podaje URL.
 * Wyniki analizy (dane o publicznej stronie) są wspólne; kto zlecił analizę — widzi tylko organizacja zlecająca.
 */
class AnalysisService
{
	public function __construct(
		private AnalysisRepository $analyses,
		private CompanyRepository $companies,
		private AnalysisLimits $limits,
		private WebsiteAnalysisRunner $runner,
	) {
	}

	/**
	 * @param list<int> $companyIds
	 * @return array<string, mixed>
	 */
	public function request(TenantContext $ctx, array $companyIds, bool $force = false, string $trigger = 'manual'): array
	{
		$this->assert($ctx, 'analysis.run');
		$companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));

		if ($companyIds === []) {
			throw UbrException::invalid('Nie wybrano firm.');
		}

		$limits = AnalysisLimits::limits($ctx->organizationId);

		if (count($companyIds) > $limits['bulk_max']) {
			throw UbrException::invalid(sprintf('Jednorazowo można zlecić maks. %d analiz.', $limits['bulk_max']));
		}

		$queued = [];
		$skipped = [];
		$toCreate = [];

		foreach ($companyIds as $id) {
			$company = $this->companies->find($id);

			if (! $company || ! $this->companies->isVisibleTo($id, $ctx->organizationId)) {
				// Pojedyncza firma spoza zasięgu organizacji = 404 (bez ujawniania, czy istnieje); w zbiorczej — pominięcie.
				if (count($companyIds) === 1) {
					throw UbrException::notFound('Nie znaleziono firmy.');
				}

				$skipped[] = ['company_id' => $id, 'reason' => 'not_found'];
				continue;
			}

			$reason = $this->skipReason($company, $force);

			if ($reason) {
				$skipped[] = ['company_id' => $id, 'name' => $company['name'], 'reason' => $reason['code'], 'message' => $reason['message']];
				continue;
			}

			$toCreate[] = $company;
		}

		if ($toCreate) {
			$this->limits->assertCanStart($ctx->organizationId, count($toCreate));
		}

		foreach ($toCreate as $company) {
			$id = $this->analyses->create([
				'company_id' => (int) $company['id'],
				'organization_id' => $ctx->organizationId,
				'user_id' => $ctx->userId,
				'trigger_type' => count($companyIds) > 1 ? 'bulk' : $trigger,
				'url' => mb_substr((string) $company['website_url'], 0, 500),
				'algorithm_version' => WebsiteAnalyzerService::ALGORITHM_VERSION,
			]);
			$this->companies->update((int) $company['id'], ['analysis_status' => 'queued']);
			$queued[] = ['company_id' => (int) $company['id'], 'analysis_id' => $id];
		}

		if ($queued) {
			$this->runner->kick();
		}

		$messages = ['not_found' => 'firma niedostępna', 'no_website' => 'brak strony WWW', 'domain_mismatch' => 'domena niezgodna z firmą', 'in_progress' => 'analiza już trwa', 'fresh' => 'aktualna analiza (< 30 dni)', 'cooldown' => 'analizowana przed chwilą'];
		$summary = [];

		foreach ($skipped as $s) {
			$summary[$s['reason']] = ($summary[$s['reason']] ?? 0) + 1;
		}

		return [
			'queued' => $queued,
			'skipped' => $skipped,
			'message' => trim(sprintf('Zlecono analiz: %d.', count($queued)) . ($summary ? ' Pominięto: ' . implode(', ', array_map(fn ($k, $n) => ($messages[$k] ?? $k) . ' (' . $n . ')', array_keys($summary), $summary)) . '.' : '')),
			'usage' => $this->limits->usage($ctx->organizationId),
		];
	}

	/** Prompt 10.4.3 — automatyczny Website Opportunity Score w Discover (UBR_AUTO_ANALYSIS, domyślnie włączony). */
	public static function autoEnabled(): bool
	{
		return (bool) apply_filters('ubr/analysis/auto', ! defined('UBR_AUTO_ANALYSIS') || constant('UBR_AUTO_ANALYSIS'));
	}

	/** Dzienny limit analiz automatycznych na organizację (UBR_AUTO_ANALYSIS_PER_DAY, domyślnie 300). */
	public static function autoDailyLimit(): int
	{
		return max(0, (int) apply_filters('ubr/analysis/auto_per_day', defined('UBR_AUTO_ANALYSIS_PER_DAY') ? (int) constant('UBR_AUTO_ANALYSIS_PER_DAY') : 300));
	}

	/**
	 * Prompt 10.4.3 — Discover: analiza strony (Website Opportunity Score) zlecana w tle po weryfikacji firmy.
	 * Bez decydentów i bez Apollo; strony zwykle z pamięci podręcznej po weryfikacji. Pomija firmy nieaktywne, z niedostępną
	 * stroną lub domeną innego podmiotu, z trwającą lub aktualną analizą. Nie zużywa limitów analiz ręcznych (osobny limit).
	 * Analizy ręczne mają pierwszeństwo w kolejce.
	 */
	public function queueAuto(int $companyId, int $organizationId): bool
	{
		if (! self::autoEnabled() || $organizationId <= 0) {
			return false;
		}

		$company = $this->companies->find($companyId);

		if (! $company
			|| in_array((string) ($company['verification_status'] ?? ''), ['inactive', 'suspended'], true)
			|| in_array((string) ($company['website_status'] ?? ''), ['unreachable', 'http_error'], true)
			|| $this->skipReason($company, false)
		) {
			return false;
		}

		if ($this->analyses->countAutoForOrganizationSince($organizationId, gmdate('Y-m-d 00:00:00')) >= self::autoDailyLimit()) {
			return false;
		}

		$this->analyses->create([
			'company_id' => $companyId,
			'organization_id' => $organizationId,
			'user_id' => null,
			'trigger_type' => 'auto',
			'url' => mb_substr((string) $company['website_url'], 0, 500),
			'algorithm_version' => WebsiteAnalyzerService::ALGORITHM_VERSION,
		]);
		$this->companies->update($companyId, ['analysis_status' => 'queued']);
		$this->runner->kick();

		return true;
	}

	/**
	 * @param array<string, mixed> $company
	 * @return array{code: string, message: string}|null
	 */
	private function skipReason(array $company, bool $force): ?array
	{
		if (empty($company['domain']) || empty($company['website_url'])) {
			return ['code' => 'no_website', 'message' => 'Firma nie ma strony WWW w danych.'];
		}

		if (($company['domain_status'] ?? '') === 'mismatch') {
			return ['code' => 'domain_mismatch', 'message' => 'Domena niezgodna z firmą (weryfikacja) — analiza nie dotyczyłaby jej strony.'];
		}

		if ($this->analyses->activeForCompany((int) $company['id'])) {
			return ['code' => 'in_progress', 'message' => 'Analiza tej strony już trwa.'];
		}

		$latest = $this->analyses->latestForCompany((int) $company['id'], true);

		// Wynik ze starszej wersji algorytmu nie blokuje ręcznego zlecenia (ani „aktualna”, ani cooldown) — nowa wersja daje inne wyniki.
		$outdated = $latest && ($latest['algorithm_version'] ?? '') !== WebsiteAnalyzerService::ALGORITHM_VERSION;

		if ($latest && $latest['finished_at'] && ! $outdated) {
			$age = time() - strtotime($latest['finished_at'] . ' UTC');
			$limits = AnalysisLimits::limits();

			if (! $force && $age < $limits['fresh_days'] * DAY_IN_SECONDS) {
				return ['code' => 'fresh', 'message' => 'Istnieje aktualna analiza — użyj „Analizuj ponownie”.'];
			}

			if ($force && $age < $limits['manual_cooldown_minutes'] * MINUTE_IN_SECONDS) {
				return ['code' => 'cooldown', 'message' => 'Strona była analizowana przed chwilą — ponowna analiza możliwa po ' . $limits['manual_cooldown_minutes'] . ' min.'];
			}
		}

		return null;
	}

	/**
	 * Najnowsza analiza firmy (pełna) + historia. Profil firmy tylko CZYTA wyniki — nie uruchamia analizy.
	 *
	 * @return array<string, mixed>
	 */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		$this->assertVisible($ctx, $companyId);
		$active = $this->analyses->activeForCompany($companyId);
		$latest = $this->analyses->latestForCompany($companyId, true) ?? $this->analyses->latestForCompany($companyId);

		return [
			'company_id' => $companyId,
			'active' => $active ? ['id' => (int) $active['id'], 'status' => $active['status'], 'created_at' => $this->iso($active['created_at'])] : null,
			'latest' => $latest ? $this->present($latest, $ctx) : null,
			'history' => array_map(fn ($h) => $this->presentLight($h, $ctx), $this->analyses->historyForCompany($companyId, 20)),
			'usage' => $this->limits->usage($ctx->organizationId),
			'can_run' => $ctx->can('analysis.run'),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(TenantContext $ctx, int $analysisId): array
	{
		$analysis = $this->analyses->find($analysisId) ?? throw UbrException::notFound('Nie znaleziono analizy.');
		$this->assertVisible($ctx, (int) $analysis['company_id']);

		return $this->present($analysis, $ctx);
	}

	/**
	 * Porównanie dwóch analiz tej samej firmy (wynik, kategorie, findings nowe/usunięte).
	 *
	 * @return array<string, mixed>
	 */
	public function compare(TenantContext $ctx, int $a, int $b): array
	{
		$first = $this->analyses->find($a) ?? throw UbrException::notFound('Nie znaleziono analizy.');
		$second = $this->analyses->find($b) ?? throw UbrException::notFound('Nie znaleziono analizy.');

		if ((int) $first['company_id'] !== (int) $second['company_id']) {
			throw UbrException::invalid('Można porównać tylko analizy tej samej firmy.');
		}

		$this->assertVisible($ctx, (int) $first['company_id']);
		[$old, $new] = (int) $first['id'] < (int) $second['id'] ? [$first, $second] : [$second, $first];
		$cats = [];

		foreach (OpportunityScoreCalculator::CATEGORIES as $key => $def) {
			$cats[] = [
				'key' => $key,
				'label' => $def['label'],
				'old' => $old['results']['opportunity']['categories'][$key]['points'] ?? null,
				'new' => $new['results']['opportunity']['categories'][$key]['points'] ?? null,
				'max' => $def['max'],
			];
		}

		$keys = fn ($an) => array_column(array_filter((array) $an['findings'], fn ($f) => ($f['source'] ?? '') !== 'manual_review'), 'title', 'key');
		$oldKeys = $keys($old);
		$newKeys = $keys($new);

		return [
			'old' => $this->presentLight($old, $ctx),
			'new' => $this->presentLight($new, $ctx),
			'categories' => $cats,
			'resolved' => array_values(array_diff_key($oldKeys, $newKeys)),
			'new_findings' => array_values(array_diff_key($newKeys, $oldKeys)),
			'algorithm_changed' => $old['algorithm_version'] !== $new['algorithm_version'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function environment(TenantContext $ctx, bool $runCheck = false): array
	{
		$this->assert($ctx, 'integrations.view');
		$capture = app(SiteCapture::class);

		return [
			'renderer' => $runCheck ? $capture->check() : ['node_found' => (bool) $capture->nodeBinary(), 'dependencies' => $capture->dependenciesInstalled(), 'checked' => false],
			'ai_vision' => app(VisionService::class)->status(),
			'lighthouse' => ['available' => false, 'note' => 'Lighthouse / PageSpeed nie są używane — wydajność mierzona laboratoryjnie w Chromium (bez Core Web Vitals).'],
			'limits' => $this->limits->usage($ctx->organizationId),
			'queue' => ['due' => $this->analyses->countDue(), 'running' => $this->analyses->countRunning()],
			'levels' => OpportunityScoreCalculator::levels(),
		];
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>
	 */
	public function present(array $a, TenantContext $ctx): array
	{
		$results = (array) $a['results'];
		$screens = [];

		foreach ((array) $a['screenshots'] as $view => $shot) {
			$screens[$view] = [
				'url' => self::screenshotUrl((int) $a['id'], $view),
				'width' => $shot['width'] ?? null,
				'height' => $shot['height'] ?? null,
				'full_height' => $shot['full_height'] ?? null,
				'truncated' => ! empty($shot['truncated']),
				'scroll_expanded' => ! empty($shot['scroll_container_expanded']),
				'kb' => isset($shot['bytes']) ? (int) round($shot['bytes'] / 1024) : null,
			];
		}

		return $this->presentLight($a, $ctx) + [
			'url' => $a['url'],
			'final_url' => $a['final_url'],
			'opportunity' => $results['opportunity'] ?? null,
			'checks' => $results['checks'] ?? [],
			'pages' => $results['pages'] ?? [],
			'visual' => $results['visual'] ?? null,
			'render' => $results['render'] ?? null,
			'preview' => $results['preview'] ?? null,
			'findings' => array_values((array) $a['findings']),
			'errors' => array_map(fn ($e) => ['stage' => $e['stage'] ?? '', 'message' => (string) ($e['message'] ?? '')], (array) $a['errors']),
			'tools' => (array) $a['tools'],
			'screenshots' => $screens,
		];
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>
	 */
	public function presentLight(array $a, TenantContext $ctx): array
	{
		return [
			'id' => (int) $a['id'],
			'company_id' => (int) $a['company_id'],
			'status' => $a['status'],
			'trigger' => $a['trigger_type'],
			'algorithm_version' => $a['algorithm_version'],
			'opportunity_score' => $a['opportunity_score'] !== null ? (int) $a['opportunity_score'] : null,
			'opportunity_level' => $a['opportunity_level'],
			'confidence' => $a['confidence'],
			'coverage' => $a['coverage'] !== null ? (int) $a['coverage'] : null,
			'findings_count' => (int) $a['findings_count'],
			'started_at' => $this->iso($a['started_at'] ?? null),
			'finished_at' => $this->iso($a['finished_at'] ?? null),
			'duration_ms' => $a['duration_ms'] !== null ? (int) $a['duration_ms'] : null,
			'created_at' => $this->iso($a['created_at'] ?? null),
			// Prywatność: tylko informacja, czy zleciła TA organizacja (bez nazw i użytkowników innych organizacji).
			'by_my_organization' => (int) ($a['organization_id'] ?? 0) === $ctx->organizationId,
		];
	}

	public static function screenshotUrl(int $analysisId, string $view): string
	{
		return add_query_arg(['action' => 'ubr_screenshot', 'analysis' => $analysisId, 'view' => $view], admin_url('admin-ajax.php'));
	}

	private function assertVisible(TenantContext $ctx, int $companyId): void
	{
		$this->assert($ctx, 'analysis.view');

		if (! $this->companies->find($companyId) || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}

	private function iso(?string $utc): ?string
	{
		return $utc ? gmdate('c', strtotime($utc . ' UTC')) : null;
	}
}
