<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Analyzers\AccessibilityAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\BusinessPotentialAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\CompanyConfidenceAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\ConversionContentAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\MobileAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\SeoAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\TechnicalAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer;
use App\Ubr\Domain\Analysis\Analyzers\VisualAnalyzer;
use App\Ubr\Domain\Analysis\Finding;
use App\Ubr\Domain\Analysis\HtmlDocument;
use App\Ubr\Domain\Analysis\OpportunityScoreCalculator;
use App\Ubr\Infrastructure\Ai\VisionService;
use App\Ubr\Infrastructure\Analysis\ScreenshotStorage;
use App\Ubr\Infrastructure\Analysis\SiteCapture;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Http\PageFetcher;
use App\Ubr\Infrastructure\Http\UrlGuard;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;

/**
 * Wykonanie jednej analizy strony (w tle, z kolejki):
 * 1) HTML strony głównej + maks. 2 podstrony (oferta, kontakt — wybór deterministyczny) przez PageFetcher (SSRF),
 * 2) render w Chromium: screenshoty Desktop 1440 / Mobile 390, metryki, axe-core (SiteCapture),
 * 3) AI Vision — tylko gdy świadomie włączone (domyślnie nie),
 * 4) analizatory → kryteria + findings → Opportunity Score (z coverage / confidence / INSUFFICIENT_DATA),
 * 5) zapis wyniku jako NOWY rekord historii + zdenormalizowane pola firmy.
 *
 * Status: COMPLETED tylko gdy HTML i oba screenshoty się udały; PARTIAL gdy HTML jest, a renderu/screenshotu brak;
 * FAILED gdy strony nie da się pobrać (lub adres zablokowany).
 */
class WebsiteAnalyzerService
{
	public const ALGORITHM_VERSION = 'wa-2026-10-v5';

	public const MAX_PAGES = 3;

	public function __construct(
		private AnalysisRepository $analyses,
		private CompanyRepository $companies,
		private PageFetcher $fetcher,
		private UrlGuard $guard,
		private SiteCapture $capture,
		private ScreenshotStorage $storage,
		private VisionService $vision,
	) {
	}

