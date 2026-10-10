<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;
use App\Ubr\Domain\Analysis\Vision\VisionSchema;

/**
 * Ocena wizualna (design, hierarchia, typografia, spójność marki) — WYŁĄCZNIE z analizy screenshotów przez model AI.
 * Bez wyniku AI wszystkie kategorie wizualne mają status NOT_EVALUATED (0 pkt) i powstaje sugestia ręcznej weryfikacji.
 */
final class VisualAnalyzer
{
	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		$vision = $ctx->vision;

		if (! $vision) {
			$findings = [];

			if ($ctx->rendered()) {
				$findings[] = Finding::make('manual_visual_review', 'design', 'info', 'Oceń wygląd strony ręcznie', 'Analiza wizualna AI jest wyłączona — nowoczesność designu, hierarchia, typografia i spójność marki NIE zostały ocenione. Screenshoty Desktop/Mobile są dostępne w podglądzie.', 'AI Vision: wyłączone', 'Obejrzeć podgląd i ocenić, czy design jest przestarzały (to główny argument sprzedażowy redesignu).', 'manual_review');
			}

			return [
				'criteria' => ['a_visual_ai' => Criterion::notEvaluated(15, 'Analiza wizualna AI niewykonana (wyłączona) — jakość designu nieznana, 0 pkt.')],
				'checks' => ['categories' => array_fill_keys(VisionSchema::CATEGORIES, 'NOT_EVALUATED')],
				'findings' => $findings,
			];
		}

		// Wynik AI (zwalidowany): score 0–100 = jakość. Słabość = odwrotność jakości, ważona pewnością modelu.
		$scores = [];
		$findings = [];

		foreach (VisionSchema::CATEGORIES as $cat) {
			$c = $vision['categories'][$cat] ?? null;

			if (! $c) {
				continue;
			}

			$scores[$cat] = (int) $c['score'];

			foreach ($c['findings'] as $i => $text) {
				$findings[] = Finding::make('ai_' . $cat . '_' . $i, in_array($cat, ['navigation', 'mobile_experience'], true) ? 'ux' : ($cat === 'conversion_clarity' ? 'conversion' : 'design'), $c['score'] < 40 ? 'medium' : 'low', 'AI: ' . $text, 'Ocena modelu AI (' . ($vision['model'] ?? '?') . ', pewność ' . $c['confidence'] . '). To interpretacja, nie pomiar.', $c['evidence'], $c['recommendations'][0] ?? 'Zweryfikować ręcznie.', 'ai');
			}
		}

		if ($scores === []) {
			return ['criteria' => ['a_visual_ai' => Criterion::notEvaluated(15, 'Wynik AI bez ocen kategorii.')], 'checks' => [], 'findings' => []];
		}

		$quality = array_sum($scores) / count($scores);
		$points = (int) round((100 - $quality) / 100 * 15);

		return [
			'criteria' => ['a_visual_ai' => Criterion::measured($points, 15, sprintf('AI Vision (%s): średnia jakość %d/100.', $vision['model'] ?? '?', (int) round($quality)), array_map(fn ($k, $v) => $k . ': ' . $v, array_keys($scores), $scores), 'ai')],
			'checks' => ['categories' => $scores, 'model' => $vision['model'] ?? null, 'prompt_version' => $vision['prompt_version'] ?? null],
			'findings' => $findings,
		];
	}
}
