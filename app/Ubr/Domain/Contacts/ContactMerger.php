<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Deduplikacja kandydatów (ContactDeduplication): jedna osoba = jeden kontakt w firmie, wiele źródeł.
 *
 * Klucz: imię + nazwisko bez diakrytyków. Źródła są łączone, dane kontaktowe uzupełniane (nie nadpisywane),
 * a sprzeczne stanowiska z różnych źródeł oznaczane jako konflikt (needs_review) — zachowujemy wszystkie obserwacje.
 */
final class ContactMerger
{
	/**
	 * @param list<array<string, mixed>> $raw kandydaci ze źródeł (PeopleExtractor / Apollo / ręczne)
	 * @return list<array<string, mixed>>
	 */
	public function merge(array $raw): array
	{
		$out = [];

		foreach ($raw as $c) {
			/** @var PersonName $name */
			$name = $c['name'];
			$key = $name->key();
			$source = [
				'source_type' => $c['source_type'],
				'source_url' => $c['source_url'] ?? null,
				'source_title' => $c['source_title'] ?? null,
				'observed_role' => $c['title'],
				'excerpt' => $c['excerpt'] ?? null,
				'page_kind' => $c['page_kind'] ?? null,
			];

			if (! isset($out[$key])) {
				$out[$key] = [
					'person_key' => $key,
					'first_name' => $name->first,
					'last_name' => $name->last,
					'full_name' => $name->full(),
					'job_title' => $c['title'],
					'normalized_role' => $c['role']['role'],
					'marketing_focus' => (bool) ($c['role']['marketing_focus'] ?? false),
					'email' => $c['email'] ?? null,
					'role_email' => $c['role_email'] ?? null,
					'phone' => $c['phone'] ?? null,
					'profile' => $c['profile'] ?? null,
					'former' => (bool) ($c['former'] ?? false),
					'other_entity' => $c['other_entity'] ?? null,
					'obfuscated' => (bool) ($c['obfuscated'] ?? false),
					'external_provider' => $c['external_provider'] ?? null,
					'external_id' => $c['external_id'] ?? null,
					'conflict' => false,
					'observed_roles' => [$c['role']['role']],
					'sources' => [$source],
				];
				continue;
			}

			$m = &$out[$key];
			$m['sources'][] = $source;
			$m['observed_roles'][] = $c['role']['role'];

			foreach (['email', 'role_email', 'phone', 'profile', 'external_provider', 'external_id'] as $f) {
				$m[$f] ??= $c[$f] ?? null;
			}

			$m['former'] = $m['former'] || ! empty($c['former']);
			$m['other_entity'] ??= $c['other_entity'] ?? null;
			$m['marketing_focus'] = $m['marketing_focus'] || ! empty($c['role']['marketing_focus']);
			$m['obfuscated'] = $m['obfuscated'] && ! empty($c['obfuscated']);

			// Stanowisko z lepszego źródła (strona firmy > Apollo); przy różnych grupach ról — konflikt do weryfikacji.
			if (self::rankSource($c['source_type']) > self::rankSource($m['sources'][0]['source_type']) && $c['source_type'] !== 'APOLLO') {
				$m['job_title'] = $c['title'];
				$m['normalized_role'] = $c['role']['role'];
			}

			if (self::conflicting($m['normalized_role'], $c['role']['role'])) {
				$m['conflict'] = true;
			}

			unset($m);
		}

		foreach ($out as &$m) {
			$m['observed_roles'] = array_values(array_unique($m['observed_roles']));
			// Najlepsze źródło pierwsze (do wyświetlenia „Źródło: …”).
			usort($m['sources'], fn ($a, $b) => self::rankSource($b['source_type']) <=> self::rankSource($a['source_type']));
		}

		unset($m);
		ksort($out);

		return array_values($out);
	}

	/**
	 * Sprzeczność ról: różne funkcje w zarządzie (np. prezes vs członek zarządu) albo zupełnie różne obszary.
	 */
	public static function conflicting(string $a, string $b): bool
	{
		if ($a === $b) {
			return false;
		}

		$boardRank = ['ceo' => 3, 'vice_president' => 2, 'board_member' => 1, 'proxy' => 0];

		if (isset($boardRank[$a], $boardRank[$b])) {
			return true;
		}

		$ga = RoleCatalog::group($a);
		$gb = RoleCatalog::group($b);
		$compatible = [
			'leadership' => ['leadership', 'board'],
			'board' => ['leadership', 'board', 'marketing_lead', 'other'],
			'marketing_lead' => ['marketing_lead', 'marketing', 'board'],
			'marketing' => ['marketing_lead', 'marketing'],
			'other' => ['other', 'board'],
		];

		return ! in_array($gb, $compatible[$ga] ?? [], true);
	}

	public static function rankSource(string $type): int
	{
		return match ($type) {
			'KRS' => 5,
			'COMPANY_WEBSITE' => 4,
			'OFFICIAL_PUBLICATION' => 3,
			'USER_PROVIDED' => 2,
			'APOLLO' => 1,
			default => 0,
		};
	}
}
