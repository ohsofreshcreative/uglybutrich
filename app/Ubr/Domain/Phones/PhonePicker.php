<?php

namespace App\Ubr\Domain\Phones;

/**
 * Wybór głównego numeru firmy spośród numerów ze strony WWW (deterministycznie, stabilne remisy).
 *
 * Preferencje: 1) numer oznaczony jako centrala (MAIN), 2) z oficjalnej podstrony Kontakt, 3) ze stopki, 4) inny potwierdzony.
 * Numer zagraniczny (oddział za granicą) nie zostaje głównym numerem firmy z Polski, gdy jest numer krajowy.
 * Numery dawno niewidziane na stronie (> 180 dni) mają najniższy priorytet. Numery ręczne organizacji wybierane są osobno.
 */
final class PhonePicker
{
	/**
	 * @param list<array<string, mixed>> $phones wiersze ubr_company_phones (org_key = 0)
	 */
	public static function primary(array $phones, ?string $companyCountry = 'PL'): ?array
	{
		$country = $companyCountry ?: 'PL';
		$best = null;
		$bestScore = PHP_INT_MIN;

		foreach ($phones as $p) {
			// Numer innej spółki z grupy i numer zagraniczny nigdy nie są numerem głównym tej firmy (wolimy brak numeru).
			if (str_starts_with((string) ($p['branch_label'] ?? ''), 'inna spółka')) {
				continue;
			}

			if (self::foreign($p, $country)) {
				continue;
			}

			$score = self::score($p, $country);

			// Remis: pierwszy w kolejności na stronie (wiersze w kolejności zapisu = kolejność dokumentu).
			if ($score > $bestScore) {
				$best = $p;
				$bestScore = $score;
			}
		}

		return $best;
	}

	/**
	 * @param array<string, mixed> $p
	 */
	public static function score(array $p, string $country = 'PL'): int
	{
		$type = (string) ($p['phone_type'] ?? $p['type'] ?? 'UNKNOWN');
		$s = match ($type) {
			'MAIN' => 100,
			'UNKNOWN' => 40,
			'SALES' => 35,
			'SUPPORT' => 20,
			'OTHER' => 10, // osoba / dział (kadry, magazyn…) — numer główny tylko, gdy nie ma innego
			'BRANCH' => 5,
			default => 0,
		};

		// Bez opisu: komórka to zwykle numer konkretnej osoby, stacjonarny — biuro.
		if ($type === 'UNKNOWN' && (PhoneNumber::parse((string) ($p['e164'] ?? ''))['mobile'] ?? false)) {
			$s -= 15;
		}

		if (($p['confidence'] ?? '') === 'low') {
			$s -= 20; // np. tekst linku ≠ numer w tel: — do ręcznej weryfikacji
		}
		$s += match ((string) ($p['page_kind'] ?? '')) {
			'contact' => 30,
			'home' => 10,
			default => 5,
		};
		$s += ! empty($p['in_footer']) ? 15 : 0;
		$s += in_array((string) ($p['source'] ?? ''), ['TEL_LINK', 'SCHEMA'], true) ? 10 : 0;
		$s += ($p['confidence'] ?? '') === 'high' ? 5 : 0;

		if (self::foreign($p, $country)) {
			$s -= 120; // numer zagraniczny nie wypiera krajowego
		}

		if (! empty($p['extension'])) {
			$s -= 10; // numer wewnętrzny — gorszy jako numer główny
		}

		$seen = isset($p['last_seen_at']) ? strtotime($p['last_seen_at'] . ' UTC') : time();

		if ($seen && $seen < time() - 180 * DAY_IN_SECONDS) {
			$s -= 200;
		}

		return $s;
	}

	/**
	 * Numer spoza kraju firmy. Gdy kraj numeru jest nieznany (np. +91, +55 — brak w mapie prefiksów), decyduje
	 * prefiks kierunkowy kraju firmy (SECO/WARWICK: numer z Indii nie może być główny dla firmy z Polski).
	 *
	 * @param array<string, mixed> $p
	 */
	public static function foreign(array $p, string $country = 'PL'): bool
	{
		$pc = (string) ($p['country_code'] ?? $p['country'] ?? '');

		if ($pc !== '') {
			return $pc !== $country;
		}

		$dial = ['PL' => '+48'][$country] ?? null;
		$e164 = (string) ($p['e164'] ?? '');

		return $dial !== null && str_starts_with($e164, '+') && ! str_starts_with($e164, $dial);
	}
}
