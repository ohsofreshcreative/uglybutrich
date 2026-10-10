<?php

namespace App\Ubr\Domain\Lead;

/**
 * Reguły Insight Engine: które findingi Website Analyzera składają się na jeden wniosek (grupa = jedna przyczyna),
 * do jakiej z 7 kategorii należą, jak opisać wniosek i jaki argument sprzedażowy z niego wynika.
 *
 * Zasady:
 * - Wniosek powstaje WYŁĄCZNIE z findingów analizy (każdy finding ma pomiar i dowód). Brak findingu = brak wniosku.
 * - Kilka findingów tej samej przyczyny (np. statyczna kontrola etykiet + axe „label”) = jedna przyczyna (cause).
 * - Język ostrożny: „może utrudniać”, nigdy „firma traci klientów”. Bez twierdzeń o wyglądzie (AI Vision wyłączone).
 */
final class InsightRuleRegistry
{
	public const CATEGORIES = [
		'performance' => 'Wydajność',
		'mobile' => 'Mobile',
		'accessibility' => 'Dostępność',
		'ux' => 'UX / użyteczność',
		'content_structure' => 'Struktura treści',
		'technical' => 'Jakość techniczna',
		'conversion' => 'Konwersja',
	];

	/** Kategorie „rdzenia” redesignu (doświadczenie użytkownika) — wymagane do oceny STRONG. */
	public const CORE = ['mobile', 'ux', 'content_structure', 'conversion'];

