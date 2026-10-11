<?php

namespace App\Ubr\Domain\Phones;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Ogólne numery telefonów firmy z oficjalnej strony WWW (strona główna, Kontakt, stopka, O nas, oddziały).
 *
 * Źródła (od najpewniejszego): linki `tel:` · Schema.org (`telephone`, ContactPoint, LocalBusiness) · `itemprop="telephone"`
 * · tekst strony, ale TYLKO przy słowie wskazującym telefon („tel.”, „telefon”, „centrala”, „kom.” …) — przypadkowe ciągi
 * cyfr (NIP, REGON, KRS, kod pocztowy, rachunek, kod produktu) nie są numerami. Fax jest pomijany.
 * Numery są numerami FIRMY — nigdy nie przypisujemy ich do konkretnej osoby.
 */
final class CompanyPhoneExtractor
{
	public const TYPES = ['MAIN', 'BRANCH', 'SALES', 'SUPPORT', 'OTHER', 'UNKNOWN'];

	private const PHONE_WORD = '(?:tel(?:efon(?:u|y|em|iczny|iczne)?)?\.?|phone|telephone|t\.?|kom\.?|komórka|komorka|mob(?:ile)?\.?|gsm|centrala|infolinia|hotline|call|zadzwoń|zadzwon|☎|📞|✆)';

	/** Etykieta wskazująca osobę / stanowisko lub komórkę — numer osoby, nie numer firmy (typ OTHER, nigdy główny przed innymi). */
	private const PERSON_ROLE = '/(\\bmanager\\b|menad[żz]er|kierownik|dyrektor|specjalist|prezes|wiceprezes|cz[łl]onek zarz|koordynator|przedstawiciel|handlowiec|doradc|asystent|\\bdirector\\b|\\bengineer\\b|in[żz]ynier|\\baccount manager|opiekun|konsultant|technolog|\bkom\.|kom[óo]rk|\bmob\.|mobile|\bgsm\b)/u';

	/** Działy, które nie są centralą (kadry, księgowość, magazyn, zakupy, biuro projektowe, media…). */
	private const DEPARTMENT = '/(kadry|\bhr\b|rekrutac|kariera|ksi[ęe]gow|faktur|finans|p[łl]atno|windykac|magazyn|dostaw|logisty|transport|spedyc|zaopatrz|zakup|purchas|projektow|konstrukc|jako[śs]ci|quality|dla medi|media|prasow|\bpress\b|rzecznik|reklamac)/u';

	/** Jednoznaczna centrala firmy. */
	private const MAIN_WORD = '/(centrala|recepcj|sekretariat|switchboard|reception|siedziba|headquarter|\bhq\b|biuro zarz|biuro g[łl][óo]wne|head office|main office|kontakt og[óo]lny|numer og[óo]lny)/u';

	private const NOT_PHONE_WORD = '/(fax|faks|nip|regon|krs|bdo|konto|rachunek|iban|swift|nr\s+konta|kod|pesel|vat|kapitał|kapital)\s*[:.]?\s*$/iu';

	/**
	 * @return list<array{raw: string, e164: string, extension: ?string, country: ?string, type: string, source: string, source_url: string, page_kind: string, context: string, branch_label: ?string, confidence: string, in_footer: bool}>
	 */
	/** Nazwa firmy, dla której szukamy numerów (rozpoznanie numerów INNYCH spółek z grupy na tej samej stronie). */
	private ?string $companyName = null;

