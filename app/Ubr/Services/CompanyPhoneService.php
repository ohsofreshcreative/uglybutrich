<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Analysis\HtmlDocument;
use App\Ubr\Domain\Analysis\Analyzers\UxStructureAnalyzer;
use App\Ubr\Domain\Phones\CompanyPhoneExtractor;
use App\Ubr\Domain\Phones\PhoneNumber;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Http\PageFetcher;
use App\Ubr\Infrastructure\Repositories\CompanyPhoneRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Support\Redactor;
use App\Ubr\Support\UbrException;
use Throwable;

/**
 * Company Phone Extraction (Prompt 10): ogólne numery firmy z oficjalnej strony WWW.
 *
 * - automatycznie przy analizie strony (Website Analyzer — strona główna + Kontakt) i przy wyszukiwaniu decydentów
 *   (crawler stron firmy) — z już pobranego HTML, bez dodatkowych zapytań;
 * - ręczne odświeżenie: maks. 2 zapytania (strona główna + Kontakt) przez PageFetcher (ochrona SSRF, limit rozmiaru,
 *   timeout), nie częściej niż co 10 min dla firmy;
 * - numery ręczne organizacji są prywatne i nie są nadpisywane.
 */
class CompanyPhoneService
{
	public const REFRESH_COOLDOWN = 600;

	public const TYPE_LABELS = ['MAIN' => 'Centrala', 'BRANCH' => 'Oddział', 'SALES' => 'Sprzedaż', 'SUPPORT' => 'Serwis / wsparcie', 'OTHER' => 'Dział / osoba', 'UNKNOWN' => 'Telefon firmy'];

	public function __construct(
		private CompanyPhoneRepository $phones,
		private CompanyRepository $companies,
		private CompanyPhoneExtractor $extractor,
		private PageFetcher $fetcher,
	) {
	}

	/**
	 * Ekstrakcja z dokumentów już pobranych przez inny proces (analiza, crawler). Błędy nie przerywają procesu wywołującego.
	 *
	 * @param list<array{html: string, url: string, kind: string}> $docs
	 */
	public function fromDocuments(int $companyId, array $docs): int
	{
		try {
			$found = [];

			$name = (string) ($this->companies->find($companyId)['name'] ?? '');

			foreach ($docs as $d) {
				foreach ($this->extractor->extract((string) $d['html'], (string) $d['url'], (string) $d['kind'], $name) as $p) {
					$found[] = $p;
				}
			}

			$saved = $this->phones->recordExtracted($companyId, $found);
			ActionCenterService::bumpGlobal();

			return $saved;
		} catch (Throwable $e) {
			Redactor::log('Company phones #' . $companyId . ': ' . $e->getMessage());

			return 0;
		}
	}

