<?php

namespace App\Ubr\Domain\Discovery;

/**
 * Nazwy grup branżowych dla kodów SIC (2 pierwsze cyfry) — Apollo w wynikach wyszukiwania zwraca kody SIC/NAICS
 * zamiast nazwy branży. Tłumaczymy tylko grupy główne; pełny kod zostaje w opisie.
 */
final class IndustryCodes
{
	private const SIC_GROUPS = [
		'15' => 'Budownictwo ogólne', '16' => 'Budownictwo inżynieryjne', '17' => 'Roboty budowlane specjalistyczne',
		'20' => 'Przemysł spożywczy', '22' => 'Przemysł tekstylny', '23' => 'Odzież', '24' => 'Przemysł drzewny',
		'25' => 'Meble', '26' => 'Papier i opakowania', '27' => 'Poligrafia', '28' => 'Chemia',
		'29' => 'Rafinerie i paliwa', '30' => 'Guma i tworzywa sztuczne', '31' => 'Skóra', '32' => 'Szkło, ceramika, beton',
		'33' => 'Hutnictwo i metale', '34' => 'Wyroby metalowe i konstrukcje', '35' => 'Maszyny i urządzenia przemysłowe',
		'36' => 'Elektrotechnika i elektronika', '37' => 'Środki transportu', '38' => 'Przyrządy pomiarowe i sterowanie',
		'39' => 'Pozostała produkcja', '42' => 'Transport drogowy i magazynowanie', '44' => 'Transport wodny',
		'45' => 'Transport lotniczy', '47' => 'Usługi transportowe', '50' => 'Hurt — dobra trwałe', '51' => 'Hurt — dobra nietrwałe',
		'73' => 'Usługi dla biznesu', '87' => 'Usługi inżynieryjne i doradcze',
	];

	/**
	 * @param list<string> $sic
	 * @param list<string> $naics
	 */
	public static function describe(array $sic, array $naics): ?string
	{
		$labels = [];

		foreach ($sic as $code) {
			$group = self::SIC_GROUPS[substr(preg_replace('/\D/', '', (string) $code), 0, 2)] ?? null;

			if ($group) {
				$labels[$group] = true;
			}
		}

		$codes = array_filter([
			$sic ? 'SIC ' . implode(', ', array_slice($sic, 0, 3)) : null,
			$naics ? 'NAICS ' . implode(', ', array_slice($naics, 0, 3)) : null,
		]);

		if (! $labels && ! $codes) {
			return null;
		}

		return trim(implode(', ', array_keys($labels)) . ($codes ? ' (' . implode('; ', $codes) . ')' : ''));
	}
}
