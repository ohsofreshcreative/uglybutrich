<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Discovery\CityCatalog;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Domain\Verification\CompanyStatusDecider;
use App\Ubr\Domain\Verification\DomainDecider;
use App\Ubr\Domain\Verification\DomainStatus;
use App\Ubr\Domain\Verification\NameMatcher;
use App\Ubr\Domain\Verification\RegistryRecord;
use App\Ubr\Domain\Verification\RegistryUnavailable;
use App\Ubr\Infrastructure\Database\DatabaseException;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Http\PageCache;
use App\Ubr\Infrastructure\Http\WebsiteInspector;
use App\Ubr\Infrastructure\Registry\CeidgClient;
use App\Ubr\Infrastructure\Registry\GusBirClient;
use App\Ubr\Infrastructure\Registry\KrsClient;
use App\Ubr\Infrastructure\Registry\VatWhitelistClient;
use App\Ubr\Infrastructure\Repositories\CompanyCheckRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;

/**
 * Weryfikacja jednej firmy: strona WWW → identyfikatory → rejestry publiczne → status przedsiębiorstwa i domeny.
 *
 * Kolejność: 1) inspekcja strony (NIP/KRS/REGON opublikowane przez firmę, sygnały domeny),
 * 2) zapytania do rejestrów WYŁĄCZNIE po identyfikatorach (KRS, biała lista VAT, CEIDG*, GUS* — *jeśli skonfigurowane),
 * 3) dopasowanie nazwy i decyzja (CompanyStatusDecider, DomainDecider), 4) zapis + historia + przeliczenie trafności.
 *
 * Nigdy: wyszukiwanie po nazwie i branie pierwszego wyniku, VERIFIED bez rejestru, nadpisanie identyfikatora innej firmy.
 * Wywoływana z kolejki w tle (VerificationRunner) — nie przy otwieraniu profilu firmy.
 */
class CompanyVerifier
{
	/** Maks. liczba zapytań do rejestrów na jedną firmę (koszt czasu i limity MF). */
	public const MAX_REGISTRY_CALLS = 6;

	private int $calls = 0;

	public function __construct(
		private CompanyRepository $companies,
		private CompanyCheckRepository $checks,
		private WebsiteInspector $inspector,
		private KrsClient $krs,
		private VatWhitelistClient $vat,
		private CeidgClient $ceidg,
		private GusBirClient $gus,
		private RelevanceService $relevance,
	) {
	}

