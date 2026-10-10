<?php

namespace App\Ubr\Infrastructure\Discovery\Providers;

use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\CompanyCandidate;
use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\DiscoveryProvider;
use App\Ubr\Domain\Discovery\IndustryCatalog;
use App\Ubr\Domain\Discovery\ProviderPage;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;

/**
 * OpenStreetMap (Overpass API) — obiekty w Polsce z nazwą i stroną WWW, dopasowane do branży/frazy.
 *
 * Licencja danych: ODbL (© OpenStreetMap contributors) — wymagana atrybucja w UI.
 * Strona WWW w OSM jest wprowadzana przez społeczność → pewność domeny: "uncertain",
 * podnoszona do "confirmed" dopiero po weryfikacji strony (WebsiteInspector).
 */
class OverpassProvider implements DiscoveryProvider
{
	public function __construct(private ProviderHttpClient $http)
	{
	}

	public function key(): string
	{
		return 'osm';
	}

	public function label(): string
	{
		return 'OpenStreetMap';
	}

	public function isAvailable(): bool
	{
		return (bool) apply_filters('ubr/discovery/osm_enabled', true);
	}

	public function description(): string
	{
		return 'Opcjonalne, bezpłatne (ODbL). Obiekty z nazwą i stroną WWW. Dobre pokrycie lokalnych firm, brak danych o zatrudnieniu.';
	}

