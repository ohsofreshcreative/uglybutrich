<?php

namespace App\Ubr\Domain\Contacts;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Wyszukiwanie osób na oficjalnej stronie firmy (zarząd, zespół, kontakt, o nas, media).
 *
 * Zasady:
 * - osoba = poprawne imię i nazwisko (PersonName) + stanowisko z RoleCatalog w tym samym bloku (sąsiednie linie
 *   „Imię Nazwisko / Stanowisko”, odwrotnie, albo w jednej linii „Jan Kowalski – Prezes Zarządu”),
 * - e-mail przypisujemy osobie tylko, gdy jest opublikowany w JEJ bloku i jego nazwa lokalna odpowiada tej osobie;
 *   adres działu (np. zarzad@, marketing@) w bloku osoby oznaczamy jako adres działu, nie osobisty,
 * - adresów nie zgadujemy i nie generujemy; profil zawodowy tylko z linku opublikowanego przy osobie,
 * - wzmianki o dawnej funkcji („były prezes”, „do 2021”) → FORMER; funkcja w innym podmiocie → niepewne dopasowanie firmy.
 */
final class PeopleExtractor
{
	private const BLOCK = ['address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'ol', 'p', 'section', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'ul', 'br'];

	private const SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'nav', 'select', 'option', 'button'];

	private const FORMER_RE = '/\b(byl[ya]?|b\. ?prezes|by[lł]a? prezes|former|ex-|dawn\w+|do (19|20)\d\d|w latach|emeryt\w*|zmar[lł]\w*|†|odszed[lł]\w*|odwo[lł]an\w*|until (19|20)\d\d)\b/u';

	private const ENTITY_RE = '/\b(s\.? ?a\.?|sp\.? z o\.? ?o\.?|spolk\w+|gmbh|ag|ltd\.?|inc\.?|holding\w*|grup\w+|group|capital group)\b/u';

	/**
	 * @return array{candidates: list<array<string, mixed>>, channels: list<array<string, string>>}
	 */
	public function extract(string $html, string $url, string $pageTitle, string $pageKind, string $companyName, string $companyDomain): array
	{
		$lines = $this->lines($html);
		$candidates = [];
		$used = [];
		$n = count($lines);

		// Klasyfikacja linii: N = imię i nazwisko, R = stanowisko, I = obie rzeczy w jednej linii.
		$kind = [];

		foreach ($lines as $i => $line) {
			$kind[$i] = $this->classify($line['text']);
		}

		// Kolejność dominująca na stronie: „imię → stanowisko” czy „stanowisko → imię”.
		$forward = $backward = 0;

		for ($i = 0; $i < $n; $i++) {
			if (($kind[$i]['type'] ?? null) === 'N') {
				$j = $this->nextSignificant($lines, $i);
				$forward += $j !== null && ($kind[$j]['type'] ?? null) === 'R' ? 1 : 0;
			}

			if (($kind[$i]['type'] ?? null) === 'R') {
				$j = $this->nextSignificant($lines, $i);
				$backward += $j !== null && ($kind[$j]['type'] ?? null) === 'N' ? 1 : 0;
			}
		}

		$nameFirst = $forward >= $backward;

		for ($i = 0; $i < $n; $i++) {
			if (isset($used[$i])) {
				continue;
			}

			$k = $kind[$i];

			if ($k['type'] === 'I') {
				$candidates[] = $this->candidate($k['name'], $k['role'], $lines, $i, $i, $url, $pageTitle, $pageKind, $companyName, $companyDomain);
				$used[$i] = true;
				continue;
			}

			$want = $nameFirst ? ['N', 'R'] : ['R', 'N'];

			if ($k['type'] !== $want[0]) {
				continue;
			}

			// Do 2 znaczących linii dalej (np. imię, krótki opis, stanowisko — bez przechodzenia przez inną osobę).
			$j = $i;

			for ($step = 0; $step < 2; $step++) {
				$j = $this->nextSignificant($lines, $j);

				if ($j === null || isset($used[$j]) || $kind[$j]['type'] === $want[0] || $kind[$j]['type'] === 'I') {
					$j = null;
					break;
				}

				if ($kind[$j]['type'] === $want[1]) {
					break;
				}
			}

			if ($j === null || $kind[$j]['type'] !== $want[1]) {
				continue;
			}

			[$nameIdx, $roleIdx] = $nameFirst ? [$i, $j] : [$j, $i];
			$candidates[] = $this->candidate($kind[$nameIdx]['name'], $kind[$roleIdx]['role'], $lines, min($i, $j), max($i, $j), $url, $pageTitle, $pageKind, $companyName, $companyDomain);
			$used[$i] = $used[$j] = true;
		}

		$candidates = array_merge($candidates, $this->jsonLdPeople($html, $url, $pageTitle, $pageKind, $companyName));

		// Ten sam telefon w blokach kilku osób = numer firmowy, nie bezpośredni.
		$phones = array_count_values(array_filter(array_column($candidates, 'phone')));

		foreach ($candidates as &$c) {
			if ($c['phone'] && ($phones[$c['phone']] ?? 0) > 1) {
				$c['phone'] = null;
			}
		}

		unset($c);

		return ['candidates' => $this->uniqueByKey($candidates), 'channels' => $this->channels($lines, $candidates, $url, $companyDomain)];
	}

