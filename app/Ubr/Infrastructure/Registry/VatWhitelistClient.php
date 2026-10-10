<?php

namespace App\Ubr\Infrastructure\Registry;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Discovery\CityCatalog;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Domain\Verification\RegistryRecord;

/**
 * Wykaz podatników VAT („biała lista”) Ministerstwa Finansów — bezpłatne API bez klucza:
 * `GET {base}/api/search/nip/{nip}?date=YYYY-MM-DD`.
 *
 * Adres bazowy: od 12.10.2026 `https://wykazpodatnikowvat.mf.gov.pl` (komunikat MF), wcześniej `https://wl-api.mf.gov.pl`.
 * Używamy obu (aktualny pierwszy, drugi zapasowo przy błędzie połączenia).
 * Limit MF: 100 zapytań wyszukujących dziennie (na IP) — domyślnie zużywamy maks. 80 (`UBR_VAT_DAILY_LIMIT`), cache 7 dni.
 *
 * Wynik NIE zawiera danych o aktywności przedsiębiorstwa (tylko status VAT): „Czynny”/„Zwolniony” = podmiot istnieje
 * i działa jako podatnik; wykreślenie z rejestru VAT ≠ zakończenie działalności (zapisujemy jako rozbieżność).
 * Numery rachunków bankowych i dane osób (reprezentanci, wspólnicy) są ignorowane — nie zapisujemy ich.
 */
class VatWhitelistClient
{
	public const NEW_BASE = 'https://wykazpodatnikowvat.mf.gov.pl';

	public const OLD_BASE = 'https://wl-api.mf.gov.pl';

	public function __construct(private RegistryHttp $http)
	{
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/registry/vat_enabled', true);
	}

	public static function dailyLimit(): int
	{
		return (int) apply_filters('ubr/registry/vat_daily_limit', defined('UBR_VAT_DAILY_LIMIT') ? (int) UBR_VAT_DAILY_LIMIT : 80);
	}

	/**
	 * @return list<string>
	 */
	public function bases(): array
	{
		$bases = gmdate('Y-m-d') >= '2026-10-12' ? [self::NEW_BASE, self::OLD_BASE] : [self::OLD_BASE, self::NEW_BASE];

		return array_values((array) apply_filters('ubr/registry/vat_bases', $bases));
	}

	public function find(string $nip): ?RegistryRecord
	{
		$nip = N::nip($nip);

		if (! $nip) {
			return null;
		}

		$date = gmdate('Y-m-d');
		$last = null;

		foreach ($this->bases() as $i => $base) {
			try {
				$response = $this->http->request('vat', 'GET', sprintf('%s/api/search/nip/%s?date=%s', $base, $nip, $date), ['headers' => ['Accept' => 'application/json']], [
					'interval_ms' => 1500,
					'daily_limit' => self::dailyLimit(),
					'endpoint' => '/api/search/nip',
					// Klucz cache niezależny od adresu bazowego i daty — wynik ważny 7 dni.
					'cache_key' => 'vat-nip-' . $nip,
				]);
			} catch (\App\Ubr\Domain\Verification\RegistryUnavailable $e) {
				$last = $e;

				// Limit dzienny dotyczy wszystkich adresów — nie próbujemy kolejnego.
				if (str_contains($e->getMessage(), 'limit')) {
					throw $e;
				}

				continue;
			}

			if ($response['status'] === 200 && is_array($response['data'])) {
				$subject = $response['data']['result']['subject'] ?? null;

				return is_array($subject) ? $this->parse($subject, $nip) : null;
			}

			if ($response['status'] === 400 || $response['status'] === 404) {
				// WL-113 itp. — nieprawidłowy/nieznany NIP.
				return null;
			}
		}

		if ($last) {
			throw $last;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $s `result.subject`
	 */
	public function parse(array $s, string $nip): RegistryRecord
	{
		$statusVat = (string) ($s['statusVat'] ?? '');
		// Adres siedziby (residenceAddress) tylko dla podmiotów z KRS — u osób fizycznych to adres prywatny, nie zapisujemy go.
		$address = (string) (($s['workingAddress'] ?? '') ?: (! empty($s['krs']) ? ($s['residenceAddress'] ?? '') : ''));
		[$street, $postal, $city] = self::splitAddress($address);

		$status = match (true) {
			in_array($statusVat, ['Czynny', 'Zwolniony'], true) => RegistryRecord::ACTIVE,
			default => RegistryRecord::UNKNOWN,
		};

		$detail = match (true) {
			$statusVat === 'Czynny' => 'Czynny podatnik VAT.',
			$statusVat === 'Zwolniony' => 'Podatnik zwolniony z VAT (zarejestrowany).',
			! empty($s['removalDate']) => sprintf('Wykreślony z rejestru VAT %s%s — nie oznacza to zakończenia działalności.', $s['removalDate'], ! empty($s['removalBasis']) ? ' (podstawa: ' . $s['removalBasis'] . ')' : ''),
			default => 'Status VAT: ' . ($statusVat ?: 'brak') . '.',
		};

		$regionKey = CityCatalog::regionFor($city);

		return new RegistryRecord(
			source: 'vat',
			lookupKey: $nip,
			name: isset($s['name']) ? trim((string) $s['name']) : null,
			nip: N::nip($s['nip'] ?? $nip),
			regon: N::regon($s['regon'] ?? null),
			krs: N::krs($s['krs'] ?? null),
			status: $status,
			statusDetail: $detail,
			street: $street,
			postalCode: $postal,
			city: $city,
			region: RegionCatalog::label($regionKey),
			registeredAt: $s['registrationLegalDate'] ?? null,
			sourceUrl: 'https://www.podatki.gov.pl/wykaz-podatnikow-vat-wyszukiwarka',
			extra: array_filter([
				'status_vat' => $statusVat ?: null,
				'vat_removal_date' => $s['removalDate'] ?? null,
				'vat_removal_basis' => $s['removalBasis'] ?? null,
				'vat_restoration_date' => $s['restorationDate'] ?? null,
			]),
		);
	}

	/**
	 * „UL. PRZYKŁADOWA 1, 00-950 WARSZAWA” → [ulica, kod, miasto].
	 *
	 * @return array{0: ?string, 1: ?string, 2: ?string}
	 */
	public static function splitAddress(string $address): array
	{
		$address = trim($address);

		if ($address === '') {
			return [null, null, null];
		}

		if (preg_match('/^(.*?),?\s*(\d{2}-\d{3})\s+(.+)$/u', $address, $m)) {
			$street = trim($m[1], " ,");

			return [
				$street !== '' ? mb_convert_case($street, MB_CASE_TITLE) : null,
				$m[2],
				CityCatalog::canonical(mb_convert_case(trim($m[3]), MB_CASE_TITLE)),
			];
		}

		return [mb_convert_case($address, MB_CASE_TITLE), null, null];
	}
}
