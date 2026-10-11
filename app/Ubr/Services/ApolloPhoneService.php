<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Phones\PhoneNumber;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Discovery\Providers\ApolloProvider;
use App\Ubr\Infrastructure\Http\ProviderHttpClient;
use App\Ubr\Infrastructure\Repositories\ApiRequestRepository;
use App\Ubr\Infrastructure\Repositories\CompanyPhoneRepository;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\ContactRepository;
use App\Ubr\Infrastructure\Repositories\PhoneRevealRepository;
use App\Ubr\Support\UbrException;

/**
 * Apollo Phone Enrichment — „Odblokuj telefon” (płatne, NIGDY automatycznie).
 *
 * Wg dokumentacji Apollo (docs.apollo.io, 10.2026): `POST /api/v1/people/match` z `reveal_phone_number=true`; numery są
 * dostarczane ASYNCHRONICZNIE — na publiczny `webhook_url` (HTTPS) albo przez odpytywanie: `poll_only=true` i potem
 * `GET /api/v1/webhook_result/{request_id}` (404 `result_pending` + `retry_after_seconds` w trakcie, wynik do 30 dni).
 * Wynik: `people[].phone_numbers[]` z polami `raw_number`, `sanitized_number`, `type_cd` (np. `mobile`, `work_direct`).
 * Koszt zależy od zwróconych danych, planu i konfiguracji — w aplikacji traktowany jako NIEZNANY (bez deklarowania liczby
 * kredytów); zapisujemy `credits_consumed` z wyniku, jeśli Apollo go poda. Działanie żądania z prawdziwym kluczem: NOT VERIFIED.
 *
 * Kontrola: flaga UBR_APOLLO_PHONE_ENABLED (domyślnie wyłączone), uprawnienie `contacts.reveal` (owner / admin), osoba
 * z identyfikatorem Apollo, brak już pobranego numeru (ponowne pobranie tylko świadomie, trybem „refresh”), dzienny limit
 * organizacji, token potwierdzenia z okna dialogowego (10 min) + osobne potwierdzenie nieznanego kosztu, idempotencja.
 * Nie pobieramy e-maili (`reveal_personal_emails=false`); e-maile z odpowiedzi nie są zapisywane.
 */
class ApolloPhoneService
{
	public const MATCH_ENDPOINT = '/people/match';

	public const RESULT_ENDPOINT = '/webhook_result/';

	public const MAX_POLLS = 40;

	public const STATUS_LABELS = [
		'AVAILABLE' => 'Numer dostępny',
		'LOCKED' => 'Do odblokowania',
		'UNAVAILABLE' => 'Brak numeru u źródła',
		'UNKNOWN' => 'Numer nieznany',
		'ERROR' => 'Błąd pobierania',
	];

	public function __construct(
		private PhoneRevealRepository $reveals,
		private ContactRepository $contacts,
		private CompanyRepository $companies,
		private CompanyPhoneRepository $companyPhones,
		private ProviderHttpClient $http,
		private ApiRequestRepository $requests,
	) {
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/apollo/phone_enabled', defined('UBR_APOLLO_PHONE_ENABLED') && constant('UBR_APOLLO_PHONE_ENABLED')) && ApolloProvider::apiKey() !== null;
	}

	public static function dailyLimit(): int
	{
		return max(0, (int) (defined('UBR_APOLLO_PHONE_DAILY_LIMIT') ? constant('UBR_APOLLO_PHONE_DAILY_LIMIT') : 20));
	}

