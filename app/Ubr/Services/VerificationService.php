<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;
use App\Ubr\Infrastructure\Repositories\SearchRepository;
use App\Ubr\Infrastructure\Repositories\VerificationQueueRepository;
use App\Ubr\Support\UbrException;

/**
 * Zlecanie weryfikacji firm: automatycznie po wyszukiwaniu oraz ręcznie (ponowna weryfikacja) z limitami.
 *
 * Weryfikacja NIE jest wykonywana przy otwieraniu profilu firmy — tylko w kolejce w tle.
 */
class VerificationService
{
	/** Wynik weryfikacji jest świeży przez tyle dni — automatyczne zlecenia go nie powtarzają. */
	public const FRESH_DAYS = 30;

	/** Minimalny odstęp ręcznej ponownej weryfikacji tej samej firmy. */
	public const MANUAL_COOLDOWN = 10 * MINUTE_IN_SECONDS;

	public function __construct(
		private VerificationQueueRepository $queue,
		private CompanyRepository $companies,
		private ResultRepository $results,
		private SearchRepository $searches,
		private VerificationRunner $runner,
	) {
	}

	public static function manualDailyLimit(): int
	{
		return (int) apply_filters('ubr/verification/manual_daily_limit', defined('UBR_VERIFY_ORG_PER_DAY') ? (int) UBR_VERIFY_ORG_PER_DAY : 150);
	}

	/**
	 * Automatyczne zlecenie po pobraniu wyników wyszukiwania (bez wymuszania — świeże wyniki są pomijane).
	 *
	 * @return int liczba dodanych do kolejki
	 */
	public function enqueueForSearch(int $searchId, int $organizationId, ?int $userId = null, int $priority = 5, bool $force = false): int
	{
		return $this->enqueueCompanies($this->results->companyIdsForSearch($searchId), $organizationId, $searchId, $userId, $priority, $force);
	}

	/**
	 * Prompt 10.4 — zlecenie weryfikacji wskazanych firm (np. zaraz po zapisaniu strony wyników Discovery, bez czekania
	 * na koniec całego wyszukiwania). Świeżo zweryfikowane są pomijane; kolejka bez duplikatów (UNIQUE company_id).
	 *
	 * @param list<int> $companyIds
	 * @return int liczba dodanych do kolejki
	 */
	public function enqueueCompanies(array $companyIds, int $organizationId, ?int $searchId = null, ?int $userId = null, int $priority = 5, bool $force = false): int
	{
		$threshold = gmdate('Y-m-d H:i:s', time() - self::FRESH_DAYS * DAY_IN_SECONDS);
		$count = 0;

		foreach (array_values(array_unique(array_map('intval', $companyIds))) as $companyId) {
			$c = $this->companies->find($companyId);

			if (! $c) {
				continue;
			}

			$fresh = $c['verification_checked_at'] !== null && $c['verification_checked_at'] >= $threshold && $c['verification_status'] !== 'pending';

			if ($fresh && ! $force) {
				continue;
			}

			$queued = ($force || $priority < 5) ? null : $this->queue->forCompany($companyId);

			if ($queued && in_array($queued['status'], ['queued', 'running'], true)) {
				continue; // już w kolejce (np. zlecona po wcześniejszej stronie wyników)
			}

			$this->queue->enqueue($companyId, $organizationId, $userId, $searchId, $priority, $force);
			$count++;
		}

		if ($count > 0) {
			$this->runner->kick();
			$this->runner->scheduleBackup();
		}

		return $count;
	}

	/**
	 * Ręczna ponowna weryfikacja jednej firmy (uprawnienie companies.verify, firma widoczna dla organizacji).
	 *
	 * @return array<string, mixed>
	 */
	public function requestCompany(TenantContext $ctx, int $companyId): array
	{
		$this->assert($ctx);
		$company = $this->companies->find($companyId);

		if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$queued = $this->queue->forCompany($companyId);

		if ($queued && in_array($queued['status'], ['queued', 'running'], true)) {
			return ['queued' => true, 'already_queued' => true, 'message' => 'Weryfikacja tej firmy jest już w kolejce.'];
		}

		if ($company['verification_checked_at'] && strtotime($company['verification_checked_at'] . ' UTC') > time() - self::MANUAL_COOLDOWN) {
			throw UbrException::invalid('Firma była weryfikowana przed chwilą — spróbuj ponownie za kilka minut.');
		}

		$this->assertDailyLimit($ctx, 1);
		$this->queue->enqueue($companyId, $ctx->organizationId, $ctx->userId, null, 1, true);
		$this->runner->kick();
		$this->runner->scheduleBackup(30);

		return ['queued' => true, 'already_queued' => false, 'message' => 'Weryfikacja zlecona — wynik pojawi się za kilkanaście sekund.'];
	}

	/**
	 * Weryfikacja wszystkich firm z wyszukiwania organizacji (pomija świeżo sprawdzone, chyba że wymuszono).
	 *
	 * @return array<string, mixed>
	 */
	public function requestSearch(TenantContext $ctx, int $searchId, bool $force = false): array
	{
		$this->assert($ctx);
		$this->searches->findForOrganization($searchId, $ctx->organizationId) ?? throw UbrException::notFound('Nie znaleziono wyszukiwania.');

		$total = count($this->results->companyIdsForSearch($searchId));

		if ($force) {
			$this->assertDailyLimit($ctx, $total);
		}

		$count = $this->enqueueForSearch($searchId, $ctx->organizationId, $ctx->userId, 3, $force);

		return ['queued' => $count, 'total' => $total, 'message' => $count ? sprintf('Zlecono weryfikację %d firm.', $count) : 'Wszystkie firmy z tego wyszukiwania mają aktualną weryfikację.'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function progressForSearch(int $searchId): array
	{
		return $this->queue->statsForSearch($searchId);
	}

	private function assertDailyLimit(TenantContext $ctx, int $requested): void
	{
		$used = $this->queue->countRequestedToday($ctx->organizationId);

		if ($used + $requested > self::manualDailyLimit()) {
			throw UbrException::limit(sprintf('Dzienny limit ręcznych weryfikacji organizacji (%d) został wykorzystany.', self::manualDailyLimit()));
		}
	}

	private function assert(TenantContext $ctx): void
	{
		if (! $ctx->can('companies.verify')) {
			throw UbrException::forbidden();
		}
	}
}
