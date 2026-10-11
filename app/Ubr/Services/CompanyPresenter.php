<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Companies\Confidence;
use App\Ubr\Domain\Companies\FitFlags;
use App\Ubr\Domain\Discovery\RelevanceScorer;
use App\Ubr\Domain\Verification\DomainStatus;
use App\Ubr\Domain\Verification\VerificationStatus;
use App\Ubr\Infrastructure\Http\UrlGuard;

/**
 * Jednolity kształt danych firmy dla API (niezależny od widoków).
 */
final class CompanyPresenter
{
	public const PROVIDER_LABELS = [
		'wikidata' => 'Wikidata',
		'osm' => 'OpenStreetMap',
		'apollo' => 'Apollo',
		'website_check' => 'Weryfikacja strony',
		'krs' => 'KRS',
		'vat' => 'Biała lista VAT',
		'ceidg' => 'CEIDG',
		'gus' => 'GUS / REGON',
		'website' => 'Strona WWW',
	];

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function row(array $r): array
	{
		return [
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'domain' => $r['domain'] ?: null,
			'website_url' => $r['website_url'] ?? null,
			'domain_confidence' => $r['domain'] ? ($r['domain_confidence'] ?? Confidence::UNCERTAIN) : Confidence::UNAVAILABLE,
			'website_status' => $r['website_status'] ?? 'unchecked',
			'industry' => $r['industry'] ?: null,
			'city' => $r['city'] ?: null,
			'region' => $r['region'] ?: null,
			'size' => self::size($r),
			'nip' => $r['nip'] ?? null,
			'provider' => $r['provider'] ?? null,
			'provider_label' => isset($r['provider']) ? (self::PROVIDER_LABELS[$r['provider']] ?? $r['provider']) : null,
			'matched_terms' => $r['matched_terms'] ?? null,
			'prospect_id' => ! empty($r['prospect_id']) ? (int) $r['prospect_id'] : null,
			'saved_at' => isset($r['saved_at']) ? gmdate('c', strtotime($r['saved_at'] . ' UTC')) : null,
			'status' => $r['status'] ?? null,
			'location' => [
				'confidence' => ($r['city'] ?? null) || ($r['region'] ?? null) ? ($r['location_confidence'] ?? Confidence::DECLARED) : Confidence::UNAVAILABLE,
				'source' => $r['location_source'] ?? null,
				'match' => $r['location_match'] ?? null,
			],
			'relevance' => isset($r['relevance_score']) && $r['relevance_score'] !== null ? (int) $r['relevance_score'] : null,
			'relevance_details' => self::relevance($r['relevance'] ?? null),
			'verification' => [
				'status' => $r['verification_status'] ?? VerificationStatus::PENDING,
				'label' => VerificationStatus::label($r['verification_status'] ?? null),
				'confidence' => $r['verification_confidence'] ?? null,
				'source' => $r['verification_source'] ?? null,
				'checked_at' => ! empty($r['verification_checked_at']) ? gmdate('c', strtotime($r['verification_checked_at'] . ' UTC')) : null,
			],
			'domain_status' => [
				'status' => $r['domain_status'] ?? DomainStatus::UNCHECKED,
				'label' => DomainStatus::label($r['domain_status'] ?? null),
				'checked_at' => ! empty($r['domain_checked_at']) ? gmdate('c', strtotime($r['domain_checked_at'] . ' UTC')) : null,
			],
			'flags' => FitFlags::present(json_decode((string) ($r['fit_flags'] ?? ''), true) ?: []),
			'analysis' => self::analysis($r),
			// Prompt 10.4.3 — kontakt firmowy z Discover: ogólny e-mail (strona firmy) i kompaktowy wiek strony.
			'general_email' => self::generalEmail($r),
			'site_age' => [
				'short' => WebsiteAgeService::shortFor($r),
				'status' => $r['site_age_status'] ?? null,
				'years' => isset($r['site_age_years']) && $r['site_age_years'] !== null ? (int) $r['site_age_years'] : null,
				'confidence' => $r['site_age_confidence'] ?? null,
				'state' => $r['site_age_state'] ?? null,
			],
			'contacts' => ContactResearchService::summary($r),
			'stages' => self::stages($r),
			'lead' => array_key_exists('li_status', $r) ? self::lead($r) : null,
			'pipeline' => isset($r['pipeline_status']) ? [
				'status' => $r['pipeline_status'],
				'label' => \App\Ubr\Domain\Pipeline\PipelineCatalog::statusLabel($r['pipeline_status']),
				'priority' => $r['priority'] ?? 'medium',
				'priority_label' => \App\Ubr\Domain\Pipeline\PipelineCatalog::PRIORITIES[$r['priority'] ?? 'medium'] ?? null,
				'owner' => app(PipelineService::class)->userBrief(! empty($r['owner_user_id']) ? (int) $r['owner_user_id'] : null),
				'qualification' => $r['qualification'] ?? null,
				'next_action_at' => ! empty($r['next_action_at']) ? gmdate('c', strtotime($r['next_action_at'] . ' UTC')) : null,
				'overdue' => ! empty($r['next_action_at']) && strtotime($r['next_action_at'] . ' UTC') < time(),
				'archived' => ! empty($r['archived_at']),
				'clickup_task_url' => UrlGuard::httpUrl($r['clickup_task_url'] ?? null),
			] : null,
		];
	}

