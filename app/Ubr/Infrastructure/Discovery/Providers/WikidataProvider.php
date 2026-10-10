<?php

namespace App\Ubr\Infrastructure\Discovery\Providers;

use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\CompanyCandidate;
use App\Ubr\Domain\Discovery\DiscoveryCriteria;
use App\Ubr\Domain\Discovery\DiscoveryProvider;
use App\Ubr\Domain\Discovery\ProviderException;
use App\Ubr\Domain\Discovery\ProviderPage;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;

/**
 * Wikidata — źródło opcjonalne (CC0, bez klucza).
 *
 * Wcześniejsza wersja używała jednego dużego zapytania SPARQL, które na Wikidata Query Service kończyło się
 * HTTP 500/504 (timeout). Teraz: wyszukiwarka MediaWiki (CirrusSearch, `haswbstatement:P17=Q36 haswbstatement:P856`)
 * + pobranie encji `wbgetentities` w paczkach po 50 — krótkie, szybkie zapytania, jedno słowo kluczowe na krok.
 *
 * P856 (oficjalna strona) = domena "confirmed". Wikidata zawiera głównie większe/znane firmy.
 */
class WikidataProvider implements DiscoveryProvider
{
	private const API = 'https://www.wikidata.org/w/api.php';

	private const MAX_TERMS = 8;

	/** Klasy (P31) typowe dla przedsiębiorstw. */
	private const COMPANY_CLASSES = ['Q4830453', 'Q783794', 'Q6881511', 'Q891723', 'Q167037', 'Q1589009', 'Q658255', 'Q134161', 'Q22687', 'Q13235160', 'Q15911314', 'Q1076968', 'Q210167', 'Q786820', 'Q2085381'];

	/** Klasy (P31), które na pewno nie są firmami (kluby sportowe, miejscowości, ludzie, muzea…). */
	private const NON_COMPANY_CLASSES = [
		'Q5', 'Q476028', 'Q847017', 'Q515', 'Q3957', 'Q532', 'Q33146843', 'Q15284', 'Q33506', 'Q3918', 'Q23002054', 'Q2088357',
		'Q12280', 'Q16970', 'Q41176', 'Q82794', 'Q2221906', 'Q484170', 'Q7075',
		// związki zawodowe, stowarzyszenia, NGO, fundacje, partie, szkoły, urzędy, szpitale, kluby (wyniki testu na żywo 10.2026)
		'Q178790', 'Q48204', 'Q163740', 'Q157031', 'Q7278', 'Q3914', 'Q327333', 'Q16917', 'Q988108', 'Q15911738', 'Q1752939',
	];

	private const VOIVODESHIP_CLASS = 'Q150093';

	/** @var array<string, array<string, mixed>> */
	private array $entityCache = [];

	public function __construct(private ProviderHttpClient $http)
	{
	}

	public function key(): string
	{
		return 'wikidata';
	}

	public function label(): string
	{
		return 'Wikidata';
	}

	public function isAvailable(): bool
	{
		return (bool) apply_filters('ubr/discovery/wikidata_enabled', true);
	}

	public function description(): string
	{
		return 'Opcjonalne, bezpłatne (CC0). Firmy z oficjalną stroną WWW (potwierdzona domena), czasem zatrudnienie i NIP. Głównie większe przedsiębiorstwa.';
	}

	/**
	 * @return list<string>
	 */
	public function searchQueries(DiscoveryCriteria $criteria): array
	{
		$queries = [];

		foreach (array_slice($criteria->terms(), 0, self::MAX_TERMS) as $term) {
			$term = trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $term) ?? '');

			if ($term === '') {
				continue;
			}