	/**
	 * Sprawdzenie przed odblokowaniem (bez zapytania do Apollo): uprawnienia, stan numeru, informacja o koszcie, token.
	 *
	 * @return array<string, mixed>
	 */
	public function preflight(TenantContext $ctx, int $contactId, bool $refresh = false): array
	{
		$contact = $this->contactFor($ctx, $contactId);
		$existing = $this->reveals->phonesFor($ctx->organizationId, [$contactId])[$contactId] ?? [];
		$latest = $this->reveals->latest($ctx->organizationId, $contactId);
		$apolloId = app(ApolloContactService::class)->apolloIdFor($ctx->organizationId, $contactId);
		$links = app(DecisionPhoneService::class)->forContacts($ctx, [$contactId])[$contactId] ?? [];
		$verified = array_filter($links, fn ($l) => ($l['status'] ?? '') === 'VERIFIED' && ! empty($l['direct']));
		$reasons = [];

		if (! self::enabled()) {
			$reasons[] = ['code' => 'disabled', 'message' => ApolloProvider::apiKey() === null ? 'Apollo nie jest skonfigurowane (brak klucza API).' : 'Odblokowywanie telefonów jest wyłączone (UBR_APOLLO_PHONE_ENABLED w wp-config.php — wymaga decyzji właściciela).'];
		}

		if (! $ctx->can('contacts.reveal')) {
			$reasons[] = ['code' => 'forbidden', 'message' => 'Odblokowanie płatnego numeru wymaga roli właściciela lub administratora organizacji.'];
		}

		if ($apolloId === null) {
			$reasons[] = ['code' => 'no_apollo_id', 'message' => 'Brak zatwierdzonego identyfikatora osoby Apollo — najpierw wyszukaj decydentów w Apollo i zatwierdź dopasowanie tej osoby.'];
		}

		if ($this->doNotContact($ctx->organizationId, (int) $contact['company_id'])) {
			$reasons[] = ['code' => 'dnc', 'message' => 'Firma ma status „Nie kontaktować” — pobieranie numeru jest zablokowane.'];
		}

		if ($verified !== [] && ! $refresh) {
			$reasons[] = ['code' => 'verified_exists', 'message' => 'Ta osoba ma już potwierdzony numer bezpośredni — pobieranie z Apollo nie jest potrzebne.'];
		}

		if ($latest && in_array($latest['status'], ['reserved', 'pending'], true)) {
			$reasons[] = ['code' => 'pending', 'message' => 'Pobieranie numeru już trwa — wynik pojawi się automatycznie.'];
		}

		if ($existing !== [] && ! $refresh) {
			$reasons[] = ['code' => 'already_revealed', 'message' => 'Numer został już pobrany. Ponowne pobranie wymaga osobnej, świadomej decyzji (może zużyć kredyty).'];
		}

		$limit = self::dailyLimit();

		if ($this->reveals->countSince($ctx->organizationId, gmdate('Y-m-d H:i:s', strtotime('today', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))) >= $limit) {
			$reasons[] = ['code' => 'limit', 'message' => sprintf('Osiągnięto dzienny limit odblokowań organizacji (%d).', $limit)];
		}

		$expires = time() + 600;
		$mode = $refresh ? 'refresh' : 'first';

		return [
			'contact_id' => $contactId,
			'can_unlock' => $reasons === [],
			'reasons' => $reasons,
			'mode' => $mode,
			'phone' => $this->phoneState($ctx, $contact, $existing, $latest),
			'cost' => self::costInfo(),
			'delivery' => 'Numer zostanie dostarczony asynchronicznie (zwykle kilka minut) — aplikacja sama odpyta wynik.',
			'confirm_token' => $reasons === [] ? $this->token($ctx, $contactId, $mode, $expires) . '.' . $expires : null,
		];
	}

	/**
	 * Informacja o koszcie: stawki z dokumentacji Apollo (10.2026) — koszt na koncie użytkownika NIEPOTWIERDZONY.
	 *
	 * @return array<string, mixed>
	 */
	public static function costInfo(): array
	{
		return [
			'known' => false,
			'label' => 'Koszt wymaga potwierdzenia',
			'documented' => ['min_credits' => 1, 'max_credits' => 9, 'source' => 'docs.apollo.io — People Enrichment (10.2026)'],
			'message' => 'Koszt wymaga potwierdzenia. Wg dokumentacji Apollo (10.2026) wzbogacenie osoby kosztuje 1–9 kredytów: 1 kredyt za dane osoby i +8 kredytów, gdy zwrócony zostanie numer komórkowy; kredyty są naliczane tylko za faktycznie znalezione dane. Na starszych planach i przy innej konfiguracji koszt może być inny — sprawdź w Apollo (Settings → Credits) przed pierwszym użyciem. Aplikacja nie pobiera e-maili.',
			'requires_acknowledgement' => true,
		];
	}