	/**
	 * Grupy (jedna przyczyna = jeden wniosek). `findings` = klucze findingów (prefiks z `*`), `confidence` = wiarygodność pomiaru.
	 */
	public const GROUPS = [
		'perf_load' => [
			'category' => 'performance', 'findings' => ['perf_slow', 'perf_heavy'], 'confidence' => 'medium',
			'title' => 'Wolne lub ciężkie ładowanie strony',
			'description' => 'Pomiar laboratoryjny wykazał długie ładowanie lub duży transfer strony głównej. Problemy z wydajnością mogą utrudniać korzystanie ze strony, zwłaszcza na słabszych łączach.',
			'recommendation' => 'Optymalizacja obrazów i skryptów, przegląd hostingu i cache.',
			'angle' => 'Możliwość przyspieszenia strony i poprawy komfortu korzystania z niej (także na telefonach).',
		],
		'mobile_responsive' => [
			'category' => 'mobile', 'findings' => ['mobile_no_viewport', 'mobile_fixed_viewport', 'mobile_scaled_layout', 'mobile_overflow'], 'confidence' => 'high',
			'title' => 'Układ strony nie dopasowuje się do telefonów',
			'description' => 'Analiza wykazała brak lub nieprawidłową konfigurację widoku mobilnego (viewport, przewijanie w poziomie lub pomniejszony układ). Korzystanie ze strony na telefonie może być utrudnione.',
			'recommendation' => 'Responsywny układ projektowany od wersji mobilnej.',
			'angle' => 'Poprawa doświadczenia mobilnego — strona dopasowana do ekranów telefonów.',
		],
		'mobile_readability' => [
			'category' => 'mobile', 'findings' => ['mobile_small_text', 'mobile_tap_targets'], 'confidence' => 'high',
			'title' => 'Elementy trudne w obsłudze na telefonie',
			'description' => 'W renderze mobilnym wykryto za mały tekst lub zbyt małe elementy klikalne. Elementy interfejsu mogą być trudne w obsłudze na telefonach.',
			'recommendation' => 'Większe cele dotykowe i czytelna typografia mobilna.',
			'angle' => 'Wygodniejsza obsługa strony na telefonach (czytelny tekst, wygodne przyciski).',
		],
		'accessibility' => [
			'category' => 'accessibility', 'findings' => ['a11y_*', 'axe_*', 'mobile_zoom_blocked'], 'confidence' => 'high',
			'title' => 'Problemy z dostępnością',
			'description' => 'Automatyczne testy dostępności (kontrola HTML i axe-core) wykryły problemy, np. z etykietami formularzy, opisami obrazów lub kontrastem. Mogą one utrudniać korzystanie ze strony części użytkowników.',
			'recommendation' => 'Poprawa zgodności z WCAG (etykiety, teksty alternatywne, kontrast).',
			'angle' => 'Poprawa dostępności strony (zgodność z dobrymi praktykami WCAG).',
		],
		'ux_navigation' => [
			'category' => 'ux', 'findings' => ['ux_no_nav', 'ux_nav_cluttered', 'ux_nav_missing_sections'], 'confidence' => 'medium',
			'title' => 'Nawigacja wymaga uporządkowania',
			'description' => 'Analiza struktury wykazała brak nawigacji, zbyt rozbudowane menu lub brak typowych sekcji. Odnalezienie informacji może być utrudnione.',
			'recommendation' => 'Uproszczenie architektury informacji i menu.',
			'angle' => 'Uproszczenie nawigacji i łatwiejsze dotarcie do kluczowych informacji.',
		],
		'content_structure' => [
			'category' => 'content_structure', 'findings' => ['ux_no_h1', 'ux_wall_of_text', 'content_no_offer'], 'confidence' => 'medium',
			'title' => 'Struktura treści wymaga uporządkowania',
			'description' => 'Wykryto braki w strukturze treści (np. brak nagłówka głównego, trudne do przeskanowania bloki tekstu lub brak czytelnej prezentacji oferty).',
			'recommendation' => 'Uporządkowanie treści i czytelna prezentacja oferty.',
			'angle' => 'Uporządkowanie struktury treści i lepsza prezentacja oferty.',
		],
		'content_outdated' => [
			'category' => 'content_structure', 'findings' => ['content_outdated_year'], 'confidence' => 'medium',
			'title' => 'Sygnały nieaktualnej treści',
			'description' => 'Na stronie wykryto daty sugerujące, że treść nie była aktualizowana od dłuższego czasu (np. stopka z dawnym rokiem).',
			'recommendation' => 'Aktualizacja treści i sprawdzenie, czy serwis jest rozwijany.',
			'angle' => 'Odświeżenie i aktualizacja treści strony.',
		],
		'seo_meta' => [
			'category' => 'content_structure', 'findings' => ['seo_no_title', 'seo_no_description', 'seo_title_length'], 'confidence' => 'high',
			'title' => 'Braki w metadanych strony',
			'description' => 'Brakuje lub są nieprawidłowe podstawowe metadane (tytuł, opis), co może ograniczać czytelność wyników wyszukiwania.',
			'recommendation' => 'Uzupełnienie tytułów i opisów stron.',
			'angle' => 'Uporządkowanie metadanych i podstaw SEO przy okazji przebudowy.',
		],
		'tech_security' => [
			'category' => 'technical', 'findings' => ['sec_no_https', 'sec_mixed_content'], 'confidence' => 'high',
			'title' => 'Braki w zabezpieczeniu połączenia (HTTPS)',
			'description' => 'Strona działa bez HTTPS lub ładuje część zasobów przez niezabezpieczone połączenie. Przeglądarki mogą oznaczać ją jako niezabezpieczoną.',
			'recommendation' => 'Wdrożenie HTTPS dla całego serwisu.',
			'angle' => 'Bezpieczne połączenie (HTTPS) i brak ostrzeżeń przeglądarki.',
		],
		'tech_quality' => [
			'category' => 'technical', 'findings' => ['sec_old_jquery', 'perf_broken_resources', 'seo_noindex', 'site_unreachable'], 'confidence' => 'high',
			'title' => 'Problemy techniczne serwisu',
			'description' => 'Wykryto problemy techniczne (np. przestarzałe biblioteki, niedziałające zasoby lub blokadę indeksowania).',
			'recommendation' => 'Przegląd techniczny i aktualizacja zaplecza strony.',
			'angle' => 'Uporządkowanie zaplecza technicznego i aktualizacja technologii.',
		],
		'conversion' => [
			'category' => 'conversion', 'findings' => ['conv_no_cta', 'conv_no_cta_fold', 'conv_contact_hard', 'content_no_trust'], 'confidence' => 'medium',
			'title' => 'Utrudniony kontakt lub brak wezwań do działania',
			'description' => 'Analiza wykazała brak wyraźnego wezwania do działania (np. w pierwszym ekranie), trudno dostępny kontakt lub brak elementów budujących zaufanie. Może to utrudniać nawiązanie kontaktu przez odwiedzających.',
			'recommendation' => 'Widoczne CTA i łatwy kontakt na kluczowych podstronach.',
			'angle' => 'Ułatwienie kontaktu i wyraźniejsze wezwania do działania.',
		],
	];

	/** Ta sama przyczyna mierzona dwiema metodami (kontrola HTML + axe-core) liczy się raz. */
	public const CAUSES = [
		'a11y_labels' => 'form_labels', 'axe_label' => 'form_labels',
		'a11y_alt' => 'image_alt', 'axe_image-alt' => 'image_alt',
		'mobile_zoom_blocked' => 'zoom', 'axe_meta-viewport' => 'zoom',
		'perf_slow' => 'load', 'perf_heavy' => 'load',
	];

	/** Findingi pomijane przez Insight Engine (to nie są stwierdzone problemy strony). */
	public const IGNORED_SOURCES = ['manual_review'];

	public const IGNORED_CATEGORIES = ['business', 'company', 'design'];

	public static function groupFor(string $findingKey): ?string
	{
		foreach (self::GROUPS as $group => $def) {
			foreach ($def['findings'] as $pattern) {
				if ($pattern === $findingKey || (str_ends_with($pattern, '*') && str_starts_with($findingKey, rtrim($pattern, '*')))) {
					return $group;
				}
			}
		}

		return null;
	}

	public static function cause(string $findingKey): string
	{
		return self::CAUSES[$findingKey] ?? $findingKey;
	}
}
