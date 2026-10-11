<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Normalizacja stanowisk (PL / EN) do ról istotnych dla zakupu strony WWW.
 *
 * Rozpoznajemy tylko role związane z decyzją: właściciel, zarząd, dyrekcja, marketing / digital, sprzedaż.
 * Stanowiska niezwiązane z decyzją (np. kierownik magazynu, przedstawiciel handlowy) nie tworzą kandydatów.
 * Funkcja z rejestru („członek zarządu”) jest zachowywana dosłownie — nie zamieniamy jej na „prezes” ani „CEO”.
 */
final class RoleCatalog
{
	public const ROLES = [
		'owner' => ['label' => 'Właściciel', 'department' => 'management', 'seniority' => 'owner', 'group' => 'leadership', 'board' => false],
		'founder' => ['label' => 'Założyciel', 'department' => 'management', 'seniority' => 'owner', 'group' => 'leadership', 'board' => false],
		'ceo' => ['label' => 'Prezes zarządu / CEO', 'department' => 'management', 'seniority' => 'c_level', 'group' => 'leadership', 'board' => true],
		'managing_director' => ['label' => 'Dyrektor generalny / zarządzający', 'department' => 'management', 'seniority' => 'c_level', 'group' => 'leadership', 'board' => false],
		'vice_president' => ['label' => 'Wiceprezes zarządu', 'department' => 'management', 'seniority' => 'board', 'group' => 'board', 'board' => true],
		'board_member' => ['label' => 'Członek zarządu', 'department' => 'management', 'seniority' => 'board', 'group' => 'board', 'board' => true],
		'proxy' => ['label' => 'Prokurent', 'department' => 'management', 'seniority' => 'board', 'group' => 'board', 'board' => true],
		'marketing_director' => ['label' => 'Dyrektor marketingu', 'department' => 'marketing', 'seniority' => 'director', 'group' => 'marketing_lead', 'board' => false],
		'digital_head' => ['label' => 'Head of Digital / E-commerce', 'department' => 'marketing', 'seniority' => 'head', 'group' => 'marketing_lead', 'board' => false],
		'marketing_manager' => ['label' => 'Marketing Manager', 'department' => 'marketing', 'seniority' => 'manager', 'group' => 'marketing', 'board' => false],
		'marketing_specialist' => ['label' => 'Specjalista ds. marketingu', 'department' => 'marketing', 'seniority' => 'specialist', 'group' => 'marketing', 'board' => false],
		'sales_director' => ['label' => 'Dyrektor handlowy / sprzedaży', 'department' => 'sales', 'seniority' => 'director', 'group' => 'other', 'board' => false],
		'other_director' => ['label' => 'Dyrektor (inny obszar)', 'department' => 'other', 'seniority' => 'director', 'group' => 'other', 'board' => false],
	];

	/**
	 * Wzorce na tekście bez diakrytyków, małymi literami. Kolejność = priorytet (pierwsze dopasowanie wygrywa
	 * jako rola główna; „wiceprezes” musi być przed „prezes”).
	 *
	 * @var array<string, string>
	 */
	private const PATTERNS = [
		'vice_president' => '/\b(wiceprezes\w*|v-?ce ?prezes\w*|vice[ -]president|deputy ceo|zastepca prezesa)\b/u',
		'ceo' => '/\b(prezes\w*|ceo|chief executive( officer)?|president)\b/u',
		'owner' => '/\b(wlasciciel\w*|wspolwlasciciel\w*|owner|co-owner|inhaber)\b/u',
		'founder' => '/\b(zalozyciel\w*|wspolzalozyciel\w*|founder|co-?founder|grunder|gruender)\b/u',
		'managing_director' => '/\b(dyrektor\w* (generaln\w*|zarzadzaj\w*|naczeln\w*)|managing director|general manager|country manager|geschaftsfuhrer|geschaeftsfuehrer)\b/u',
		'marketing_director' => '/\b(dyrektor\w* (ds\.? )?(marketing\w*|komunikacji|marki)|marketing director|director of marketing|head of marketing|chief marketing( officer)?|cmo|vp,? marketing|vice president,? marketing|szef\w* marketingu|marketing lead|head of brand|brand director)\b/u',
		'digital_head' => '/\b(head of (digital|e-?commerce|online)|digital director|director of digital|chief digital( officer)?|cdo|e-?commerce director|dyrektor\w* (ds\.? )?(digital|e-?commerce|cyfryzacji|sprzedazy internetowej))\b/u',
		'marketing_manager' => '/\b(marketing manager|kierownik\w* (ds\.? )?(dzialu )?marketingu|manager\w* (ds\.? )?marketingu|menedzer\w* (ds\.? )?marketingu|digital marketing manager|e-?commerce manager|kierownik\w* (ds\.? )?e-?commerce|manager\w* (ds\.? )?e-?commerce|brand manager|communications? manager|pr manager|kierownik\w* (ds\.? )?komunikacji|manager\w* ds\.? komunikacji|head of communications?|head of pr|website manager|web manager)\b/u',
		'marketing_specialist' => '/\b(specjalist\w* (ds\.? )?(marketingu|komunikacji|e-?commerce|social media|pr)|marketing (specialist|coordinator|executive)|koordynator\w* (ds\.? )?marketingu|content (manager|specialist)|social media (manager|specialist))\b/u',
		'board_member' => '/\b(czlon\w* zarzadu|czlonkini zarzadu|board member|member of the (management )?board|management board member|vorstand)\b/u',
		'proxy' => '/\b(prokurent\w*|prokura)\b/u',
		'sales_director' => '/\b(dyrektor\w* (ds\.? )?(handlow\w*|sprzedazy|eksportu|komercyjn\w*)|sales director|director of sales|head of sales|chief commercial( officer)?|commercial director|cco|vp,? sales)\b/u',
		'other_director' => '/\b(dyrektor\w* (ds\.? )?(finansow\w*|operacyjn\w*|produkcji|techniczn\w*|it|personaln\w*|hr|zakupow|logistyki|jakosci|rozwoju|administracyjn\w*)|cfo|coo|cto|cio|chief (financial|operating|technology|information) officer|finance director|operations director|technical director)\b/u',
	];

