<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Companies\FitFlags;
use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\RelevanceScorer;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;

/**
 * Liczenie i zapis Relevance Score dla wyników wyszukiwań. Przeliczane po pobraniu wyniku
 * i ponownie po weryfikacji firmy (rejestr może potwierdzić lokalizację/branżę albo wykazać niezgodność).
 */
class RelevanceService
{
	public function __construct(
		private ResultRepository $results,
		private CompanyRepository $companies,
	) {
	}

	/**
	 * @param list<string>|string|null $matchedTerms
	 * @return array<string, mixed>|null
	 */
	public function scoreResult(int $searchId, int $companyId, DiscoveryCriteria $criteria, string $provider, array|string|null $matchedTerms = null): ?array
	{
		$company = $this->companies->find($companyId);

		if (! $company) {
			return null;
		}

		$relevance = RelevanceScorer::score($company, $criteria, $provider, $matchedTerms);
		$this->results->setRelevance($searchId, $companyId, $relevance);
		$this->storeFlags($companyId, $company, $relevance['flags']);

		return $relevance;
	}

	/**
	 * Przelicza trafność firmy we wszystkich wyszukiwaniach, w których wystąpiła.
	 */
	public function recomputeForCompany(int $companyId): int
	{
		$company = $this->companies->find($companyId);

		if (! $company) {
			return 0;
		}

		$count = 0;
		$flags = FitFlags::detect($company);

		foreach ($this->results->occurrencesForCompany($companyId) as $o) {
			$criteria = DiscoveryCriteria::fromStored((array) json_decode((string) $o['criteria'], true));
			$relevance = RelevanceScorer::score($company, $criteria, (string) $o['provider'], $o['matched_terms']);
			$this->results->setRelevance((int) $o['search_id'], $companyId, $relevance);
			$flags = $relevance['flags'];
			$count++;
		}

		$this->storeFlags($companyId, $company, $flags);

		return $count;
	}

	/**
	 * Uzupełnia trafność wyników, które jej nie mają (np. po migracji z etapu 1.1).
	 */
	public function backfill(int $limit = 500): int
	{
		$count = 0;

		foreach ($this->results->withoutRelevance($limit) as $row) {
			$count += $this->recomputeForCompany((int) $row['company_id']);
		}

		return $count;
	}

	/**
	 * @param array<string, mixed> $company
	 * @param list<string> $flags
	 */
	private function storeFlags(int $companyId, array $company, array $flags): void
	{
		$json = wp_json_encode(array_values($flags));

		if (($company['fit_flags'] ?? null) !== $json) {
			$this->companies->update($companyId, ['fit_flags' => $json]);
		}
	}
}