	/**
	 * @return array{type: string, name?: PersonName, role?: array<string, mixed>}
	 */
	public function classify(string $text): array
	{
		$text = trim($text);

		if ($text === '' || mb_strlen($text) > 200) {
			return ['type' => '-'];
		}

		$name = mb_strlen($text) <= 60 ? PersonName::parse($text) : null;

		if ($name) {
			return ['type' => 'N', 'name' => $name];
		}

		// Jedna linia: „Jan Kowalski – Prezes Zarządu”, „Jan Kowalski, CEO”, „Prezes Zarządu: Jan Kowalski”, „Jan Kowalski (Dyrektor Marketingu)”.
		// preg_split zwraca false dla niepoprawnego UTF-8 (strony w innym kodowaniu) — wtedy brak podziału.
		$parts = preg_split('/\s*(?:\s[–—|-]\s|[–—|:,(])\s*/u', $text, 2) ?: [$text];

		if (count($parts) === 2 && mb_strlen($parts[0]) >= 4 && mb_strlen($parts[0]) <= 140 && mb_strlen($parts[1]) >= 2) {
			$parts[1] = rtrim($parts[1], ' .');

			if (str_ends_with($parts[1], ')') && substr_count($parts[1], '(') < substr_count($parts[1], ')')) {
				$parts[1] = rtrim(substr($parts[1], 0, -1));
			}

			foreach ([[$parts[0], $parts[1]], [$parts[1], $parts[0]]] as [$a, $b]) {
				$person = PersonName::parse($a);
				$role = $person ? RoleCatalog::match($b) : null;

				if ($person && $role) {
					return ['type' => 'I', 'name' => $person, 'role' => $role];
				}
			}
		}

		if (mb_strlen($text) <= 140) {
			$role = RoleCatalog::match($text);

			// Linia stanowiska nie może być zdaniem opisowym (np. „Nasz zarząd składa się z…”).
			$plain = preg_replace('/\b(ds|np|ul|inz|dr|mgr|prof|hab|tel|nr|sp|o\.o|s\.a)\./iu', '$1', $text) ?? $text;

			if ($role && str_word_count(PersonName::fold($text)) <= 12 && ! preg_match('/[.!?]\s+\p{Lu}\p{Ll}+\s+\p{Ll}/u', $plain)) {
				return ['type' => 'R', 'role' => $role];
			}
		}

		return ['type' => '-'];
	}

