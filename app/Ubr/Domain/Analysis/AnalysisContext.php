<?php

namespace App\Ubr\Domain\Analysis;

/**
 * Dane wejściowe analizy jednej strony: HTML (statycznie), wynik renderowania w przeglądarce (opcjonalny),
 * dane firmy. Brak elementu = nie oceniamy kryteriów, które go wymagają (NOT_EVALUATED, 0 pkt).
 */
final class AnalysisContext
{
	/**
	 * @param array<string, mixed> $homeFetch wynik PageFetcher dla strony głównej
	 * @param array<string, mixed>|null $capture wynik komponentu przeglądarki (null = niewykonany/niedostępny)
	 * @param array<string, mixed> $company wiersz ubr_companies
	 * @param array<string, mixed>|null $vision zwalidowany wynik AI Vision (null = nie wykonano)
	 * @param bool|null $httpsRedirect czy http:// przekierowuje na https:// (null = nie sprawdzono)
	 */
	public function __construct(
		public readonly ?HtmlDocument $home,
		public readonly array $homeFetch,
		public readonly ?HtmlDocument $offer,
		public readonly ?HtmlDocument $contact,
		public readonly ?array $capture,
		public readonly array $company,
		public readonly ?array $vision = null,
		public readonly ?bool $httpsRedirect = null,
		public readonly int $year = 0,
	) {
	}

	public function year(): int
	{
		return $this->year ?: (int) gmdate('Y');
	}

	/**
	 * @return array<string, mixed>|null metryki DOM widoku z przeglądarki
	 */
	public function view(string $view): ?array
	{
		$v = $this->capture['views'][$view] ?? null;

		return is_array($v) && ! empty($v['metrics']) ? $v['metrics'] : null;
	}

	public function rendered(): bool
	{
		return $this->view('desktop') !== null;
	}

	public function siteReachable(): bool
	{
		return $this->home !== null && ! empty($this->homeFetch['ok']);
	}
}