	/**
	 * @param array{org?: ?int, user?: ?int, trigger?: string} $context
	 * @return array{status: string, domain_status: string, retry: bool, retry_after: int, error: ?string}
	 */
	public function verify(int $companyId, array $context = []): array
	{
		$company = $this->companies->find($companyId);

		if (! $company) {
			return ['status' => 'missing', 'domain_status' => DomainStatus::UNCHECKED, 'retry' => false, 'retry_after' => 0, 'error' => 'Firma nie istnieje.'];
		}

		$started = microtime(true);
		$this->calls = 0;
		$domain = $company['domain'] ?: null;
		$site = null;
		$nameMatch = 'none';

		// 1. Strona WWW.
		if ($domain && $company['website_url']) {
			$site = $this->inspector->inspect((string) $company['website_url']);
			$nameMatch = WebsiteInspector::nameMatch((string) $company['name'], $site);
		}

		$siteUsable = $site && $site['reachable'] && empty($site['parked']) && in_array($site['final_domain_type'] ?? 'official', ['official', 'website_builder_subdomain'], true);
		$siteBelongs = $siteUsable && in_array($nameMatch, ['strong', 'medium'], true);

		// 2. Identyfikatory: najpierw opublikowane na stronie firmy, potem zapisane przy firmie (z rekordu dostawcy).
		$candidates = [];

		if ($siteUsable) {
			foreach (['krs', 'nip', 'regon'] as $type) {
				foreach (array_slice((array) $site['identifiers'][$type], 0, 2) as $value) {
					$candidates[$type . ':' . $value] ??= ['type' => $type, 'value' => $value, 'origin' => 'website'];
				}
			}
		}

		foreach (['krs', 'nip', 'regon'] as $type) {
			if (! empty($company[$type])) {
				$candidates[$type . ':' . $company[$type]] ??= ['type' => $type, 'value' => (string) $company[$type], 'origin' => 'company'];
			}
		}

		// 3. Rejestry.
		[$hits, $errors] = $this->lookup(array_values($candidates), (string) $company['name'], $domain);

		$decision = CompanyStatusDecider::decide($hits, $errors, count($candidates), $siteBelongs);
		$primary = $decision['primary'];

		// Domena: czy identyfikator ze strony wskazuje tę firmę, czy inny podmiot.
		$identifierConfirmed = false;
		$identifierOther = null;

		foreach ($hits as $hit) {
			if ($hit['origin'] !== 'website') {
				continue;
			}

			$inEntity = (bool) array_filter($decision['entity'], fn ($e) => $e['record'] === $hit['record']);

			if ($inEntity && ($hit['score'] >= NameMatcher::STRONG || $hit['domain_match'])) {
				$identifierConfirmed = true;
			} elseif (! $inEntity && $hit['score'] < NameMatcher::WEAK) {
				$identifierOther ??= (string) $hit['record']->name;
			}
		}

		$registryWebsiteMatch = (bool) array_filter($decision['entity'], fn ($e) => $e['domain_match']);
		$domainDecision = DomainDecider::decide($domain, $site, $nameMatch, $identifierConfirmed, $identifierOther, $registryWebsiteMatch);

		// 4. Zapis.
		$this->persist($company, $site, $nameMatch, $decision, $domainDecision, $errors, $context, (int) round((microtime(true) - $started) * 1000));
		$this->relevance->recomputeForCompany($companyId);
		$this->extractPhones($company, $site, (string) $domainDecision['status']);

		$retryAfter = 0;

		if ($decision['retry']) {
			$retryAfter = max(array_map(fn ($e) => $e['retry_after'] ?? 600, array_values($this->lastErrorMeta)) ?: [600]);
		}

		return [
			'status' => $decision['status'],
			'domain_status' => $domainDecision['status'],
			'retry' => $decision['retry'],
			'retry_after' => $retryAfter,
			'error' => $errors ? implode('; ', $errors) : null,
		];
	}

	/**
	 * Prompt 10.4 — numery firmy ze stron pobranych już przy weryfikacji (wspólna pamięć podręczna): bez dodatkowych
	 * zapytań do strony, od razu po weryfikacji (wcześniej dopiero po Website Analyzerze / ręcznym odświeżeniu).
	 * Pomijane, gdy strona nieosiągalna, zaparkowana albo domena należy do innego podmiotu.
	 *
	 * @param array<string, mixed> $company
	 * @param array<string, mixed>|null $site
	 */
	private function extractPhones(array $company, ?array $site, string $domainStatus): void
	{
		if (! $site || empty($site['reachable']) || ! empty($site['parked']) || $domainStatus === DomainStatus::MISMATCH || ! apply_filters('ubr/verification/extract_phones', true)) {
			return;
		}

		$cache = app(PageCache::class);
		$docs = [];

		foreach ((array) ($site['pages'] ?? []) as $page) {
			$hit = $cache->follow((string) ($page['url'] ?? ''));

			if ($hit && $hit['code'] >= 200 && $hit['code'] < 400 && $hit['body'] !== '') {
				$docs[] = ['html' => $hit['body'], 'url' => $hit['final_url'], 'kind' => (string) ($page['kind'] ?? 'other')];
			}
		}

		if ($docs !== []) {
			app(CompanyPhoneService::class)->fromDocuments((int) $company['id'], $docs);
		}
	}

	/** @var array<string, array{retry_after: int}> */
	private array $lastErrorMeta = [];

