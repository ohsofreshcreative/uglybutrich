<?php

namespace App\Ubr\Domain\Discovery;

use App\Ubr\Support\UbrException;

/**
 * Kryteria wyszukiwania (zwalidowane). Niezależne od UI i od dostawców danych.
 */
final class DiscoveryCriteria
{
	/**
	 * Przedziały zatrudnienia zgodne z filtrem Apollo `organization_num_employees_ranges[]` ("min,max").
	 */
	public const SIZES = [
		'any' => [null, null, 'Dowolna'],
		'1_10' => [1, 10, '1–10 osób'],
		'11_50' => [11, 50, '11–50 osób'],
		'51_200' => [51, 200, '51–200 osób'],
		'201_500' => [201, 500, '201–500 osób'],
		'501_1000' => [501, 1000, '501–1000 osób'],
		'1001_plus' => [1001, null, '1000+ osób'],
	];

	public const LIMITS = [25, 50, 100, 250, 500];

	/**
	 * Słowa zbyt ogólne, żeby szukać po nich (występują w każdej nazwie/opisie).
	 */
	private const GENERIC = ['produc', 'produk', 'firm', 'przedsi', 'polsk', 'polsc', 'branz', 'branż', 'uslug', 'usług', 'zatrudn', 'osob', 'osób', 'okolo', 'około', 'ktore', 'które', 'oraz', 'sektor', 'rynek', 'b2b', 'company', 'compan', 'poland', 'mazow', 'wojew', 'regionu', 'region'];

	/**
	 * @param list<string> $providers
	 */
	public function __construct(
		public readonly ?string $industry,
		public readonly ?string $phrase,
		public readonly ?string $region,
		public readonly ?string $city,
		public readonly string $size,
		public readonly bool $includeUnknownSize,
		public readonly int $limit,
		public readonly array $providers,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 * @param list<string> $availableProviders
	 */
	public static function fromArray(array $input, array $availableProviders): self
	{
		$industry = sanitize_key((string) ($input['industry'] ?? '')) ?: null;
		$phrase = trim(sanitize_text_field((string) ($input['phrase'] ?? ''))) ?: null;
		$region = sanitize_key((string) ($input['region'] ?? '')) ?: null;
		$city = trim(sanitize_text_field((string) ($input['city'] ?? ''))) ?: null;
		$size = sanitize_key((string) ($input['size'] ?? 'any')) ?: 'any';
		$size = $size === '1001plus' ? '1001_plus' : $size;
		$limit = (int) ($input['limit'] ?? 100);
		$providers = array_values(array_unique(array_map('sanitize_key', (array) ($input['providers'] ?? $availableProviders))));

		if ($industry !== null && ! IndustryCatalog::get($industry)) {
			throw UbrException::invalid('Nieznana branża.');
		}

		if ($region !== null && ! RegionCatalog::label($region)) {
			throw UbrException::invalid('Nieznane województwo.');
		}

		if (! array_key_exists($size, self::SIZES)) {
			throw UbrException::invalid('Nieznany przedział wielkości.');
		}

		if ($phrase !== null && mb_strlen($phrase) > 160) {
			throw UbrException::invalid('Fraza może mieć maksymalnie 160 znaków.');
		}

		if ($city !== null && mb_strlen($city) > 80) {
			throw UbrException::invalid('Nazwa miejscowości jest za długa.');
		}

		$city = CityCatalog::canonical($city);
		$cityRegion = CityCatalog::regionFor($city);

		// Sprzeczne kryteria dałyby 0 wyników bez wyjaśnienia — zatrzymujemy je przed wysłaniem zapytania (i przed kosztem).
		if ($city !== null && $region !== null && $cityRegion !== null && $cityRegion !== $region) {
			throw UbrException::invalid(sprintf('Miejscowość %s leży w województwie %s, a wybrano %s. Zmień miasto albo województwo.', $city, RegionCatalog::label($cityRegion), RegionCatalog::label($region)));
		}

		$providers = array_values(array_intersect($providers, $availableProviders));

		if ($providers === []) {
			throw UbrException::invalid('Wybierz co najmniej jedno dostępne źródło danych.');
		}

		$criteria = new self($industry, $phrase, $region, $city, $size, (bool) ($input['include_unknown_size'] ?? true), max(10, min(500, $limit)), $providers);

		if ($criteria->terms() === []) {
			throw UbrException::invalid('Podaj branżę albo frazę opisującą działalność (min. 4 znaki, niezbyt ogólną).');
		}

		return $criteria;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromStored(array $data): self
	{
		return new self(
			$data['industry'] ?? null,
			$data['phrase'] ?? null,
			$data['region'] ?? null,
			$data['city'] ?? null,
			$data['size'] ?? 'any',
			(bool) ($data['include_unknown_size'] ?? true),
			(int) ($data['limit'] ?? 100),
			(array) ($data['providers'] ?? []),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'industry' => $this->industry,
			'phrase' => $this->phrase,
			'region' => $this->region,
			'city' => $this->city,
			'size' => $this->size,
			'include_unknown_size' => $this->includeUnknownSize,
			'limit' => $this->limit,
			'providers' => $this->providers,
		];
	}

	/**
	 * Rdzenie słów do dopasowania (z branży + z frazy). Tylko litery, cyfry, spacje.
	 *
	 * @return list<string>
	 */
	public function terms(): array
	{
		$terms = IndustryCatalog::get($this->industryKey())['terms'] ?? [];

		foreach ($this->phraseStems() as $stem) {
			$terms[] = $stem;
		}

		$terms = array_map(fn ($t) => mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t) ?? '')), $terms);

