<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Dostępność: reguły statyczne (alt, etykiety pól, lang) + axe-core w przeglądarce (WCAG 2 A/AA, w tym kontrast).
 * Testy automatyczne wykrywają tylko część problemów — NIE stwierdzamy zgodności z WCAG.
 */
final class AccessibilityAnalyzer
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
			$images = $ctx->home->images();
			$noAlt = array_values(array_filter($images, fn ($i) => $i['alt'] === null));
			$fields = $ctx->home->formFields();
			$checks += ['images' => count($images), 'images_without_alt' => count($noAlt), 'form_fields' => $fields['total'], 'unlabeled_fields' => $fields['unlabeled'], 'lang' => $ctx->home->lang()];
			$points = 0;
			$evidence = [];

			if (count($images) >= 3 && count($noAlt) / count($images) > 0.2) {
				$points++;
				$evidence[] = sprintf('Obrazy bez atrybutu alt: %d z %d', count($noAlt), count($images));
				$findings[] = Finding::make('a11y_alt', 'accessibility', 'low', 'Obrazy bez tekstu alternatywnego', sprintf('%d z %d obrazów nie ma atrybutu alt.', count($noAlt), count($images)), array_merge([$evidence[array_key_last($evidence)]], array_map(fn ($i) => mb_substr((string) $i['src'], 0, 120), array_slice($noAlt, 0, 3))), 'Dodać opisowe alt do obrazów treści (dekoracyjne: alt="").');
			}

			if ($fields['unlabeled'] > 0) {
				$points++;
				$evidence[] = sprintf('Pola formularza bez etykiety: %d z %d', $fields['unlabeled'], $fields['total']);
				$findings[] = Finding::make('a11y_labels', 'accessibility', 'medium', 'Formularz nie ma etykiet pól', sprintf('%d pól formularza nie ma powiązanej etykiety (label/aria-label).', $fields['unlabeled']), $evidence[array_key_last($evidence)], 'Dodać etykiety <label for> do każdego pola.');
			}

			if (! $ctx->home->lang()) {
				$evidence[] = 'Brak atrybutu lang w <html>';
			}

			$criteria['b_a11y_static'] = Criterion::measured($points, 2, 'Reguły statyczne (alt, etykiety, lang).', $evidence);
		} else {
			$criteria['b_a11y_static'] = Criterion::notEvaluated(2, 'Brak HTML.');
		}

		$axe = $ctx->capture['axe'] ?? null;

		if (is_array($axe) && isset($axe['violations'])) {
			$byImpact = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0];

			foreach ($axe['violations'] as $v) {
				$byImpact[$v['impact'] ?? 'minor'] = ($byImpact[$v['impact'] ?? 'minor'] ?? 0) + 1;
			}

			$checks['axe'] = ['version' => $axe['version'] ?? null, 'by_impact' => $byImpact, 'rules' => array_map(fn ($v) => $v['id'], $axe['violations'])];
			$points = min(2, $byImpact['critical'] * 2) + ($byImpact['serious'] >= 2 ? 1 : 0);
			$evidence = array_map(fn ($v) => sprintf('%s (%s): %s — %d elem.', $v['id'], $v['impact'] ?? '-', $v['help'] ?? '', $v['nodes'] ?? 0), array_slice($axe['violations'], 0, 6));

			foreach ($axe['violations'] as $v) {
				if (in_array($v['impact'] ?? '', ['critical', 'serious'], true)) {
					$findings[] = Finding::make('axe_' . $v['id'], $v['id'] === 'color-contrast' ? 'accessibility' : 'accessibility', $v['impact'] === 'critical' ? 'medium' : 'low', 'Dostępność: ' . ($v['help'] ?? $v['id']), sprintf('axe-core (%s): %d elementów narusza regułę „%s”. Wynik automatyczny — wymaga potwierdzenia.', $v['impact'], $v['nodes'] ?? 0, $v['id']), $v['targets'] ?? [], 'Poprawić elementy wskazane przez test (szczegóły reguły: dequeuniversity.com/rules/axe/' . $v['id'] . ').');
				}
			}

			$criteria['b_a11y_axe'] = Criterion::measured($points, 3, 'axe-core ' . ($axe['version'] ?? '') . ' (WCAG 2 A/AA, test automatyczny — nie certyfikacja zgodności).', $evidence);
		} else {
			$criteria['b_a11y_axe'] = Criterion::notEvaluated(3, 'axe-core nie został uruchomiony (brak renderu w przeglądarce).');
		}

		return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
	}
}
