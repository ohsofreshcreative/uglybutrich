<?php

namespace App\Ubr\Infrastructure\Registry;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Discovery\CityCatalog;
use App\Ubr\Domain\Discovery\IndustryCatalog;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Domain\Verification\RegistryRecord;

/**
 * CEIDG (jednoosobowe działalności gospodarcze) — API hurtowni danych CEIDG, wymaga bezpłatnego tokenu JWT
 * wydawanego na wniosek (dane.biznes.gov.pl). Token: stała `UBR_CEIDG_API_TOKEN` w wp-config.php — nigdy w repozytorium.
 *
 * `GET https://dane.biznes.gov.pl/api/ceidg/v3/firma?nip={nip}` (Authorization: Bearer …). Bez tokenu klient jest wyłączony,
 * a JDG weryfikujemy przez białą listę VAT (+ GUS, jeśli skonfigurowany).
 * Statusy CEIDG: AKTYWNY, ZAWIESZONY, WYKRESLONY, OCZEKUJE_NA_ROZPOCZECIE_DZIALANOSCI, DZIALA_WYLACZNIE_W_FORMIE_SPOLKI.
 * Dane właściciela (imię, nazwisko, adres zamieszkania) są ignorowane.
 */
class CeidgClient
{
	public function __construct(private RegistryHttp $http)
	{
	}

	public static function token(): ?string
	{
		$token = defined('UBR_CEIDG_API_TOKEN') ? (string) UBR_CEIDG_API_TOKEN : (string) getenv('UBR_CEIDG_API_TOKEN');

		return trim($token) !== '' ? trim($token) : null;
	}

	public static function enabled(): bool
	{
		return self::token() !== null && (bool) apply_filters('ubr/registry/ceidg_enabled', true);
	}

	public static function baseUrl(): string
	{
		return (string) apply_filters('ubr/registry/ceidg_base', defined('UBR_CEIDG_API_BASE') ? UBR_CEIDG_API_BASE : 'https://dane.biznes.gov.pl/api/ceidg/v3');
	}

	public function find(string $nip): ?RegistryRecord
	{
		$nip = N::nip($nip);

		if (! $nip || ! self::enabled()) {
			return null;
		}

		$response = $this->http->request('ceidg', 'GET', self::baseUrl() . '/firma?nip=' . $nip, [
			'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . self::token()],
		], ['interval_ms' => 1000, 'daily_limit' => 1000, 'endpoint' => '/firma', 'cache_key' => 'ceidg-nip-' . $nip]);

		if ($response['status'] !== 200 || ! is_array($response['data'])) {
			return null;
		}

		$firms = $response['data']['firma'] ?? $response['data']['firmy'] ?? [];
		$firms = array_values(array_filter((array) $firms, 'is_array'));

		if ($firms === []) {
			return null;
		}

		// Ten sam NIP może mieć kilka wpisów (historyczne + aktualny) — preferujemy aktywny/zawieszony, potem najnowszy.
		usort($firms, function ($a, $b) {
			$rank = fn ($f) => match (strtoupper((string) ($f['status'] ?? ''))) {
				'AKTYWNY' => 3, 'ZAWIESZONY' => 2, 'OCZEKUJE_NA_ROZPOCZECIE_DZIALANOSCI' => 1, default => 0,
			};

			return [$rank($b), (string) ($b['dataRozpoczecia'] ?? '')] <=> [$rank($a), (string) ($a['dataRozpoczecia'] ?? '')];
		});

		return $this->parse($firms[0], $nip);
	}

	/**
	 * @param array<string, mixed> $f
	 */
	public function parse(array $f, string $nip): RegistryRecord
	{
		$raw = strtoupper((string) ($f['status'] ?? ''));
		$status = match ($raw) {
			'AKTYWNY' => RegistryRecord::ACTIVE,
			'ZAWIESZONY' => RegistryRecord::SUSPENDED,
			'WYKRESLONY', 'DZIALA_WYLACZNIE_W_FORMIE_SPOLKI' => RegistryRecord::INACTIVE,
			default => RegistryRecord::UNKNOWN,
		};

		$a = (array) ($f['adresDzialalnosci'] ?? []);
		$street = trim(implode(' ', array_filter([$a['ulica'] ?? null, $a['budynek'] ?? null, ! empty($a['lokal']) ? '/' . $a['lokal'] : null])));
		$city = CityCatalog::canonical($a['miasto'] ?? null);
		$pkd = $f['pkdGlowny'] ?? ($f['pkd'][0] ?? null);
		$pkdCode = is_array($pkd) ? ($pkd['kod'] ?? null) : $pkd;

		return new RegistryRecord(
			source: 'ceidg',
			lookupKey: $nip,
			name: isset($f['nazwa']) ? trim((string) $f['nazwa']) : null,
			nip: N::nip($f['wlasciciel']['nip'] ?? $nip),
			regon: N::regon($f['wlasciciel']['regon'] ?? null),
			legalForm: 'Jednoosobowa działalność gospodarcza (CEIDG)',
			status: $status,
			statusDetail: 'Status CEIDG: ' . ($raw ?: 'brak') . (! empty($f['dataZawieszenia']) ? ' od ' . $f['dataZawieszenia'] : '') . (! empty($f['dataZakonczenia']) ? ', zakończenie ' . $f['dataZakonczenia'] : '') . '.',
			street: $street !== '' ? $street : null,
			postalCode: $a['kod'] ?? null,
			city: $city,
			region: RegionCatalog::label(RegionCatalog::keyFromName($a['wojewodztwo'] ?? null) ?? CityCatalog::regionFor($city)),
			pkdMain: IndustryCatalog::pkd(is_string($pkdCode) ? $pkdCode : null),
			pkdDescription: is_array($pkd) && isset($pkd['nazwa']) ? mb_substr((string) $pkd['nazwa'], 0, 255) : null,
			website: isset($f['adresStronyInternetowej']) ? (string) $f['adresStronyInternetowej'] : null,
			registeredAt: $f['dataRozpoczecia'] ?? null,
			sourceUrl: isset($f['link']) ? (string) $f['link'] : 'https://aplikacja.ceidg.gov.pl/ceidg/ceidg.public.ui/search.aspx',
			extra: array_filter(['ceidg_status' => $raw ?: null]),
		);
	}
}
