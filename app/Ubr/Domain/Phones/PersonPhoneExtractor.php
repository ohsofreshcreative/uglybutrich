<?php

namespace App\Ubr\Domain\Phones;

use App\Ubr\Domain\Contacts\PeopleExtractor;
use App\Ubr\Domain\Contacts\PersonName;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Prompt 10.2 — numery służbowe osób z oficjalnej strony firmy, z DOWODEM powiązania numeru z osobą.
 *
 * Powiązanie (link_status):
 * - VERIFIED: numer w tym samym, małym elemencie HTML (karta pracownika, wiersz tabeli, akapit) co JEDNA osoba
 *   z imieniem, nazwiskiem i stanowiskiem; numer nie występuje gdzie indziej na stronie; albo indywidualna podstrona osoby.
 * - LIKELY: ten sam blok bez stanowiska albo układ „imię → stanowisko → telefon” bez wspólnego elementu (lista płaska).
 * - UNCERTAIN: numer w pobliżu osoby, ale bez jednoznacznej struktury, w bloku wielu numerów albo także w innym miejscu strony.
 * - REJECTED: numer firmy (centrala, oddział, stopka / nagłówek strony), numer z etykietą działu („Sekretariat:”, „Dział
 *   sprzedaży:”) albo ten sam numer przy kilku osobach.
 * Samo nazwisko „gdzieś na stronie”, numer w stopce czy numer działu NIGDY nie dają powiązania z osobą.
 *
 * Właściciel numeru (owner_class): PERSON_DIRECT / DEPARTMENT / COMPANY_GENERAL / BRANCH / UNCERTAIN.
 * Czysta logika (bez SQL, bez HTTP) — dane wejściowe to HTML już pobranej strony.
 */
final class PersonPhoneExtractor
{
	public const VERIFIED = 'VERIFIED';

	public const LIKELY = 'LIKELY';

	public const UNCERTAIN = 'UNCERTAIN';

	public const REJECTED = 'REJECTED';

	public const STATUSES = [self::VERIFIED, self::LIKELY, self::UNCERTAIN, self::REJECTED];

	public const STATUS_LABELS = [
		self::VERIFIED => 'Potwierdzony',
		self::LIKELY => 'Prawdopodobny',
		self::UNCERTAIN => 'Do weryfikacji',
		self::REJECTED => 'Odrzucony',
	];

	public const OWNER_CLASSES = ['PERSON_DIRECT', 'DEPARTMENT', 'COMPANY_GENERAL', 'BRANCH', 'UNCERTAIN'];

	public const OWNER_LABELS = [
		'PERSON_DIRECT' => 'Numer osoby',
		'DEPARTMENT' => 'Numer działu',
		'COMPANY_GENERAL' => 'Numer ogólny firmy',
		'BRANCH' => 'Numer oddziału',
		'UNCERTAIN' => 'Właściciel nieustalony',
	];

	public const EVIDENCE_LABELS = [
		'SAME_BLOCK' => 'Ten sam blok kontaktowy: imię i nazwisko, stanowisko i numer',
		'TABLE_ROW' => 'Ten sam wiersz tabeli: imię i nazwisko, stanowisko i numer',
		'PERSON_PAGE' => 'Indywidualna podstrona osoby',
		'SAME_BLOCK_NO_TITLE' => 'Ten sam blok co imię i nazwisko, bez stanowiska',
		'SEQUENCE' => 'Kolejne linie: imię i nazwisko → stanowisko → numer (bez wspólnego bloku)',
		'NEARBY' => 'Numer w pobliżu osoby, bez jednoznacznego bloku',
		'FOOTER_BLOCK' => 'Blok osoby w stopce strony: imię i nazwisko, stanowisko i numer',
		'COMPANY_BLOCK' => 'Blok z danymi firmy (adres, NIP, forma prawna) — numer może należeć do firmy',
		'MANY_NUMBERS' => 'Blok z wieloma numerami — nie wiadomo, który należy do osoby',
		'ALSO_ELSEWHERE' => 'Ten sam numer występuje także w innym miejscu strony',
		'GENERAL_NUMBER' => 'Numer ogólny firmy (centrala / numer główny)',
		'BRANCH_NUMBER' => 'Numer oddziału',
		'FOOTER_NUMBER' => 'Numer ze stopki / nagłówka strony (ogólny)',
		'DEPARTMENT_LABEL' => 'Numer opisany jako dział / sekretariat / centrala',
		'SHARED_NUMBER' => 'Ten sam numer przy kilku osobach',
		'MANUAL_CONFIRM' => 'Potwierdzone ręcznie',
		'MANUAL_REJECT' => 'Odrzucone ręcznie',
		'MANUAL_ASSIGN' => 'Przypisane ręcznie',
		'LEGACY_BLOCK' => 'Numer zapisany przy osobie przed etapem 10.2 — do ponownego sprawdzenia',
		'APOLLO' => 'Numer osoby z Apollo (identyfikator osoby)',
	];