	/**
	 * Odblokowanie po potwierdzeniu w oknie dialogowym. Idempotentne: podwójne kliknięcie zwraca to samo zlecenie.
	 *
	 * @return array<string, mixed>
	 */
	public function reveal(TenantContext $ctx, int $contactId, string $token, bool $acknowledgedUnknownCost, bool $refresh = false): array
	{
		$check = $this->preflight($ctx, $contactId, $refresh);
		$mode = $refresh ? 'refresh' : 'first';
		[$sig, $expires] = array_pad(explode('.', $token, 2), 2, '0');

		if (! hash_equals($this->token($ctx, $contactId, $mode, (int) $expires), $sig) || (int) $expires < time()) {
			throw UbrException::invalid('Potwierdzenie wygasło lub jest nieprawidłowe — otwórz okno odblokowania ponownie.');
		}

		if (! $acknowledgedUnknownCost) {
			throw UbrException::invalid('Potwierdź, że koszt odblokowania jest nieznany i może zużyć kredyty Apollo.');
		}

		$pending = array_filter($check['reasons'], fn ($r) => $r['code'] === 'pending');
		$latest = $this->reveals->latest($ctx->organizationId, $contactId);

		if ($pending && $latest) {
			return $this->presentReveal($latest) + ['created' => false];
		}

		if (! $check['can_unlock']) {
			throw new UbrException($check['reasons'][0]['message'], 'ubr_' . $check['reasons'][0]['code'], $check['reasons'][0]['code'] === 'forbidden' ? 403 : 422);
		}

		$contact = $this->contactFor($ctx, $contactId);
		$key = sprintf('%d:%d:%d', $ctx->organizationId, $contactId, $this->reveals->countFor($ctx->organizationId, $contactId));
		$apolloId = (string) app(ApolloContactService::class)->apolloIdFor($ctx->organizationId, $contactId);
		$reservation = $this->reveals->reserve($ctx->organizationId, $contactId, (int) $contact['company_id'], $ctx->userId, $key);

		if (! $reservation['created']) {
			return $this->presentReveal((array) $this->reveals->find($reservation['id'])) + ['created' => false];
		}

		$this->reveals->update($reservation['id'], ['apollo_person_id' => $apolloId]);
		$this->request($reservation['id'], $apolloId, $ctx->organizationId);

		return $this->presentReveal((array) $this->reveals->find($reservation['id'])) + ['created' => true];
	}

	/**
	 * Zlecenie w Apollo (jedno zapytanie, bez automatycznego ponawiania — mogło zużyć kredyty).
	 */
	private function request(int $revealId, string $apolloPersonId, int $organizationId): void
	{
		// Obrona w głąb: płatne zapytanie nigdy bez włączonej funkcji i identyfikatora osoby (niezależnie od interfejsu).
		if (! self::enabled() || ! preg_match('/^[A-Za-z0-9]{6,64}$/', $apolloPersonId)) {
			$this->reveals->update($revealId, ['status' => 'error', 'error' => 'Odblokowywanie telefonów jest wyłączone albo brak identyfikatora osoby Apollo — nic nie zostało wysłane.', 'completed_at' => Db::now()]);

			return;
		}

		$url = ApolloProvider::BASE_URL . self::MATCH_ENDPOINT . '?' . http_build_query(['id' => $apolloPersonId, 'reveal_phone_number' => 'true', 'poll_only' => 'true', 'reveal_personal_emails' => 'false']);
		$res = $this->http->send('apollo', 'POST', $url, [
			'timeout' => 25,
			'headers' => ['x-api-key' => (string) ApolloProvider::apiKey(), 'Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'],
			'body' => '{}',
		], 1300);
		$status = $res['status'];
		$requestId = (string) ($res['data']['request_id'] ?? $res['data']['person']['request_id'] ?? '');
		$error = match (true) {
			$status === null => 'Apollo: brak odpowiedzi (' . mb_substr((string) $res['error'], 0, 80) . ').',
			$status === 401 => 'Apollo: nieprawidłowy klucz API.',
			$status === 403 => 'Apollo: brak dostępu do odblokowywania telefonów na obecnym planie / kluczu.',
			$status === 422 => 'Apollo: nieprawidłowe żądanie (' . mb_substr((string) ($res['data']['error'] ?? $res['data']['message'] ?? ''), 0, 120) . ').',
			$status === 429 => 'Apollo: przekroczony limit zapytań.',
			$status >= 400 => 'Apollo: błąd HTTP ' . $status . '.',
			! preg_match('/^-?\d{1,20}$/', $requestId) => 'Apollo nie zwróciło identyfikatora wyniku (request_id) — numeru nie da się odebrać.',
			default => null,
		};
		$this->requests->log(['organization_id' => $organizationId, 'provider' => 'apollo', 'endpoint' => self::MATCH_ENDPOINT, 'method' => 'POST', 'http_status' => $status, 'duration_ms' => $res['ms'], 'cached' => 0, 'billable' => 1, 'error' => $error]);

		if ($error) {
			$this->reveals->update($revealId, ['status' => 'error', 'error' => $error, 'completed_at' => Db::now()]);

			return;
		}

		$this->reveals->update($revealId, ['status' => 'pending', 'request_id' => $requestId, 'next_poll_at' => gmdate('Y-m-d H:i:s', time() + 60)]);
		app(ApolloPhoneRunner::class)->kick();
	}

