<?php

namespace App\Ubr\Infrastructure\Registry;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Discovery\IndustryCatalog;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Domain\Verification\RegistryRecord;

/**
 * KRS — oficjalne API Ministerstwa Sprawiedliwości (bezpłatne, bez klucza):
 * `GET https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/{numerKRS}?rejestr=P&format=json` (rejestr P = przedsiębiorcy, S = stowarzyszenia).
 *
 * API wyszukuje WYŁĄCZNIE po numerze KRS (nie po nazwie) — numer pochodzi ze strony firmy, z białej listy VAT
 * albo z rekordu dostawcy. Nigdy nie wybieramy „pierwszego wyniku” po nazwie.
 *
 * Status: sekcje odpisu dot. wykreślenia / rozwiązania → INACTIVE, likwidacji → LIQUIDATION, upadłości → BANKRUPTCY,
 * zawieszenia (bez późniejszego wznowienia) → SUSPENDED. Klucze odpisu są wykrywane po nazwie (odporność na zmiany schematu).
 */
class KrsClient
{
	public const BASE_URL = 'https://api-krs.ms.gov.pl/api/krs';

	public function __construct(private RegistryHttp $http)
	{
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/registry/krs_enabled', true);
	}

	/**
	 * @return RegistryRecord|null null = brak podmiotu o tym numerze w KRS
	 */
	public function find(string $krs): ?RegistryRecord
	{
		$krs = N::krs($krs);

		if (! $krs) {
			return null;
		}

		$odpis = $this->odpis($krs);

		return $odpis ? $this->parse($odpis['data'], $krs, $odpis['register']) : null;
	}

	/**
	 * Surowy odpis aktualny (cache 7 dni w RegistryHttp — ta sama odpowiedź co przy weryfikacji firmy, bez dodatkowego zapytania).
	 *
	 * @return array{data: array<string, mixed>, register: string}|null
	 */
	public function odpis(string $krs): ?array
	{
		$krs = N::krs($krs);

		if (! $krs) {
			return null;
		}

		foreach (['P', 'S'] as $register) {
			$url = sprintf('%s/OdpisAktualny/%s?rejestr=%s&format=json', self::BASE_URL, $krs, $register);
			$response = $this->http->request('krs', 'GET', $url, ['headers' => ['Accept' => 'application/json']], [
				'interval_ms' => 1100,
				'daily_limit' => (int) apply_filters('ubr/registry/krs_daily_limit', defined('UBR_KRS_DAILY_LIMIT') ? (int) UBR_KRS_DAILY_LIMIT : 2000),
				'endpoint' => '/OdpisAktualny (' . $register . ')',
			]);

			if ($response['status'] === 200 && is_array($response['data']) && isset($response['data']['odpis'])) {
				return ['data' => $response['data'], 'register' => $register];
			}
		}

		return null;
	}