	private const SKIP = '//script|//style|//noscript|//template|//svg|//select|//iframe';

	private const MAX_DEPTH = 6;

	private const MAX_BLOCK_TEXT = 600;

	/** @var \SplObjectStorage<DOMNode, int> */
	private \SplObjectStorage $textLen;

	/** @var list<string> numery firmy (ogólne + oddziały) bieżącego wywołania */
	private array $known = [];

	public function __construct(private PeopleExtractor $people = new PeopleExtractor())
	{
		$this->textLen = new \SplObjectStorage();
	}

	/**
	 * @param list<string> $generalNumbers numery ogólne firmy (główny, centrala) — nigdy numer osoby
	 * @param list<string> $branchNumbers numery oddziałów
	 * @return array{people: list<array<string, mixed>>, numbers: array<string, array{class: string, note: ?string, label: string}>}
	 */
	public function extract(string $html, string $url, array $generalNumbers = [], array $branchNumbers = []): array
	{
		$this->textLen = new \SplObjectStorage();
		$this->known = array_values(array_merge($generalNumbers, $branchNumbers));

		if (trim($html) === '') {
			return ['people' => [], 'numbers' => []];
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xp = new DOMXPath($doc);

		foreach (iterator_to_array($xp->query(self::SKIP) ?: new \ArrayIterator([]), false) as $n) {
			$n->parentNode?->removeChild($n);
		}

		// 1. Węzły tekstowe w kolejności dokumentu.
		$texts = [];

		foreach ($xp->query('//body//text()[normalize-space()]') ?: [] as $t) {
			// mb_scrub: strony w innym kodowaniu niż UTF-8 nie mogą przerwać analizy (preg_* zwraca false).
			$value = mb_scrub((string) $t->nodeValue, 'UTF-8');
			$texts[] = ['node' => $t, 'text' => trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value), 'el' => $t->parentNode];
		}

		$phones = $this->phones($xp, $texts);
		[$persons, $roles] = $this->people($texts);

		// 2. Powiązania numer → osoba.
		$links = [];
		$path = PersonName::fold(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
		$distinct = array_unique(array_map(fn ($p) => $p['key'], array_filter($persons, fn ($p) => ! $p['chrome'])));
		$personPage = count($distinct) === 1 ? reset($distinct) : null;

		if ($personPage !== null) {
			$only = array_values(array_filter($persons, fn ($p) => $p['key'] === $personPage))[0];
			$slug = preg_replace('/[^a-z]/', '', PersonName::fold($only['name']->last)) ?? '';
			$personPage = $slug !== '' && mb_strlen($slug) >= 3 && str_contains(preg_replace('/[^a-z]/', '', $path) ?? '', $slug) ? $personPage : null;
		}

		// Stopka / nagłówek: numer jest ogólny, CHYBA ŻE stoi w bloku konkretnej osoby ze stopki („Jan Nowak – Sales Manager
		// tel. …”) — wtedy co najwyżej LIKELY (słabsze miejsce niż karta zespołu), bez stanowiska UNCERTAIN.
		$chromeNumbers = [];

		foreach ($phones as $ph) {
			if (! $ph['chrome']) {
				continue;
			}

			$link = $this->blockLink($ph, $persons, $roles, $phones, true);

			if ($link === null || in_array($ph['e164'], array_merge($generalNumbers, $branchNumbers), true)) {
				$chromeNumbers[$ph['e164']] = true;
				continue;
			}

			$link['status'] = $link['status'] === self::VERIFIED ? self::LIKELY : self::UNCERTAIN;
			$link['evidence'] = $link['status'] === self::LIKELY ? 'FOOTER_BLOCK' : $link['evidence'];
			$link += ['e164' => $ph['e164'], 'raw' => $ph['raw'], 'label' => $ph['label'], 'extension' => $ph['extension'], 'ph' => $ph];
			$links[$link['person'] . '|' . $ph['e164']] = self::stronger($links[$link['person'] . '|' . $ph['e164']] ?? null, $link);
		}

		foreach ($phones as $pi => $ph) {
			if ($ph['chrome']) {
				continue;
			}

			$link = $personPage !== null
				? $this->personPageLink($ph, $persons, $roles, $personPage)
				: ($this->blockLink($ph, $persons, $roles, $phones) ?? $this->sequenceLink($ph, $pi, $persons, $roles, $texts));

			if ($link === null) {
				continue;
			}

			// Twarde odrzucenia: numer firmy / oddziału / stopki, etykieta działu (bez stanowiska i nazwiska samej osoby —
			// „Kierownik Magazynu tel.” to stanowisko osoby, nie numer magazynu).
			$own = array_filter([$link['role']['text'] ?? null, ...array_map(fn ($p) => $p['name']->full(), array_filter($persons, fn ($p) => $p['key'] === $link['person']))]);
			$kind = CompanyPhoneExtractor::labelKind(trim(str_ireplace($own, ' ', $ph['near'])));
			$reject = match (true) {
				in_array($ph['e164'], $generalNumbers, true) => 'GENERAL_NUMBER',
				in_array($ph['e164'], $branchNumbers, true) => 'BRANCH_NUMBER',
				isset($chromeNumbers[$ph['e164']]) => 'FOOTER_NUMBER',
				in_array($kind, ['main', 'department', 'branch'], true) => 'DEPARTMENT_LABEL',
				default => null,
			};

			if ($reject) {
				$link['status'] = self::REJECTED;
				$link['evidence'] = $reject;
			}

			$link['e164'] = $ph['e164'];
			$link['raw'] = $ph['raw'];
			$link['label'] = $ph['label'];
			$link['extension'] = $ph['extension'];
			$link['ph'] = $ph;
			$links[$link['person'] . '|' . $ph['e164']] = self::stronger($links[$link['person'] . '|' . $ph['e164']] ?? null, $link);
		}

		// Ten sam numer przy kilku osobach = numer wspólny (sekretariat, dział), nie bezpośredni.
		$owners = [];

		foreach ($links as $l) {
			if ($l['status'] !== self::REJECTED) {
				$owners[$l['e164']][$l['person']] = true;
			}
		}

		foreach ($links as &$l) {
			if ($l['status'] === self::REJECTED) {
				continue;
			}

			if (count($owners[$l['e164']] ?? []) > 1) {
				$l['status'] = self::REJECTED;
				$l['evidence'] = 'SHARED_NUMBER';
			} elseif ($l['status'] !== self::UNCERTAIN && $this->occursOutside($l['ph'], $phones, $l['container'])) {
				$l['status'] = self::UNCERTAIN;
				$l['evidence'] = 'ALSO_ELSEWHERE';
			}
		}

		unset($l);

		// 3. Osoby (także bez numeru — z imieniem, nazwiskiem i stanowiskiem) z ich numerami. `mentions` — ile razy pełne imię
		// i nazwisko występuje w tekście strony (literówka na stronie, np. „Kornulik” obok 2× „Korniluk”, ma mniej wystąpień).
		$people = [];
		$pageText = mb_strtolower(implode(' ', array_column($texts, 'text')));

		foreach ($persons as $p) {
			if (isset($people[$p['key']]) || ($p['chrome'] && ! array_filter($links, fn ($l) => $l['person'] === $p['key']))) {
				continue;
			}

			$role = $p['role'] ?? $this->nearestRole($p, $persons, $roles);
			$people[$p['key']] = [
				'name' => $p['name'],
				'key' => $p['key'],
				'title' => $role ? mb_substr(trim((string) $role['text']), 0, 200) : null,
				'role' => $role,
				'source_url' => $url,
				'excerpt' => mb_substr($this->excerptAround($p['i'], $texts), 0, 300),
				'mentions' => max(1, substr_count($pageText, mb_strtolower($p['name']->full()))),
				'phones' => [],
			];
		}

		foreach ($links as $l) {
			if (! isset($people[$l['person']])) {
				continue;
			}

			// Stanowisko z bloku numeru (np. wiersz tabeli), gdy przy samej osobie go nie znaleziono.
			if ($people[$l['person']]['role'] === null && $l['role'] !== null) {
				$people[$l['person']]['role'] = $l['role'];
				$people[$l['person']]['title'] = mb_substr(trim((string) $l['role']['text']), 0, 200);
			}

			$people[$l['person']]['phones'][] = [
				'e164' => $l['e164'],
				'raw' => mb_substr((string) $l['raw'], 0, 60),
				'extension' => $l['extension'],
				'status' => $l['status'],
				'evidence' => $l['evidence'],
				'label' => mb_substr((string) $l['label'], 0, 100),
				'excerpt' => mb_substr((string) $l['excerpt'], 0, 300),
			];
		}

		// 4. Właściciel każdego numeru na stronie.
		$numbers = [];

		foreach ($phones as $ph) {
			$numbers[$ph['e164']] ??= ['labels' => [], 'chrome' => false];
			$numbers[$ph['e164']]['labels'][] = $ph['label'];
			$numbers[$ph['e164']]['chrome'] = $numbers[$ph['e164']]['chrome'] || $ph['chrome'];
		}

		$out = [];

		foreach ($numbers as $e164 => $info) {
			$personLinks = array_values(array_filter($links, fn ($l) => $l['e164'] === $e164));
			$direct = array_values(array_filter($personLinks, fn ($l) => in_array($l['status'], [self::VERIFIED, self::LIKELY], true)));
			$label = trim((string) ($info['labels'][0] ?? ''));
			$kinds = array_map([CompanyPhoneExtractor::class, 'labelKind'], array_filter($info['labels']));

			$class = match (true) {
				$direct !== [] => 'PERSON_DIRECT',
				in_array($e164, $branchNumbers, true) || in_array('branch', $kinds, true) => 'BRANCH',
				in_array($e164, $generalNumbers, true) || in_array('main', $kinds, true) => 'COMPANY_GENERAL',
				$info['chrome'] && in_array('person', $kinds, true) => 'UNCERTAIN',
				$info['chrome'] => 'COMPANY_GENERAL',
				(bool) array_intersect($kinds, ['department', 'sales', 'support']) || in_array('SHARED_NUMBER', array_column($personLinks, 'evidence'), true) => 'DEPARTMENT',
				default => 'UNCERTAIN',
			};

			$first = $direct[0] ?? null;
			$maybe = array_values(array_filter($personLinks, fn ($l) => $l['status'] === self::UNCERTAIN))[0] ?? null;
			$note = match (true) {
				$first !== null => $people[$first['person']]['name']->full() . ($people[$first['person']]['title'] ? ' — ' . $people[$first['person']]['title'] : '') . ' (' . self::STATUS_LABELS[$first['status']] . ')',
				$maybe !== null && isset($people[$maybe['person']]) => 'Możliwie: ' . $people[$maybe['person']]['name']->full() . ' — ' . (self::EVIDENCE_LABELS[$maybe['evidence']] ?? $maybe['evidence']),
				$label !== '' => $label,
				default => null,
			};

			$out[$e164] = ['class' => $class, 'note' => $note ? mb_substr($note, 0, 200) : null, 'label' => mb_substr($label, 0, 100)];
		}

		return ['people' => array_values($people), 'numbers' => $out];
	}

	/**
	 * Silniejsze z dwóch powiązań tej samej pary (osoba, numer) — numer może wystąpić przy osobie kilka razy.
	 *
	 * @param array<string, mixed>|null $a
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	private static function stronger(?array $a, array $b): array
	{
		if ($a === null) {
			return $b;
		}

		$rank = [self::REJECTED => 4, self::VERIFIED => 3, self::LIKELY => 2, self::UNCERTAIN => 1];

		// Odrzucenie (numer firmy / działu) wygrywa — inne wystąpienie przy osobie nie czyni numeru bezpośrednim.
		return $rank[$b['status']] > $rank[$a['status']] ? $b : $a;
	}

	/**
	 * Numery na stronie: linki tel: i tekst (tylko po słowie „tel.”, „kom.” … albo z prefiksem +48 / 0048).
	 *
	 * @param list<array{node: DOMNode, text: string, el: ?DOMNode}> $texts
	 * @return list<array{e164: string, raw: string, extension: ?string, el: DOMNode, i: float, chrome: bool, label: string}>
	 */
	private function phones(DOMXPath $xp, array $texts): array
	{
		$out = [];
		$index = new \SplObjectStorage();

		foreach ($texts as $i => $t) {
			$index[$t['node']] = $i;
		}

		foreach ($xp->query('//a[starts-with(translate(@href, "TEL", "tel"), "tel:")]') ?: [] as $a) {
			/** @var DOMElement $a */
			$n = PhoneNumber::parse((string) $a->getAttribute('href'));

			if (! $n) {
				continue;
			}

			$i = null;

			foreach ($xp->query('.//text()[normalize-space()]', $a) ?: [] as $t) {
				$i = $index[$t] ?? null;
				break;
			}

			$i ??= $this->indexBefore($a, $texts, $index);
			$own = trim(preg_replace('/(?:\+|00)?\d[\d\s().\/\-–]{6,}\d/u', ' ', $a->textContent) ?? '');
			$label = preg_match_all('/\p{L}/u', preg_replace('/\b(tel|telefon|phone|zadzwo[ńn]|call|t)\b\.?:?/iu', '', $own) ?? '') >= 3 ? $own : $this->labelBefore((int) floor($i), $texts, '');
			$near = $label === $own ? $own : $this->nearLabel((int) floor($i), $texts, '', $i != floor($i));
			$out[] = ['e164' => $n['e164'], 'raw' => trim($a->textContent) ?: $n['national'], 'extension' => $n['extension'], 'el' => $a, 'i' => (float) $i, 'chrome' => $this->chrome($a), 'label' => $label, 'near' => $near];
		}

		$word = CompanyPhoneExtractor::phoneWord();

		foreach ($texts as $i => $t) {
			if ($this->insideTelLink($t['node'])) {
				continue;
			}

			if (! preg_match_all('/(?:\+|00)?\s?\(?\d[\d\s().\/\-–]{7,20}\d/u', $t['text'], $m, PREG_OFFSET_CAPTURE)) {
				continue;
			}

			foreach ($m[0] as [$raw, $offset]) {
				$digits = preg_replace('/\D/', '', $raw) ?? '';

				if (strlen($digits) < 9 || strlen($digits) > 13) {
					continue;
				}

				$before = mb_substr(($texts[$i - 1]['text'] ?? '') . ' ' . substr($t['text'], 0, $offset), -40);
				$after = substr($t['text'], $offset + strlen($raw), 1);
				$prefixed = (bool) preg_match('/^\s*(\+|00)\s?\(?48/', $raw);
				$worded = (bool) preg_match('/(?<!\p{L})' . $word . '\s*[:\-–.]?\s*$/iu', $before);

				if ((! $prefixed && ! $worded) || CompanyPhoneExtractor::notPhoneBefore($before) || ($after !== '' && ctype_digit($after))) {
					continue;
				}

				$n = PhoneNumber::parse(trim($raw));

				if (! $n) {
					continue;
				}

				$out[] = ['e164' => $n['e164'], 'raw' => trim($raw), 'extension' => $n['extension'], 'el' => $t['el'], 'i' => (float) $i, 'chrome' => $t['el'] instanceof DOMNode && $this->chrome($t['el']), 'label' => $this->labelBefore($i, $texts, substr($t['text'], 0, $offset)), 'near' => $this->nearLabel($i, $texts, substr($t['text'], 0, $offset), false)];
			}
		}

		usort($out, fn ($a, $b) => $a['i'] <=> $b['i']);

		return $out;
	}

	/**
	 * Osoby (imię i nazwisko, opcjonalnie ze stanowiskiem w tej samej linii) i stanowiska.
	 *
	 * @param list<array{node: DOMNode, text: string, el: ?DOMNode}> $texts
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	private function people(array $texts): array
	{
		$persons = [];
		$roles = [];

		foreach ($texts as $i => $t) {
			$text = $t['text'];

			if ($text === '' || mb_strlen($text) > 200) {
				continue;
			}

			// „Jan Kowalski | tel.: …”, „Jan Kowalski j.kowalski@…” — osoba przed numerem / adresem w tej samej linii.
			$prefix = trim((string) (preg_split('/\s[|•·–—-]\s|,\s|\s(?=\S+@)|(?<!\p{L})(?:tel|telefon|phone|kom|mob|gsm|e-?mail|t)\b\.?\s*:?\s*[\d+(]|[\d+(]?\d{3}[\s\-]?\d{3}/iu', $text, 2) ?: [$text])[0], " \t,;:|–-");
			$best = ['type' => '-'];

			foreach (array_unique([$text, $prefix]) as $candidate) {
				if ($candidate === '') {
					continue;
				}

				$k = $this->people->classify($candidate);

				if ($k['type'] === 'I' || ($k['type'] === 'N' && $best['type'] !== 'I') || ($k['type'] === 'R' && $best['type'] === '-')) {
					$best = $k;
				}
			}

			// „Jan Kowalski · Prezes Zarządu” / „Jan Kowalski | Dyrektor Marketingu” — stanowisko po separatorze.
			if ($best['type'] === 'N' && $prefix !== '' && $prefix !== $text) {
				$rest = trim(mb_substr($text, mb_strlen($prefix)), " \t,;:|–-·•");
				$restRole = $rest !== '' && mb_strlen($rest) <= 140 ? $this->people->classify(trim((string) (preg_split('/\s[|•·]\s|(?<!\p{L})(?:tel|telefon|phone|kom|mob|gsm|e-?mail)\b/iu', $rest, 2) ?: [$rest])[0])) : ['type' => '-'];

				if ($restRole['type'] === 'R') {
					$best = ['type' => 'I', 'name' => $best['name'], 'role' => $restRole['role']];
				} elseif (($generic = self::genericTitle(trim((string) (preg_split('/\s[|•·]\s|(?<!\p{L})(?:tel|telefon|phone|kom|mob|gsm|e-?mail)\b/iu', $rest, 2) ?: [$rest])[0]))) !== null) {
					$best = ['type' => 'I', 'name' => $best['name'], 'role' => $generic];
				}
			}

			// Stanowisko spoza katalogu ról (np. „Kierownik Magazynu”, „Sales Manager”) — dowód struktury bloku osoby,
			// ale NIE rola decydenta (role decydentów wyłącznie z RoleCatalog).
			if ($best['type'] === '-' && ($generic = self::genericTitle($text)) !== null) {
				$best = ['type' => 'R', 'role' => $generic];
			}

			$chrome = $t['el'] instanceof DOMNode && $this->chrome($t['el']);

			if (in_array($best['type'], ['N', 'I'], true)) {
				$persons[] = ['name' => $best['name'], 'key' => $best['name']->key(), 'role' => $best['type'] === 'I' ? $best['role'] : null, 'el' => $t['el'], 'i' => $i, 'chrome' => $chrome];
			} elseif ($best['type'] === 'R') {
				$roles[] = ['role' => $best['role'], 'el' => $t['el'], 'i' => $i];
			}
		}

		return [$persons, $roles];
	}

	/**
	 * Stanowisko w ogólnym sensie (słowa stanowisk PL / EN), krótka linia bez cyfr i adresów — `role` = null.
	 *
	 * @return array{role: null, text: string, generic: true}|null
	 */
	public static function genericTitle(string $text): ?array
	{
		$text = trim($text, " \t,;:|–-·•");

		if ($text === '' || mb_strlen($text) > 80 || preg_match('/[\d@]|https?:/u', $text) || count(preg_split('/\s+/u', $text) ?: []) > 8 || PersonName::parse($text)) {
			return null;
		}

		return preg_match('/(kierownik|dyrektor|prezes|wiceprezes|cz[łl]onek zarz|prokurent|w[łl]a[śs]ciciel|specjalist|koordynator|asystent|handlowiec|doradc|in[żz]ynier|konstruktor|technolog|przedstawiciel|opiekun|konsultant|ksi[ęe]gow|sekretar|referent|mened[żz]er|menad[żz]er|kontroler|mistrz|brygadzista|rzecznik|rzeczniczka|manager|director|head of|\blead\b|\bengineer\b|specialist|coordinator|assistant|officer|executive|representative|owner|founder|\bceo\b|\bcfo\b|\bcoo\b|\bcto\b|\bcmo\b)/iu', $text)
			? ['role' => null, 'text' => mb_substr($text, 0, 200), 'generic' => true]
			: null;
	}

	/**
	 * Wspólny element HTML z JEDNĄ osobą (karta, wiersz tabeli, akapit).
	 *
	 * @param array<string, mixed> $ph
	 * @param list<array<string, mixed>> $persons
	 * @param list<array<string, mixed>> $roles
	 * @param list<array<string, mixed>> $phones
	 * @return array<string, mixed>|null
	 */
	private function blockLink(array $ph, array $persons, array $roles, array $phones, bool $chromeMode = false): ?array
	{
		$el = $ph['el'];

		for ($depth = 0; $depth < self::MAX_DEPTH && $el instanceof DOMElement && ! in_array(strtolower($el->tagName), ['body', 'html', 'main'], true); $depth++, $el = $el->parentNode) {
			$len = $this->len($el);

			if ($len > self::MAX_BLOCK_TEXT) {
				return null;
			}

			$inside = array_values(array_filter($persons, fn ($p) => $p['chrome'] === $chromeMode && $this->within($p['el'], $el)));
			$keys = array_unique(array_map(fn ($p) => $p['key'], $inside));

			if ($keys === []) {
				continue;
			}

			if (count($keys) > 1) {
				return null; // kilka osób w jednym bloku — rozstrzyga kolejność linii (sequenceLink)
			}

			$person = $inside[0];
			$role = $person['role'] ?? (array_values(array_filter($roles, fn ($r) => $this->within($r['el'], $el)))[0]['role'] ?? null);
			// Numery w bloku, które MOGĄ należeć do osoby (bez opisanych jako centrala / dział / oddział i numerów firmy).
			$numbers = array_unique(array_map(fn ($p) => $p['e164'], array_filter($phones, fn ($p) => $this->within($p['el'], $el)
				&& ! in_array($p['e164'], $this->known, true)
				&& ! in_array(CompanyPhoneExtractor::labelKind($p['near']), ['main', 'department', 'branch'], true))));
			$tag = strtolower($el->tagName);
			$status = $role ? self::VERIFIED : self::LIKELY;
			$evidence = $role ? ($tag === 'tr' ? 'TABLE_ROW' : 'SAME_BLOCK') : 'SAME_BLOCK_NO_TITLE';

			if (count($numbers) > 3) {
				[$status, $evidence] = [self::UNCERTAIN, 'MANY_NUMBERS'];
			} elseif (preg_match('/\b(nip|regon|krs|sp\.\s?z\s?o\.?\s?o|s\.a\.|ul\.)|\b\d{2}-\d{3}\b/iu', $this->clean($el->textContent))) {
				// Blok danych firmy (adres, NIP, forma prawna) z nazwiskiem — numer może być numerem firmy.
				[$status, $evidence] = [self::UNCERTAIN, 'COMPANY_BLOCK'];
			} elseif ($len > 400 || $ph['i'] < $person['i']) {
				// Duży blok albo numer PRZED nazwiskiem — słabsza struktura.
				$status = $status === self::VERIFIED ? self::LIKELY : self::UNCERTAIN;
			}

			return ['person' => $person['key'], 'status' => $status, 'evidence' => $evidence, 'role' => $role, 'container' => $el, 'excerpt' => $this->clean($el->textContent)];
		}

		return null;
	}

	/**
	 * Lista płaska: najbliższa osoba PRZED numerem, bez innej osoby pomiędzy, maks. 6 linii; numer przed następną osobą.
	 *
	 * @param array<string, mixed> $ph
	 * @param list<array<string, mixed>> $persons
	 * @param list<array<string, mixed>> $roles
	 * @param list<array<string, mixed>> $texts
	 * @return array<string, mixed>|null
	 */
	private function sequenceLink(array $ph, int $pi, array $persons, array $roles, array $texts): ?array
	{
		$i = (int) floor($ph['i']);
		$prev = null;

		foreach ($persons as $p) {
			if (! $p['chrome'] && $p['i'] <= $i) {
				$prev = $p;
			}
		}

		if ($prev === null || $i - $prev['i'] > 6) {
			return null;
		}

		$role = $prev['role'] ?? (array_values(array_filter($roles, fn ($r) => $r['i'] > $prev['i'] && $r['i'] <= $i))[0]['role'] ?? null);
		$excerpt = implode(' · ', array_map(fn ($t) => $t['text'], array_slice($texts, $prev['i'], $i - $prev['i'] + 1)));

		return [
			'person' => $prev['key'],
			'status' => $role ? self::LIKELY : self::UNCERTAIN,
			'evidence' => $role ? 'SEQUENCE' : 'NEARBY',
			'role' => $role,
			'container' => null,
			'excerpt' => $excerpt,
		];
	}

	/**
	 * @param array<string, mixed> $ph
	 * @param list<array<string, mixed>> $persons
	 * @param list<array<string, mixed>> $roles
	 * @return array<string, mixed>
	 */
	private function personPageLink(array $ph, array $persons, array $roles, string $key): array
	{
		$person = array_values(array_filter($persons, fn ($p) => $p['key'] === $key))[0];
		$role = $person['role'] ?? ($roles[0]['role'] ?? null);

		return ['person' => $key, 'status' => $role ? self::VERIFIED : self::LIKELY, 'evidence' => 'PERSON_PAGE', 'role' => $role, 'container' => null, 'excerpt' => $person['name']->full() . ($role ? ' — ' . $role['text'] : '') . ' · ' . $ph['raw']];
	}

	/**
	 * Ten sam numer także poza blokiem osoby (i poza stopką) — np. drugi raz w ogólnym bloku kontaktowym.
	 *
	 * @param array<string, mixed> $ph
	 * @param list<array<string, mixed>> $phones
	 */
	private function occursOutside(array $ph, array $phones, ?DOMNode $container): bool
	{
		foreach ($phones as $other) {
			if ($other['e164'] !== $ph['e164'] || $other['chrome'] || $other['el'] === $ph['el']) {
				continue;
			}

			if ($container === null ? abs($other['i'] - $ph['i']) > 6 : ! $this->within($other['el'], $container)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stanowisko w sąsiedniej linii (do 2 przed lub 3 po), bez innej osoby pomiędzy.
	 *
	 * @param array<string, mixed> $p
	 * @param list<array<string, mixed>> $persons
	 * @param list<array<string, mixed>> $roles
	 * @return array<string, mixed>|null
	 */
	private function nearestRole(array $p, array $persons, array $roles): ?array
	{
		$best = null;

		foreach ($roles as $r) {
			$d = $r['i'] - $p['i'];

			if ($d < -2 || $d > 3 || $d === 0) {
				continue;
			}

			[$lo, $hi] = $d > 0 ? [$p['i'], $r['i']] : [$r['i'], $p['i']];
			$between = array_filter($persons, fn ($o) => $o['i'] > $lo && $o['i'] < $hi && $o['key'] !== $p['key']);

			if ($between === [] && $this->sameBlock($p, $r['el'], $persons) && ($best === null || abs($d) < abs($best['d']))) {
				$best = ['d' => $d, 'role' => $r['role']];
			}
		}

		return $best['role'] ?? null;
	}

	/**
	 * Osoba i stanowisko w jednym małym elemencie bez innej osoby (nie łączymy stanowiska z sąsiedniego wiersza tabeli).
	 *
	 * @param array<string, mixed> $p
	 * @param list<array<string, mixed>> $persons
	 */
	private function sameBlock(array $p, ?DOMNode $roleEl, array $persons): bool
	{
		for ($el = $p['el']; $el instanceof DOMElement && ! in_array(strtolower($el->tagName), ['body', 'html'], true); $el = $el->parentNode) {
			if ($this->len($el) > self::MAX_BLOCK_TEXT) {
				return false;
			}

			if ($this->within($roleEl, $el)) {
				return count(array_unique(array_map(fn ($o) => $o['key'], array_filter($persons, fn ($o) => $this->within($o['el'], $el))))) === 1;
			}
		}

		return false;
	}

	/**
	 * Etykieta TUŻ przy numerze: tekst przed numerem w tym samym węźle + poprzedni węzeł tylko, gdy jest etykietą
	 * („Sekretariat:”, „tel.”) — nie stanowisko z sąsiedniej komórki tabeli („Kierownik Magazynu”).
	 *
	 * @param list<array<string, mixed>> $texts
	 */
	private function nearLabel(int $i, array $texts, string $inNode, bool $afterNode): string
	{
		$prev = $afterNode ? ($texts[$i]['text'] ?? '') : ($i > 0 ? $texts[$i - 1]['text'] : '');
		$prev = preg_match('/:\s*$/u', $prev) || mb_strlen($prev) <= 12 ? $prev : '';

		return trim(CompanyPhoneExtractor::cutLabel(mb_substr($prev, -60) . ' ' . $inNode));
	}

	/**
	 * @param list<array<string, mixed>> $texts
	 */
	private function labelBefore(int $i, array $texts, string $inNode): string
	{
		$prev = $i > 0 ? $texts[$i - 1]['text'] : '';

		return CompanyPhoneExtractor::cutLabel(mb_substr($prev, -80) . ' ' . $inNode);
	}

	/**
	 * @param list<array<string, mixed>> $texts
	 */
	private function excerptAround(int $i, array $texts): string
	{
		return implode(' · ', array_map(fn ($t) => $t['text'], array_slice($texts, $i, 3)));
	}

	/**
	 * Stopka / nagłówek / nawigacja strony — numery i nazwiska stamtąd są ogólne, nie z bloku osoby.
	 */
	private function chrome(DOMNode $node): bool
	{
		for ($n = $node; $n instanceof DOMElement; $n = $n->parentNode) {
			$tag = strtolower($n->tagName);
			$marker = strtolower($n->getAttribute('id') . ' ' . $n->getAttribute('class') . ' ' . $n->getAttribute('role'));

			if (in_array($tag, ['footer', 'header', 'nav'], true) || preg_match('/(^|[\s_-])(footer|stopka|site-header|page-header|topbar|top-bar|navbar|contentinfo|banner)([\s_-]|$)/', $marker)) {
				return true;
			}
		}

		return false;
	}

	private function within(?DOMNode $node, DOMNode $container): bool
	{
		for ($n = $node; $n !== null; $n = $n->parentNode) {
			if ($n === $container) {
				return true;
			}
		}

		return false;
	}

	private function insideTelLink(DOMNode $node): bool
	{
		for ($n = $node->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
			if (strtolower($n->tagName) === 'a' && stripos(trim($n->getAttribute('href')), 'tel:') === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array<string, mixed>> $texts
	 * @param \SplObjectStorage<DOMNode, int> $index
	 */
	private function indexBefore(DOMNode $el, array $texts, \SplObjectStorage $index): float
	{
		$xp = new DOMXPath($el->ownerDocument);

		foreach ($xp->query('(preceding::text()[normalize-space()])[last()]', $el) ?: [] as $t) {
			return isset($index[$t]) ? $index[$t] + 0.5 : 0.0;
		}

		return 0.0;
	}

	private function len(DOMNode $el): int
	{
		if (! isset($this->textLen[$el])) {
			$this->textLen[$el] = mb_strlen($this->clean($el->textContent));
		}

		return $this->textLen[$el];
	}

	private function clean(string $text): string
	{
		return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
	}
}