	/**
	 * Odpytanie wyniku (worker). Zwraca nowy status zlecenia.
	 */
	public function poll(array $reveal): string
	{
		$id = (int) $reveal['id'];
		$url = ApolloProvider::BASE_URL . self::RESULT_ENDPOINT . rawurlencode((string) $reveal['request_id']);
		$res = $this->http->send('apollo', 'GET', $url, ['timeout' => 20, 'headers' => ['x-api-key' => (string) ApolloProvider::apiKey(), 'Cache-Control' => 'no-cache']], 1300);
		$status = $res['status'];
		$code = (string) ($res['data']['error_code'] ?? '');
		$attempts = (int) $reveal['attempts'] + 1;
		$this->requests->log(['organization_id' => (int) $reveal['organization_id'], 'provider' => 'apollo', 'endpoint' => '/webhook_result', 'method' => 'GET', 'http_status' => $status, 'duration_ms' => $res['ms'], 'cached' => 0, 'billable' => 0, 'error' => $status === 200 || $code === 'result_pending' ? null : 'HTTP ' . $status . ' ' . $code]);

		if ($status === 404 && $code === 'result_pending' || $status === null || $status === 429 || ($status >= 500)) {
			if ($attempts >= self::MAX_POLLS) {
				$this->reveals->update($id, ['status' => 'error', 'attempts' => $attempts, 'error' => 'Apollo nie dostarczyło wyniku w oczekiwanym czasie.', 'completed_at' => Db::now()]);

				return 'error';
			}

			$wait = max(30, min(900, (int) ($res['data']['retry_after_seconds'] ?? 60 * min(10, $attempts))));
			$this->reveals->update($id, ['attempts' => $attempts, 'next_poll_at' => gmdate('Y-m-d H:i:s', time() + $wait)]);

			return 'pending';
		}

		if ($status !== 200 || ! is_array($res['data'])) {
			$message = match ($code) {
				'request_id_unknown' => 'Apollo nie rozpoznaje zlecenia.',
				'request_id_expired' => 'Wynik w Apollo wygasł (30 dni).',
				'invalid_request_id' => 'Nieprawidłowy identyfikator zlecenia.',
				default => 'Apollo: błąd HTTP ' . $status . '.',
			};
			$this->reveals->update($id, ['status' => 'error', 'attempts' => $attempts, 'error' => $message, 'completed_at' => Db::now()]);

			return 'error';
		}

		return $this->applyResult($reveal, $res['data'], $attempts);
	}

