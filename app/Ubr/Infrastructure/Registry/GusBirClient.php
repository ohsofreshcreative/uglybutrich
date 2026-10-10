<?php

namespace App\Ubr\Infrastructure\Registry;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Discovery\CityCatalog;
use App\Ubr\Domain\Discovery\RegionCatalog;
use App\Ubr\Domain\Verification\RegistryRecord;
use App\Ubr\Domain\Verification\RegistryUnavailable;

/**
 * GUS BIR 1.1 (rejestr REGON) — usługa SOAP 1.2, bezpłatna, wymaga klucza użytkownika wydawanego przez GUS
 * (wniosek: regon_bir@stat.gov.pl). Klucz: stała `UBR_GUS_API_KEY` w wp-config.php — nigdy w repozytorium.
 * `UBR_GUS_TEST=true` → środowisko testowe GUS (publiczny klucz testowy, dane nieaktualne — tylko do sprawdzenia integracji).
 *
 * Używamy `DaneSzukajPodmioty` (wyszukiwanie po NIP/REGON/KRS — nigdy po nazwie). Zwraca m.in. nazwę, adres, województwo,
 * typ (P/F), silos (1 = CEIDG, 6 = osoby prawne) i datę zakończenia działalności (→ INACTIVE).
 * Zawieszenia JDG GUS nie podaje w wyszukiwaniu — dla JDG status zawieszenia daje CEIDG.
 */
class GusBirClient
{
	public const PROD_URL = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';

	public const TEST_URL = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';

	public const TEST_KEY = 'abcde12345abcde12345';

	private const NS = 'http://CIS/BIR/PUBL/2014/07';

	public function __construct(private RegistryHttp $http)
	{
	}

	public static function testMode(): bool
	{
		return defined('UBR_GUS_TEST') && UBR_GUS_TEST;
	}

	public static function key(): ?string
	{
		if (self::testMode()) {
			return self::TEST_KEY;
		}

		$key = defined('UBR_GUS_API_KEY') ? (string) UBR_GUS_API_KEY : (string) getenv('UBR_GUS_API_KEY');

		return trim($key) !== '' ? trim($key) : null;
	}

	public static function enabled(): bool
	{
		return self::key() !== null && (bool) apply_filters('ubr/registry/gus_enabled', true);
	}

	public static function url(): string
	{
		return self::testMode() ? self::TEST_URL : self::PROD_URL;
	}

	/**
	 * @param 'Nip'|'Regon'|'Krs' $field
	 */
	public function find(string $field, string $value): ?RegistryRecord
	{
		if (! self::enabled()) {
			return null;
		}

		$value = preg_replace('/\D/', '', $value) ?? '';

		if ($value === '') {
			return null;
		}

		$sid = $this->session();
		$body = $this->envelope('DaneSzukajPodmioty', sprintf(
			'<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania><dat:%1$s>%2$s</dat:%1$s></ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>',
			$field,
			esc_html($value)
		));

		$response = $this->http->request('gus', 'POST', self::url(), [
			'headers' => ['Content-Type' => 'application/soap+xml; charset=utf-8', 'sid' => $sid],
			'body' => $body,
		], ['interval_ms' => 600, 'daily_limit' => 2000, 'endpoint' => 'DaneSzukajPodmioty', 'cache_key' => 'gus-' . $field . '-' . $value]);

		$xml = $this->result($response['body'], 'DaneSzukajPodmiotyResult');

		if ($xml === null || trim($xml) === '') {
			return null;
		}

		return $this->parse($xml, $field . ':' . $value);
	}

