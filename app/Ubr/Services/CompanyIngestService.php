<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\CompanyCandidate;
use App\Ubr\Infrastructure\Database\DatabaseException;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;

/**
 * Zapis kandydatów do globalnej bazy firm z deduplikacją i historią pochodzenia danych.
 *
 * Kolejność dopasowania: identyfikator u dostawcy → domena → NIP → KRS → nazwa+miasto.
 * Istniejące wartości nie są nadpisywane danymi o niższej pewności.
 */
class CompanyIngestService
{
	public function __construct(private CompanyRepository $companies)
	{
	}

	/**
	 * @return array{company_id: int, created: bool, domain: ?string}
	 */
	public function ingest(CompanyCandidate $c, ?int $searchId = null): array
	{
		$name = trim(wp_strip_all_tags($c->name));
		$normalized = N::normalizeName($name);
		$websiteUrl = N::websiteUrl($c->website);
		$domain = N::domain($websiteUrl);
		$domainType = N::classifyDomain($domain);
		$officialDomain = $domainType === 'official' ? $domain : null;
		$nip = N::nip($c->nip);
		$krs = N::krs($c->krs);
		$regon = N::regon($c->regon);
		$city = N::normalizeCity($c->city);

		$companyId = $this->match($c, $officialDomain, $nip, $krs, $normalized, $city);
		$created = false;

		if (! $companyId) {
			try {
				$companyId = $this->companies->insert([
					'name' => mb_substr($name, 0, 255),
					'normalized_name' => mb_substr($normalized, 0, 255),
					'country_code' => 'PL',
				]);
				$created = true;
			} catch (DatabaseException $e) {
				// Wyścig z równoległym zapisem — spróbuj dopasować ponownie.
				$companyId = $this->match($c, $officialDomain, $nip, $krs, $normalized, $city);

				if (! $companyId) {
					throw $e;
				}
			}
		}

		$this->companies->addExternalId($companyId, $c->provider, $c->externalId, $c->sourceUrl);
		$this->merge($companyId, $c, $created, $websiteUrl, $officialDomain, $domainType, $nip, $krs, $regon, $city, $searchId);
		$this->companies->touch($companyId);

		$row = $this->companies->find($companyId);

		return ['company_id' => $companyId, 'created' => $created, 'domain' => $row['domain'] ?? null];
	}

	private function match(CompanyCandidate $c, ?string $domain, ?string $nip, ?string $krs, string $normalized, ?string $city): ?int
	{
		if ($id = $this->companies->idByExternalId($c->provider, $c->externalId)) {
			return $id;
		}

		if ($domain && ($id = $this->companies->idByColumn('domain', $domain))) {
			return $id;
		}

		if ($nip && ($id = $this->companies->idByColumn('nip', $nip))) {
			return $id;
		}

		if ($krs && ($id = $this->companies->idByColumn('krs', $krs))) {
			return $id;
		}

		// Nazwa + miasto: tylko gdy nie ma sprzecznych identyfikatorów.
		if ($normalized !== '' && $city) {
			foreach ($this->companies->findByNormalizedName($normalized) as $row) {
				if (! $row['city'] || mb_strtolower($row['city']) !== mb_strtolower($city)) {
					continue;
				}

				if ($domain && $row['domain'] && $row['domain'] !== $domain) {
					continue;
				}

				if ($nip && $row['nip'] && $row['nip'] !== $nip) {
					continue;
				}

				return (int) $row['id'];
			}
		}

		return null;
	}

