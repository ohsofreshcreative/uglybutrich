<?php

namespace App\Ubr\Http;

use App\Ubr\Infrastructure\Analysis\ScreenshotStorage;
use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Services\TenantResolver;

/**
 * Serwowanie screenshotów (admin-ajax.php?action=ubr_screenshot) — tylko zalogowani członkowie organizacji,
 * która widzi daną firmę. Pliki leżą poza publicznym katalogiem; odpowiedź nie ujawnia ścieżek serwera.
 */
class ScreenshotEndpoint
{
	public function __construct(
		private TenantResolver $tenants,
		private AnalysisRepository $analyses,
		private CompanyRepository $companies,
		private ScreenshotStorage $storage,
	) {
	}

	public function handle(): void
	{
		$ctx = $this->tenants->resolve();
		$analysisId = (int) ($_GET['analysis'] ?? 0);
		$view = sanitize_key((string) ($_GET['view'] ?? ''));

		if (! $ctx || ! $ctx->can('analysis.view') || ! in_array($view, ['desktop', 'mobile'], true)) {
			$this->deny(403);
		}

		$analysis = $this->analyses->find($analysisId);

		if (! $analysis || ! $this->companies->isVisibleTo((int) $analysis['company_id'], $ctx->organizationId)) {
			$this->deny(404);
		}

		$file = (string) ($analysis['screenshots'][$view]['file'] ?? '');
		$path = $file !== '' ? $this->storage->path((int) $analysis['company_id'], $file) : null;

		if (! $path) {
			$this->deny(404);
		}

		nocache_headers();
		header('Content-Type: image/jpeg');
		header('Content-Length: ' . filesize($path));
		header('Cache-Control: private, max-age=3600');
		header('X-Content-Type-Options: nosniff');
		header('Content-Disposition: inline; filename="screenshot-' . $view . '.jpg"');
		readfile($path);
		exit;
	}

	private function deny(int $code): never
	{
		status_header($code);
		header('Content-Type: text/plain; charset=utf-8');
		echo $code === 403 ? 'Brak dostępu.' : 'Nie znaleziono.';
		exit;
	}
}
