<?php

namespace App\Ubr\Infrastructure\Http;

use App\Ubr\Domain\Companies\CompanyNormalizer as N;
use App\Ubr\Domain\Discovery\CityCatalog;

/**
 * Inspekcja strony WWW firmy na potrzeby weryfikacji domeny i przedsiębiorstwa.
 *
 * Pobiera stronę główną i maks. 3 podstrony z danymi firmy (Kontakt, O firmie, polityka prywatności/RODO — tam
 * polskie spółki publikują NIP/KRS/REGON). Zbiera sygnały: odpowiedź HTTP, przekierowania, parking domeny,
 * katalog/social, nazwa w tytule/og:site_name/JSON-LD/stopce, dane kontaktowe, identyfikatory (z sumą kontrolną).
 * Nie zapisuje adresów e-mail ani telefonów — tylko fakt ich obecności (w domenie firmy / poza nią).
 */
class WebsiteInspector
{
	public const USER_AGENT = 'UglyButRichBot/0.2 (+https://ohsofresh.pl; company verification)';

	public const MAX_BYTES = 600 * 1024;

	private const PARKED_PATTERNS = [
		'domain is for sale', 'this domain is for sale', 'this domain may be for sale', 'buy this domain', 'domain for sale',
		'domena na sprzedaz', 'ta domena jest na sprzedaz', 'domena jest na sprzedaz', 'kup te domene', 'domena zostala zarejestrowana',
		'ta domena zostala zarejestrowana', 'domena jest utrzymywana', 'parked free', 'domain parking', 'parkingcrew', 'sedoparking',
		'bodis', 'hugedomains', 'afternic', 'dan com', 'is parked', 'domain has expired', 'domena wygasla', 'this domain has been registered',
	];

	private const PLACEHOLDER_PATTERNS = [
		'strona w budowie', 'website under construction', 'under construction', 'coming soon', 'apache2 ubuntu default page',
		'welcome to nginx', 'default web site page', 'it works', 'index of', 'hello world', 'just another wordpress site',
	];

