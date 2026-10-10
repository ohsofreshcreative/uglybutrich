<?php

namespace App\Ubr\Domain\Verification;

/**
 * Reguły statusu przedsiębiorstwa (czyste, deterministyczne — bez I/O, testowalne).
 *
 * Wejście: rekordy z rejestrów znalezione po identyfikatorach (NIP/KRS/REGON) wraz z pochodzeniem identyfikatora
 * (website = opublikowany na stronie firmy, company = z rekordu dostawcy/bazy, registry = z innego rejestru).
 *
 * Zasady:
 * - rekord pasuje do firmy, gdy nazwa jest zgodna (≥ 0,85), albo rejestr podaje tę samą stronę WWW,
 *   albo identyfikator pochodzi ze strony należącej do firmy i nazwa jest częściowo zgodna (≥ 0,5);
 * - identyfikator z rekordu niepasującego NIE jest przypisywany (rozbieżność);
 * - kilka różnych podmiotów pasujących (np. spółki grupy) → UNCERTAIN, bez przypisania;
 * - status aktywności: KRS / CEIDG / GUS. Sama biała lista VAT („Czynny”) → VERIFIED z pewnością średnią;
 * - VERIFIED nigdy bez rekordu rejestru publicznego (dane Apollo/Wikidata/OSM nie wystarczają).
 */
final class CompanyStatusDecider
{
	private const PRIORITY = ['krs' => 0, 'ceidg' => 1, 'gus' => 2, 'vat' => 3];