	/**
	 * @return RegistryRecord|null null = brak danych (komunikat GUS „Nie znaleziono podmiotu”)
	 */
	public function parse(string $xml, string $lookup): ?RegistryRecord
	{
		$doc = @simplexml_load_string($xml);

		if (! $doc || ! isset($doc->dane)) {
			return null;
		}

		$d = $doc->dane[0];
		$get = fn (string $key) => isset($d->{$key}) && trim((string) $d->{$key}) !== '' ? trim((string) $d->{$key}) : null;

		if ($get('ErrorCode') !== null) {
			return null;
		}

		$ended = $get('DataZakonczeniaDzialalnosci');
		$city = CityCatalog::canonical($get('Miejscowosc') ? mb_convert_case($get('Miejscowosc'), MB_CASE_TITLE) : null);
		$street = trim(implode(' ', array_filter([$get('Ulica'), $get('NrNieruchomosci'), $get('NrLokalu') ? '/' . $get('NrLokalu') : null])));

		return new RegistryRecord(
			source: 'gus',
			lookupKey: $lookup,
			name: $get('Nazwa'),
			nip: N::nip($get('Nip')),
			regon: N::regon($get('Regon')),
			legalForm: match ($get('Typ')) {
				'F' => 'Osoba fizyczna prowadząca działalność (REGON)',
				'P' => 'Osoba prawna / jednostka organizacyjna (REGON)',
				default => null,
			},
			status: $ended ? RegistryRecord::INACTIVE : RegistryRecord::ACTIVE,
			statusDetail: $ended ? 'Zakończenie działalności wg REGON: ' . $ended . '.' : 'Podmiot w rejestrze REGON bez daty zakończenia działalności (GUS nie podaje tu zawieszeń JDG).',
			street: $street !== '' ? $street : null,
			postalCode: $get('KodPocztowy'),
			city: $city,
			region: RegionCatalog::label(RegionCatalog::keyFromName($get('Wojewodztwo')) ?? CityCatalog::regionFor($city)),
			sourceUrl: 'https://wyszukiwarkaregon.stat.gov.pl/appBIR/index.aspx',
			extra: array_filter(['gus_silos' => $get('SilosID'), 'gus_end_date' => $ended, 'gus_test_environment' => self::testMode() ? true : null]),
		);
	}

	private function session(): string
	{
		$cached = get_transient('ubr_gus_sid');

		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$body = $this->envelope('Zaloguj', '<ns:Zaloguj><ns:pKluczUzytkownika>' . esc_html((string) self::key()) . '</ns:pKluczUzytkownika></ns:Zaloguj>');
		$response = $this->http->request('gus', 'POST', self::url(), [
			'headers' => ['Content-Type' => 'application/soap+xml; charset=utf-8'],
			'body' => $body,
		], ['interval_ms' => 600, 'daily_limit' => 2000, 'endpoint' => 'Zaloguj', 'cache_ttl' => 1, 'cache_key' => 'gus-login-' . microtime(true)]);

		$sid = trim((string) $this->result($response['body'], 'ZalogujResult'));

		if ($sid === '') {
			throw new RegistryUnavailable('GUS: logowanie nieudane (nieprawidłowy klucz lub usługa niedostępna).', 'gus', 1800);
		}

		set_transient('ubr_gus_sid', $sid, 50 * MINUTE_IN_SECONDS);

		return $sid;
	}

	private function envelope(string $action, string $body): string
	{
		return sprintf(
			'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="%1$s" xmlns:dat="%1$s/DataContract">'
			. '<soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing"><wsa:To>%2$s</wsa:To><wsa:Action>%1$s/IUslugaBIRzewnPubl/%3$s</wsa:Action></soap:Header>'
			. '<soap:Body>%4$s</soap:Body></soap:Envelope>',
			self::NS,
			self::url(),
			$action,
			$body
		);
	}

	/**
	 * Odpowiedź BIR jest w formacie MTOM (multipart) — wyciągamy zawartość elementu wyniku.
	 */
	private function result(string $body, string $element): ?string
	{
		if (! preg_match('#<' . $element . '>(.*?)</' . $element . '>#s', $body, $m)) {
			return null;
		}

		return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
}