	/**
	 * @param list<array{text: string, emails: list<string>, phones: list<string>, profiles: list<string>}> $lines
	 * @return array<string, mixed>
	 */
	private function candidate(PersonName $name, array $role, array $lines, int $from, int $to, string $url, string $pageTitle, string $pageKind, string $companyName, string $companyDomain): array
	{
		// Blok osoby: od linii z imieniem/stanowiskiem do 4 linii dalej, do następnej osoby.
		$blockEnd = $to;

		for ($i = $to + 1; $i < min(count($lines), $to + 5); $i++) {
			$k = $this->classify($lines[$i]['text']);

			if (in_array($k['type'], ['N', 'I'], true)) {
				break;
			}

			$blockEnd = $i;
		}

		$block = array_slice($lines, $from, $blockEnd - $from + 1);
		// Wzmianka o dawnej funkcji — tylko w linii osoby i jej stanowiska (nie w sąsiednich blokach).
		$contextText = PersonName::fold(implode(' ', array_column(array_slice($lines, $from, $to - $from + 1), 'text')));
		// Bez samego imienia i nazwiska (np. nazwisko „Dawny” to nie „dawna funkcja”).
		$contextText = str_replace([PersonName::fold($name->first), PersonName::fold($name->last)], ' ', $contextText);
		$emails = array_values(array_unique(array_merge(...array_map(fn ($l) => $l['emails'], $block))));
		$phones = array_values(array_unique(array_merge(...array_map(fn ($l) => $l['phones'], $block))));
		$profiles = array_values(array_unique(array_merge(...array_map(fn ($l) => $l['profiles'], $block))));

		$personal = null;
		$roleEmail = null;

		foreach ($emails as $email) {
			if ($this->emailBelongsTo($email, $name)) {
				$personal ??= $email;
			} elseif ($this->sameDomain($email, $companyDomain)) {
				$roleEmail ??= $email;
			}
		}

		// Inny podmiot w opisie funkcji (np. „Prezes Zarządu FAMUR S.A.” na stronie spółki zależnej).
		$roleText = PersonName::fold($role['text']);
		$otherEntity = null;

		if (preg_match(self::ENTITY_RE, $roleText) && ! $this->mentionsCompany($roleText, $companyName)) {
			$otherEntity = $role['text'];
		}

		$excerpt = trim(implode(' · ', array_filter(array_map(fn ($l) => $l['text'], array_slice($lines, $from, $to - $from + 1)))));

		return [
			'name' => $name,
			'title' => mb_substr(trim($role['text']), 0, 200),
			'role' => $role,
			'source_type' => 'COMPANY_WEBSITE',
			'source_url' => $url,
			'source_title' => mb_substr($pageTitle, 0, 255),
			'page_kind' => $pageKind,
			'excerpt' => mb_substr($excerpt, 0, 300),
			'email' => $personal,
			'role_email' => $roleEmail,
			'phone' => $phones[0] ?? null,
			'profile' => $profiles[0] ?? null,
			'former' => (bool) preg_match(self::FORMER_RE, $contextText),
			'other_entity' => $otherEntity,
		];
	}