	/** Role, których nie traktujemy jako decydentów (rada nadzorcza, pełnomocnik ds. jakości itp.). */
	private const EXCLUDE = '/\b(rad\w* nadzorcz\w*|supervisory board|przewodnicz\w* rady|komitet\w* audytu|asystent\w*|assistant|pelnomocnik\w*|przedstawiciel\w* handlow\w*|doradc\w*|konsultant\w*|kierownik\w* (magazynu|produkcji|zmiany|budowy|serwisu|projektu)|inspektor\w*|recepcj\w*|sekretar\w*|stazyst\w*|intern)\b/u';

	/**
	 * @return array{role: string, label: string, department: string, seniority: string, group: string, board: bool, marketing_focus: bool, text: string}|null
	 */
	public static function match(string $title): ?array
	{
		$text = self::normalize($title);

		if ($text === '' || mb_strlen($text) > 160) {
			return null;
		}

		if (preg_match(self::EXCLUDE, $text)) {
			return null;
		}

		$matched = [];

		foreach (self::PATTERNS as $role => $pattern) {
			if (preg_match($pattern, $text)) {
				$matched[] = $role;
			}
		}

		if ($matched === []) {
			return null;
		}

		// „Vice President” zawiera „President” — wiceprezes wyklucza prezesa.
		if (in_array('vice_president', $matched, true)) {
			$matched = array_values(array_diff($matched, ['ceo']));
		}

		// Rola główna = najwyższa decyzyjność wśród dopasowań (np. „Członek Zarządu, Dyrektor Marketingu” → członek zarządu z fokusem marketingowym).
		$priority = ['owner', 'founder', 'ceo', 'managing_director', 'vice_president', 'board_member', 'marketing_director', 'digital_head', 'proxy', 'marketing_manager', 'sales_director', 'other_director', 'marketing_specialist'];
		usort($matched, fn ($a, $b) => array_search($a, $priority, true) <=> array_search($b, $priority, true));
		$role = $matched[0];

		// „Prezes” w kontekście rady nadzorczej / stowarzyszenia itp. jest odfiltrowany przez EXCLUDE; „president of the board” = prezes.
		$marketing = (bool) preg_match('/(marketing\w*|komunikacj\w*|digital|e-?commerce|marki|brand|pr\b|handl\w*|sprzedaz\w*|sales|commercial)/u', $text)
			&& in_array($role, ['ceo', 'vice_president', 'board_member', 'managing_director', 'proxy'], true);

		return ['role' => $role, 'text' => trim($title)] + self::ROLES[$role] + ['marketing_focus' => $marketing || in_array($role, ['marketing_director', 'digital_head', 'marketing_manager', 'marketing_specialist'], true)];
	}

	public static function label(string $role): string
	{
		return self::ROLES[$role]['label'] ?? 'Inne stanowisko';
	}

	public static function group(string $role): string
	{
		return self::ROLES[$role]['group'] ?? 'other';
	}

	public static function isBoard(string $role): bool
	{
		return (bool) (self::ROLES[$role]['board'] ?? false);
	}

	/**
	 * Funkcja w organie z KRS → rola („PREZES ZARZĄDU” → ceo, „WICEPREZES ZARZĄDU” → vice_president, „CZŁONEK ZARZĄDU” → board_member).
	 */
	public static function fromRegistry(string $function): string
	{
		$m = self::match($function);

		return $m['role'] ?? 'board_member';
	}

	public static function normalize(string $title): string
	{
		$t = PersonName::fold(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$t = str_replace(['&', '/', '|', '–', '—'], [' i ', ' / ', ' ', ' ', ' '], $t);

		return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
	}
}