	/**
	 * Lead Intelligence na listach (z kolumn ubr_lead_intelligence dołączonych w zapytaniu — bez liczenia w żądaniu).
	 *
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function lead(array $r): array
	{
		if ($r['li_status'] === null) {
			return ['available' => false, 'pending' => true];
		}

		return [
			'available' => true,
			'pending' => (bool) $r['li_stale'],
			'score' => $r['li_score'] !== null ? (int) $r['li_score'] : null,
			'status' => $r['li_status'],
			'confidence' => $r['li_confidence'],
			'coverage' => (int) $r['li_coverage'],
			'redesign' => $r['li_redesign'],
			'redesign_label' => \App\Ubr\Domain\Lead\RedesignAssessor::LABELS[$r['li_redesign']] ?? $r['li_redesign'],
			'recommendation' => $r['li_recommendation'],
			'recommendation_label' => \App\Ubr\Domain\Lead\LeadRecommender::ACTIONS[$r['li_recommendation']] ?? $r['li_recommendation'],
			'rank' => $r['li_rank'] !== null ? (float) $r['li_rank'] : null,
			'blocker' => (bool) $r['li_blocker'],
		];
	}

	public const ANALYSIS_LABELS = [
		'' => 'Nieanalizowana',
		'queued' => 'W kolejce',
		'running' => 'Analizowanie',
		'completed' => 'Gotowa',
		'partial' => 'Częściowa',
		'failed' => 'Błąd',
	];

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public static function analysis(array $r): array
	{
		$status = $r['analysis_status'] ?? null;

		return [
			'status' => $status,
			'label' => self::ANALYSIS_LABELS[(string) $status] ?? 'Nieanalizowana',
			'analysis_id' => ! empty($r['last_analysis_id']) ? (int) $r['last_analysis_id'] : null,
			'opportunity' => isset($r['opportunity_score']) && $r['opportunity_score'] !== null ? (int) $r['opportunity_score'] : null,
			'level' => $r['opportunity_level'] ?? null,
			'confidence' => $r['opportunity_confidence'] ?? null,
			'coverage' => isset($r['opportunity_coverage']) && $r['opportunity_coverage'] !== null ? (int) $r['opportunity_coverage'] : null,
			'findings_count' => isset($r['findings_count']) && $r['findings_count'] !== null ? (int) $r['findings_count'] : null,
			'analyzed_at' => ! empty($r['analyzed_at']) ? gmdate('c', strtotime($r['analyzed_at'] . ' UTC')) : null,
			'can_preview' => ! empty($r['last_analysis_id']),
		];
	}

	/**
	 * Prompt 10.4 — niezależne statusy etapów przetwarzania firmy (Discover): weryfikacja istnienia, strona WWW
	 * (Website Analyzer), telefony firmy, decydent. Każdy etap ma własny status — firma jest widoczna na liście od razu,
	 * a niepewność danych jest jawnie oznaczona. Ton: good | info | active | warn | danger | muted.
	 *
	 * @param array<string, mixed> $r
	 * @return array<string, array{key: string, label: string, tone: string, title: string}>
	 */
	public static function stages(array $r): array
	{
		$v = (string) ($r['verification_status'] ?? VerificationStatus::PENDING);
		$queue = (string) ($r['vq_status'] ?? '');
		$domain = (string) ($r['domain_status'] ?? DomainStatus::UNCHECKED);
		$st = fn (string $key, string $label, string $tone, string $title) => compact('key', 'label', 'tone', 'title');

		$verification = match (true) {
			$queue === 'running' => $st('running', 'Weryfikacja w toku', 'active', 'Sprawdzanie rejestrów (KRS, biała lista VAT) i strony firmy.'),
			in_array($v, [VerificationStatus::INACTIVE, 'suspended'], true) => $st('inactive', 'Nieaktywna', 'danger', 'Rejestr wskazuje firmę wykreśloną, zawieszoną lub nieaktywną — nie trafia do gotowych leadów.'),
			$domain === DomainStatus::MISMATCH => $st('mismatch', 'Niezgodność', 'danger', 'Strona WWW należy do innego podmiotu niż firma — dane wymagają wyjaśnienia.'),
			$v === VerificationStatus::VERIFIED => $st('verified', 'Zweryfikowana', 'good', 'Istnienie firmy potwierdzone w rejestrze.'),
			$v === VerificationStatus::UNCERTAIN => $st('uncertain', 'Niepewna', 'warn', 'Nie udało się jednoznacznie potwierdzić firmy w rejestrze.'),
			default => $st('pending', 'Oczekuje na weryfikację', 'muted', $queue === 'queued' ? 'Firma w kolejce weryfikacji.' : 'Weryfikacja nie została jeszcze wykonana.'),
		};

		$analysis = (string) ($r['analysis_status'] ?? '');
		$website = match ($analysis) {
			'queued' => $st('queued', 'Oczekuje', 'muted', 'Analiza strony w kolejce.'),
			'running' => $st('running', 'Analizowanie', 'active', 'Website Analyzer sprawdza stronę.'),
			'completed', 'partial' => $st('done', 'Gotowe', 'good', $analysis === 'partial' ? 'Analiza częściowa.' : 'Analiza zakończona.'),
			'failed' => $st('failed', 'Błąd', 'danger', 'Analiza strony nie powiodła się.'),
			default => empty($r['domain']) ? $st('none', 'Brak strony', 'muted', 'Firma nie ma ustalonej strony WWW.') : $st('none', 'Nie analizowano', 'muted', 'Analiza strony (Opportunity Score) jest zlecana w tle po weryfikacji firmy z Discover albo ręcznie.'),
		};

		$count = (int) ($r['phones_count'] ?? 0);
		$checked = ! empty($r['phones_checked_at']);
		$unreachable = in_array((string) ($r['website_status'] ?? ''), ['unreachable', 'http_error'], true);
		$phones = match (true) {
			$count > 0 => $st('found', 'Znaleziono (' . $count . ')', 'good', 'Numery firmy ze strony WWW lub dodane ręcznie.'),
			$checked => $st('none', 'Nie znaleziono', 'muted', 'Na sprawdzonych stronach nie ma numeru firmy.'),
			empty($r['domain']) => $st('no_site', 'Brak strony', 'muted', 'Brak strony WWW — numer można dodać ręcznie.'),
			$queue === 'running' => $st('running', 'Wyszukiwanie', 'active', 'Numery są odczytywane ze stron pobranych przy weryfikacji.'),
			$unreachable => $st('error', 'Błąd', 'danger', 'Strona firmy nie odpowiadała.'),
			$v === VerificationStatus::PENDING || $queue === 'queued' => $st('pending', 'Oczekuje', 'muted', 'Numery zostaną odczytane przy weryfikacji firmy.'),
			default => $st('not_checked', 'Nie sprawdzono', 'muted', 'Numery można odświeżyć w karcie firmy.'),
		};

		$email = self::generalEmail($r);
		$emailStage = match (true) {
			$email !== null => $st('found', 'Znaleziono', 'good', 'Ogólny e-mail firmowy: ' . $email),
			empty($r['domain']) => $st('no_site', 'Brak strony', 'muted', 'Brak strony WWW.'),
			$v === VerificationStatus::PENDING || $queue !== '' => $st('pending', 'Oczekuje', 'muted', 'E-mail zostanie odczytany ze stron pobranych przy weryfikacji.'),
			default => $st('none', 'Nie znaleziono', 'muted', 'Na sprawdzonych stronach nie ma ogólnego adresu e-mail firmy.'),
		};

		$ageShort = WebsiteAgeService::shortFor($r);
		$ageStatus = (string) ($r['site_age_status'] ?? '');
		$age = match (true) {
			$ageShort === null => $st('not_started', '—', 'muted', 'Wiek strony jest sprawdzany w tle po analizie strony.'),
			$ageShort === 'sprawdzanie' => $st('running', 'sprawdzanie', 'active', 'Website Age Intelligence analizuje archiwum strony (w tle).'),
			$ageStatus === 'UNKNOWN' => $st('unknown', $ageShort, 'muted', 'Nie udało się ustalić wieku obecnego projektu strony.'),
			default => $st(strtolower($ageStatus), $ageShort, in_array($ageStatus, ['AGING', 'OUTDATED'], true) ? 'warn' : 'good', 'Szacowany wiek obecnego projektu strony (pewność: ' . ($r['site_age_confidence'] ?? '—') . ').'),
		};

		$contacts = (string) ($r['contacts_status'] ?? '');
		$dm = match ($contacts) {
			'queued' => $st('queued', 'Oczekuje', 'muted', 'Wyszukiwanie decydentów w kolejce.'),
			'running' => $st('running', 'Wyszukiwanie', 'active', 'Decision Maker Finder sprawdza KRS i stronę firmy.'),
			'completed', 'partial' => ! empty($r['top_contact_id']) ? $st('found', 'Znaleziono', 'good', 'Znaleziono decydenta.') : $st('none', 'Nie znaleziono', 'muted', 'Nie znaleziono wiarygodnego decydenta.'),
			'failed' => $st('failed', 'Błąd', 'danger', 'Wyszukiwanie decydentów nie powiodło się.'),
			default => $st('not_started', 'Po przejęciu', 'muted', 'Decydent i jego telefon są wyszukiwane automatycznie po przejęciu firmy.'),
		};

		return ['verification' => $verification, 'website' => $website, 'phones' => $phones, 'email' => $emailStage, 'age' => $age, 'dm' => $dm];
	}