	/**
	 * @param list<array{record: RegistryRecord, origin: string, score: float, domain_match: bool}> $hits
	 * @param array<string, string> $errors źródło => komunikat (rejestr niedostępny)
	 * @return array{status: string, confidence: string, primary: ?RegistryRecord, entity: list<array{record: RegistryRecord, origin: string, score: float, domain_match: bool}>, reasons: list<string>, discrepancies: list<string>, retry: bool}
	 */
	public static function decide(array $hits, array $errors, int $identifierCount, bool $siteBelongsToCompany): array
	{
		$reasons = [];
		$discrepancies = [];
		$matched = [];

		foreach ($hits as $hit) {
			$r = $hit['record'];
			$isMatch = $hit['score'] >= NameMatcher::STRONG
				|| $hit['domain_match']
				|| ($hit['score'] >= NameMatcher::WEAK && $hit['origin'] === 'website' && $siteBelongsToCompany);

			if ($isMatch) {
				$matched[] = $hit;
			} else {
				$discrepancies[] = sprintf('%s %s należy w rejestrze %s do „%s” (zgodność nazwy %d%%) — nie przypisano.', self::idLabel($r), $r->lookupKey, $r->label(), (string) $r->name, (int) round($hit['score'] * 100));
			}
		}

		$retry = false;

		if ($matched === []) {
			if ($errors !== []) {
				$retry = true;
				$reasons[] = 'Rejestr niedostępny: ' . implode('; ', $errors) . ' — weryfikacja zostanie ponowiona.';
			}

			if ($identifierCount === 0) {
				$reasons[] = 'Brak NIP/KRS/REGON (nie podaje ich strona ani źródło danych) — nie można jednoznacznie potwierdzić firmy w rejestrze.';
			} elseif ($hits === [] && $errors === []) {
				$reasons[] = 'Identyfikatory nie zostały znalezione w rejestrach.';
			} elseif ($hits !== []) {
				$reasons[] = 'Znalezione w rejestrze podmioty nie odpowiadają tej firmie.';
			}

			return self::result('uncertain', 'low', null, [], $reasons, $discrepancies, $retry);
		}

		// Grupowanie po podmiocie: KRS (jeśli jest) albo NIP.
		$entities = [];

		foreach ($matched as $hit) {
			$r = $hit['record'];
			$key = $r->krs ? 'krs:' . $r->krs : ($r->nip ? 'nip:' . $r->nip : $r->source . ':' . $r->lookupKey);
			$entities[$key][] = $hit;
		}

		// Ten sam podmiot może być raz kluczowany po KRS, raz po NIP (np. rekord VAT bez KRS) — scal po wspólnym NIP.
		$entities = self::mergeByNip($entities);

		if (count($entities) > 1) {
			$names = array_map(fn ($group) => (string) $group[0]['record']->name, $entities);
			$reasons[] = 'Kilka różnych podmiotów w rejestrach pasuje do firmy (' . implode(' | ', array_slice($names, 0, 3)) . ') — np. spółki z grupy. Nie przypisano identyfikatorów.';

			return self::result('uncertain', 'low', null, [], $reasons, $discrepancies, $errors !== []);
		}

		$entity = array_values(array_values($entities)[0]);
		usort($entity, fn ($a, $b) => (self::PRIORITY[$a['record']->source] ?? 9) <=> (self::PRIORITY[$b['record']->source] ?? 9));

		$activity = null;
		$vat = null;

		foreach ($entity as $hit) {
			if ($hit['record']->isActivityRegistry() && $activity === null) {
				$activity = $hit;
			}

			if ($hit['record']->source === 'vat' && $vat === null) {
				$vat = $hit;
			}
		}

		$primaryHit = $activity ?? $vat ?? $entity[0];
		$primary = $primaryHit['record'];
		$bestScore = max(array_column($entity, 'score'));

		if ($activity) {
			$status = match ($activity['record']->status) {
				RegistryRecord::ACTIVE => 'verified',
				RegistryRecord::SUSPENDED => 'suspended',
				RegistryRecord::INACTIVE, RegistryRecord::LIQUIDATION, RegistryRecord::BANKRUPTCY => 'inactive',
				default => 'uncertain',
			};
			$reasons[] = sprintf('%s: %s %s', $activity['record']->label(), (string) $activity['record']->name, $activity['record']->statusDetail ? '— ' . $activity['record']->statusDetail : '');

			if ($vat && $vat['record']->status !== RegistryRecord::ACTIVE && $status === 'verified') {
				$discrepancies[] = 'Biała lista VAT: ' . (string) $vat['record']->statusDetail;
			}

			if ($vat && $vat['record']->status === RegistryRecord::ACTIVE && $status === 'inactive') {
				$discrepancies[] = 'Rejestr wskazuje zakończenie działalności, a biała lista VAT nadal pokazuje czynnego podatnika.';
			}
		} else {
			$status = $vat && $vat['record']->status === RegistryRecord::ACTIVE ? 'verified' : 'uncertain';
			$reasons[] = sprintf('Biała lista VAT: %s — %s', (string) $primary->name, (string) $primary->statusDetail);

			if ($status === 'verified') {
				$reasons[] = 'Aktywność potwierdzona statusem VAT; rejestr działalności (KRS/CEIDG/GUS) niesprawdzony' . ($errors ? ' (niedostępny)' : '') . '.';
			}
		}

		if ($bestScore < NameMatcher::STRONG) {
			$discrepancies[] = sprintf('Nazwa w rejestrze („%s”) różni się od nazwy w źródle danych — dopasowanie przez stronę WWW/identyfikator.', (string) $primary->name);
		}

		$fromOwnSite = (bool) array_filter($entity, fn ($h) => $h['origin'] === 'website' || $h['domain_match']);
		$confidence = match (true) {
			$status === 'uncertain' => 'low',
			// Rejestr działalności + zgodna nazwa (identyfikator ze strony firmy albo z danych źródła).
			$activity !== null && $bestScore >= NameMatcher::STRONG => 'high',
			$activity !== null && $fromOwnSite => 'medium',
			default => 'medium',
		};

		if ($activity !== null && ! $fromOwnSite && $primaryHit['origin'] === 'company') {
			$reasons[] = 'Identyfikator pochodził z danych źródła (nie ze strony firmy) — potwierdzony zgodną nazwą w rejestrze.';
		}

		return self::result($status, $confidence, $primary, $entity, $reasons, $discrepancies, $errors !== [] && $activity === null);
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $entities
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function mergeByNip(array $entities): array
	{
		$byNip = [];

		foreach ($entities as $key => $group) {
			foreach ($group as $hit) {
				if ($hit['record']->nip) {
					$byNip[$hit['record']->nip][] = $key;
				}
			}
		}

		foreach ($byNip as $keys) {
			$keys = array_values(array_unique($keys));

			for ($i = 1; $i < count($keys); $i++) {
				if (isset($entities[$keys[$i]], $entities[$keys[0]])) {
					$entities[$keys[0]] = array_merge($entities[$keys[0]], $entities[$keys[$i]]);
					unset($entities[$keys[$i]]);
				}
			}
		}

		return $entities;
	}

	private static function idLabel(RegistryRecord $r): string
	{
		return match ($r->source) {
			'krs' => 'KRS',
			'gus' => 'Identyfikator',
			default => 'NIP',
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function result(string $status, string $confidence, ?RegistryRecord $primary, array $entity, array $reasons, array $discrepancies, bool $retry): array
	{
		return [
			'status' => $status,
			'confidence' => $confidence,
			'primary' => $primary,
			'entity' => $entity,
			'reasons' => array_values(array_map('trim', $reasons)),
			'discrepancies' => array_values(array_unique($discrepancies)),
			'retry' => $retry,
		];
	}
}