	/**
	 * @return array<string, mixed>
	 */
	public function inspect(string $url, int $timeout = 7): array
	{
		$result = $this->blank($url);

		$home = $this->fetch($url, $timeout);

		if ($home['error'] !== null && str_starts_with($url, 'https://')) {
			// Część starszych stron działa tylko po http.
			$fallback = $this->fetch('http://' . substr($url, 8), $timeout);
			$home = $fallback['error'] === null ? $fallback : $home;
		}

		$result['http_code'] = $home['code'];
		$result['error'] = $home['error'];

		if ($home['error'] !== null || $home['code'] === null) {
			return $result;
		}

		$result['final_url'] = mb_substr($home['final_url'], 0, 500);
		$result['final_domain'] = N::domain($home['final_url']);
		$originalDomain = N::domain($url);
		$result['redirected_domain'] = $result['final_domain'] && $originalDomain && ! $this->sameSite($result['final_domain'], $originalDomain);
		$result['final_domain_type'] = N::classifyDomain($result['final_domain']);
		$result['reachable'] = $home['code'] >= 200 && $home['code'] < 400;

		if (! $result['reachable']) {
			return $result;
		}

		$html = $home['body'];
		$this->collect($html, $result, true);
		$result['pages'][] = ['url' => $result['final_url'], 'code' => $home['code'], 'kind' => 'home'];

		foreach (array_slice($this->subpages($html, $home['final_url']), 0, 3) as [$kind, $subUrl]) {
			// Identyfikatory już znalezione — nie pobieramy kolejnych podstron (oszczędność czasu i ruchu).
			if ($result['identifiers']['nip'] !== [] && $result['identifiers']['krs'] !== [] && $kind !== 'legal') {
				break;
			}

			$page = $this->fetch($subUrl, $timeout);

			if ($page['error'] === null && $page['code'] >= 200 && $page['code'] < 400) {
				$this->collect($page['body'], $result, false);
				$result['pages'][] = ['url' => mb_substr($page['final_url'], 0, 300), 'code' => $page['code'], 'kind' => $kind];
			}
		}

		foreach (['nip', 'krs', 'regon'] as $k) {
			$result['identifiers'][$k] = array_slice(array_values(array_unique($result['identifiers'][$k])), 0, 5);
		}

		$result['address_cities'] = array_slice(array_values(array_unique($result['address_cities'])), 0, 5);
		$result['postal_codes'] = array_slice(array_values(array_unique($result['postal_codes'])), 0, 5);

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blank(string $url): array
	{
		return [
			'url' => $url,
			'reachable' => false,
			'http_code' => null,
			'error' => null,
			'final_url' => null,
			'final_domain' => null,
			'redirected_domain' => false,
			'final_domain_type' => null,
			'title' => null,
			'site_name' => null,
			'description' => null,
			'jsonld' => [],
			'parked' => false,
			'placeholder' => false,
			'text_length' => 0,
			'pages' => [],
			'identifiers' => ['nip' => [], 'krs' => [], 'regon' => []],
			'emails_same_domain' => 0,
			'emails_other_domain' => 0,
			'emails' => [],
			'has_phone' => false,
			'address_cities' => [],
			'postal_codes' => [],
			'copyright' => null,
		];
	}

	/**
	 * Prompt 10.4 — kolejna podstrona, którą `inspect()` pobrałby po stronie głównej i podstronach już
	 * sprawdzonych (`$subHtmls`, pusty tekst = nieudane pobranie). Pozwala pobrać z wyprzedzeniem dokładnie te same
	 * strony (równolegle dla kilku firm) — bez dodatkowych zapytań. Null = inspect() nie pobierze kolejnej strony.
	 *
	 * @param list<string> $subHtmls
	 */
	public function nextSubpage(string $homeHtml, string $homeFinalUrl, array $subHtmls = []): ?string
	{
		$state = $this->blank($homeFinalUrl);
		$this->collect($homeHtml, $state, true);

		foreach ($subHtmls as $html) {
			if ($html !== '') {
				$this->collect($html, $state, false);
			}
		}

		$plan = array_slice($this->subpages($homeHtml, $homeFinalUrl), 0, 3);
		$next = $plan[count($subHtmls)] ?? null;

		if (! $next || ($state['identifiers']['nip'] !== [] && $state['identifiers']['krs'] !== [] && $next[0] !== 'legal')) {
			return null;
		}

		return $next[1];
	}

	/**
	 * Siła dopasowania nazwy firmy do strony: strong | medium | weak | none.
	 *
	 * strong = nazwa (wszystkie istotne słowa) w tytule, og:site_name, JSON-LD, stopce/copyright;
	 * medium = wszystkie słowa nazwy w treści; weak = tylko część słów lub tylko domena. Domena sama w sobie nie wystarcza.
	 *
	 * @param array<string, mixed> $inspection
	 */
	public static function nameMatch(string $companyName, array $inspection): string
	{
		$tokens = N::nameTokens($companyName);

		if ($tokens === []) {
			return 'none';
		}

		$strongText = self::fold(implode(' ', array_filter([
			$inspection['title'] ?? null,
			$inspection['site_name'] ?? null,
			$inspection['copyright'] ?? null,
			implode(' ', array_map(fn ($j) => ($j['name'] ?? '') . ' ' . ($j['legalName'] ?? ''), (array) ($inspection['jsonld'] ?? []))),
		])));
		$allText = self::fold((string) ($inspection['_text'] ?? '')) . ' ' . $strongText;

		$inStrong = array_filter($tokens, fn ($t) => self::hasWord($strongText, $t));
		$inText = array_filter($tokens, fn ($t) => self::hasWord($allText, $t));

		if (count($inStrong) === count($tokens)) {
			return 'strong';
		}

		if (count($inText) === count($tokens)) {
			return 'medium';
		}

		if ($inText !== [] || $inStrong !== []) {
			return 'weak';
		}

		return 'none';
	}

	/**
	 * @return array{code: ?int, body: string, final_url: string, error: ?string}
	 */
	private function fetch(string $url, int $timeout): array
	{
		// Przekierowania ręcznie, KAŻDY krok przez UrlGuard (jak PageFetcher). wp_safe_remote_get nie blokuje
		// 169.254.0.0/16 (metadata chmury), 100.64.0.0/10 ani IPv6 i sprawdza tylko pierwszy rekord A.
		$guard = app(UrlGuard::class);
		$current = $url;

		for ($hop = 0; $hop <= 5; $hop++) {
			$check = $guard->check($current);

			if (! $check['ok']) {
				return ['code' => null, 'body' => '', 'final_url' => $current, 'error' => 'Adres zablokowany (ochrona SSRF): ' . $check['reason']];
			}

			$response = PageFetcher::hop($current, $check['ips'] ?? [], [
				'timeout' => $timeout,
				'user-agent' => self::USER_AGENT,
				'headers' => ['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'pl,en;q=0.5'],
			], self::MAX_BYTES);

			if ($response['code'] === null) {
				return ['code' => null, 'body' => '', 'final_url' => $current, 'error' => mb_substr((string) $response['error'], 0, 250)];
			}

			$code = (int) $response['code'];

			if ($code >= 300 && $code < 400 && $response['location'] !== '') {
				$current = PageFetcher::absolute($response['location'], $current);
				continue;
			}

			return [
				'code' => $code ?: null,
				'body' => substr($response['body'], 0, self::MAX_BYTES),
				'final_url' => $current,
				'error' => null,
			];
		}

		return ['code' => null, 'body' => '', 'final_url' => $current, 'error' => 'Zbyt wiele przekierowań.'];
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function collect(string $html, array &$result, bool $isHome): void
	{
		if ($isHome) {
			if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
				$result['title'] = mb_substr(self::clean($m[1]), 0, 255) ?: null;
			}

			if (preg_match('#<meta[^>]+property=["\']og:site_name["\'][^>]*content=["\']([^"\']+)#i', $html, $m) || preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:site_name#i', $html, $m)) {
				$result['site_name'] = mb_substr(self::clean($m[1]), 0, 255);
			}

			if (preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)#i', $html, $m)) {
				$result['description'] = mb_substr(self::clean($m[1]), 0, 500) ?: null;
			}
		}

		foreach ($this->jsonLd($html) as $org) {
			$result['jsonld'][] = $org;

			foreach (['taxID', 'vatID'] as $k) {
				if (! empty($org[$k]) && ($nip = N::nip((string) $org[$k]))) {
					$result['identifiers']['nip'][] = $nip;
				}
			}

			if (! empty($org['address']['addressLocality'])) {
				$result['address_cities'][] = CityCatalog::canonical((string) $org['address']['addressLocality']);
			}
		}

		$text = self::text($html);
		$result['_text'] = mb_substr(($result['_text'] ?? '') . ' ' . $text, 0, 200000);
		$result['text_length'] += mb_strlen($text);
		$folded = self::fold($text . ' ' . ($result['title'] ?? ''));

		if ($isHome) {
			foreach (self::PARKED_PATTERNS as $p) {
				if (str_contains($folded, $p)) {
					$result['parked'] = true;
					break;
				}
			}

			foreach (self::PLACEHOLDER_PATTERNS as $p) {
				if (str_contains($folded, $p) && mb_strlen($text) < 3000) {
					$result['placeholder'] = true;
					break;
				}
			}
		}

		// Identyfikatory — tylko z etykietą (NIP/KRS/REGON) i poprawną sumą kontrolną.
		if (preg_match_all('/\b(?:NIP|VAT(?:[\s-]?(?:ID|UE|EU))?|Tax\s?ID)\b[\s:.\-–]*(?:nr\.?\s*)?((?:PL)?\s?\d{3}[\s\-]?\d{2,3}[\s\-]?\d{2}[\s\-]?\d{2,3})/iu', $text, $m)) {
			foreach ($m[1] as $raw) {
				if ($nip = N::nip($raw)) {
					$result['identifiers']['nip'][] = $nip;
				}
			}
		}

		if (preg_match_all('/\bKRS\b[\s:.\-–]*(?:nr\.?\s*|numer\s*)?(\d{10}|\d{6,9})\b/iu', $text, $m)) {
			foreach ($m[1] as $raw) {
				if ($krs = N::krs($raw)) {
					$result['identifiers']['krs'][] = $krs;
				}
			}
		}

		if (preg_match_all('/\bREGON\b[\s:.\-–]*(\d{14}|\d{9})\b/iu', $text, $m)) {
			foreach ($m[1] as $raw) {
				if ($regon = N::regon($raw)) {
					$result['identifiers']['regon'][] = $regon;
				}
			}
		}

		// Adresy: „00-950 Warszawa” → miasto (do porównania z rejestrem; nie zapisujemy pełnych adresów ze strony).
		if (preg_match_all('/\b(\d{2}-\d{3})[ \t]+([A-ZŁŚŻŹĆĄĘÓŃ][\p{L}\-]+(?:[ \t]+[A-ZŁŚŻŹĆĄĘÓŃ][\p{L}\-]+)?)/u', $text, $m)) {
			foreach ($m[2] as $i => $city) {
				$result['postal_codes'][] = $m[1][$i];
				$first = preg_split('/[ \t]+/', $city)[0];
				// „Warszawa NIP” → „Warszawa”; miasta dwuczłonowe („Nowy Sącz”) rozpoznaje katalog.
				$result['address_cities'][] = CityCatalog::find($city) ? CityCatalog::canonical($city) : CityCatalog::canonical($first);
			}
		}

		$domain = (string) ($result['final_domain'] ?? '');

		if (preg_match_all('/[a-z0-9._%+\-]+@([a-z0-9.\-]+\.[a-z]{2,})/i', $html, $m)) {
			foreach (array_unique($m[1]) as $mailDomain) {
				$mailDomain = strtolower($mailDomain);

				if (preg_match('/\.(png|jpg|jpeg|gif|webp|svg)$/', $mailDomain)) {
					continue;
				}

				$this->sameSite($mailDomain, $domain) ? $result['emails_same_domain']++ : $result['emails_other_domain']++;
			}

			// Prompt 10.4.3 — adresy w domenie firmy (do wyboru ogólnego e-maila firmowego; nie zapisujemy ich listy).
			foreach (array_unique(array_map('strtolower', $m[0])) as $address) {
				if (count($result['emails']) < 20 && ! preg_match('/\.(png|jpg|jpeg|gif|webp|svg)$/', $address) && $this->sameSite(substr((string) strrchr($address, '@'), 1), $domain)) {
					$result['emails'][] = $address;
				}
			}

			$result['emails'] = array_values(array_unique($result['emails']));
		}

		if (preg_match('/(\+48[\s\-]?)?\(?\d{2}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}\b|\b\d{3}[\s\-]\d{3}[\s\-]\d{3}\b/', $text)) {
			$result['has_phone'] = true;
		}

		if (preg_match('/(?:©|&copy;|copyright)\s*(?:\d{4}\s*[\-–]?\s*\d{0,4})?\s*([^|•\n]{3,120})/iu', $text, $m)) {
			$result['copyright'] = mb_substr(trim($m[1]), 0, 160);
		}
	}

	/**
	 * Podstrony z danymi firmy (ten sam serwis), w kolejności przydatności:
	 * 1) dane firmowe / rejestrowe / impressum / nota prawna, 2) RODO / polityka prywatności (administrator danych + NIP/KRS),
	 * 3) kontakt (najkrótszy adres — „/kontakt/szkolenia” przegrywa z „/kontakt”), 4) o firmie.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function subpages(string $html, string $baseUrl): array
	{
		$host = (string) parse_url($baseUrl, PHP_URL_HOST);
		$scheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
		$rules = [
			'legal' => '/(dane[-_ ]?firm|dane[-_ ]?rejestr|dane[-_ ]?spolki|impressum|nota[-_ ]?prawna|informacje[-_ ]?prawne|legal[-_ ]?notice|imprint)/i',
			'privacy' => '/(rodo|polityka[-_ ]?prywatnosci|privacy|ochrona[-_ ]?danych)/i',
			'contact' => '/(kontakt|contact)/i',
			'about' => '/(o[-_ ]?nas|o[-_ ]?firmie|about)/i',
		];
		$candidates = [];

		if (! preg_match_all('#<a\s[^>]*href=["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#is', $html, $m)) {
			return [];
		}

		foreach ($m[1] as $i => $href) {
			$label = str_replace(' ', '-', self::fold(wp_strip_all_tags($m[2][$i])));
			$url = str_starts_with($href, '//') ? $scheme . ':' . $href : (preg_match('#^https?://#i', $href) ? $href : $scheme . '://' . $host . '/' . ltrim($href, '/'));
			$urlHost = (string) parse_url($url, PHP_URL_HOST);
			$path = (string) parse_url($url, PHP_URL_PATH);

			if ($urlHost === '' || ! $this->sameSite($urlHost, $host) || preg_match('/\.(pdf|jpe?g|png|zip|docx?)$/i', $path) || in_array(rtrim($path, '/'), ['', '/'], true)) {
				continue;
			}

			foreach ($rules as $kind => $pattern) {
				if (preg_match($pattern, self::fold($path)) || preg_match($pattern, $label)) {
					// Krótsza ścieżka = strona główna sekcji (lepsza niż podstrona, np. kontakt/szkolenia).
					$score = strlen($path);

					if (! isset($candidates[$kind]) || $score < $candidates[$kind][2]) {
						$candidates[$kind] = [$kind, $url, $score];
					}

					break;
				}
			}
		}

		$out = [];

		foreach (array_keys($rules) as $kind) {
			if (isset($candidates[$kind])) {
				$out[] = [$kind, $candidates[$kind][1]];
			}
		}

		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function jsonLd(string $html): array
	{
		$out = [];

		if (! preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
			return [];
		}

		foreach ($m[1] as $json) {
			$data = json_decode(trim($json), true);

			if (! is_array($data)) {
				continue;
			}

			$nodes = isset($data['@graph']) ? (array) $data['@graph'] : (array_is_list($data) ? $data : [$data]);

			foreach ($nodes as $node) {
				$type = implode(' ', (array) ($node['@type'] ?? []));

				if (is_array($node) && preg_match('/(Organization|Corporation|LocalBusiness|Company|Store)/i', $type)) {
					$out[] = array_filter([
						'type' => $type,
						'name' => is_string($node['name'] ?? null) ? mb_substr($node['name'], 0, 200) : null,
						'legalName' => is_string($node['legalName'] ?? null) ? mb_substr($node['legalName'], 0, 200) : null,
						'taxID' => is_string($node['taxID'] ?? null) ? $node['taxID'] : null,
						'vatID' => is_string($node['vatID'] ?? null) ? $node['vatID'] : null,
						'address' => is_array($node['address'] ?? null) ? array_intersect_key($node['address'], array_flip(['addressLocality', 'postalCode', 'addressRegion', 'addressCountry'])) : null,
					]);
				}
			}
		}

		return array_slice($out, 0, 5);
	}

	private function sameSite(string $a, string $b): bool
	{
		$a = preg_replace('/^www\./', '', strtolower($a));
		$b = preg_replace('/^www\./', '', strtolower($b));

		return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
	}

	private static function text(string $html): string
	{
		$html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $html) ?? '';
		$html = preg_replace('#<(br|/p|/div|/li|/h\d|/td|/tr)[^>]*>#i', "\n", $html) ?? '';

		return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', html_entity_decode(wp_strip_all_tags($html, false), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
	}

	private static function clean(string $value): string
	{
		return trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
	}

	public static function fold(string $value): string
	{
		return trim(preg_replace('/[^a-z0-9]+/', ' ', N::foldAscii($value)) ?? '');
	}

	private static function hasWord(string $folded, string $token): bool
	{
		return (bool) preg_match('/(^| )' . preg_quote($token, '/') . '( |$)/', $folded);
	}
}