	/**
	 * @param array<string, mixed> $data wynik (taki sam format jak payload webhooka)
	 */
	public function applyResult(array $reveal, array $data, int $attempts = 1): string
	{
		$id = (int) $reveal['id'];
		// Wynik przypisujemy wyłącznie osobie, dla której wysłano zlecenie (identyfikator zapisany w zleceniu).
		$externalId = (string) ($reveal['apollo_person_id'] ?? '');

		if ($externalId === '') {
			$externalId = (string) app(ApolloContactService::class)->apolloIdFor((int) $reveal['organization_id'], (int) $reveal['contact_id']);
		}

		$personal = [];
		$companyNumbers = [];

		foreach ((array) ($data['people'] ?? []) as $person) {
			// Tylko osoba, dla której zlecono odblokowanie (identyfikator Apollo) — bez przypisywania cudzych numerów.
			if (! is_array($person) || $externalId === '' || (isset($person['id']) && (string) $person['id'] !== $externalId)) {
				continue;
			}

			foreach ((array) ($person['phone_numbers'] ?? []) as $ph) {
				$n = is_array($ph) ? PhoneNumber::parse((string) ($ph['sanitized_number'] ?? $ph['raw_number'] ?? '')) : null;

				if (! $n) {
					continue;
				}

				$type = strtolower((string) ($ph['type_cd'] ?? $ph['type'] ?? ''));
				$entry = ['raw' => (string) ($ph['raw_number'] ?? $n['national']), 'e164' => $n['e164'], 'type' => match (true) {
					$type === 'mobile' => 'MOBILE',
					in_array($type, ['work_direct', 'direct', 'direct_dial'], true) => 'DIRECT',
					in_array($type, ['work_hq', 'hq', 'corporate', 'company'], true) => 'HQ',
					default => 'OTHER',
				}, 'confidence' => isset($ph['confidence_cd']) ? mb_substr((string) $ph['confidence_cd'], 0, 10) : null];

				// Numer centrali firmy nie jest numerem osoby.
				$entry['type'] === 'HQ' ? $companyNumbers[] = $entry : $personal[] = $entry;
			}
		}

		$saved = $this->reveals->savePhones((int) $reveal['organization_id'], (int) $reveal['contact_id'], (int) $reveal['company_id'], $id, $personal);

		foreach ($companyNumbers as $num) {
			$this->companyPhones->addManual((int) $reveal['company_id'], (int) $reveal['organization_id'], ['raw' => $num['raw'], 'e164' => $num['e164'], 'type' => 'MAIN', 'label' => 'Apollo (centrala)'], (int) $reveal['user_id']);
		}

		$credits = $data['credits_consumed'] ?? null;
		$state = $personal === [] ? 'unavailable' : 'completed';
		$this->reveals->update($id, ['status' => $state, 'attempts' => $attempts, 'phones_found' => count($personal), 'credits_consumed' => is_numeric($credits) ? (float) $credits : null, 'error' => null, 'completed_at' => Db::now()]);
		ActionCenterService::bumpOrganization((int) $reveal['organization_id']);

		return $state;
	}

	/**
	 * Stan telefonu osoby w organizacji. LOCKED tylko, gdy źródło jednoznacznie to potwierdza — obecne API tego nie robi,
	 * więc bez numeru stan to UNKNOWN (z informacją, że można spróbować odblokować).
	 *
	 * @param array<string, mixed> $contact
	 * @param list<array<string, mixed>> $revealed
	 * @return array<string, mixed>
	 */
	public function phoneState(TenantContext $ctx, array $contact, array $revealed, ?array $latest): array
	{
		$links = app(DecisionPhoneService::class)->forContacts($ctx, [(int) $contact['id']])[(int) $contact['id']] ?? [];
		$state = self::state($links, $revealed, $latest, app(ApolloContactService::class)->apolloIdFor($ctx->organizationId, (int) $contact['id']) !== null);
		$state['apollo'] = app(ApolloContactService::class)->approvedFor($ctx->organizationId, [(int) $contact['id']])[(int) $contact['id']] ?? null;

		return $state;
	}