	/**
	 * @param list<array{type: string, value: string, origin: string}> $candidates
	 * @return array{0: list<array{record: RegistryRecord, origin: string, score: float, domain_match: bool}>, 1: array<string, string>}
	 */
	private function lookup(array $candidates, string $companyName, ?string $domain): array
	{
		$hits = [];
		$errors = [];
		$this->lastErrorMeta = [];
		$seenKrs = [];
		$seenNip = [];
		$activityFor = [];

		$add = function (?RegistryRecord $record, string $origin) use (&$hits, $companyName, $domain, &$seenKrs, &$seenNip, &$activityFor): void {
			if (! $record) {
				return;
			}

			$registryDomain = $record->website ? N::domain(N::websiteUrl($record->website)) : null;
			$hits[] = [
				'record' => $record,
				'origin' => $origin,
				'score' => NameMatcher::score($companyName, $record->name),
				'domain_match' => $domain !== null && $registryDomain !== null && $registryDomain === $domain,
			];

			// Numer KRS uznajemy za sprawdzony dopiero po odpytaniu KRS (rekord VAT tylko go wskazuje).
			if ($record->krs && $record->source === 'krs') {
				$seenKrs[$record->krs] = true;
			}

			if ($record->nip && $record->isActivityRegistry()) {
				$activityFor[$record->nip] = true;
			}
		};

		$call = function (string $source, callable $fn) use (&$errors) {
			if ($this->calls >= self::MAX_REGISTRY_CALLS || isset($errors[$source])) {
				return null;
			}

			$this->calls++;

			try {
				return $fn();
			} catch (RegistryUnavailable $e) {
				$errors[$source] = $e->getMessage();
				$this->lastErrorMeta[$source] = ['retry_after' => $e->retryAfter];

				return null;
			}
		};

		// KRS po numerze KRS.
		foreach ($candidates as $c) {
			if ($c['type'] === 'krs' && ! isset($seenKrs[$c['value']]) && KrsClient::enabled()) {
				$seenKrs[$c['value']] = true;
				$add($call('krs', fn () => $this->krs->find($c['value'])), $c['origin']);
			}
		}

		// NIP: biała lista VAT (podaje też KRS → odpis KRS), CEIDG dla JDG, GUS jeśli skonfigurowany.
		foreach ($candidates as $c) {
			if ($c['type'] !== 'nip' || isset($seenNip[$c['value']]) || count($seenNip) >= 2) {
				continue;
			}

			$seenNip[$c['value']] = true;
			$vatRecord = VatWhitelistClient::enabled() ? $call('vat', fn () => $this->vat->find($c['value'])) : null;
			$add($vatRecord, $c['origin']);

			if ($vatRecord && $vatRecord->krs && ! isset($seenKrs[$vatRecord->krs]) && KrsClient::enabled()) {
				$seenKrs[$vatRecord->krs] = true;
				$add($call('krs', fn () => $this->krs->find($vatRecord->krs)), 'registry');
			}

			$isKrsEntity = $vatRecord && $vatRecord->krs;

			if (! $isKrsEntity && ! isset($activityFor[$c['value']]) && CeidgClient::enabled()) {
				$add($call('ceidg', fn () => $this->ceidg->find($c['value'])), $c['origin']);
			}

			if (! isset($activityFor[$c['value']]) && GusBirClient::enabled()) {
				$add($call('gus', fn () => $this->gus->find('Nip', $c['value'])), $c['origin']);
			}
		}

		// REGON tylko przez GUS (inne rejestry nie wyszukują po REGON).
		if (GusBirClient::enabled()) {
			foreach ($candidates as $c) {
				if ($c['type'] === 'regon' && ! array_filter($hits, fn ($h) => $h['record']->regon === $c['value'])) {
					$add($call('gus', fn () => $this->gus->find('Regon', $c['value'])), $c['origin']);
					break;
				}
			}
		}

		return [$hits, $errors];
	}

