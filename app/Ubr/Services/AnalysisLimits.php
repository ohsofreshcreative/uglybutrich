<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Repositories\AnalysisRepository;
use App\Ubr\Support\UbrException;

/**
 * Limity Website Analyzera — konfigurowalne stałymi w wp-config.php lub filtrem `ubr/analysis/limits`
 * (filtr dostaje organization_id — miejsce na przyszłe plany abonamentowe; płatności nie są implementowane).
 */
class AnalysisLimits
{
	public const DEFAULTS = [
		'org_per_day' => 25,
		'org_per_month' => 300,
		'bulk_max' => 20,
		'concurrency' => 1,
		'pages' => 3,
		'timeout_ms' => 75000,
		'screenshot_max_bytes' => 2500000,
		'fresh_days' => 30,
		'manual_cooldown_minutes' => 60,
	];

	public function __construct(private AnalysisRepository $analyses)
	{
	}

	/**
	 * @return array<string, int>
	 */
	public static function limits(?int $organizationId = null): array
	{
		$limits = self::DEFAULTS;
		$constants = [
			'org_per_day' => 'UBR_ANALYSIS_ORG_PER_DAY',
			'org_per_month' => 'UBR_ANALYSIS_ORG_PER_MONTH',
			'bulk_max' => 'UBR_ANALYSIS_BULK_MAX',
			'concurrency' => 'UBR_ANALYSIS_CONCURRENCY',
			'timeout_ms' => 'UBR_ANALYSIS_TIMEOUT_MS',
			'screenshot_max_bytes' => 'UBR_SCREENSHOT_MAX_BYTES',
		];

		foreach ($constants as $key => $constant) {
			if (defined($constant)) {
				$limits[$key] = (int) constant($constant);
			}
		}

		$limits = array_map('intval', (array) apply_filters('ubr/analysis/limits', $limits, $organizationId));
		// LocalWP ma mało procesów PHP — maks. 2 równoległe przeglądarki niezależnie od konfiguracji.
		$limits['concurrency'] = max(1, min(2, $limits['concurrency']));
		$limits['pages'] = max(1, min(3, $limits['pages']));

		return $limits;
	}

	public static function analysisTimeoutMs(): int
	{
		return max(20000, min(120000, self::limits()['timeout_ms']));
	}

	public static function screenshotMaxBytes(): int
	{
		return max(300000, min(8000000, self::limits()['screenshot_max_bytes']));
	}

	/**
	 * @return array<string, int>
	 */
	public function usage(int $organizationId): array
	{
		$limits = self::limits($organizationId);
		$today = $this->analyses->countForOrganizationSince($organizationId, gmdate('Y-m-d 00:00:00'));
		$month = $this->analyses->countForOrganizationSince($organizationId, gmdate('Y-m-01 00:00:00'));

		return [
			'today' => $today,
			'month' => $month,
			'per_day' => $limits['org_per_day'],
			'per_month' => $limits['org_per_month'],
			'left_today' => max(0, min($limits['org_per_day'] - $today, $limits['org_per_month'] - $month)),
			'bulk_max' => $limits['bulk_max'],
			'concurrency' => $limits['concurrency'],
			'pages' => $limits['pages'],
			'timeout_seconds' => (int) round(self::analysisTimeoutMs() / 1000),
			'screenshot_max_kb' => (int) round(self::screenshotMaxBytes() / 1024),
			'fresh_days' => $limits['fresh_days'],
		];
	}

	public function assertCanStart(int $organizationId, int $count): void
	{
		$u = $this->usage($organizationId);

		if ($count > $u['bulk_max']) {
			throw UbrException::invalid(sprintf('Jednorazowo można zlecić maks. %d analiz.', $u['bulk_max']));
		}

		if ($count > $u['left_today']) {
			throw UbrException::limit(sprintf('Limit analiz organizacji: pozostało %d na dziś (dziennie %d, miesięcznie %d).', $u['left_today'], $u['per_day'], $u['per_month']));
		}
	}
}