	/**
	 * Numery osoby: powiązania ze strony / ręczne (Prompt 10.2 — tylko VERIFIED i LIKELY, wyraźnie rozróżnione) oraz numery
	 * pobrane z Apollo (identyfikator osoby). Powiązania UNCERTAIN / REJECTED nie są numerami decydenta; numer odrzucony
	 * przez organizację nie jest pokazywany także wtedy, gdy pochodzi z Apollo.
	 *
	 * @param list<array<string, mixed>> $links wynik DecisionPhoneService::present()
	 * @param list<array<string, mixed>> $revealed
	 * @return array<string, mixed>
	 */
	public static function state(array $links, array $revealed, ?array $latest, bool $hasApolloId): array
	{
		$numbers = [];
		$rejected = array_map(fn ($l) => $l['e164'], array_filter($links, fn ($l) => ($l['status'] ?? '') === 'REJECTED'));

		foreach ($links as $l) {
			if (empty($l['direct'])) {
				continue;
			}

			$verified = $l['status'] === 'VERIFIED';
			$numbers[] = [
				'number' => $l['number'], 'e164' => $l['e164'], 'tel' => $l['tel'], 'type' => 'DIRECT',
				'type_label' => $verified ? 'Potwierdzony — ' . mb_strtolower((string) $l['source_label']) : 'Prawdopodobny — do weryfikacji',
				'source' => strtolower((string) $l['source']), 'source_label' => $l['source_label'],
				'status' => $l['status'], 'status_label' => $l['status_label'], 'verified' => $verified,
				'evidence_label' => $l['evidence_label'] ?? null, 'link_id' => $l['id'] ?? null,
			];
		}

		foreach ($revealed as $r) {
			if (in_array($r['e164'], array_column($numbers, 'e164'), true) || in_array($r['e164'], $rejected, true)) {
				continue;
			}

			$numbers[] = ['number' => PhoneNumber::format((string) $r['e164']), 'e164' => $r['e164'], 'tel' => 'tel:' . $r['e164'], 'type' => $r['phone_type'], 'type_label' => ['MOBILE' => 'komórkowy (Apollo)', 'DIRECT' => 'bezpośredni (Apollo)'][$r['phone_type']] ?? 'Apollo', 'source' => 'apollo', 'source_label' => 'Apollo', 'status' => 'VERIFIED', 'status_label' => 'Apollo', 'verified' => true, 'evidence_label' => 'Numer osoby z Apollo (identyfikator osoby)', 'link_id' => null];
		}

		// Potwierdzone przed prawdopodobnymi.
		usort($numbers, fn ($a, $b) => (int) $b['verified'] <=> (int) $a['verified']);

		$lastStatus = $latest['status'] ?? null;
		$status = match (true) {
			$numbers !== [] => 'AVAILABLE',
			in_array($lastStatus, ['reserved', 'pending'], true) => 'UNKNOWN',
			$lastStatus === 'unavailable' => 'UNAVAILABLE',
			$lastStatus === 'error' => 'ERROR',
			default => 'UNKNOWN',
		};

		return [
			'status' => $status,
			'status_label' => in_array($lastStatus, ['reserved', 'pending'], true) && $numbers === [] ? 'Pobieranie numeru…' : self::STATUS_LABELS[$status],
			'pending' => in_array($lastStatus, ['reserved', 'pending'], true),
			'numbers' => $numbers,
			// Bez potwierdzonego numeru (także przy numerze „prawdopodobnym”) — świadome odblokowanie nadal możliwe.
			'reveal_possible' => $hasApolloId && ! array_filter($numbers, fn ($n) => $n['verified']) && ! in_array($lastStatus, ['reserved', 'pending'], true),
			'error' => $lastStatus === 'error' ? ($latest['error'] ?? null) : null,
		];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function presentReveal(array $r): array
	{
		return [
			'reveal_id' => (int) ($r['id'] ?? 0),
			'status' => $r['status'] ?? null,
			'error' => $r['error'] ?? null,
			'phones_found' => (int) ($r['phones_found'] ?? 0),
			'credits_consumed' => isset($r['credits_consumed']) ? (float) $r['credits_consumed'] : null,
			'message' => match ($r['status'] ?? '') {
				'pending', 'reserved' => 'Zlecono odblokowanie. Numer pojawi się automatycznie po dostarczeniu przez Apollo (zwykle kilka minut).',
				'error' => 'Nie udało się zlecić odblokowania: ' . ($r['error'] ?? 'błąd') . ' Ponowienie wymaga osobnej decyzji.',
				'completed' => 'Numer pobrany.',
				'unavailable' => 'Apollo nie zwróciło numeru tej osoby.',
				default => '',
			},
		];
	}

	private function doNotContact(int $organizationId, int $companyId): bool
	{
		$db = app(Db::class);

		return (bool) $db->value("SELECT 1 FROM {$db->table(\App\Ubr\Infrastructure\Database\Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d AND do_not_contact = 1 AND archived_at IS NULL LIMIT 1", $organizationId, $companyId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function contactFor(TenantContext $ctx, int $contactId): array
	{
		$contact = $this->contacts->find($contactId);

		if (! $contact || ! in_array((int) $contact['org_key'], [0, $ctx->organizationId], true) || ! $this->companies->isVisibleTo((int) $contact['company_id'], $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono osoby.');
		}

		return $contact;
	}

	private function token(TenantContext $ctx, int $contactId, string $mode, int $expires): string
	{
		return hash_hmac('sha256', implode('|', ['ubr_phone_reveal', $ctx->organizationId, $ctx->userId, $contactId, $mode, $expires]), wp_salt('auth'));
	}
}