			$expr = str_contains($term, ' ') ? '"' . $term . '"' : $term . '*';
			$queries[] = 'haswbstatement:P17=Q36 haswbstatement:P856 ' . $expr;
		}

		return $queries;
	}

	public function fetch(DiscoveryCriteria $criteria, array $cursor): ProviderPage
	{
		$queries = $this->searchQueries($criteria);
		$ti = (int) ($cursor['ti'] ?? 0);

		if (! isset($queries[$ti])) {
			return new ProviderPage([], null);
		}

		$search = $this->api([
			'action' => 'query',
			'list' => 'search',
			'srsearch' => $queries[$ti],
			'srnamespace' => '0',
			'srlimit' => '50',
			'srprop' => '',
			'srinfo' => '',
		]);

		$ids = array_values(array_filter(array_map(fn ($r) => (string) ($r['title'] ?? ''), (array) ($search['query']['search'] ?? [])), fn ($id) => (bool) preg_match('/^Q\d+$/', $id)));
		$entities = $this->entities($ids, 'labels|descriptions|claims');

		$companies = [];

		foreach ($ids as $qid) {
			if (isset($entities[$qid]) && $this->isCompany($entities[$qid])) {
				$companies[$qid] = $entities[$qid];
			}
		}

		// Etykiety branż i siedzib + województwo siedziby (łańcuch P131).
		$related = [];

		foreach ($companies as $e) {
			foreach (['P452', 'P159'] as $p) {
				foreach ($this->itemValues($e, $p) as $v) {
					$related[] = $v;
				}
			}
		}

		$this->entities(array_values(array_unique($related)), 'labels|claims');

		$candidates = [];
		$rejected = [];

		if (count($ids) > count($companies)) {
			$rejected['not_a_company'] = count($ids) - count($companies);
		}

		$wantedRegion = $criteria->regionKey();

		foreach ($companies as $qid => $e) {
			$candidate = $this->map($qid, $e, $criteria);

			if (! $candidate) {
				$rejected['invalid_record'] = ($rejected['invalid_record'] ?? 0) + 1;
				continue;
			}

			// Wikidata nie filtruje po lokalizacji — sprawdzamy po pobraniu i rozróżniamy brak danych od niezgodności.
			if ($wantedRegion) {
				$regionKey = RegionCatalog::keyFromName($candidate->region) ?? \App\Ubr\Domain\Discovery\CityCatalog::regionFor($candidate->city);

				if ($regionKey === null) {
					$rejected['location_unknown'] = ($rejected['location_unknown'] ?? 0) + 1;
					continue;
				}

				if ($regionKey !== $wantedRegion || ($criteria->city && $candidate->city && ! \App\Ubr\Domain\Discovery\CityCatalog::same($candidate->city, $criteria->city))) {
					$rejected['location_mismatch'] = ($rejected['location_mismatch'] ?? 0) + 1;
					continue;
				}
			}

			$candidates[] = $candidate;
		}

		usort($candidates, fn ($a, $b) => count($b->matchedTerms) <=> count($a->matchedTerms));
		$next = isset($queries[$ti + 1]) ? ['ti' => $ti + 1] : null;

		return new ProviderPage($candidates, $next, ['query' => $queries[$ti], 'hits' => count($ids), 'returned' => count($ids), 'companies' => count($companies), 'filtered_out' => count($ids) - count($candidates), 'rejected' => $rejected]);
	}

	/**
	 * @param array<string, mixed> $e encja z wbgetentities
	 */
	public function isCompany(array $e): bool
	{
		if (($e['missing'] ?? null) !== null || $this->itemValues($e, 'P576') !== []) {
			return false;
		}

		$types = $this->itemValues($e, 'P31');

		if (array_intersect($types, self::NON_COMPANY_CLASSES)) {
			return false;
		}

		if (! in_array('Q36', $this->itemValues($e, 'P17'), true) || $this->stringValues($e, 'P856') === []) {
			return false;
		}

		// Firma: klasa przedsiębiorstwa albo cechy typowe dla firm (branża, forma prawna, VAT UE, zatrudnienie).
		// Firma: klasa przedsiębiorstwa albo branża (P452). Sama forma prawna / VAT nie wystarcza (łapały stowarzyszenia).
		return (bool) array_intersect($types, self::COMPANY_CLASSES) || $this->itemValues($e, 'P452') !== [];
	}

	/**
	 * @param array<string, mixed> $e
	 */
	public function map(string $qid, array $e, DiscoveryCriteria $criteria): ?CompanyCandidate
	{
		$name = $this->entityLabel($e);

		if ($name === null) {
			return null;
		}

		$websites = $this->stringValues($e, 'P856');
		usort($websites, fn ($a, $b) => (int) str_contains($b, '.pl') <=> (int) str_contains($a, '.pl'));

		$industryLabels = array_values(array_filter(array_map(fn ($id) => $this->entityLabel($this->entityCache[$id] ?? []), $this->itemValues($e, 'P452'))));
		$hqId = $this->itemValues($e, 'P159')[0] ?? null;
		$hqLabel = $hqId ? $this->entityLabel($this->entityCache[$hqId] ?? []) : null;
		$voivodeship = $hqId ? $this->voivodeshipOf($hqId) : null;

		$employees = null;

		foreach ($this->claims($e, 'P1128') as $value) {
			$amount = is_array($value) ? (int) ltrim((string) ($value['amount'] ?? ''), '+') : 0;
			$employees = $amount > 0 ? max($employees ?? 0, $amount) : $employees;
		}

		$lat = $lon = null;

		foreach ($this->claims($e, 'P625') as $value) {
			if (is_array($value) && isset($value['latitude'], $value['longitude'])) {
				$lat = (float) $value['latitude'];
				$lon = (float) $value['longitude'];
				break;
			}
		}

		$vat = $this->stringValues($e, 'P3608')[0] ?? null;
		$description = $e['descriptions']['pl']['value'] ?? $e['descriptions']['en']['value'] ?? null;
		$industry = $industryLabels ? implode(', ', array_slice($industryLabels, 0, 4)) : null;

		return new CompanyCandidate(
			provider: 'wikidata',
			externalId: $qid,
			name: $name,
			sourceUrl: 'https://www.wikidata.org/wiki/' . $qid,
			website: $websites[0] ?? null,
			websiteConfidence: Confidence::CONFIRMED,
			industry: $industry,
			description: $description,
			city: $hqLabel,
			region: $voivodeship,
			latitude: $lat,
			longitude: $lon,
			employeesMin: $employees,
			employeesMax: $employees,
			employeesConfidence: $employees ? Confidence::DECLARED : Confidence::UNAVAILABLE,
			nip: $vat && str_starts_with(strtoupper($vat), 'PL') ? $vat : null,
			matchedTerms: $criteria->matchedTerms(implode(' ', array_filter([$name, $description, $industry]))),
			raw: ['qid' => $qid, 'websites' => $websites],
			locationConfidence: ($hqLabel || $voivodeship) ? Confidence::DECLARED : Confidence::UNAVAILABLE,
		);
	}

	/**
	 * Województwo siedziby: idziemy w górę po P131 (miasto → powiat → województwo), maks. 4 poziomy.
	 */
	private function voivodeshipOf(string $placeId): ?string
	{
		$level = [$placeId];

		for ($depth = 0; $depth < 4 && $level !== []; $depth++) {
			$this->entities($level, 'labels|claims');
			$next = [];

			foreach ($level as $id) {
				$e = $this->entityCache[$id] ?? [];

				if (in_array(self::VOIVODESHIP_CLASS, $this->itemValues($e, 'P31'), true)) {
					$label = $this->entityLabel($e);

					return $label ? trim(str_replace('województwo', '', $label)) : null;
				}

				foreach ($this->itemValues($e, 'P131') as $parent) {
					$next[] = $parent;
				}
			}

			$level = array_values(array_unique($next));
		}

		return null;
	}

	/**
	 * @param list<string> $ids
	 * @return array<string, array<string, mixed>>
	 */
	private function entities(array $ids, string $props): array
	{
		$missing = array_values(array_filter($ids, fn ($id) => ! isset($this->entityCache[$id])));

		foreach (array_chunk($missing, 50) as $chunk) {
			$data = $this->api([
				'action' => 'wbgetentities',
				'ids' => implode('|', $chunk),
				'props' => $props,
				'languages' => 'pl|en',
			]);

			foreach ((array) ($data['entities'] ?? []) as $id => $entity) {
				$this->entityCache[(string) $id] = (array) $entity;
			}
		}

		return array_intersect_key($this->entityCache, array_flip($ids));
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>
	 */
	private function api(array $params): array
	{
		// Bez `maxlag`: to zapytania tylko do odczytu, a maxlag uwzględnia też opóźnienie Query Service (np. 11 s)
		// i odrzucał wszystkie zapytania (sprawdzone na żywo 10.2026).
		$params += ['format' => 'json', 'formatversion' => '2'];
		$data = $this->http->json('wikidata', 'GET', self::API . '?' . http_build_query($params), ['timeout' => 20], 300);

		if (isset($data['error'])) {
			$code = (string) ($data['error']['code'] ?? '');

			throw new ProviderException('Wikidata API: ' . $code . ' ' . mb_substr((string) ($data['error']['info'] ?? ''), 0, 200), in_array($code, ['maxlag', 'ratelimited', 'internal_api_error_DBQueryTimeoutError'], true), $code === 'maxlag' ? 10 : null);
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $e
	 */
	private function entityLabel(array $e): ?string
	{
		$label = $e['labels']['pl']['value'] ?? $e['labels']['en']['value'] ?? null;

		return $label !== null && $label !== '' ? (string) $label : null;
	}

	/**
	 * Wartości oświadczeń (bez rangi "deprecated"), preferowane najpierw.
	 *
	 * @param array<string, mixed> $e
	 * @return list<mixed>
	 */
	private function claims(array $e, string $property): array
	{
		$preferred = [];
		$normal = [];

		foreach ((array) ($e['claims'][$property] ?? []) as $claim) {
			$rank = $claim['rank'] ?? 'normal';
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;

			if ($rank === 'deprecated' || $value === null) {
				continue;
			}

			$rank === 'preferred' ? $preferred[] = $value : $normal[] = $value;
		}

		return array_merge($preferred, $normal);
	}

	/**
	 * @param array<string, mixed> $e
	 * @return list<string>
	 */
	private function itemValues(array $e, string $property): array
	{
		return array_values(array_filter(array_map(fn ($v) => is_array($v) ? (string) ($v['id'] ?? '') : '', $this->claims($e, $property))));
	}

	/**
	 * @param array<string, mixed> $e
	 * @return list<string>
	 */
	private function stringValues(array $e, string $property): array
	{
		return array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : '', $this->claims($e, $property))));
	}
}