	/**
	 * Ręczne odświeżenie numerów firmy (strona główna + Kontakt).
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(TenantContext $ctx, int $companyId): array
	{
		$company = $this->visible($ctx, $companyId);

		if (empty($company['website_url'])) {
			throw UbrException::invalid('Firma nie ma strony WWW — numery można dodać ręcznie.');
		}

		if (($company['domain_status'] ?? '') === 'mismatch') {
			throw UbrException::invalid('Domena należy do innego podmiotu — numery z tej strony nie są przypisywane do firmy.');
		}

		$lock = 'ubr_phones_refresh_' . $companyId;

		if (get_transient($lock)) {
			return $this->forCompany($ctx, $companyId) + ['message' => 'Numery były odświeżane przed chwilą — spróbuj ponownie za kilka minut.'];
		}

		set_transient($lock, 1, self::REFRESH_COOLDOWN);

		// robots.txt respektowany jak w Decision Maker Finderze (grupa `*` i nasz User-Agent; cache 1 dzień).
		$robots = app(\App\Ubr\Infrastructure\Contacts\RobotsPolicy::class);
		$requests = 0;
		$rules = $robots->rulesFor((string) $company['website_url'], $requests);

		if (! $robots->allowed($rules, (string) (parse_url((string) $company['website_url'], PHP_URL_PATH) ?: '/'))) {
			$this->phones->refreshPrimary($companyId); // oznaczenie „sprawdzono” (phones_checked_at)

			throw UbrException::invalid('robots.txt strony nie pozwala na pobranie strony głównej — numery można dodać ręcznie.');
		}

		$home = $this->fetcher->fetch((string) $company['website_url'], 10);

		if (! $home['ok']) {
			throw UbrException::invalid('Nie udało się pobrać strony: ' . ($home['blocked'] ? 'adres zablokowany (ochrona SSRF)' : ($home['error'] ?? 'błąd')) . '.');
		}

		$docs = [['html' => $home['body'], 'url' => (string) $home['final_url'], 'kind' => 'home']];
		$sub = WebsiteAnalyzerService::pickSubpage(new HtmlDocument($home['body'], (string) $home['final_url']), UxStructureAnalyzer::CONTACT_RE, self::baseHref((string) $home['body'], (string) $home['final_url']), [(string) $home['final_url']]);
		$skipped = false;

		if ($sub && ! $robots->allowed($rules, (string) (parse_url($sub, PHP_URL_PATH) ?: '/'))) {
			$sub = null;
			$skipped = true;
		}

		if ($sub) {
			$res = $this->fetcher->fetch($sub, 10);

			if ($res['ok']) {
				$docs[] = ['html' => $res['body'], 'url' => (string) $res['final_url'], 'kind' => 'contact'];
			}
		}

		// Pełne odświeżenie: numery ze stron pobranych TERAZ zastępują wcześniejsze z tych samych adresów (aktualne typy
		// i etykiety, bez numerów usuniętych ze strony). Numery ręczne organizacji i z innych stron — bez zmian.
		$found = [];

		foreach ($docs as $d) {
			foreach ($this->extractor->extract((string) $d['html'], (string) $d['url'], (string) $d['kind'], (string) ($company['name'] ?? '')) as $p) {
				$found[] = $p;
			}
		}

		// Brak numerów na stronie Kontakt, która tylko odsyła dalej (np. lista krajów → „Polska”): jedna dodatkowa strona.
		$contactDoc = $docs[1] ?? null;

		if ($found === [] && $contactDoc) {
			// Najpierw jednoznaczne „Polska” / centrala, dopiero potem ogólne „contact-…” (np. ISCAR: /contact-iscar/uae
			// jest krótszy od /contact-iscar/poland, więc jedno wspólne wyrażenie wybierało ZEA).
			$listDoc = new HtmlDocument((string) $contactDoc['html'], (string) $contactDoc['url']);
			$exclude = [(string) $home['final_url'], (string) $contactDoc['url']];
			$next = WebsiteAnalyzerService::pickSubpage($listDoc, '/(polska|poland|headquarters|centrala|siedziba)/iu', (string) $contactDoc['url'], $exclude)
				?? WebsiteAnalyzerService::pickSubpage($listDoc, '/(biuro|office|kontakt-|contact-)/iu', (string) $contactDoc['url'], $exclude);

			if ($next && $robots->allowed($rules, (string) (parse_url($next, PHP_URL_PATH) ?: '/'))) {
				$res = $this->fetcher->fetch($next, 10);

				if ($res['ok']) {
					$docs[] = ['html' => $res['body'], 'url' => (string) $res['final_url'], 'kind' => 'contact'];

					foreach ($this->extractor->extract((string) $res['body'], (string) $res['final_url'], 'contact', (string) ($company['name'] ?? '')) as $p) {
						$found[] = $p;
					}
				}
			}
		}

		$this->phones->replaceExtracted($companyId, array_map(fn ($d) => (string) $d['url'], $docs), $found);
		ActionCenterService::bumpGlobal();

		return $this->forCompany($ctx, $companyId) + ['message' => 'Odświeżono numery firmy ze strony WWW' . ($skipped ? ' (strona Kontakt pominięta — robots.txt)' : '') . '.'];
	}

	/**
	 * Adres bazowy dla linków względnych: `<base href>` strony (ta sama domena), inaczej adres strony.
	 * Np. strona /pl/start.html z `<base href="https://firma.pl/">` i linkiem „pl/kontakt.html”.
	 */
	public static function baseHref(string $html, string $pageUrl): string
	{
		if (preg_match('/<base\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) {
			$base = PageFetcher::absolute(html_entity_decode($m[1]), $pageUrl);
			$host = strtolower((string) parse_url($base, PHP_URL_HOST));
			$pageHost = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));

			if ($host !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $pageHost) && in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
				return $base;
			}
		}

		return $pageUrl;
	}

	/**
	 * @return array{phones: list<array<string, mixed>>, main: ?array<string, mixed>, checked_at: ?string, can_manage: bool}
	 */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		$company = $this->visible($ctx, $companyId);
		$rows = $this->phones->forCompanies([$companyId], $ctx->organizationId)[$companyId] ?? [];
		$list = array_map(fn ($r) => self::present($r), $rows);
		$main = self::mainFor($rows);

		return [
			'phones' => $list,
			'main' => $main ? self::present($main) : null,
			'checked_at' => ! empty($company['phones_checked_at']) ? gmdate('c', strtotime($company['phones_checked_at'] . ' UTC')) : null,
			'can_manage' => $ctx->can('pipeline.manage'),
		];
	}

	/**
	 * Numer główny dla organizacji: ręczny numer główny organizacji (jeśli jest), inaczej numer główny ze strony.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	public static function mainFor(array $rows): ?array
	{
		foreach ($rows as $r) {
			if ((int) $r['manual'] === 1 && $r['phone_type'] === 'MAIN') {
				return $r;
			}
		}

		foreach ($rows as $r) {
			if ((int) $r['is_primary'] === 1) {
				return $r;
			}
		}

		foreach ($rows as $r) {
			if ((int) $r['manual'] === 1) {
				return $r;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $input number, type, label
	 * @return array<string, mixed>
	 */
	public function addManual(TenantContext $ctx, int $companyId, array $input): array
	{
		if (! $ctx->can('pipeline.manage')) {
			throw UbrException::forbidden();
		}

		$this->visible($ctx, $companyId);
		$n = PhoneNumber::parse(sanitize_text_field((string) ($input['number'] ?? '')));

		if (! $n) {
			throw UbrException::invalid('Nieprawidłowy numer telefonu (np. +48 12 345 67 89 lub 512 345 678).');
		}

		$type = in_array($input['type'] ?? '', CompanyPhoneExtractor::TYPES, true) ? (string) $input['type'] : 'MAIN';
		$this->phones->addManual($companyId, $ctx->organizationId, $n + ['type' => $type, 'label' => mb_substr(sanitize_text_field((string) ($input['label'] ?? '')), 0, 120)], $ctx->userId);
		ActionCenterService::bumpOrganization($ctx->organizationId);

		return $this->forCompany($ctx, $companyId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function deleteManual(TenantContext $ctx, int $companyId, int $phoneId): array
	{
		if (! $ctx->can('pipeline.manage')) {
			throw UbrException::forbidden();
		}

		$this->visible($ctx, $companyId);
		$row = $this->phones->find($phoneId);

		if (! $row || (int) $row['company_id'] !== $companyId || (int) $row['org_key'] !== $ctx->organizationId || (int) $row['manual'] !== 1) {
			throw UbrException::notFound('Nie znaleziono numeru dodanego ręcznie przez Twoją organizację.');
		}

		$this->phones->deleteManual($phoneId, $ctx->organizationId);
		ActionCenterService::bumpOrganization($ctx->organizationId);

		return $this->forCompany($ctx, $companyId);
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function present(array $r): array
	{
		return [
			'id' => (int) $r['id'],
			'number' => PhoneNumber::format((string) $r['e164']) . (! empty($r['extension']) ? ' wew. ' . $r['extension'] : ''),
			'e164' => $r['e164'],
			'tel' => 'tel:' . $r['e164'] . (! empty($r['extension']) ? ';ext=' . $r['extension'] : ''),
			'raw' => $r['raw_number'],
			'country' => $r['country_code'],
			'type' => $r['phone_type'],
			'type_label' => self::TYPE_LABELS[$r['phone_type']] ?? $r['phone_type'],
			'source' => $r['source'],
			'source_label' => ['TEL_LINK' => 'link tel: na stronie', 'SCHEMA' => 'dane strukturalne strony', 'TEXT' => 'treść strony', 'MANUAL' => 'dodany ręcznie'][$r['source']] ?? $r['source'],
			'source_url' => \App\Ubr\Infrastructure\Http\UrlGuard::httpUrl($r['source_url'] ?? null),
			'branch' => $r['branch_label'],
			'context' => $r['context'],
			'confidence' => $r['confidence'],
			'primary' => (bool) $r['is_primary'],
			'manual' => (bool) $r['manual'],
			'found_at' => ! empty($r['found_at']) ? gmdate('c', strtotime($r['found_at'] . ' UTC')) : null,
			'last_seen_at' => ! empty($r['last_seen_at']) ? gmdate('c', strtotime($r['last_seen_at'] . ' UTC')) : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function visible(TenantContext $ctx, int $companyId): array
	{
		$company = $this->companies->find($companyId);

		if (! $company || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		return $company;
	}
}