		return array_values(array_unique(array_filter($terms, fn ($t) => mb_strlen($t) >= 3)));
	}

	/**
	 * Rdzenie słów samej branży (bez frazy) — do oceny kryterium „branża”.
	 *
	 * @return list<string>
	 */
	public function industryTerms(): array
	{
		$terms = array_map(fn ($t) => mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t) ?? '')), IndustryCatalog::get($this->industryKey())['terms'] ?? []);

		return array_values(array_unique(array_filter($terms, fn ($t) => mb_strlen($t) >= 3)));
	}

	/**
	 * Słowa kluczowe dla Apollo (`q_organization_keyword_tags[]`): tagi branży (EN) + fraza użytkownika w całości.
	 *
	 * @return list<string>
	 */
	public function apolloKeywords(): array
	{
		$industry = $this->industryKey();
		$tags = IndustryCatalog::get($industry)['apollo'] ?? [];

		// Polska fraza katalogowa („obróbka metali”) jest już przełożona na angielskie tagi Apollo — nie dublujemy jej.
		// Inną frazę przekazujemy dosłownie (tagi Apollo są łączone przez OR).
		if ($this->phrase && ! $this->phraseIsCatalogPhrase($industry)) {
			$tags[] = mb_strtolower($this->phrase);
		}

		return array_values(array_unique(array_filter(array_map('trim', $tags))));
	}

	/**
	 * Branża wybrana z listy albo rozpoznana z frazy (np. „obróbka metali” → metalworking).
	 */
	public function industryKey(): ?string
	{
		return $this->industry ?? $this->detectedIndustry();
	}

	public function detectedIndustry(): ?string
	{
		return $this->industry === null ? IndustryCatalog::detect($this->phrase) : null;
	}

	/**
	 * Województwo z kryteriów albo wynikające z miasta (Kraków → małopolskie).
	 */
	public function regionKey(): ?string
	{
		return $this->region ?? CityCatalog::regionFor($this->city);
	}

	public function hasLocation(): bool
	{
		return $this->region !== null || $this->city !== null;
	}

	public function hasSize(): bool
	{
		return $this->size !== 'any';
	}

	private function phraseIsCatalogPhrase(?string $industry): bool
	{
		if (! $industry || ! $this->phrase) {
			return false;
		}

		$fold = fn ($v) => trim(preg_replace('/[^a-z0-9]+/', ' ', \App\Ubr\Domain\Companies\CompanyNormalizer::foldAscii((string) $v)) ?? '');
		$phrase = $fold($this->phrase);

		foreach (IndustryCatalog::get($industry)['phrases'] ?? [] as $p) {
			if ($fold($p) === $phrase) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	public function phraseStems(): array
	{
		if (! $this->phrase) {
			return [];
		}

		$stems = [];

		foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($this->phrase)) ?: [] as $word) {
			$len = mb_strlen($word);

			if ($len < 4) {
				continue;
			}

			// Prosty stemming dla polskiej fleksji: obetnij końcówkę, zachowując min. 4 znaki.
			$stem = $len >= 7 ? mb_substr($word, 0, max(5, $len - 3)) : ($len >= 5 ? mb_substr($word, 0, $len - 1) : $word);

			foreach (self::GENERIC as $generic) {
				if (str_starts_with($stem, $generic) || str_starts_with($generic, $stem)) {
					continue 2;
				}
			}

			$stems[] = $stem;
		}

		return array_values(array_unique($stems));
	}

	/**
	 * @return array{0: ?int, 1: ?int}
	 */
	public function sizeRange(): array
	{
		return [self::SIZES[$this->size][0], self::SIZES[$this->size][1]];
	}

	public function acceptsSize(?int $min, ?int $max): bool
	{
		[$from, $to] = $this->sizeRange();

		if ($from === null && $to === null) {
			return true;
		}

		if ($min === null && $max === null) {
			return $this->includeUnknownSize;
		}

		$value = $min ?? $max;

		return ($from === null || $value >= $from) && ($to === null || $value <= $to);
	}

	public function label(): string
	{
		$parts = array_filter([
			IndustryCatalog::get($this->industry)['label'] ?? null,
			$this->phrase ? '„' . $this->phrase . '”' : null,
			$this->city,
			RegionCatalog::label($this->region) ? 'woj. ' . RegionCatalog::label($this->region) : null,
			$this->size !== 'any' ? self::SIZES[$this->size][2] : null,
		]);

		return mb_substr(implode(' · ', $parts) ?: 'Wyszukiwanie', 0, 255);
	}

	/**
	 * Lista dopasowanych rdzeni w tekście.
	 *
	 * @return list<string>
	 */
	public function matchedTerms(string $text): array
	{
		$text = mb_strtolower($text);

		return array_values(array_filter(
			$this->terms(),
			fn ($t) => (bool) preg_match('/(^|[^\p{L}])' . preg_quote($t, '/') . '/u', $text)
		));
	}

	/**
	 * Wyrażenie regularne (POSIX/Java) dopasowujące rdzenie na początku słowa — wspólne dla OSM i Wikidata.
	 */
	public function termsRegex(): string
	{
		return '(^|[^a-ząćęłńóśźżé0-9])(' . implode('|', $this->terms()) . ')';
	}
}