	/**
	 * @param array<string, mixed> $analysis
	 * @return array{status: string, retry: bool, error: ?string}
	 */
	public function run(array $analysis): array
	{
		$started = microtime(true);
		$id = (int) $analysis['id'];
		$company = $this->companies->find((int) $analysis['company_id']);
		$errors = [];

		if (! $company) {
			return $this->fail($id, 'Firma nie istnieje.', $started);
		}

		$url = (string) $analysis['url'];

		// 1. HTML strony głównej.
		$home = $this->fetcher->fetch($url);

		if ($home['blocked']) {
			return $this->fail($id, $home['error'], $started, ['security' => $home['blocked']]);
		}

		$homeDoc = null;

		if ($home['ok'] && str_contains(strtolower($home['headers']['content-type'] ?? 'text/html'), 'html')) {
			$homeDoc = new HtmlDocument($home['body'], (string) $home['final_url']);
		} elseif ($home['ok']) {
			$errors[] = ['stage' => 'html', 'message' => 'Odpowiedź nie jest dokumentem HTML (' . ($home['headers']['content-type'] ?? '?') . ').'];
		} else {
			$errors[] = ['stage' => 'html', 'message' => 'Nie udało się pobrać strony: ' . ($home['error'] ?? 'błąd') . ($home['certificate_error'] ? ' (błąd certyfikatu SSL)' : '')];
		}

		// Podstrony: oferta + kontakt (maks. 3 strony łącznie), wybór deterministyczny (najkrótsza pasująca ścieżka).
		$offerDoc = null;
		$contactDoc = null;
		$pages = [['kind' => 'home', 'url' => $home['final_url'] ?? $url, 'status' => $home['status'], 'ms' => $home['ms'], 'bytes' => $home['bytes']]];

		if ($homeDoc) {
			foreach (['offer' => UxStructureAnalyzer::OFFER_RE, 'contact' => UxStructureAnalyzer::CONTACT_RE] as $kind => $re) {
				$sub = self::pickSubpage($homeDoc, $re, (string) $home['final_url'], array_column($pages, 'url'), $kind === 'offer' ? UxStructureAnalyzer::CONTACT_RE : null);

				if (! $sub || count($pages) >= self::MAX_PAGES) {
					continue;
				}

				$res = $this->fetcher->fetch($sub, 10);
				$pages[] = ['kind' => $kind, 'url' => $sub, 'status' => $res['status'], 'ms' => $res['ms'], 'bytes' => $res['bytes'], 'error' => $res['error']];

				if ($res['ok']) {
					$doc = new HtmlDocument($res['body'], (string) $res['final_url']);
					$kind === 'offer' ? $offerDoc = $doc : $contactDoc = $doc;
				}
			}
		}

		// http → https.
		$httpsRedirect = null;

		if ($homeDoc && str_starts_with((string) $home['final_url'], 'https://')) {
			$httpsRedirect = $this->checkHttpsRedirect((string) $home['final_url']);
		}

		// 2. Render w przeglądarce (tylko strona główna — podstrony bez screenshotów, żeby nie zwiększać kosztów).
		$capture = null;

		if ($homeDoc) {
			$capture = $this->capture->capture([
				'url' => (string) $home['final_url'],
				'outDir' => $this->storage->dirForCompany((int) $company['id']),
				'prefix' => $this->storage->prefix($id),
				'views' => ['desktop', 'mobile'],
				'timeoutMs' => AnalysisLimits::analysisTimeoutMs(),
				'maxBytes' => AnalysisLimits::screenshotMaxBytes(),
			], (int) ceil(AnalysisLimits::analysisTimeoutMs() / 1000) + 25);

			foreach ((array) ($capture['errors'] ?? []) as $e) {
				$errors[] = ['stage' => 'render:' . ($e['stage'] ?? '?'), 'message' => (string) ($e['message'] ?? '')];
			}

			if (! empty($capture['security_violation'])) {
				$capture = null;
			}
		}

		$screenshots = [];

		foreach (['desktop', 'mobile'] as $view) {
			$shot = $capture['views'][$view]['screenshot'] ?? null;

			if ($shot && $this->storage->path((int) $company['id'], (string) $shot['file'])) {
				$screenshots[$view] = array_intersect_key($shot, array_flip(['file', 'bytes', 'width', 'height', 'full_height', 'truncated', 'quality', 'scroll_container_expanded']));
			}
		}

		// 3. AI Vision (domyślnie wyłączone).
		$visionResult = null;
		$visionStatus = 'disabled';

		if ($screenshots && VisionService::enabled()) {
			$paths = array_map(fn ($s) => (string) $this->storage->path((int) $company['id'], $s['file']), $screenshots);
			$v = $this->vision->analyze($paths, ['url' => $home['final_url'], 'title' => $homeDoc?->title()], $analysis['organization_id'] ? (int) $analysis['organization_id'] : null);
			$visionStatus = $v['status'];
			$visionResult = $v['result'];

			foreach ($v['errors'] as $e) {
				$errors[] = ['stage' => 'ai_vision', 'message' => $e];
			}
		}

		// 4. Analizatory i Opportunity Score.
		$ctx = new AnalysisContext($homeDoc, $home, $offerDoc, $contactDoc, $capture && empty($capture['unavailable']) ? $capture : null, $company, $visionResult, $httpsRedirect);
		$criteria = [];
		$findings = [];
		$checks = [];

		foreach ([
			'visual' => new VisualAnalyzer(),
			'ux' => new UxStructureAnalyzer(),
			'mobile' => new MobileAnalyzer(),
			'conversion' => new ConversionContentAnalyzer(),
			'technical' => new TechnicalAnalyzer(),
			'seo' => new SeoAnalyzer(),
			'accessibility' => new AccessibilityAnalyzer(),
			'business' => new BusinessPotentialAnalyzer(),
			'company' => new CompanyConfidenceAnalyzer(),
		] as $area => $analyzer) {
			$r = $analyzer->analyze($ctx);
			$criteria += $r['criteria'];
			$findings = array_merge($findings, $r['findings']);
			$checks[$area] = $r['checks'];
		}

		if (! $homeDoc) {
			$findings[] = Finding::make('site_unreachable', 'security', 'high', 'Strona niedostępna', 'Nie udało się pobrać strony firmy podczas analizy.', (string) ($home['error'] ?? 'brak odpowiedzi'), 'Sprawdzić ręcznie, czy strona działa (niedziałająca strona to też powód do kontaktu, ale nie oceniamy jej automatycznie).');
		}

		$opportunity = OpportunityScoreCalculator::calculate($criteria, $homeDoc !== null, (string) ($company['verification_status'] ?? 'pending'));
		$findings = Finding::sort($findings);

		$renderOk = isset($screenshots['desktop'], $screenshots['mobile']);
		$status = match (true) {
			! $homeDoc => 'failed',
			$renderOk => 'completed',
			default => 'partial',
		};

		$embeddable = self::embeddable($home['headers'] ?? []);
		$results = [
			'opportunity' => $opportunity,
			'checks' => $checks,
			'pages' => $pages,
			'visual' => ['status' => $visionResult ? 'evaluated' : 'NOT_EVALUATED', 'vision_status' => $visionStatus, 'categories' => $checks['visual']['categories'] ?? []],
			'render' => $capture ? [
				'available' => empty($capture['unavailable']),
				'cookie_banner' => $capture['cookie_banner'] ?? null,
				'redirects' => $capture['redirects'] ?? [],
				'blocked_requests' => count((array) ($capture['network']['blocked'] ?? [])),
				'status' => $capture['status'] ?? null,
			] : ['available' => false],
			'preview' => ['embeddable' => $embeddable, 'final_url' => $home['final_url'] ?? $url],
		];

		$durationMs = (int) round((microtime(true) - $started) * 1000);
		$tools = [
			'algorithm' => self::ALGORITHM_VERSION,
			'opportunity' => OpportunityScoreCalculator::VERSION,
			'html' => 'PageFetcher (WordPress HTTP API)',
			'renderer' => $capture && empty($capture['unavailable']) ? ['name' => 'Playwright ' . ($capture['tool']['playwright'] ?? '?'), 'browser' => 'Chromium ' . ($capture['tool']['browser'] ?? '?')] : null,
			'axe' => $capture['axe']['version'] ?? null,
			'ai_vision' => $visionResult ? ['model' => $visionResult['model'], 'prompt' => $visionResult['prompt_version']] : $visionStatus,
			'lighthouse' => 'not_used',
		];

		$this->analyses->update($id, [
			'status' => $status,
			'final_url' => isset($home['final_url']) ? mb_substr((string) $home['final_url'], 0, 500) : null,
			'finished_at' => Db::now(),
			'duration_ms' => $durationMs,
			'locked_until' => null,
			'opportunity_score' => $opportunity['score'],
			'opportunity_level' => $opportunity['level']['key'] ?? ($opportunity['status'] === 'INSUFFICIENT_DATA' ? 'insufficient' : null),
			'confidence' => $opportunity['confidence'],
			'coverage' => $opportunity['coverage'],
			'findings_count' => count(array_filter($findings, fn ($f) => $f['source'] !== 'manual_review' && $f['severity'] !== 'info')),
			'results' => $results,
			'findings' => $findings,
			'errors' => $errors,
			'tools' => $tools,
			'screenshots' => $screenshots,
		]);

		$this->syncCompany((int) $company['id']);

		// Ponowienie tylko przy błędzie przejściowym (timeout/połączenie), nie przy błędzie HTTP 4xx czy blokadzie.
		$transient = ! $homeDoc && empty($home['status']) && ! $home['blocked'] && (int) $analysis['attempts'] < 2;

		return ['status' => $status, 'retry' => $transient, 'error' => $errors[0]['message'] ?? null];
	}

