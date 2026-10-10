<?php

namespace App\Ubr\Domain\Discovery;

use App\Ubr\Domain\Companies\Confidence;

/**
 * Firma zwrócona przez dostawcę danych — tylko to, co faktycznie jest w źródle.
 * Pola null oznaczają "niedostępne". Pewność domeny, lokalizacji i wielkości jest jawna.
 * Dostawca NIE może wypełniać pól wartościami z filtrów wyszukiwania (np. województwo z filtra Apollo).
 */
final class CompanyCandidate
{
	/**
	 * @param array<string, mixed> $raw fragment danych źródłowych (do audytu)
	 */
	public function __construct(
		public readonly string $provider,
		public readonly string $externalId,
		public readonly string $name,
		public readonly ?string $sourceUrl = null,
		public readonly ?string $website = null,
		public readonly string $websiteConfidence = Confidence::UNAVAILABLE,
		public readonly ?string $industry = null,
		public readonly ?string $description = null,
		public readonly ?string $street = null,
		public readonly ?string $postalCode = null,
		public readonly ?string $city = null,
		public readonly ?string $region = null,
		public readonly ?float $latitude = null,
		public readonly ?float $longitude = null,
		public readonly ?int $employeesMin = null,
		public readonly ?int $employeesMax = null,
		public readonly string $employeesConfidence = Confidence::UNAVAILABLE,
		public readonly ?string $nip = null,
		public readonly ?string $regon = null,
		public readonly ?string $krs = null,
		public readonly array $matchedTerms = [],
		public readonly array $raw = [],
		public readonly ?int $foundedYear = null,
		public readonly ?int $revenueEstimate = null,
		public readonly ?string $linkedinUrl = null,
		// Pewność lokalizacji: declared = pole w rekordzie dostawcy; nigdy z filtra wyszukiwania.
		public readonly string $locationConfidence = Confidence::DECLARED,
		/** @var array{sic?: list<string>, naics?: list<string>, pkd?: list<string>} */
		public readonly array $industryCodes = [],
		/** @var list<string> słowa kluczowe dostawcy (np. tagi Apollo), jeśli są w rekordzie */
		public readonly array $keywords = [],
	) {
	}
}