	/**
	 * @param array<string, mixed> $company
	 * @param array<string, mixed>|null $site
	 * @param array<string, mixed> $decision
	 * @param array{status: string, reasons: list<string>} $domainDecision
	 * @param array<string, string> $errors
	 * @param array<string, mixed> $context
	 */
	private function persist(array $company, ?array $site, string $nameMatch, array $decision, array $domainDecision, array $errors, array $context, int $durationMs): void
	{
		$id = (int) $company['id'];
		$now = Db::now();
		/** @var RegistryRecord|null $primary */
		$primary = $decision['primary'];
		$discrepancies = $decision['discrepancies'];
		$update = [];

		// Identyfikatory i dane rejestrowe — tylko z rekordu podmiotu dopasowanego do firmy.
		if ($primary && in_array($decision['status'], ['verified', 'inactive', 'suspended'], true)) {
			$records = array_map(fn ($h) => $h['record'], $decision['entity']);
			$pick = function (string $field) use ($records) {
				foreach ($records as $r) {
					if (! empty($r->{$field})) {
						return $r->{$field};
					}
				}

				return null;
			};

			foreach (['nip' => $pick('nip'), 'krs' => $pick('krs')] as $field => $value) {
				if (! $value) {
					continue;
				}

				if (! empty($company[$field]) && $company[$field] !== $value) {
					$discrepancies[] = sprintf('%s zapisany wcześniej (%s) różni się od rejestru (%s) — pozostawiono bez zmian.', strtoupper($field), $company[$field], $value);
					continue;
				}

				$owner = $this->companies->idByColumn($field, $value);

				if ($owner && $owner !== $id) {
					$discrepancies[] = sprintf('%s %s jest już przypisany do firmy #%d — możliwy duplikat (bez automatycznego łączenia).', strtoupper($field), $value, $owner);
					continue;
				}

				if (empty($company[$field])) {
					$update[$field] = $value;
				}
			}

			if (($regon = $pick('regon')) && empty($company['regon'])) {
				$update['regon'] = $regon;
			}

			$update['registry_name'] = $primary->name ? mb_substr($primary->name, 0, 500) : null;
			$update['legal_form'] = ($legal = $pick('legalForm')) ? mb_substr($legal, 0, 120) : null;

			if ($pkd = $pick('pkdMain')) {
				$update['pkd_main'] = $pkd;
				$update['pkd_description'] = $pick('pkdDescription') ? mb_substr((string) $pick('pkdDescription'), 0, 255) : null;
			}

			// Lokalizacja z rejestru = potwierdzona. Wcześniejsza deklaracja dostawcy zostaje w historii; rozbieżność zapisujemy.
			$regionRecord = null;

			foreach ($records as $r) {
				if ($r->city || $r->region) {
					$regionRecord = $r;
					break;
				}
			}

			if ($regionRecord) {
				$newRegion = $regionRecord->region ?? RegionCatalog::label(CityCatalog::regionFor($regionRecord->city));
				$oldRegionKey = RegionCatalog::keyFromName($company['region'] ?? null);
				$newRegionKey = RegionCatalog::keyFromName($newRegion);

				if ($oldRegionKey && $newRegionKey && $oldRegionKey !== $newRegionKey) {
					$discrepancies[] = sprintf('Województwo wg źródła danych (%s) różni się od rejestru (%s) — przyjęto rejestr.', $company['region'], $newRegion);
				}

				$update += array_filter([
					'street' => $regionRecord->street ? mb_substr($regionRecord->street, 0, 255) : null,
					'postal_code' => $regionRecord->postalCode,
					'city' => $regionRecord->city ? mb_substr($regionRecord->city, 0, 120) : null,
					'region' => $newRegion,
				]);
				$update['location_confidence'] = Confidence::CONFIRMED;
				$update['location_source'] = $regionRecord->source;
			}

			foreach ($records as $r) {
				foreach (['name' => $r->name, 'nip' => $r->nip, 'krs' => $r->krs, 'regon' => $r->regon, 'legal_form' => $r->legalForm, 'registry_status' => $r->status . ($r->statusDetail ? ': ' . $r->statusDetail : ''), 'city' => $r->city, 'region' => $r->region, 'pkd_main' => $r->pkdMain] as $field => $value) {
					if ($value !== null && $value !== '') {
						$this->companies->addEvidence($id, $r->source, $r->lookupKey, $field, (string) $value, Confidence::CONFIRMED, $r->sourceUrl, null);
					}
				}
			}
		}

		$siteSummary = $site ? array_intersect_key($site, array_flip(['http_code', 'final_url', 'final_domain', 'redirected_domain', 'final_domain_type', 'title', 'site_name', 'parked', 'placeholder', 'text_length', 'pages', 'identifiers', 'emails_same_domain', 'emails_other_domain', 'has_phone', 'address_cities', 'copyright', 'error'])) + ['name_match' => $nameMatch, 'jsonld_names' => array_values(array_filter(array_map(fn ($j) => $j['legalName'] ?? $j['name'] ?? null, (array) ($site['jsonld'] ?? []))))] : null;

		// Domena.
		$update += [
			'domain_status' => $domainDecision['status'],
			'domain_checked_at' => $now,
			'domain_summary' => wp_json_encode(['reasons' => $domainDecision['reasons'], 'signals' => $siteSummary]),
		];

		if ($site) {
			$update += [
				'website_status' => match (true) {
					! $site['reachable'] && $site['http_code'] === null => 'unreachable',
					! $site['reachable'] => 'http_error',
					! empty($site['redirected_domain']) => 'redirected',
					default => 'reachable',
				},
				'website_http_code' => $site['http_code'],
				'website_final_url' => $site['final_url'],
				'website_title' => $site['title'],
				'website_checked_at' => $now,
			];
		}

		if ($domainDecision['status'] === DomainStatus::CONFIRMED && $company['domain_confidence'] !== Confidence::CONFIRMED) {
			$update['domain_confidence'] = Confidence::CONFIRMED;
			$update['domain_source'] = mb_substr(($company['domain_source'] ?: 'unknown') . '+verified', 0, 50);
		} elseif (in_array($domainDecision['status'], [DomainStatus::MISMATCH], true) && $company['domain_confidence'] === Confidence::CONFIRMED && ! str_contains((string) $company['domain_source'], 'wikidata')) {
			$update['domain_confidence'] = Confidence::UNCERTAIN;
		}

		// Przedsiębiorstwo.
		$identifiers = [];

		foreach ((array) ($site['identifiers'] ?? []) as $type => $values) {
			foreach ($values as $v) {
				$identifiers[] = ['type' => $type, 'value' => $v, 'origin' => 'website'];
			}
		}

		$summary = [
			'reasons' => $decision['reasons'],
			'discrepancies' => array_values(array_unique($discrepancies)),
			'errors' => $errors,
			'identifiers_on_website' => $identifiers,
			'records' => array_map(fn ($h) => $h['record']->toArray() + ['origin' => $h['origin'], 'name_score' => $h['score'], 'domain_match' => $h['domain_match']], $decision['entity']),
		];

		$update += [
			'verification_status' => $decision['status'],
			'verification_confidence' => $decision['confidence'],
			'verification_source' => $primary ? mb_substr(implode(' + ', array_unique(array_map(fn ($h) => $h['record']->label(), $decision['entity']))), 0, 100) : null,
			'verification_checked_at' => $now,
			'verification_summary' => wp_json_encode($summary),
		];

		try {
			$this->companies->update($id, $update);
		} catch (DatabaseException $e) {
			if (! $e->duplicate) {
				throw $e;
			}

			unset($update['nip'], $update['krs']);
			$this->companies->update($id, $update);
		}

		$trigger = (string) ($context['trigger'] ?? 'auto');
		$org = isset($context['org']) ? (int) $context['org'] : null;
		$user = isset($context['user']) ? (int) $context['user'] : null;

		$this->checks->add($id, 'company', $decision['status'], $decision['confidence'], $update['verification_source'], $summary, $org, $user, $trigger, $durationMs);
		$this->checks->add($id, 'domain', $domainDecision['status'], null, $company['domain'] ?: null, ['reasons' => $domainDecision['reasons'], 'signals' => $siteSummary], $org, $user, $trigger, $durationMs);
	}
}