	/**
	 * Zdenormalizowane pola firmy = ostatnia ZAKOŃCZONA analiza (completed/partial/failed).
	 */
	public function syncCompany(int $companyId): void
	{
		$latest = $this->analyses->latestForCompany($companyId);
		$finished = $this->analyses->latestForCompany($companyId, true);

		$this->companies->update($companyId, [
			'last_analysis_id' => $finished['id'] ?? ($latest['id'] ?? null),
			'analysis_status' => $latest['status'] ?? null,
			'analyzed_at' => $finished['finished_at'] ?? null,
			'opportunity_score' => $finished['opportunity_score'] ?? null,
			'opportunity_level' => $finished['opportunity_level'] ?? null,
			'opportunity_confidence' => $finished['confidence'] ?? null,
			'opportunity_coverage' => $finished['coverage'] ?? null,
			'findings_count' => $finished['findings_count'] ?? null,
		]);

		if ($latest && in_array($latest['status'], ['completed', 'partial'], true)) {
			do_action('ubr/company/changed', $companyId, 'analysis_done', null);
		}
	}

	/**
	 * @param array<string, string> $headers
	 */
	public static function embeddable(array $headers): bool
	{
		$xfo = strtolower((string) ($headers['x-frame-options'] ?? ''));

		if ($xfo !== '') {
			return false;
		}

		$csp = strtolower((string) ($headers['content-security-policy'] ?? ''));

		if (preg_match('/frame-ancestors\s+([^;]+)/', $csp, $m)) {
			return trim($m[1]) === '*';
		}

		return true;
	}

