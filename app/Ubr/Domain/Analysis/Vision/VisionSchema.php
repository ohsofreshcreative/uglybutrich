<?php

namespace App\Ubr\Domain\Analysis\Vision;

/**
 * Schemat odpowiedzi modelu AI Vision + walidacja. Odpowiedź modelu NIE jest akceptowana bez walidacji:
 * nieznane kategorie są odrzucane, liczby obcinane do zakresu, teksty skracane, brak wymaganych pól = błąd.
 *
 * Każda kategoria: score (0–100, jakość — wyżej = lepiej), confidence (low/medium/high), findings (obserwacje),
 * evidence (co na screenshocie to pokazuje), recommendations. Obserwacje są oddzielone od interpretacji (`interpretation`).
 */
final class VisionSchema
{
	public const PROMPT_VERSION = 'vision-2026-10-v1';

	public const CATEGORIES = ['visual_quality', 'visual_hierarchy', 'typography', 'navigation', 'mobile_experience', 'conversion_clarity', 'brand_consistency'];

	/**
	 * @param mixed $raw zdekodowany JSON z modelu
	 * @return array{ok: bool, errors: list<string>, result: ?array<string, mixed>}
	 */
	public static function validate(mixed $raw, string $model): array
	{
		$errors = [];

		if (! is_array($raw) || ! isset($raw['categories']) || ! is_array($raw['categories'])) {
			return ['ok' => false, 'errors' => ['Brak obiektu "categories".'], 'result' => null];
		}

		$out = ['model' => mb_substr($model, 0, 80), 'prompt_version' => self::PROMPT_VERSION, 'categories' => [], 'interpretation' => null];

		foreach ($raw['categories'] as $key => $c) {
			if (! in_array($key, self::CATEGORIES, true)) {
				$errors[] = 'Nieznana kategoria odrzucona: ' . mb_substr((string) $key, 0, 40);
				continue;
			}

			if (! is_array($c) || ! isset($c['score']) || ! is_numeric($c['score'])) {
				$errors[] = "Kategoria {$key}: brak liczbowego score.";
				continue;
			}

			$confidence = in_array($c['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $c['confidence'] : null;

			if (! $confidence) {
				$errors[] = "Kategoria {$key}: brak poprawnego confidence.";
				continue;
			}

			$list = fn ($v, $n) => array_values(array_slice(array_map(fn ($s) => mb_substr(trim((string) $s), 0, 300), array_filter((array) $v, fn ($s) => is_string($s) && trim($s) !== '')), 0, $n));
			$evidence = $list($c['evidence'] ?? [], 5);

			// Ocena bez dowodów nie jest akceptowana (zasada: nie generujemy problemów bez dowodów).
			if ($evidence === []) {
				$errors[] = "Kategoria {$key}: brak dowodów (evidence) — odrzucona.";
				continue;
			}

			$out['categories'][$key] = [
				'score' => max(0, min(100, (int) round((float) $c['score']))),
				'confidence' => $confidence,
				'findings' => $list($c['findings'] ?? [], 6),
				'evidence' => $evidence,
				'recommendations' => $list($c['recommendations'] ?? [], 5),
			];
		}

		if (isset($raw['interpretation']) && is_string($raw['interpretation'])) {
			$out['interpretation'] = mb_substr($raw['interpretation'], 0, 1000);
		}

		if ($out['categories'] === []) {
			return ['ok' => false, 'errors' => $errors ?: ['Brak poprawnych kategorii.'], 'result' => null];
		}

		return ['ok' => true, 'errors' => $errors, 'result' => $out];
	}

	/**
	 * Instrukcja dla modelu (wersjonowana). Przechowywana w kodzie — zmiana = nowa PROMPT_VERSION.
	 */
	public static function prompt(array $facts): string
	{
		return "Jesteś ekspertem UX/UI oceniającym stronę firmy B2B na podstawie DWÓCH screenshotów (desktop 1440 px i mobile 390 px).\n"
			. "Oceniaj tylko to, co widać na obrazach. Nie zgaduj. Jeżeli czegoś nie widać, obniż confidence.\n"
			. 'Kategorie: ' . implode(', ', self::CATEGORIES) . ".\n"
			. "Dla każdej kategorii zwróć: score (0–100, 100 = wzorcowo nowoczesne i czytelne), confidence (low|medium|high), "
			. "findings (konkretne obserwacje), evidence (gdzie na screenshocie to widać), recommendations.\n"
			. "Osobno pole interpretation: krótka interpretacja biznesowa (oddzielona od obserwacji).\n"
			. "Fakty z pomiarów (nie oceniaj ich ponownie): " . wp_json_encode($facts) . "\n"
			. 'Odpowiedz WYŁĄCZNIE poprawnym JSON: {"categories":{"visual_quality":{...},...},"interpretation":"..."}';
	}
}
