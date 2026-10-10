<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Mobile: meta viewport (HTML) + pomiary w przeglądarce 390 px (układ desktopowy pomniejszony, poziomy overflow,
 * małe cele dotykowe < 24 px, tekst < 12 px, nawigacja mobilna).
 */
final class MobileAnalyzer
{
	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		$criteria = [];
		$findings = [];
		$checks = [];

		if ($ctx->home) {
			$viewport = $ctx->home->meta('viewport');
			$checks['meta_viewport'] = $viewport;

			if ($viewport === null || $viewport === '') {
				$criteria['a_mobile_viewport'] = Criterion::measured(3, 3, 'Brak meta viewport.', ['<meta name="viewport"> nie istnieje']);
				$findings[] = Finding::make('mobile_no_viewport', 'mobile', 'high', 'Strona nie jest przystosowana do telefonów', 'Brak znacznika meta viewport — telefony wyświetlają pomniejszoną wersję desktopową.', 'meta viewport: brak', 'Wdrożyć responsywny układ (RWD) z <meta name="viewport" content="width=device-width, initial-scale=1">.');
			} elseif (! str_contains(strtolower($viewport), 'device-width')) {
				$criteria['a_mobile_viewport'] = Criterion::measured(2, 3, 'Viewport o stałej szerokości.', ['viewport: ' . $viewport]);
				$findings[] = Finding::make('mobile_fixed_viewport', 'mobile', 'medium', 'Stała szerokość viewportu', 'Meta viewport nie używa device-width — układ nie dopasowuje się do ekranu.', 'viewport: ' . $viewport, 'Ustawić width=device-width i responsywny układ.');
			} elseif (preg_match('/(user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?\b)/i', $viewport)) {
				$criteria['a_mobile_viewport'] = Criterion::measured(1, 3, 'Viewport blokuje powiększanie.', ['viewport: ' . $viewport]);
				$findings[] = Finding::make('mobile_zoom_blocked', 'accessibility', 'low', 'Zablokowane powiększanie na telefonie', 'Viewport uniemożliwia powiększanie strony gestem (problem dostępności).', 'viewport: ' . $viewport, 'Usunąć user-scalable=no / maximum-scale=1.');
			} else {
				$criteria['a_mobile_viewport'] = Criterion::measured(0, 3, 'Poprawny meta viewport.', ['viewport: ' . $viewport]);
			}
		} else {
			$criteria['a_mobile_viewport'] = Criterion::notEvaluated(3, 'Brak HTML strony głównej.');
		}

		$m = $ctx->view('mobile');

		if (! $m) {
			$criteria['a_mobile_render'] = Criterion::notEvaluated(7, 'Brak renderu mobilnego (przeglądarka niedostępna lub błąd) — overflow, cele dotykowe i czytelność niezmierzone.');

			return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
		}

		$points = 0;
		$evidence = [];
		$checks['mobile'] = array_intersect_key($m, array_flip(['viewport', 'screen_width', 'scaled_desktop_layout', 'horizontal_overflow_px', 'small_tap_targets', 'interactive_count', 'small_text_elements', 'text_elements', 'hamburger_visible', 'nav_links_visible']));

		if (! empty($m['scaled_desktop_layout'])) {
			$points += 4;
			$evidence[] = sprintf('Układ %d px pomniejszony na ekranie %d px', $m['viewport']['width'] ?? 0, $m['screen_width'] ?? 390);
			$findings[] = Finding::make('mobile_scaled_layout', 'mobile', 'high', 'Na telefonie wyświetla się pomniejszona wersja desktopowa', 'W widoku 390 px przeglądarka renderuje układ szerokości ' . ($m['viewport']['width'] ?? '?') . ' px i go pomniejsza — tekst i przyciski są bardzo małe.', $evidence[array_key_last($evidence)], 'Przebudować stronę responsywnie (mobile-first).');
		} elseif (($m['horizontal_overflow_px'] ?? 0) > 20) {
			$points += 3;
			$evidence[] = 'Poziomy overflow: ' . $m['horizontal_overflow_px'] . ' px';
			$findings[] = Finding::make('mobile_overflow', 'mobile', 'high', 'Strona ma poziomy overflow na telefonie', 'Zawartość wychodzi poza ekran o ' . $m['horizontal_overflow_px'] . ' px — pojawia się przewijanie w bok.', $evidence[array_key_last($evidence)], 'Poprawić elementy o stałej szerokości (tabele, obrazy, kontenery).');
		}

		$interactive = max(1, (int) ($m['interactive_count'] ?? 0));
		$smallRatio = (int) ($m['small_tap_targets'] ?? 0) / $interactive;

		if (empty($m['scaled_desktop_layout'])) {
			if ($smallRatio > 0.3) {
				$points += 2;
			} elseif ($smallRatio > 0.1) {
				$points += 1;
			}

			if ($smallRatio > 0.1) {
				$evidence[] = sprintf('Małe cele dotykowe (< 24 px): %d z %d', $m['small_tap_targets'], $interactive);
				$findings[] = Finding::make('mobile_tap_targets', 'mobile', $smallRatio > 0.3 ? 'medium' : 'low', 'Za małe elementy klikalne na telefonie', sprintf('%d z %d linków/przycisków ma mniej niż 24×24 px (minimum WCAG 2.2 AA).', $m['small_tap_targets'], $interactive), $evidence[array_key_last($evidence)], 'Powiększyć obszary klikalne linków i przycisków (min. 24 px, zalecane 44 px).');
			}
		}

		$textEls = max(1, (int) ($m['text_elements'] ?? 0));

		if ((int) ($m['small_text_elements'] ?? 0) / $textEls > 0.15) {
			$points += 1;
			$evidence[] = sprintf('Tekst < 12 px: %d z %d elementów', $m['small_text_elements'], $textEls);
			$findings[] = Finding::make('mobile_small_text', 'mobile', 'medium', 'Mały, trudno czytelny tekst na telefonie', sprintf('%d z %d elementów tekstowych ma czcionkę mniejszą niż 12 px.', $m['small_text_elements'], $textEls), $evidence[array_key_last($evidence)], 'Zwiększyć bazowy rozmiar czcionki (min. 16 px dla treści).');
		}

		$criteria['a_mobile_render'] = Criterion::measured($points, 7, 'Pomiary w przeglądarce, ekran 390 px.', $evidence);

		return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
	}
}
