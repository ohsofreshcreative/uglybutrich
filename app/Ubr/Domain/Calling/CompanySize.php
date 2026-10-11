<?php

namespace App\Ubr\Domain\Calling;

/**
 * Wielkość firmy z danych już zapisanych (Apollo / rejestry / ręcznie) — bez zgadywania. Brak danych = „Brak danych”.
 */
final class CompanySize
{
	public const BANDS = ['1-10' => [1, 10], '11-50' => [11, 50], '51-200' => [51, 200], '201-500' => [201, 500], '501-1000' => [501, 1000], '1000+' => [1001, PHP_INT_MAX]];

	public const SOURCE_LABELS = ['apollo' => 'Apollo', 'krs' => 'KRS', 'gus' => 'GUS', 'ceidg' => 'CEIDG', 'wikidata' => 'Wikidata', 'osm' => 'OpenStreetMap', 'manual' => 'ręcznie'];

	/**
	 * @param array<string, mixed> $c wiersz firmy
	 * @return array{band: ?string, label: string, exact: ?int, source: ?string, known: bool}
	 */
	public static function present(array $c): array
	{
		$min = isset($c['employees_min']) && $c['employees_min'] !== '' ? (int) $c['employees_min'] : null;
		$max = isset($c['employees_max']) && $c['employees_max'] !== '' ? (int) $c['employees_max'] : null;
		$value = $max ?: $min;

		if (! $value) {
			return ['band' => null, 'label' => 'Brak danych', 'exact' => null, 'source' => null, 'known' => false];
		}

		$band = null;

		foreach (self::BANDS as $key => [$lo, $hi]) {
			if ($value >= $lo && $value <= $hi) {
				$band = $key;
				break;
			}
		}

		$exact = $min !== null && $max !== null && $min === $max ? $min : null;
		$source = (string) ($c['employees_source'] ?? '');

		return [
			'band' => $band,
			'label' => $exact !== null ? number_format($exact, 0, ',', ' ') . ' os.' : str_replace('-', '–', (string) $band) . ' os.',
			'exact' => $exact,
			'source' => $source !== '' ? (self::SOURCE_LABELS[$source] ?? $source) : null,
			'known' => true,
		];
	}
}