	/**
	 * schema.org Person z jobTitle (JSON-LD) — tylko gdy oba pola są podane.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function jsonLdPeople(string $html, string $url, string $pageTitle, string $pageKind, string $companyName): array
	{
		$out = [];

		if (! preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
			return [];
		}

		$walk = function ($node) use (&$walk, &$out, $url, $pageTitle, $pageKind, $companyName) {
			if (! is_array($node)) {
				return;
			}

			$type = (array) ($node['@type'] ?? []);

			if (in_array('Person', $type, true) && ! empty($node['name']) && ! empty($node['jobTitle'])) {
				$name = PersonName::parse((string) $node['name']);
				$role = RoleCatalog::match(is_array($node['jobTitle']) ? implode(', ', $node['jobTitle']) : (string) $node['jobTitle']);

				if ($name && $role) {
					$works = is_array($node['worksFor'] ?? null) ? (string) ($node['worksFor']['name'] ?? '') : (string) ($node['worksFor'] ?? '');
					$out[] = [
						'name' => $name, 'title' => mb_substr($role['text'], 0, 200), 'role' => $role, 'source_type' => 'COMPANY_WEBSITE',
						'source_url' => $url, 'source_title' => mb_substr($pageTitle, 0, 255), 'page_kind' => $pageKind,
						'excerpt' => mb_substr($name->full() . ' · ' . $role['text'] . ' (dane strukturalne strony)', 0, 300),
						'email' => null, 'role_email' => null, 'phone' => null,
						// Tylko pełny adres profilu (jak dla linków w HTML) — „zawiera linkedin.com/in/” przepuszczało javascript:…
						'profile' => is_string($node['sameAs'] ?? null) && preg_match('#^https?://([a-z]{2,3}\.)?linkedin\.com/in/[A-Za-z0-9\-_%]+/?$#i', (string) $node['sameAs']) ? rtrim((string) $node['sameAs'], '/') : null,
						'former' => false,
						'other_entity' => $works !== '' && ! $this->mentionsCompany(PersonName::fold($works), $companyName) ? $works : null,
					];
				}
			}

			foreach ($node as $value) {
				if (is_array($value)) {
					$walk($value);
				}
			}
		};

		foreach ($m[1] as $json) {
			$walk(json_decode(html_entity_decode(trim($json)), true));
		}

		return $out;
	}

	/**
	 * Ogólne kanały firmy (adresy i telefony bez przypisania do osoby) — tylko w domenie firmy.
	 *
	 * @param list<array<string, mixed>> $lines
	 * @param list<array<string, mixed>> $candidates
	 * @return list<array<string, string>>
	 */
	private function channels(array $lines, array $candidates, string $url, string $companyDomain): array
	{
		$personal = array_filter(array_column($candidates, 'email'));
		$out = [];

		foreach ($lines as $line) {
			foreach ($line['emails'] as $email) {
				if (in_array($email, $personal, true) || ! $this->sameDomain($email, $companyDomain)) {
					continue;
				}

				$local = PersonName::fold(strstr($email, '@', true) ?: '');
				$department = match (true) {
					(bool) preg_match('/(marketing|pr$|^pr\.|media|press|prasa|komunikacja|brand)/', $local) => 'marketing',
					(bool) preg_match('/(zarzad|board|management|prezes|ceo|sekretariat|dyrekcja)/', $local) => 'management',
					(bool) preg_match('/(sprzedaz|sales|handel|handlowy|oferty|order|zamowienia|export|eksport)/', $local) => 'sales',
					default => 'general',
				};
				$out[$email] = ['type' => $department === 'general' ? 'COMPANY_GENERAL_EMAIL' : 'DEPARTMENT_EMAIL', 'department' => $department, 'value' => $email, 'source_url' => $url];
			}
		}

		return array_values($out);
	}

	/**
	 * Linie tekstu strony (granice bloków HTML) z adresami e-mail, telefonami i profilami opublikowanymi w danej linii.
	 *
	 * @return list<array{text: string, emails: list<string>, phones: list<string>, profiles: list<string>}>
	 */
	public function lines(string $html): array
	{
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$body = $dom->getElementsByTagName('body')->item(0);

		if (! $body) {
			return [];
		}

		$lines = [];
		$current = ['text' => '', 'emails' => [], 'phones' => [], 'profiles' => []];
		$flush = function () use (&$lines, &$current) {
			$text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $current['text']) ?? '');

			if ($text !== '' || $current['emails'] || $current['phones'] || $current['profiles']) {
				foreach ($this->emailsIn($text) as $e) {
					$current['emails'][] = $e;
				}

				$lines[] = ['text' => $text, 'emails' => array_values(array_unique($current['emails'])), 'phones' => array_values(array_unique($current['phones'])), 'profiles' => array_values(array_unique($current['profiles']))];
			}