	/**
	 * Publiczne instancje Overpass. Przy ponowieniu (np. 504/429 na głównej) używamy kolejnej.
	 *
	 * @return list<string>
	 */
	public function endpoints(): array
	{
		$default = defined('UBR_OVERPASS_ENDPOINT') ? [UBR_OVERPASS_ENDPOINT] : ['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];

		return array_values((array) apply_filters('ubr/discovery/overpass_endpoints', $default));
	}

	public function endpoint(int $attempt = 0): string
	{
		$endpoints = $this->endpoints();

		return $endpoints[$attempt % max(1, count($endpoints))];
	}

	/**
	 * Przybliżone prostokąty (S, W, N, E) województw i Polski. Filtr prostokątem korzysta z indeksu przestrzennego
	 * Overpass i jest wielokrotnie szybszy niż filtr obszarem administracyjnym (ten przekraczał limit czasu na żywo).
	 * Prostokąt zahacza o sąsiednie regiony — dlatego województwo firmy ustalamy tylko z tagów adresu.
	 */
	public const BBOXES = [
		'PL' => [49.0, 14.1, 54.9, 24.2],
		'dolnoslaskie' => [50.09, 14.81, 51.81, 17.80],
		'kujawsko-pomorskie' => [52.33, 17.24, 53.78, 19.76],
		'lubelskie' => [50.25, 21.62, 52.29, 24.15],
		'lubuskie' => [51.36, 14.53, 53.12, 16.42],
		'lodzkie' => [50.84, 18.07, 52.39, 20.66],
		'malopolskie' => [49.17, 19.08, 50.52, 21.42],
		'mazowieckie' => [51.01, 19.26, 53.48, 23.13],
		'opolskie' => [49.97, 16.91, 51.20, 18.71],
		'podkarpackie' => [49.00, 21.14, 50.82, 23.55],
		'podlaskie' => [52.28, 21.59, 54.41, 23.93],
		'pomorskie' => [53.49, 16.70, 54.84, 19.65],
		'slaskie' => [49.39, 18.03, 51.10, 19.97],
		'swietokrzyskie' => [50.17, 19.71, 51.35, 21.87],
		'warminsko-mazurskie' => [53.13, 19.13, 54.45, 22.89],
		'wielkopolskie' => [51.10, 15.78, 53.66, 19.08],
		'zachodniopomorskie' => [52.62, 14.12, 54.59, 16.98],
	];

	public function buildQuery(DiscoveryCriteria $criteria): string
	{
		$regex = $this->escape($criteria->termsRegex());
		$limit = min(500, $criteria->limit * 2);
		$lines = ['[out:json][timeout:50];'];

		if ($criteria->city) {
			// Miasto: mały obszar administracyjny — filtr obszarem jest tu szybki.
			$lines[] = 'area["name"="' . $this->escape($criteria->city) . '"]["boundary"="administrative"]["admin_level"~"^(6|7|8)$"]->.s;';
			$scope = '(area.s)';
		} else {
			$scope = '(' . implode(',', self::BBOXES[$criteria->region ?? 'PL'] ?? self::BBOXES['PL']) . ')';
		}

		$lines[] = '(';

		foreach (['website', 'contact:website'] as $websiteKey) {
			$lines[] = sprintf('  nwr["%s"]["name"~"%s",i]%s;', $websiteKey, $regex, $scope);
		}

		foreach (IndustryCatalog::get($criteria->industry)['osm'] ?? [] as [$tag, $value]) {
			$lines[] = sprintf('  nwr["%s"="%s"]["website"]%s;', $this->escape($tag), $this->escape($value), $scope);
		}

		$lines[] = ');';
		$lines[] = sprintf('out center tags %d;', $limit);

		return implode("\n", $lines);
	}

	public function fetch(DiscoveryCriteria $criteria, array $cursor): ProviderPage
	{
		$query = $this->buildQuery($criteria);

		$data = $this->http->json('osm', 'POST', $this->endpoint((int) ($cursor['_attempt'] ?? 0)), [
			'timeout' => 60,
			'body' => ['data' => $query],
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
		], 5000);

		if (! empty($data['remark']) && empty($data['elements'])) {
			throw new \App\Ubr\Domain\Discovery\ProviderException('Overpass: ' . mb_substr((string) $data['remark'], 0, 300), str_contains((string) $data['remark'], 'timed out'));
		}

		$candidates = [];
		$seen = [];
		$rejected = [];

		foreach ($data['elements'] ?? [] as $el) {
			$candidate = $this->map($el, $criteria);

			if (! $candidate) {
				$rejected['invalid_record'] = ($rejected['invalid_record'] ?? 0) + 1;
				continue;
			}

			if (isset($seen[$candidate->externalId])) {
				$rejected['duplicate_in_page'] = ($rejected['duplicate_in_page'] ?? 0) + 1;
				continue;
			}

			$seen[$candidate->externalId] = true;
			$candidates[] = $candidate;
		}

		// Najpierw najlepiej dopasowane (więcej trafionych rdzeni).
		usort($candidates, fn ($a, $b) => count($b->matchedTerms) <=> count($a->matchedTerms));

		return new ProviderPage($candidates, null, ['query' => $query, 'elements' => count($data['elements'] ?? []), 'returned' => count($data['elements'] ?? []), 'rejected' => $rejected]);
	}

	/**
	 * @param array<string, mixed> $el
	 */
	public function map(array $el, DiscoveryCriteria $criteria): ?CompanyCandidate
	{
		$tags = (array) ($el['tags'] ?? []);
		$name = trim((string) ($tags['name'] ?? ''));
		$website = $tags['website'] ?? $tags['contact:website'] ?? $tags['url'] ?? null;

		if ($name === '' || ! $website || empty($el['type']) || empty($el['id'])) {
			return null;
		}

		// Prostokąt obejmuje też fragmenty krajów sąsiednich — odrzucamy obiekty z jawnie innym krajem.
		if (! empty($tags['addr:country']) && strtoupper((string) $tags['addr:country']) !== 'PL') {
			return null;
		}

		$industryParts = array_filter([
			$tags['industrial'] ?? null,
			$tags['craft'] ?? null,
			$tags['office'] ?? null,
			$tags['shop'] ?? null,
			$tags['product'] ?? null,
		]);
		$industry = $industryParts ? implode(', ', array_map(fn ($v) => str_replace('_', ' ', (string) $v), $industryParts)) : null;

		$text = implode(' ', array_filter([$name, $tags['official_name'] ?? null, $tags['description'] ?? null, $tags['product'] ?? null, $industry, $tags['operator'] ?? null]));

		$street = trim(implode(' ', array_filter([$tags['addr:street'] ?? $tags['addr:place'] ?? null, $tags['addr:housenumber'] ?? null])));
		$lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
		$lon = $el['lon'] ?? ($el['center']['lon'] ?? null);

		return new CompanyCandidate(
			provider: 'osm',
			externalId: $el['type'] . '/' . $el['id'],
			name: $tags['official_name'] ?? $name,
			sourceUrl: 'https://www.openstreetmap.org/' . $el['type'] . '/' . $el['id'],
			website: (string) $website,
			websiteConfidence: Confidence::UNCERTAIN,
			industry: $industry,
			description: isset($tags['description']) ? (string) $tags['description'] : null,
			street: $street ?: null,
			postalCode: $tags['addr:postcode'] ?? null,
			city: $tags['addr:city'] ?? null,
			region: RegionCatalog::label(RegionCatalog::keyFromName($tags['addr:province'] ?? $tags['addr:state'] ?? null)),
			latitude: $lat !== null ? (float) $lat : null,
			longitude: $lon !== null ? (float) $lon : null,
			nip: $tags['ref:vatin'] ?? $tags['ref:NIP'] ?? $tags['ref:nip'] ?? $tags['nip'] ?? null,
			regon: $tags['ref:REGON'] ?? $tags['ref:regon'] ?? null,
			krs: $tags['ref:KRS'] ?? $tags['ref:krs'] ?? null,
			matchedTerms: $criteria->matchedTerms($text),
			raw: array_intersect_key($tags, array_flip(['name', 'official_name', 'website', 'contact:website', 'url', 'industrial', 'craft', 'office', 'shop', 'product', 'addr:city', 'addr:postcode'])),
			locationConfidence: ! empty($tags['addr:city']) ? Confidence::DECLARED : Confidence::UNAVAILABLE,
		);
	}

	private function escape(string $value): string
	{
		return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
	}
}
