<?php

namespace App\Ubr\Services;

use App\Ubr\Infrastructure\Repositories\ContactRunRepository;
use App\Ubr\Support\UbrException;

/**
 * Limity Decision Maker Findera — stałe w wp-config.php (`UBR_CONTACTS_*`) lub filtr `ubr/contacts/limits`
 * (z organization_id — miejsce na przyszłe plany SaaS; płatności nie są implementowane).
 */
class ContactLimits
{
	public const DEFAULTS = [
		'bulk_max' => 20,
		'org_per_day' => 50,
		'org_per_month' => 500,
		'org_active_requests' => 1,
		'concurrency' => 1,
		'manual_cooldown_hours' => 24,
		'fresh_days' => 30,
		'max_pages' => 6,
		'max_requests' => 10,
		'time_budget_seconds' => 45,
		'max_attempts' => 2,
		'retention_days' => 365,
	];

	private const CONSTANTS = [
		'bulk_max' => 'UBR_CONTACTS_BULK_MAX',
		'org_per_day' => 'UBR_CONTACTS_ORG_PER_DAY',
		'org_per_month' => 'UBR_CONTACTS_ORG_PER_MONTH',
		'org_active_requests' => 'UBR_CONTACTS_ORG_ACTIVE',
		'manual_cooldown_hours' => 'UBR_CONTACTS_COOLDOWN_HOURS',
		'max_pages' => 'UBR_CONTACTS_MAX_PAGES',
		'max_requests' => 'UBR_CONTACTS_MAX_REQUESTS',
		'time_budget_seconds' => 'UBR_CONTACTS_TIME_BUDGET',
		'retention_days' => 'UBR_CONTACT_RETENTION_DAYS',
	];

	public function __construct(private ContactRunRepository $runs)
	{
	}

	/**
	 * @return array<string, int>
	 */
	public static function limits(?int $organizationId = null): array
	{
		$limits = self::DEFAULTS;

		foreach (self::CONSTANTS as $key => $constant) {
			if (defined($constant)) {
				$limits[$key] = (int) constant($constant);
			}
		}

		$limits = array_map('intval', (array) apply_filters('ubr/contacts/limits', $limits, $organizationId));
		// Twarde granice bezpieczeństwa (brak nieograniczonego crawlera).
		$limits['concurrency'] = max(1, min(2, $limits['concurrency']));
		$limits['max_pages'] = max(1, min(10, $limits['max_pages']));
		$limits['max_requests'] = max(3, min(15, $limits['max_requests']));
		$limits['time_budget_seconds'] = max(15, min(90, $limits['time_budget_seconds']));
		$limits['max_attempts'] = max(1, min(3, $limits['max_attempts']));

		return $limits;
	}

	/**
	 * @return array<string, int>
	 */
	public function usage(int $organizationId): array
	{
		$l = self::limits($organizationId);
		$today = $this->runs->countForOrganizationSince($organizationId, gmdate('Y-m-d 00:00:00'));
		$month = $this->runs->countForOrganizationSince($organizationId, gmdate('Y-m-01 00:00:00'));

		return [
			'today' => $today,
			'month' => $month,
			'per_day' => $l['org_per_day'],
			'per_month' => $l['org_per_month'],
			'left_today' => max(0, min($l['org_per_day'] - $today, $l['org_per_month'] - $month)),
			'bulk_max' => $l['bulk_max'],
			'active' => $this->runs->countActiveForOrganization($organizationId),
			'active_max' => $l['org_active_requests'],
			'cooldown_hours' => $l['manual_cooldown_hours'],
			'fresh_days' => $l['fresh_days'],
			'max_pages' => $l['max_pages'],
		];
	}

	public function assertCanStart(int $organizationId, int $count): void
	{
		$u = $this->usage($organizationId);

		if ($count > $u['bulk_max']) {
			throw UbrException::invalid(sprintf('Jednorazowo można zlecić wyszukiwanie dla maks. %d firm.', $u['bulk_max']));
		}

		if ($u['active_max'] > 0 && $u['active'] > 0) {
			throw UbrException::limit('Trwa poprzednie wyszukiwanie decydentów w Twojej organizacji — poczekaj na jego zakończenie (limit aktywnych zadań: ' . $u['active_max'] . ').');
		}

		if ($count > $u['left_today']) {
			throw UbrException::limit(sprintf('Limit wyszukiwań decydentów: pozostało %d na dziś (dziennie %d, miesięcznie %d).', $u['left_today'], $u['per_day'], $u['per_month']));
		}
	}
}