	/**
	 * Skład organu reprezentacji (zarząd) i prokurenci z działu 2 odpisu.
	 *
	 * API MS ZANONIMIZOWUJE dane osób: imię i nazwisko jako pierwsza litera + gwiazdki (np. „M****”), PESEL zamaskowany.
	 * Zapisujemy wyłącznie maski i funkcje (bez PESEL) — służą do POTWIERDZENIA osób znalezionych w innych źródłach,
	 * nie do tworzenia kontaktów. Rada nadzorcza jest pomijana (nie podejmuje decyzji zakupowych).
	 *
	 * @return array{krs: string, state_date: ?string, organ: ?string, representation: ?string, members: list<array<string, mixed>>, proxies: list<array<string, mixed>>, source_url: string}|null
	 */
	public function board(string $krs): ?array
	{
		$odpis = $this->odpis($krs);

		return $odpis ? $this->parseBoard($odpis['data'], (string) N::krs($krs)) : null;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{krs: string, state_date: ?string, organ: ?string, representation: ?string, members: list<array<string, mixed>>, proxies: list<array<string, mixed>>, source_url: string}
	 */
	public function parseBoard(array $data, string $krs): array
	{
		$odpis = (array) ($data['odpis'] ?? []);
		$d2 = (array) ($odpis['dane']['dzial2'] ?? []);
		$rep = (array) ($d2['reprezentacja'] ?? []);
		$person = function (array $p): array {
			$first = (string) ($p['imiona']['imie'] ?? '');
			$last = (string) ($p['nazwisko']['nazwiskoICzlon'] ?? '');

			return ['first_mask' => mb_substr($first, 0, 40), 'last_mask' => mb_substr($last, 0, 60), 'masked' => str_contains($first . $last, '*')];
		};

		$members = [];

		foreach ((array) ($rep['sklad'] ?? []) as $p) {
			if (! is_array($p)) {
				continue;
			}

			$members[] = $person($p) + [
				'function' => mb_substr(trim((string) ($p['funkcjaWOrganie'] ?? 'CZŁONEK ZARZĄDU')), 0, 120),
				'suspended' => ! empty($p['czyZawieszona']),
			];
		}

		$proxies = [];

		foreach ((array) ($d2['prokurenci'] ?? []) as $p) {
			if (is_array($p)) {
				$proxies[] = $person($p) + ['function' => 'PROKURENT', 'kind' => mb_substr((string) ($p['rodzajProkury'] ?? ''), 0, 120), 'suspended' => false];
			}
		}

		$stateDate = $odpis['naglowekA']['stanZDnia'] ?? null;

		if (is_string($stateDate) && preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $stateDate, $m)) {
			$stateDate = $m[3] . '-' . $m[2] . '-' . $m[1];
		}

		return [
			'krs' => $krs,
			'state_date' => is_string($stateDate) ? $stateDate : null,
			'organ' => isset($rep['nazwaOrganu']) ? mb_substr((string) $rep['nazwaOrganu'], 0, 120) : null,
			'representation' => isset($rep['sposobReprezentacji']) ? mb_substr((string) $rep['sposobReprezentacji'], 0, 600) : null,
			'members' => $members,
			'proxies' => $proxies,
			'source_url' => sprintf('https://wyszukiwarka-krs.ms.gov.pl/details?krs=%s', $krs),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function parse(array $data, string $krs, string $register = 'P'): RegistryRecord
	{
		$odpis = (array) ($data['odpis'] ?? []);
		$header = (array) ($odpis['naglowekA'] ?? []);
		$dane = (array) ($odpis['dane'] ?? []);
		$d1 = (array) ($dane['dzial1'] ?? []);
		$subject = (array) ($d1['danePodmiotu'] ?? []);
		$ids = (array) ($subject['identyfikatory'] ?? []);
		$seat = (array) ($d1['siedzibaIAdres']['siedziba'] ?? []);
		$address = (array) ($d1['siedzibaIAdres']['adres'] ?? []);
		$website = $d1['siedzibaIAdres']['adresStronyInternetowej'] ?? null;
		$pkd = (array) (($dane['dzial3']['przedmiotDzialalnosci']['przedmiotPrzewazajacejDzialalnosci'] ?? [])[0] ?? []);

		[$status, $detail] = $this->status($odpis);

		$street = trim(implode(' ', array_filter([
			$address['ulica'] ?? null,
			$address['nrDomu'] ?? null,
			isset($address['nrLokalu']) && $address['nrLokalu'] !== '' ? '/' . $address['nrLokalu'] : null,
		])));

		$pkdCode = isset($pkd['kodDzial']) ? IndustryCatalog::pkd(($pkd['kodDzial'] ?? '') . ($pkd['kodKlasa'] ?? '') . ($pkd['kodPodklasa'] ?? '')) : null;

		return new RegistryRecord(
			source: 'krs',
			lookupKey: $krs,
			name: isset($subject['nazwa']) ? trim((string) $subject['nazwa']) : null,
			nip: N::nip($ids['nip'] ?? null),
			regon: N::regon(substr(preg_replace('/\D/', '', (string) ($ids['regon'] ?? '')) ?? '', 0, 14)),
			krs: $krs,
			legalForm: isset($subject['formaPrawna']) ? (string) $subject['formaPrawna'] : ($register === 'S' ? 'Rejestr stowarzyszeń' : null),
			status: $status,
			statusDetail: $detail,
			street: $street !== '' ? mb_convert_case($street, MB_CASE_TITLE) : null,
			postalCode: $address['kodPocztowy'] ?? null,
			city: isset($seat['miejscowosc']) ? mb_convert_case((string) $seat['miejscowosc'], MB_CASE_TITLE) : (isset($address['miejscowosc']) ? mb_convert_case((string) $address['miejscowosc'], MB_CASE_TITLE) : null),
			region: RegionCatalog::label(RegionCatalog::keyFromName($seat['wojewodztwo'] ?? null)),
			pkdMain: $pkdCode,
			pkdDescription: isset($pkd['opis']) ? mb_substr((string) $pkd['opis'], 0, 255) : null,
			website: is_string($website) && $website !== '' ? mb_strtolower($website) : null,
			registeredAt: $header['dataRejestracjiWKRS'] ?? null,
			sourceUrl: sprintf('https://wyszukiwarka-krs.ms.gov.pl/details?krs=%s', $krs),
			extra: array_filter([
				'register' => $register,
				'state_date' => $header['stanZDnia'] ?? null,
				'country' => $seat['kraj'] ?? null,
			]),
		);
	}

	/**
	 * @param array<string, mixed> $odpis
	 * @return array{0: string, 1: ?string}
	 */
	public function status(array $odpis): array
	{
		$flat = [];
		$this->flatten($odpis, '', $flat);

		$has = function (string $pattern) use ($flat): ?string {
			foreach ($flat as $path => $value) {
				if (preg_match($pattern, mb_strtolower($path)) && $value !== '' && $value !== false && $value !== null) {
					return $path;
				}
			}

			return null;
		};

		if ($path = $has('/wykresl/')) {
			return [RegistryRecord::INACTIVE, 'Podmiot wykreślony z KRS (' . $path . ').'];
		}

		if ($path = $has('/(rozwiazanie|uniewaznienie)/')) {
			return [RegistryRecord::INACTIVE, 'Rozwiązanie / unieważnienie podmiotu (' . $path . ').'];
		}

		if ($path = $has('/upadl/')) {
			return [RegistryRecord::BANKRUPTCY, 'Wpis o upadłości (' . $path . ').'];
		}

		if ($path = $has('/likwidac/')) {
			return [RegistryRecord::LIQUIDATION, 'Podmiot w likwidacji (' . $path . ').'];
		}

		$suspended = $this->latestDate($flat, '/zawiesz/');
		$resumed = $this->latestDate($flat, '/wznowien/');

		if ($suspended !== null && ($resumed === null || $resumed < $suspended)) {
			return [RegistryRecord::SUSPENDED, 'Zawieszenie działalności od ' . $suspended . '.'];
		}

		if (($has('/zawiesz/')) && $suspended === null) {
			return [RegistryRecord::UNKNOWN, 'Odpis zawiera informację o zawieszeniu bez daty — sprawdź ręcznie.'];
		}

		return [RegistryRecord::ACTIVE, 'Podmiot wpisany do KRS, brak wpisów o wykreśleniu, likwidacji, upadłości i zawieszeniu.'];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $out
	 */
	private function flatten(array $data, string $prefix, array &$out): void
	{
		foreach ($data as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if (is_array($value)) {
				if ($value === []) {
					continue;
				}

				$this->flatten($value, $path, $out);
			} else {
				$out[$path] = $value;
			}
		}
	}

	/**
	 * @param array<string, mixed> $flat
	 */
	private function latestDate(array $flat, string $pattern): ?string
	{
		$latest = null;

		foreach ($flat as $path => $value) {
			if (! preg_match($pattern, mb_strtolower($path)) || ! is_string($value)) {
				continue;
			}

			if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
				$date = $m[0];
			} elseif (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
				$date = $m[3] . '-' . $m[2] . '-' . $m[1];
			} else {
				continue;
			}

			$latest = $latest === null || $date > $latest ? $date : $latest;
		}

		return $latest;
	}
}