			$current = ['text' => '', 'emails' => [], 'phones' => [], 'profiles' => []];
		};

		$walk = function (DOMNode $node) use (&$walk, &$current, $flush) {
			foreach ($node->childNodes as $child) {
				if ($child->nodeType === XML_TEXT_NODE) {
					$current['text'] .= $child->textContent;
					continue;
				}

				if (! $child instanceof DOMElement) {
					continue;
				}

				$tag = strtolower($child->tagName);

				if (in_array($tag, self::SKIP, true)) {
					continue;
				}

				$block = in_array($tag, self::BLOCK, true);

				if ($block) {
					$flush();
				}

				if ($tag === 'a') {
					$href = trim($child->getAttribute('href'));

					if (stripos($href, 'mailto:') === 0) {
						$email = strtolower(trim(rawurldecode(strtok(substr($href, 7), '?') ?: '')));

						if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
							$current['emails'][] = $email;
						}
					} elseif (stripos($href, 'tel:') === 0) {
						$phone = preg_replace('/[^\d+]/', '', substr($href, 4)) ?? '';

						if (strlen(ltrim($phone, '+')) >= 9) {
							$current['phones'][] = $phone;
						}
					} elseif (preg_match('#^https?://([a-z]{2,3}\.)?linkedin\.com/in/[A-Za-z0-9\-_%]+/?$#i', $href)) {
						$current['profiles'][] = rtrim($href, '/');
					}
				}

				$walk($child);

				if ($block) {
					$flush();
				}
			}
		};

		$walk($body);
		$flush();

		return $lines;
	}

	/**
	 * @return list<string>
	 */
	private function emailsIn(string $text): array
	{
		if (! preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m)) {
			return [];
		}

		return array_values(array_filter(array_map('strtolower', $m[0]), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
	}

	/**
	 * Adres osobisty: nazwa lokalna zawiera nazwisko albo imię (z inicjałem drugiej części) danej osoby.
	 * Nie tworzymy adresów — tylko oceniamy adres opublikowany przy osobie.
	 */
	public function emailBelongsTo(string $email, PersonName $name): bool
	{
		// Adres ogólny / działu (biuro@, sekretariat@, marketing@ …) nigdy nie jest adresem osobistym — nawet
		// jeśli nazwa lokalna przypadkiem zawiera nazwisko („sekretariat” zawiera „kret”).
		if (self::isGenericEmail($email)) {
			return false;
		}

		$local = PersonName::fold(strstr($email, '@', true) ?: '');
		$local = preg_replace('/[^a-z]/', '', $local) ?? '';
		$first = preg_replace('/[^a-z]/', '', PersonName::fold($name->first)) ?? '';
		$last = preg_replace('/[^a-z]/', '', PersonName::fold($name->last)) ?? '';

		if ($local === '' || $last === '') {
			return false;
		}

		// Dopasowanie podciągu tylko dla nazwisk ≥ 3 liter (krótkie nazwiska trafiały przypadkowo w dowolny adres).
		return (strlen($last) >= 3 && str_contains($local, $last))
			|| $local === $last
			|| ($first !== '' && str_starts_with($local, $first) && str_contains($local, substr($last, 0, 1)) && strlen($local) <= strlen($first) + 2)
			|| $local === substr($first, 0, 1) . $last
			|| $local === $first . substr($last, 0, 1);
	}

	/** Nazwy lokalne adresów ogólnych firmy i działów (porównanie po usunięciu polskich znaków). */
	public const GENERIC_LOCALS = [
		'biuro', 'info', 'informacja', 'kontakt', 'contact', 'office', 'sekretariat', 'recepcja', 'reception', 'hello', 'hi', 'mail', 'email',
		'poczta', 'admin', 'administrator', 'webmaster', 'marketing', 'sales', 'sprzedaz', 'handlowy', 'handel', 'oferty', 'oferta', 'zamowienia',
		'order', 'orders', 'export', 'eksport', 'import', 'serwis', 'service', 'support', 'pomoc', 'help', 'hr', 'kadry', 'rekrutacja', 'kariera',
		'praca', 'jobs', 'career', 'careers', 'ksiegowosc', 'faktury', 'invoice', 'invoices', 'billing', 'zarzad', 'board', 'management',
		'dyrekcja', 'press', 'prasa', 'pr', 'media', 'noreply', 'newsletter', 'rodo', 'iod', 'dpo', 'gdpr', 'logistyka', 'magazyn',
		'zakupy', 'purchasing', 'it', 'team', 'firma', 'company', 'bok', 'obsluga', 'klient', 'klienci', 'centrala', 'reklamacje', 'technika',
	];

	/**
	 * Adres ogólny lub działu (biuro@, info2@, sales-pl@, no-reply@ …) — kanał firmy, a nie adres konkretnej osoby.
	 */
	public static function isGenericEmail(string $email): bool
	{
		$local = PersonName::fold(strstr(strtolower($email), '@', true) ?: '');
		$local = preg_replace('/\+.*$/', '', $local) ?? $local;
		$letters = preg_replace('/[^a-z]/', '', $local) ?? '';

		if (in_array($letters, self::GENERIC_LOCALS, true) || in_array(str_replace(['-', '_', '.'], '', $local), ['noreply', 'donotreply'], true)) {
			return true;
		}

		// biuro2, info.pl, sales-export, dzial.handlowy, kontakt_firma
		$alt = implode('|', array_map(fn ($w) => preg_quote($w, '/'), self::GENERIC_LOCALS));

		return (bool) preg_match('/^(' . $alt . ')([\d._\-].*)?$/', $local) || (bool) preg_match('/^dzial[\d._\-]?/', $local);
	}

	private function sameDomain(string $email, string $companyDomain): bool
	{
		$domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
		$base = preg_replace('/^www\./', '', strtolower($companyDomain)) ?? '';

		if ($domain === '' || $base === '') {
			return false;
		}

		// Ta sama domena, subdomena lub ta sama nazwa w innej końcówce (flowair.com ↔ flowair.pl).
		$label = fn (string $d) => explode('.', $d)[0];

		return $domain === $base || str_ends_with($domain, '.' . $base) || $label($domain) === $label($base);
	}

	/**
	 * Czy podmiot wymieniony przy funkcji to ta sama firma. Porównujemy CAŁY zestaw słów nazwy (bez form prawnych
	 * i słów ogólnych) — „Stalex-Bis Sp. z o.o.” to inna firma niż „Stalex Sp. z o.o.”.
	 */
	private function mentionsCompany(string $foldedText, string $companyName): bool
	{
		$generic = ['spolka', 'akcyjna', 'grupa', 'grupy', 'group', 'polska', 'poland', 'holding', 'capital', 'kapitalowa', 'kapitalowej', 'sp', 'z', 'o', 'oo', 'sa', 's', 'a', 'gmbh', 'ag', 'ltd', 'inc', 'zoo', 'ubrtest'];
		$tokens = function (string $text) use ($generic): array {
			$norm = \App\Ubr\Domain\Companies\CompanyNormalizer::normalizeName($text);

			return array_values(array_diff(array_unique(array_filter(explode(' ', $norm), fn ($w) => strlen($w) >= 2)), $generic));
		};

		$company = $tokens($companyName);

		if ($company === []) {
			return true;
		}

		// Nazwa podmiotu z opisu funkcji = tekst po słowach funkcji (np. „prezes zarzadu stalex bis sp z o o” → „stalex bis”).
		$entityText = preg_replace('/^.*?\b(zarzadu|zarzad|board|ceo|prezes|dyrektor\w*|director|manager|wlasciciel\w*|owner|czlonek|wiceprezes|prokurent)\b( (ds|do spraw)\.? [a-z]+( i [a-z]+)?)?/u', '', $foldedText) ?? $foldedText;
		$entity = $tokens($entityText);

		if ($entity === []) {
			return true;
		}

		sort($entity);
		sort($company);

		return $entity === $company || array_diff($entity, $company) === [];
	}

	/**
	 * @param list<array<string, mixed>> $lines
	 */
	private function nextSignificant(array $lines, int $i): ?int
	{
		for ($j = $i + 1; $j < count($lines); $j++) {
			if ($lines[$j]['text'] !== '') {
				return $j;
			}
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $candidates
	 * @return list<array<string, mixed>>
	 */
	private function uniqueByKey(array $candidates): array
	{
		$out = [];

		foreach ($candidates as $c) {
			$key = $c['name']->key() . '|' . RoleCatalog::normalize($c['title']);

			if (! isset($out[$key])) {
				$out[$key] = $c;
			} else {
				foreach (['email', 'role_email', 'phone', 'profile'] as $f) {
					$out[$key][$f] ??= $c[$f];
				}
			}
		}

		return array_values($out);
	}
}