	private function merge(int $id, CompanyCandidate $c, bool $created, ?string $websiteUrl, ?string $officialDomain, string $domainType, ?string $nip, ?string $krs, ?string $regon, ?string $city, ?int $searchId): void
	{
		$row = $this->companies->find($id) ?? [];
		$update = [];
		$evidence = fn (string $field, $value, string $confidence) => $value === null || $value === ''
			? null
			: $this->companies->addEvidence($id, $c->provider, $c->externalId, $field, (string) $value, $confidence, $c->sourceUrl, $searchId);

		// Dane od dostawcy Discovery to deklaracje (DECLARED) — potwierdzenie daje dopiero rejestr lub weryfikacja strony.
		$evidence('name', $c->name, Confidence::DECLARED);

		// Strona WWW / domena.
		if ($websiteUrl) {
			$evidence('website', $websiteUrl, $officialDomain ? $c->websiteConfidence : Confidence::UNCERTAIN);

			if (! $officialDomain) {
				$evidence('website_non_official', $domainType . ': ' . $websiteUrl, Confidence::CONFIRMED);
			} elseif (empty($row['domain'])) {
				$owner = $this->companies->idByColumn('domain', $officialDomain);

				if ($owner && $owner !== $id) {
					$evidence('domain_conflict', $officialDomain . ' (company #' . $owner . ')', Confidence::UNCERTAIN);
				} else {
					$update += [
						'domain' => $officialDomain,
						'website_url' => mb_substr($websiteUrl, 0, 500),
						'domain_confidence' => $c->websiteConfidence,
						'domain_source' => $c->provider,
						'website_status' => 'unchecked',
					];
				}
			} elseif ($row['domain'] === $officialDomain && Confidence::rank($c->websiteConfidence) > Confidence::rank((string) $row['domain_confidence'])) {
				$update += ['domain_confidence' => $c->websiteConfidence, 'domain_source' => $c->provider];
			}
		}

		// Pola opisowe — uzupełniamy tylko puste. Lokalizacja: tylko z rekordu dostawcy (nigdy z filtra wyszukiwania),
		// i nie nadpisujemy lokalizacji potwierdzonej w rejestrze.
		$locationFields = ['street', 'postal_code', 'city', 'region', 'latitude', 'longitude'];
		$fill = [
			'industry' => $c->industry,
			'description' => $c->description,
			'street' => $c->street,
			'postal_code' => $c->postalCode,
			'city' => $city,
			'region' => $c->region,
			'latitude' => $c->latitude,
			'longitude' => $c->longitude,
		];
		$locationSet = false;
		$registryLocation = ($row['location_confidence'] ?? '') === Confidence::CONFIRMED;

		foreach ($fill as $field => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			$isLocation = in_array($field, $locationFields, true);
			$evidence($field, is_float($value) ? (string) round($value, 7) : $value, $isLocation ? $c->locationConfidence : Confidence::DECLARED);

			if (empty($row[$field]) && ! ($isLocation && $registryLocation)) {
				$update[$field] = is_string($value) ? mb_substr($value, 0, $field === 'description' ? 2000 : 255) : $value;
				$locationSet = $locationSet || in_array($field, ['city', 'region'], true);
			}
		}

		if ($locationSet && Confidence::rank($c->locationConfidence) >= Confidence::rank((string) ($row['location_confidence'] ?? Confidence::UNAVAILABLE))) {
			$update['location_confidence'] = $c->locationConfidence;
			$update['location_source'] = $c->provider;
		}

		// Kody branżowe dostawcy (SIC/NAICS) — scalane, do oceny trafności.
		if ($c->industryCodes !== []) {
			$codes = json_decode((string) ($row['industry_codes'] ?? ''), true) ?: [];

			foreach ($c->industryCodes as $type => $list) {
				$codes[$type] = array_values(array_unique(array_merge((array) ($codes[$type] ?? []), array_map('strval', (array) $list))));
				$evidence('industry_codes_' . $type, implode(',', (array) $list), Confidence::DECLARED);
			}

			$update['industry_codes'] = wp_json_encode($codes);
		}

		if ($c->keywords !== []) {
			$evidence('keywords', implode(', ', array_slice($c->keywords, 0, 30)), Confidence::DECLARED);
		}

		// Identyfikatory z rekordu dostawcy (np. Wikidata P3608, tag OSM ref:vatin) — tylko poprawne sumy kontrolne,
		// bez nadpisywania i bez konfliktów. To deklaracja dostawcy: weryfikacja sprawdza je w rejestrze.
		foreach (['nip' => $nip, 'krs' => $krs] as $field => $value) {
			if (! $value) {
				continue;
			}

			$evidence($field, $value, Confidence::DECLARED);

			if (empty($row[$field]) && ! $this->companies->idByColumn($field, $value)) {
				$update[$field] = $value;
			}
		}

		if ($regon) {
			$evidence('regon', $regon, Confidence::DECLARED);

			if (empty($row['regon'])) {
				$update['regon'] = $regon;
			}
		}

		// Wielkość — tylko jeżeli źródło ją podaje.
		if ($c->employeesMin !== null || $c->employeesMax !== null) {
			$range = ($c->employeesMin ?? '?') . '-' . ($c->employeesMax ?? '?');
			$evidence('employees', $range, $c->employeesConfidence);

			if (Confidence::rank($c->employeesConfidence) >= Confidence::rank((string) ($row['employees_confidence'] ?? Confidence::UNAVAILABLE)) && ($row['employees_min'] ?? null) === null) {
				$update += [
					'employees_min' => $c->employeesMin,
					'employees_max' => $c->employeesMax,
					'employees_confidence' => $c->employeesConfidence,
					'employees_source' => $c->provider,
				];
			}
		}

		// Rok założenia, szacowane przychody, LinkedIn — tylko jeśli źródło je podaje; pierwsza wartość zostaje.
		if ($c->foundedYear !== null && $c->foundedYear > 1800 && $c->foundedYear <= (int) gmdate('Y')) {
			$evidence('founded_year', (string) $c->foundedYear, Confidence::UNCERTAIN);

			if (empty($row['founded_year'])) {
				$update['founded_year'] = $c->foundedYear;
			}
		}

		if ($c->revenueEstimate !== null && $c->revenueEstimate > 0) {
			$evidence('revenue_estimate', (string) $c->revenueEstimate, Confidence::UNCERTAIN);

			if (empty($row['revenue_estimate'])) {
				$update['revenue_estimate'] = $c->revenueEstimate;
				$update['revenue_source'] = $c->provider;
			}
		}

		if ($c->linkedinUrl && preg_match('#^https?://([a-z]+\.)?linkedin\.com/#i', $c->linkedinUrl)) {
			$evidence('linkedin_url', $c->linkedinUrl, Confidence::UNCERTAIN);

			if (empty($row['linkedin_url'])) {
				$update['linkedin_url'] = mb_substr($c->linkedinUrl, 0, 255);
			}
		}

		try {
			$this->companies->update($id, $update);
		} catch (DatabaseException $e) {
			if (! $e->duplicate) {
				throw $e;
			}

			// Konflikt unikalności (domena/NIP/KRS zajęte równolegle) — zapisz resztę bez identyfikatorów.
			unset($update['domain'], $update['website_url'], $update['domain_confidence'], $update['domain_source'], $update['nip'], $update['krs']);
			$this->companies->update($id, $update);
		}
	}
}