	/**
	 * Ogólny e-mail firmowy: ze stron pobranych przy weryfikacji, a gdy go brak — z kanałów firmy znalezionych przez
	 * Decision Maker Findera (COMPANY_GENERAL_EMAIL).
	 *
	 * @param array<string, mixed> $r
	 */
	public static function generalEmail(array $r): ?string
	{
		if (! empty($r['general_email']) && is_email((string) $r['general_email'])) {
			return (string) $r['general_email'];
		}

		foreach ((array) (json_decode((string) ($r['company_channels'] ?? ''), true) ?: []) as $channel) {
			if (($channel['type'] ?? '') === 'COMPANY_GENERAL_EMAIL' && is_email((string) ($channel['value'] ?? ''))) {
				return (string) $channel['value'];
			}
		}

		return null;
	}

	/**
	 * Zwięzłe szczegóły trafności dla UI (bez danych wewnętrznych).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function relevance(?string $json): ?array
	{
		$data = $json ? json_decode($json, true) : null;

		if (! is_array($data)) {
			return null;
		}

		$criteria = [];

		foreach ((array) ($data['criteria'] ?? []) as $key => $c) {
			$criteria[] = [
				'key' => $key,
				'label' => ['industry' => 'Branża', 'keywords' => 'Słowa kluczowe', 'location' => 'Lokalizacja', 'size' => 'Wielkość'][$key] ?? $key,
				'state' => $c['state'] ?? 'unknown',
				'state_label' => RelevanceScorer::STATE_LABELS[$c['state'] ?? 'unknown'] ?? ($c['state'] ?? ''),
				'points' => (int) ($c['points'] ?? 0),
				'max' => (int) ($c['max'] ?? 0),
				'detail' => (string) ($c['detail'] ?? ''),
			];
		}

		return [
			'criteria' => $criteria,
			'penalties' => array_values((array) ($data['penalties'] ?? [])),
			'incomplete' => array_values((array) ($data['incomplete'] ?? [])),
		];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array{label: ?string, confidence: string}
	 */
	public static function size(array $r): array
	{
		$min = isset($r['employees_min']) ? (int) $r['employees_min'] : null;
		$max = isset($r['employees_max']) ? (int) $r['employees_max'] : null;

		if (! $min && ! $max) {
			return ['label' => null, 'confidence' => Confidence::UNAVAILABLE];
		}

		$label = $min === $max ? number_format_i18n((float) $min) . ' os.' : trim(($min ?: '?') . '–' . ($max ?: '?')) . ' os.';

		return ['label' => $label, 'confidence' => $r['employees_confidence'] ?? Confidence::UNCERTAIN];
	}
}