	/**
	 * Wybór podstrony (deterministyczny): najpierw dopasowanie w ścieżce URL, dopiero potem w tekście linku;
	 * przy remisie krótsza ścieżka. Adresy pasujące do $avoid (np. kontakt przy szukaniu oferty) są pomijane,
	 * a porównanie z już wybranymi stronami ignoruje końcowy „/” i fragment.
	 *
	 * @param list<string> $exclude
	 */
	public static function pickSubpage(HtmlDocument $doc, string $re, string $base, array $exclude, ?string $avoid = null): ?string
	{
		$best = null;
		$bestRank = [PHP_INT_MAX, PHP_INT_MAX];
		$baseHost = (string) parse_url($base, PHP_URL_HOST);
		$norm = static fn (string $u): string => strtolower(rtrim((string) strtok($u, '#'), '/'));
		$excluded = array_map($norm, $exclude);

		foreach ($doc->links() as $l) {
			if (! $l['internal'] || preg_match('/\.(pdf|jpe?g|png|zip|docx?)(\?|$)/i', $l['href'])) {
				continue;
			}

			$url = (string) strtok(PageFetcher::absolute($l['href'], $base), '#');
			$path = (string) parse_url($url, PHP_URL_PATH);
			$host = (string) parse_url($url, PHP_URL_HOST);

			if (in_array(rtrim($path, '/'), ['', '/'], true) || in_array($norm($url), $excluded, true)) {
				continue;
			}

			if (strcasecmp($host, $baseHost) !== 0 && ! str_ends_with($host, (string) preg_replace('/^www\./', '', $baseHost))) {
				continue;
			}

			if ($avoid && preg_match($avoid, $path)) {
				continue;
			}

			$inPath = (bool) preg_match($re, $path);

			if (! $inPath && ! preg_match($re, $l['text'])) {
				continue;
			}

			$rank = [$inPath ? 0 : 1, strlen($path)];

			if ($rank < $bestRank) {
				$best = $url;
				$bestRank = $rank;
			}
		}

		return $best ?: null;
	}

	private function checkHttpsRedirect(string $httpsUrl): ?bool
	{
		$http = 'http://' . substr($httpsUrl, 8);
		$http = preg_replace('#^(http://[^/]+).*$#', '$1/', $http) ?? $http;

		if (! $this->guard->check($http)['ok']) {
			return null;
		}

		$response = wp_remote_get($http, ['timeout' => 6, 'redirection' => 0, 'user-agent' => PageFetcher::USER_AGENT]);

		if (is_wp_error($response)) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$location = (string) wp_remote_retrieve_header($response, 'location');

		return $code >= 300 && $code < 400 && str_starts_with(strtolower($location), 'https://');
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array{status: string, retry: bool, error: ?string}
	 */
	private function fail(int $id, string $message, float $started, array $extra = []): array
	{
		$this->analyses->update($id, [
			'status' => 'failed',
			'finished_at' => Db::now(),
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
			'locked_until' => null,
			'errors' => [['stage' => 'analysis', 'message' => $message] + $extra],
			'tools' => ['algorithm' => self::ALGORITHM_VERSION],
		]);

		$analysis = $this->analyses->find($id);

		if ($analysis) {
			$this->syncCompany((int) $analysis['company_id']);
		}

		return ['status' => 'failed', 'retry' => false, 'error' => $message];
	}
}