	public function extract(string $html, string $url, string $pageKind = 'home', ?string $companyName = null): array
	{
		$this->companyName = $companyName;

		if (trim($html) === '') {
			return [];
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xp = new DOMXPath($doc);

		foreach ($xp->query('//script[not(@type="application/ld+json")]|//style|//noscript|//template') ?: [] as $n) {
			$n->parentNode?->removeChild($n);
		}

		$found = [];

		// 1. Linki tel: (najpewniejsze — autor strony oznaczył numer jako numer telefonu).
		foreach ($xp->query('//a[starts-with(translate(@href, "TELCAOP", "telcaop"), "tel:") or starts-with(translate(@href, "TELCAOP", "telcaop"), "callto:")]') ?: [] as $a) {
			/** @var DOMElement $a */
			$context = $this->context($a);

			if (preg_match('/\b(fax|faks)\b/iu', $context)) {
				continue;
			}

			// Etykieta w samym linku („<a href=tel:…>Recepcja: 48 384 58 20</a>”) ma pierwszeństwo przed tekstem wcześniej.
			$own = trim(preg_replace('/(?:\+|00)?\d[\d\s().\/\-–]{6,}\d/u', ' ', $this->spacedText($xp, $a)) ?? '');
			$label = preg_match_all('/\p{L}/u', preg_replace('/\b(tel|telefon|phone|zadzwo[ńn]|call|t)\b\.?:?/iu', '', $own) ?? '') >= 3
				? $own
				: $this->label($xp, $a);

			// Własna etykieta w tym samym akapicie („<p>Infolinia: <a href=tel:…>”) wygrywa z tekstem wcześniejszych bloków
			// (np. adresy oddziałów nad infolinią w stopce ekookna.pl). Wyjątek: wcześniejszy tekst nazywa spółkę
			// (strona grupy: „Huta Bankowa Sp. z o.o.” + „Sekretariat tel.:”) — wtedy zostaje pełna etykieta.
			$prefix = $label === $own ? '' : $this->blockPrefix($xp, $a);

			if ($prefix !== '' && preg_match_all('/\p{L}/u', preg_replace('/\b(tel|telefon|phone|kom|mob|zadzwo[ńn]|call|t)\b\.?:?/iu', '', $prefix) ?? '') >= 3 && self::entityIn($label) === null) {
				$label = $prefix;
			}
			$confidence = 'high';
			$display = PhoneNumber::parse(trim($this->spacedText($xp, $a)));
			$href = PhoneNumber::parse((string) $a->getAttribute('href'));

			// Tekst linku to inny numer niż adres tel: — błąd na stronie; numer do ręcznej weryfikacji.
			if ($display && $href && $display['e164'] !== $href['e164']) {
				$confidence = 'low';
				$context = '[tekst linku: ' . $display['national'] . '] ' . $context;
			}

			$this->add($found, (string) $a->getAttribute('href'), 'TEL_LINK', $confidence, $url, $pageKind, $context, $this->inFooter($a), null, null, $label);
		}

		// 2. Schema.org JSON-LD (Organization / LocalBusiness / ContactPoint, także oddziały).
		foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $script) {
			$this->walkJsonLd(json_decode(html_entity_decode(trim($script->textContent)), true), $found, $url, $pageKind, null, null);
		}

		// 3. Mikrodane itemprop="telephone".
		foreach ($xp->query('//*[@itemprop="telephone"]') ?: [] as $el) {
			/** @var DOMElement $el */
			$value = $el->hasAttribute('content') ? $el->getAttribute('content') : $el->textContent;
			$this->add($found, $value, 'SCHEMA', 'high', $url, $pageKind, $this->context($el), $this->inFooter($el), null, null, $this->label($xp, $el));
		}

		// 4. Tekst strony — tylko numery poprzedzone słowem wskazującym telefon.
		foreach ($this->blocks($xp) as [$text, $footer, $blockEl]) {
			if (! preg_match_all('/(?<!\p{L})' . self::PHONE_WORD . '\s*[:\-–.]?\s*((?:\+|00)?\(?\d[\d\s().\/\-–]{6,22}\d(?:\s*(?:wew|ext|w)\.?\s*\d{1,6})?)/iu', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
				continue;
			}

			foreach ($m as $match) {
				$before = mb_substr(substr($text, 0, $match[0][1]), -40);

				// „Fax / NIP: …” tuż przed słowem „tel” (np. „tel./fax”) — pomijamy, gdy numer jest opisany jako fax.
				if (preg_match('/\b(fax|faks)\b/iu', $match[0][0]) || preg_match(self::NOT_PHONE_WORD, $before)) {
					continue;
				}

				$candidate = $match[1][0];
				$after = substr($text, $match[1][1] + strlen($candidate), 1);

				// Część dłuższego ciągu cyfr (rachunek, identyfikator) — to nie numer telefonu.
				if ($after !== '' && ctype_digit($after)) {
					continue;
				}

				$context = trim(mb_substr($before . $match[0][0] . mb_substr(substr($text, $match[1][1] + strlen($candidate)), 0, 40), 0, 160));
				// Etykieta = tekst przed numerem (bez samego numeru): poprzedzające węzły + początek bloku + słowo „tel.”.
				$label = self::cutLabel($this->precedingText($xp, $blockEl) . ' ' . substr($text, 0, $match[1][1]));
				$this->add($found, $candidate, 'TEXT', 'medium', $url, $pageKind, $context, $footer, null, null, $label);
			}
		}

		return array_values($found);
	}

	/**
	 * @param array<string, array<string, mixed>> $found
	 */
	private function add(array &$found, string $value, string $source, string $confidence, string $url, string $pageKind, string $context, bool $footer, ?string $branch, ?string $typeHint = null, ?string $label = null): void
	{
		$n = PhoneNumber::parse($value);

		if (! $n) {
			return;
		}

		// Typ z etykiety tuż przed numerem (nagłówek, „Recepcja:”, imię i nazwisko). Jednoznaczna etykieta na stronie
		// wygrywa z podpowiedzią z danych strukturalnych (np. ContactPoint „customer service” przy „Recepcja”).
		$fromLabel = $label !== null ? self::classify($label, $branch) : 'UNKNOWN';
		$type = match (true) {
			$branch !== null => 'BRANCH',
			$fromLabel !== 'UNKNOWN' => $fromLabel,
			$typeHint !== null => $typeHint,
			$label === null => self::classify($context, $branch),
			// Kontekst szerszy niż etykieta tylko, gdy zawiera wyłącznie ten numer (inaczej etykiety innych numerów).
			// Oddział tylko z etykiety / bloku oddziału: „Oddział Wodzisław:” w sąsiednim bloku adresu przy „Infolinia:”
			// (ekookna.pl) nie czyni infolinii numerem oddziału.
			preg_match_all('/\d[\d\s().\/\-–]{6,}\d/u', $context) <= 1 => ($c = self::classify($context, $branch)) === 'BRANCH' ? 'UNKNOWN' : $c,
			default => 'UNKNOWN',
		};

		// Spółka wymieniona w etykiecie (np. „Huta Bankowa Sp. z o.o. Sekretariat” na stronie grupy): inna spółka → oddział
		// (nigdy numer główny tej firmy); ta sama spółka z adresem → centrala.
		if ($label !== null && $this->companyName && $branch === null) {
			$entity = self::entityIn($label);

			if ($entity !== null && ! self::sameCompany($entity, $this->companyName)) {
				$type = 'BRANCH';
				$branch = mb_substr('inna spółka: ' . $entity, 0, 120);
			} elseif ($entity !== null && in_array($type, ['UNKNOWN', 'MAIN'], true)) {
				$type = 'MAIN';
			}
		}

		if ($label !== null && trim($label) !== '') {
			$context = mb_substr(trim($label) . ' | ' . $context, 0, 160);
		}
		$key = $n['e164'] . '|' . ($n['extension'] ?? '');

		if (isset($found[$key])) {
			$old = $found[$key];
			$rank = ['high' => 3, 'medium' => 2, 'low' => 1];
			// Ten sam numer w kilku miejscach: zachowujemy najpewniejsze źródło i najbardziej konkretny typ.
			$found[$key]['confidence'] = $rank[$confidence] > $rank[$old['confidence']] ? $confidence : $old['confidence'];
			$found[$key]['in_footer'] = $old['in_footer'] || $footer;
			$found[$key]['type'] = $old['type'] === 'UNKNOWN' ? $type : $old['type'];
			$found[$key]['branch_label'] ??= $branch;

			if ($source === 'TEL_LINK' && $old['source'] === 'TEXT') {
				$found[$key]['source'] = $source;
			}

			return;
		}

		$found[$key] = [
			'raw' => mb_substr(trim(preg_replace('/^(tel|callto):/i', '', $n['raw']) ?? $n['raw']), 0, 60),
			'e164' => $n['e164'],
			'extension' => $n['extension'],
			'country' => $n['country'],
			'type' => $type,
			'source' => $source,
			'source_url' => mb_substr($url, 0, 500),
			'page_kind' => $pageKind,
			'context' => mb_substr(preg_replace('/\s+/u', ' ', $context) ?? '', 0, 160),
			'branch_label' => $branch ? mb_substr($branch, 0, 120) : null,
			'confidence' => $confidence,
			'in_footer' => $footer,
		];
	}

	/**
	 * Typ numeru z opisu przy numerze (słowa kluczowe PL/EN).
	 */
	public static function classify(string $context, ?string $branch = null): string
	{
		$t = mb_strtolower($context);

		return match (true) {
			$branch !== null || (bool) preg_match('/(oddzia[łl]|filia|branch|zak[łl]ad\s+\w+|przedstawicielstwo)/u', $t) => 'BRANCH',
			(bool) preg_match(self::MAIN_WORD, $t) => 'MAIN',
			(bool) preg_match(self::PERSON_ROLE, $t) || self::hasPersonName($context) => 'OTHER',
			(bool) preg_match(self::DEPARTMENT, $t) => 'OTHER',
			(bool) preg_match('/(serwis|support|pomoc techniczn|wsparcie|helpdesk|service|customer)/u', $t) => 'SUPPORT',
			(bool) preg_match('/(sprzeda[żz]|handlow|sales|zam[óo]wie|oferty|ofert|eksport|export)/u', $t) => 'SALES',
			default => 'UNKNOWN',
		};
	}

	/**
	 * Ostatnia (najbliższa numerowi) nazwa spółki z formą prawną w tekście, np. „Huta Bankowa Sp. z o.o.”.
	 */
	public static function entityIn(string $text): ?string
	{
		if (! preg_match_all('/(\p{Lu}[\p{L}\-.&]*(?:\s+(?:\p{Lu}|\d)[\p{L}\d\-.&]*){0,4})\s+(?:[Ss]p\.\s?z\s?o\.\s?o\.|S\.\s?A\.|SA\b|[Ss]p\.\s?[kj]\.|GmbH|Ltd\b|Inc\b)/u', $text, $m)) {
			return null;
		}

		return trim((string) end($m[0]));
	}

	public static function sameCompany(string $entity, string $companyName): bool
	{
		$stop = ['sp', 'z', 'o', 'oo', 'sa', 'spolka', 'akcyjna', 'grupa', 'group', 'polska', 'poland', 'gmbh', 'ltd', 'inc', 'k', 'j', 'the', 'and', 'i', 'firma', 'przedsiebiorstwo'];
		$tok = function (string $v) use ($stop): array {
			$v = strtolower(\App\Ubr\Domain\Companies\CompanyNormalizer::foldAscii($v));

			return array_values(array_diff(preg_split('/[^a-z0-9]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [], $stop));
		};
		$a = array_filter($tok($entity), fn ($t) => strlen($t) >= 3);
		$b = array_filter($tok($companyName), fn ($t) => strlen($t) >= 3);

		return $a !== [] && array_intersect($a, $b) !== [];
	}

	/**
	 * Imię i nazwisko w etykiecie (słownik imion — „Dział Handlowy” czy „Centrala Grupy” nie są osobami).
	 */
	/**
	 * Rodzaj etykiety numeru (Prompt 10.2 — właściciel numeru): main / branch / department / sales / support / person / unknown.
	 * Te same słowa co klasyfikacja typu (jedna lista); „person” = stanowisko lub imię i nazwisko w etykiecie.
	 */
	public static function labelKind(string $label): string
	{
		$t = mb_strtolower($label);

		return match (true) {
			(bool) preg_match('/(oddzia[łl]|filia|branch|przedstawicielstwo)/u', $t) => 'branch',
			(bool) preg_match(self::MAIN_WORD, $t) || (bool) preg_match('/(infolinia|hotline|biuro obs[łl]ugi|obs[łl]uga klienta|customer service)/u', $t) => 'main',
			(bool) preg_match(self::DEPARTMENT, $t) || (bool) preg_match('/\bdzia[łl]\b|\bdepartment\b|\bbiuro\b|\bpion\b/u', $t) => 'department',
			(bool) preg_match(self::PERSON_ROLE, $t) || self::hasPersonName($label) => 'person',
			(bool) preg_match('/(sprzeda[żz]|handlow|sales|zam[óo]wie|oferty|eksport|export)/u', $t) => 'sales',
			(bool) preg_match('/(serwis|support|pomoc techniczn|wsparcie|helpdesk|service)/u', $t) => 'support',
			default => 'unknown',
		};
	}

	/** Kontekst wykluczający (fax, NIP, REGON, KRS, rachunek…) tuż przed numerem. */
	public static function notPhoneBefore(string $before): bool
	{
		return (bool) preg_match(self::NOT_PHONE_WORD, $before) || (bool) preg_match('/\b(fax|faks)\b[^\d]{0,6}$/iu', $before);
	}

	public static function phoneWord(): string
	{
		return self::PHONE_WORD;
	}

	public static function hasPersonName(string $text): bool
	{
		$tokens = preg_split('/[^\p{L}\-\x{2019}\']+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

		for ($i = 0; $i < count($tokens) - 1; $i++) {
			if (\App\Ubr\Domain\Contacts\PersonName::parse($tokens[$i] . ' ' . $tokens[$i + 1])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Etykieta numeru: tekst przed numerem od poprzedniego numeru (lub adresu e-mail osoby), maks. 100 znaków.
	 */
	public static function cutLabel(string $before): string
	{
		$before = trim(preg_replace('/\s+/u', ' ', $before) ?? '');
		preg_match_all('/(?:\+|00)?\d[\d\s().\/\-–]{6,}\d/u', $before, $m, PREG_OFFSET_CAPTURE);
		// Tylko pełne numery (≥ 9 cyfr) — adres „ul. X 51, 40-698 Katowice” nie jest poprzednim numerem.
		$ends = array_values(array_map(fn ($x) => $x[1] + strlen($x[0]), array_filter($m[0], fn ($x) => preg_match_all('/\d/', $x[0]) >= 9)));
		$text = $before;

		// Tekst po ostatnim wcześniejszym numerze; gdy to tylko „tel.” (dwa numery pod jedną etykietą) — sięgamy za numer wcześniej.
		for ($i = count($ends) - 1; $i >= 0; $i--) {
			$candidate = trim(substr($before, $ends[$i]));
			$start = $i > 0 ? $ends[$i - 1] : 0;

			if (preg_match_all('/\p{L}/u', preg_replace('/\b(tel|telefon|phone|kom|mob|t|e-?mail)\b\.?:?/iu', '', $candidate) ?? '') >= 3) {
				$text = $candidate;
				break;
			}

			$text = trim(substr($before, $start));
		}

		return mb_substr($text, -100);
	}

	/**
	 * @param mixed $node
	 * @param array<string, array<string, mixed>> $found
	 */
	private function walkJsonLd(mixed $node, array &$found, string $url, string $pageKind, ?string $branch, ?string $typeHint, int $depth = 0): void
	{
		if (! is_array($node) || $depth > 8) {
			return;
		}

		$locality = is_array($node['address'] ?? null) ? (string) ($node['address']['addressLocality'] ?? '') : '';
		$nodeType = is_string($node['@type'] ?? null) ? $node['@type'] : '';
		$hint = $typeHint;

		if (isset($node['contactType']) && is_string($node['contactType'])) {
			$hint = match (self::classify($node['contactType'])) {
				'SALES' => 'SALES',
				'SUPPORT' => 'SUPPORT',
				'MAIN' => 'MAIN',
				default => $hint,
			};
		}

		// Węzły podrzędne typu department / subOrganization / location = oddziały.
		$nodeBranch = $branch;

		foreach (['telephone', 'telePhone'] as $key) {
			foreach ((array) ($node[$key] ?? []) as $phone) {
				if (is_string($phone)) {
					$this->add($found, $phone, 'SCHEMA', 'high', $url, $pageKind, trim($nodeType . ' ' . ($node['name'] ?? '') . ' ' . $locality), false, $nodeBranch, $nodeBranch ? 'BRANCH' : $hint);
				}
			}
		}

		foreach ($node as $key => $value) {
			if (! is_array($value) || $key === 'address') {
				continue;
			}

			if (in_array($key, ['department', 'subOrganization', 'location', 'branchOf', 'hasPOS'], true)) {
				// Oddziały: etykieta z nazwy i miejscowości KAŻDEGO węzła (lista lub pojedynczy węzeł).
				foreach (array_is_list($value) ? $value : [$value] as $item) {
					if (is_array($item)) {
						$label = trim((string) ($item['name'] ?? '') . ' ' . (is_array($item['address'] ?? null) ? (string) ($item['address']['addressLocality'] ?? '') : ''));
						$this->walkJsonLd($item, $found, $url, $pageKind, $label !== '' ? $label : 'oddział', $hint, $depth + 1);
					}
				}

				continue;
			}

			$this->walkJsonLd($value, $found, $url, $pageKind, $nodeBranch, $hint, $depth + 1);
		}
	}

	/**
	 * Bloki tekstu (akapity, komórki, elementy list, stopka) z informacją, czy blok leży w stopce.
	 *
	 * @return list<array{0: string, 1: bool, 2: DOMElement}>
	 */
	private function blocks(DOMXPath $xp): array
	{
		$out = [];

		// Elementy, których WŁASNY tekst zawiera ≥ 3 cyfry z rzędu (akapity, stopka, komórki, spany — bez zależności od tagu).
		$query = '//body//*[text()[contains(translate(., "0123456789", "##########"), "###")]]';

		foreach (array_slice(iterator_to_array($xp->query($query) ?: new \ArrayIterator([]), false), 0, 3000) as $el) {
			/** @var DOMElement $el */
			// Tekst z odstępami między węzłami (bez sklejania „Kowalskitel.” przez <br> i sąsiednie elementy).
			$text = trim(preg_replace('/\s+/u', ' ', $this->spacedText($xp, $el)) ?? '');

			if ($text === '' || mb_strlen($text) > 600 || ! preg_match('/\d{3}/', $text)) {
				continue;
			}

			$out[] = [$text, $this->inFooter($el), $el];
		}

		return $out;
	}

	private function spacedText(DOMXPath $xp, \DOMNode $node): string
	{
		$parts = [];

		foreach ($xp->query('.//text()', $node) ?: [] as $t) {
			$parts[] = $t->nodeValue;
		}

		return implode(' ', $parts);
	}

	/**
	 * Tekst bezpośrednio poprzedzający element w kolejności dokumentu (maks. 8 węzłów tekstowych — nagłówek, etykieta).
	 */
	private function precedingText(DOMXPath $xp, DOMElement $el): string
	{
		$parts = [];

		foreach ($xp->query('(preceding::text()[normalize-space()][not(ancestor::script)][not(ancestor::style)])[position() > last() - 8]', $el) ?: [] as $t) {
			$parts[] = trim((string) $t->nodeValue);
		}

		return implode(' ', $parts);
	}

	/**
	 * Tekst w tym samym (krótkim) elemencie nadrzędnym przed linkiem, np. „Infolinia:” w „<p>Infolinia: <a>…</a></p>”.
	 */
	private function blockPrefix(DOMXPath $xp, DOMElement $el): string
	{
		$parent = $el->parentNode;

		if (! $parent instanceof DOMElement || mb_strlen(trim($parent->textContent)) > 200) {
			return '';
		}

		$parts = [];

		foreach ($xp->query('.//text()', $parent) ?: [] as $t) {
			for ($n = $t; $n !== null && $n !== $parent; $n = $n->parentNode) {
				if ($n === $el) {
					break 2;
				}
			}

			$parts[] = trim((string) $t->nodeValue);
		}

		return self::cutLabel(implode(' ', $parts));
	}

	private function label(DOMXPath $xp, DOMElement $el): string
	{
		return self::cutLabel($this->precedingText($xp, $el));
	}

	private function context(DOMElement $el): string
	{
		$text = trim($el->textContent);
		$parent = $el->parentNode instanceof DOMElement ? trim($el->parentNode->textContent) : '';
		$label = $el->getAttribute('aria-label') . ' ' . $el->getAttribute('title');

		return mb_substr(preg_replace('/\s+/u', ' ', $label . ' ' . (mb_strlen($parent) <= 200 ? $parent : $text)) ?? '', 0, 160);
	}

	private function inFooter(DOMElement $el): bool
	{
		for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
			$marker = strtolower($n->tagName . ' ' . $n->getAttribute('id') . ' ' . $n->getAttribute('class') . ' ' . $n->getAttribute('role'));

			if ($n->tagName === 'footer' || str_contains($marker, 'footer') || str_contains($marker, 'stopka') || str_contains($marker, 'contentinfo')) {
				return true;
			}
		}

		return false;
	}
}
