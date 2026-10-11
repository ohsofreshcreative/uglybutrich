<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Analysis\Age\AgeEstimator;
use App\Ubr\Domain\Analysis\Age\FingerprintComparator;
use App\Ubr\Domain\Analysis\Age\FingerprintExtractor;
use App\Ubr\Domain\Analysis\Age\TechSignals;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Http\PageFetcher;
use App\Ubr\Infrastructure\Http\WaybackClient;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;

/**
 * Prompt 10.4.3 — Website Age Intelligence: szacowany wiek OBECNEGO projektu strony firmy.
 *
 * Działa wyłącznie w tle, z niskim priorytetem (WebsiteAgeRunner ustępuje weryfikacji firm), po analizie strony
 * (Discover) albo na żądanie z karty firmy. Nigdy przy wyświetlaniu list / paginacji.
 *
 * Analiza jest podzielona na małe kroki (jedno zapytanie do archiwum na krok) z zapisem postępu — przerwany proces
 * (np. limit hostingu) nie traci wyników, a kolejny krok kontynuuje. Kroki:
 *  1. obecna strona (z pamięci podręcznej stron) → odcisk projektu + poszlaki techniczne i metadane,
 *  2. lista kopii w Wayback Machine (CDX, jedna na miesiąc, cache 14 dni),
 *  3. kopie reprezentatywne: najnowsza, ~1 / 3 / 5 / 8 / 12 lat temu i najstarsza (maks. 7; identyczne kopie po
 *     skrócie treści nie są pobierane ponownie; odciski kopii w cache 180 dni),
 *  4. doprecyzowanie granicy (maks. 2 dodatkowe kopie między ostatnią wersją innego projektu a pierwszą obecnego),
 *  5. oszacowanie (AgeEstimator) i zapis z dowodami i poziomem pewności.
 * Archiwum niedostępne (429 / 5xx / timeout) → ponowienie po 30 min, 3 h, 12 h; po 3 próbach wynik UNKNOWN.
 */
class WebsiteAgeService
{
	public const FRESH_DAYS = 90;

	public const COOLDOWN_HOURS = 24;

	public const MAX_ATTEMPTS = 3;

	public const BACKOFF = [1800, 10800, 43200];

	public const TARGET_YEARS = [1, 3, 5, 8, 12];

	public const MAX_SAMPLES = 7;

	public const MAX_REFINE = 3;

	public const STATE_LABELS = ['queued' => 'Oczekuje na analizę wieku', 'running' => 'Analiza wieku w toku', 'done' => 'Zakończona', 'failed' => 'Nie udało się ustalić'];

	public function __construct(private Db $db, private CompanyRepository $companies, private WaybackClient $wayback, private PageFetcher $fetcher)
	{
	}

	public static function enabled(): bool
	{
		return (bool) apply_filters('ubr/site_age/enabled', ! defined('UBR_SITE_AGE') || constant('UBR_SITE_AGE'));
	}

	/** Maks. liczba nowych analiz wieku dziennie (instalacja; UBR_SITE_AGE_DAILY_LIMIT, domyślnie 150). */
	public static function dailyLimit(): int
	{
		return max(0, (int) apply_filters('ubr/site_age/daily_limit', defined('UBR_SITE_AGE_DAILY_LIMIT') ? (int) constant('UBR_SITE_AGE_DAILY_LIMIT') : 150));
	}

	/**
	 * Zlecenie analizy wieku (bez wykonywania jej w tym żądaniu).
	 *
	 * @return array{queued: bool, reason: ?string, message: string}
	 */
	public function queue(int $companyId, bool $force = false): array
	{
		$company = $this->companies->find($companyId);
		$no = fn (string $reason, string $message) => ['queued' => false, 'reason' => $reason, 'message' => $message];

		if (! self::enabled()) {
			return $no('disabled', 'Website Age Intelligence jest wyłączone.');
		}

		if (! $company || empty($company['domain']) || empty($company['website_url'])) {
			return $no('no_website', 'Firma nie ma strony WWW.');
		}

		if (($company['domain_status'] ?? '') === 'mismatch') {
			return $no('domain_mismatch', 'Domena należy do innego podmiotu — wiek tej strony nie dotyczy firmy.');
		}

		if (in_array($company['site_age_state'] ?? '', ['queued', 'running'], true)) {
			return $no('in_progress', 'Analiza wieku strony już trwa.');
		}

		$checked = ! empty($company['site_age_checked_at']) ? time() - strtotime($company['site_age_checked_at'] . ' UTC') : null;

		if ($checked !== null && ! $force && $checked < self::FRESH_DAYS * DAY_IN_SECONDS) {
			return $no('fresh', 'Wiek strony był sprawdzany w ostatnich ' . self::FRESH_DAYS . ' dniach.');
		}

		if ($checked !== null && $force && $checked < self::COOLDOWN_HOURS * HOUR_IN_SECONDS) {
			return $no('cooldown', 'Wiek strony sprawdzano przed chwilą — ponowna analiza możliwa po ' . self::COOLDOWN_HOURS . ' h.');
		}

		$updated = $this->db->execute(
			"UPDATE {$this->t()} SET site_age_state = 'queued', site_age_attempts = 0, site_age_next_at = %s, site_age_locked_until = NULL WHERE id = %d AND (site_age_state IS NULL OR site_age_state NOT IN ('queued','running'))",
			Db::now(),
			$companyId
		);

		if ($updated > 0) {
			delete_transient(self::workKey($companyId));
			app(WebsiteAgeRunner::class)->kick();
		}

		return $updated > 0 ? ['queued' => true, 'reason' => null, 'message' => 'Zlecono analizę wieku strony (w tle).'] : $no('in_progress', 'Analiza wieku strony już trwa.');
	}

	public function countDue(): int
	{
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->t()} WHERE (site_age_state = 'queued' AND site_age_next_at <= %s) OR (site_age_state = 'running' AND site_age_locked_until < %s)",
			Db::now(),
			Db::now()
		);
	}

	/**
	 * Atomowe pobranie kolejnej firmy (bez zwiększania liczby prób — próbę zużywa dopiero błąd archiwum).
	 *
	 * @return array<string, mixed>|null
	 */
	public function claim(int $lockSeconds = 60): ?array
	{
		$now = Db::now();
		$rows = $this->db->rows(
			"SELECT id FROM {$this->t()} WHERE (site_age_state = 'queued' AND site_age_next_at <= %s) OR (site_age_state = 'running' AND site_age_locked_until < %s) ORDER BY site_age_next_at ASC, id ASC LIMIT 5",
			$now,
			$now
		);

		foreach ($rows as $row) {
			$id = (int) $row['id'];

			// Nowa analiza (bez zapisanego postępu) tylko w granicach limitu dziennego.
			if (! get_transient(self::workKey($id)) && self::usedToday() >= self::dailyLimit()) {
				continue;
			}

			$claimed = $this->db->execute(
				"UPDATE {$this->t()} SET site_age_state = 'running', site_age_locked_until = %s WHERE id = %d AND ((site_age_state = 'queued' AND site_age_next_at <= %s) OR (site_age_state = 'running' AND site_age_locked_until < %s))",
				gmdate('Y-m-d H:i:s', time() + $lockSeconds),
				$id,
				$now,
				$now
			);

			if ($claimed > 0) {
				return $this->companies->find($id);
			}
		}

		return null;
	}

	/** Zwrot firmy do kolejki bez zużycia próby (koniec budżetu kroku workera — postęp zapisany). */
	public function release(int $companyId): void
	{
		$this->db->execute("UPDATE {$this->t()} SET site_age_state = 'queued', site_age_locked_until = NULL, site_age_next_at = %s WHERE id = %d AND site_age_state = 'running'", Db::now(), $companyId);
	}

	/**
	 * Kroki analizy jednej firmy do wyczerpania czasu. Zwraca `finished` (wynik zapisany), `retry` (archiwum
	 * niedostępne — ponowienie zaplanowane) albo żadne z nich (czas kroku minął — postęp zapisany).
	 *
	 * @param array<string, mixed> $company
	 * @return array{finished: bool, retry: bool, requests: int}
	 */
	public function step(array $company, float $deadline): array
	{
		$id = (int) $company['id'];
		$work = get_transient(self::workKey($id));
		$requests = 0;

		if (! is_array($work)) {
			$work = ['phase' => 'live', 'results' => [], 'fps' => [], 'plan' => [], 'refine' => 0];
			self::countToday();
		}

		while (true) {
			$left = $deadline - microtime(true);

			if ($work['phase'] === 'live') {
				$page = $this->fetcher->fetch((string) $company['website_url'], (int) max(3, min(8, floor($left))));

				if (empty($page['ok']) || (string) ($page['body'] ?? '') === '') {
					// Strona chwilowo nieosiągalna → ograniczone ponowienia (30 min / 3 h), potem UNKNOWN z uzasadnieniem.
					$work['archive'] = 'site_unreachable';

					return $this->retryLater($company, $work, 'Strona firmy nie odpowiadała.') + ['requests' => $requests];
				} else {
					unset($work['archive']);
					$work['current'] = FingerprintExtractor::extract((string) $page['body'], (string) ($page['final_url'] ?: $company['website_url']));
					$work['tech'] = TechSignals::analyze((string) $page['body'], (string) ($page['final_url'] ?: $company['website_url']), (array) ($page['headers'] ?? []));
					$work['phase'] = 'captures';
				}

				$this->save($id, $work);
				continue;
			}

			if ($work['phase'] === 'finalize') {
				$this->finalize($company, $work);

				return ['finished' => true, 'retry' => false, 'requests' => $requests];
			}

			// Kolejne zapytanie do archiwum tylko, gdy zmieści się w budżecie kroku (krótki timeout).
			if ($left < 3.0) {
				$this->save($id, $work);

				return ['finished' => false, 'retry' => false, 'requests' => $requests];
			}

			$timeout = (int) max(2, min(6, floor($left - 1)));

			if ($work['phase'] === 'captures') {
				$r = $this->wayback->captures((string) $company['domain'], $timeout);
				$requests += $r['cached'] ? 0 : 1;

				if (! $r['ok']) {
					return $this->retryLater($company, $work, (string) $r['error']) + ['requests' => $requests];
				}

				if ($r['captures'] === []) {
					$work['archive'] = 'empty';
					$work['phase'] = 'finalize';
				} else {
					$work['archive'] = 'ok';
					$work['first_ts'] = $r['captures'][0]['ts'];
					$work['count'] = count($r['captures']);
					$work['captures'] = $r['captures'];
					$work['plan'] = self::plan($r['captures'], gmdate('YmdHis'));
					$work['phase'] = 'samples';
				}

				$this->save($id, $work);
				continue;
			}

			// phase = samples
			$ts = array_shift($work['plan']);

			if ($ts === null) {
				$extra = self::refinement($work);

				if ($extra !== null) {
					$work['plan'][] = $extra;
					$work['refine']++;
				} else {
					$work['phase'] = 'finalize';
				}

				$this->save($id, $work);
				continue;
			}

			$fp = $this->sampleFingerprint($ts, (string) $company['domain'], $work, $timeout, $requests);

			if ($fp === 'unavailable') {
				array_unshift($work['plan'], $ts);

				return $this->retryLater($company, $work, 'Archiwum nie odpowiadało (kopia ' . AgeEstimator::date($ts) . ').') + ['requests' => $requests];
			}

			if (is_array($fp)) {
				$work['fps'][$ts] = $fp;
				$work['results'][$ts] = FingerprintComparator::compare($work['current'], $fp);
			} else {
				$work['results'][$ts] = ['verdict' => 'error', 'similarity' => null, 'parts' => [], 'reason' => (string) $fp];
			}

			$this->save($id, $work);
		}
	}

	/**
	 * Reprezentatywne kopie: najnowsza, ~1/3/5/8/12 lat temu (najbliższa dostępna), najstarsza; malejąco.
	 *
	 * @param list<array{ts: string, digest: string}> $captures
	 * @return list<string>
	 */
	public static function plan(array $captures, string $nowTs): array
	{
		if ($captures === []) {
			return [];
		}

		$months = fn (string $ts) => (int) substr($ts, 0, 4) * 12 + (int) substr($ts, 4, 2);
		$now = $months($nowTs);
		$picked = [end($captures)['ts'], $captures[0]['ts']];

		foreach (self::TARGET_YEARS as $years) {
			$target = $now - $years * 12;

			if ($target < $months($captures[0]['ts']) - 6) {
				continue;
			}

			$best = null;

			foreach ($captures as $c) {
				if ($best === null || abs($months($c['ts']) - $target) < abs($months($best) - $target)) {
					$best = $c['ts'];
				}
			}

			$picked[] = $best;
		}

		$picked = array_values(array_unique(array_filter($picked)));
		rsort($picked);

		return array_slice($picked, 0, self::MAX_SAMPLES);
	}

	/**
	 * Dodatkowa kopia (wyszukiwanie połówkowe) między ostatnią wersją innego projektu a pierwszą obecnego — gdy granica
	 * jest szersza niż 13 mies. albo obejmuje dwa lata kalendarzowe; maks. MAX_REFINE dodatkowych kopii.
	 *
	 * @param array<string, mixed> $work
	 */
	public static function refinement(array $work): ?string
	{
		if (($work['refine'] ?? 0) >= self::MAX_REFINE || empty($work['captures'])) {
			return null;
		}

		$results = (array) ($work['results'] ?? []);
		krsort($results);
		$newer = null;
		$old = null;

		foreach ($results as $ts => $r) {
			if (! in_array($r['verdict'], ['same', 'minor', 'major'], true)) {
				continue;
			}

			if ($r['verdict'] === 'major') {
				$old = (string) $ts;
				break;
			}

			$newer = (string) $ts;
		}

		$upper = $newer ?? gmdate('YmdHis');

		// Granica wystarczająco dokładna: ≤ 13 mies. i w jednym roku kalendarzowym (jednoznaczny rok wdrożenia).
		if ($old === null || (AgeEstimator::months($old, $upper) <= 13 && substr($old, 0, 4) === substr($upper, 0, 4))) {
			return null;
		}

		$between = array_values(array_filter(array_column((array) $work['captures'], 'ts'), fn ($ts) => strcmp($ts, $old) > 0 && strcmp($ts, $upper) < 0 && ! isset($results[$ts])));

		return $between ? $between[intdiv(count($between), 2)] : null;
	}

	/**
	 * @param array<string, mixed> $work
	 * @return array<string, mixed>|string odcisk, komunikat błędu albo 'unavailable'
	 */
	private function sampleFingerprint(string $ts, string $domain, array &$work, int $timeout, int &$requests): array|string
	{
		$key = 'ubr_wb_fp_' . md5($ts . '|' . strtolower($domain));
		$cached = get_transient($key);

		if (is_array($cached)) {
			return $cached;
		}

		// Ta sama treść (skrót CDX) co kopia już pobrana → ten sam odcisk, bez zapytania.
		$digest = '';

		foreach ((array) ($work['captures'] ?? []) as $c) {
			if ($c['ts'] === $ts) {
				$digest = $c['digest'];
			}
		}

		foreach ((array) ($work['captures'] ?? []) as $c) {
			if ($digest !== '' && $c['digest'] === $digest && isset($work['fps'][$c['ts']])) {
				return $work['fps'][$c['ts']];
			}
		}

		$snap = $this->wayback->snapshot($ts, $domain, $timeout);
		$requests++;

		if (! $snap['ok']) {
			return $snap['unavailable'] ? 'unavailable' : 'Kopia niedostępna w archiwum (' . $snap['error'] . ').';
		}

		$fp = FingerprintExtractor::extract($snap['html'], 'http://' . $domain . '/');
		set_transient($key, $fp, 180 * DAY_IN_SECONDS);

		return $fp;
	}

	/**
	 * @param array<string, mixed> $company
	 * @param array<string, mixed> $work
	 * @return array{finished: bool, retry: bool}
	 */
	private function retryLater(array $company, array $work, string $error): array
	{
		$id = (int) $company['id'];
		$attempts = (int) ($company['site_age_attempts'] ?? 0) + 1;

		if ($attempts >= self::MAX_ATTEMPTS) {
			$work['archive'] = ($work['archive'] ?? '') === 'site_unreachable' ? 'site_unreachable' : 'unavailable';
			$work['last_error'] = $error;
			$this->finalize($company, $work, 'failed');

			return ['finished' => true, 'retry' => false];
		}

		$this->save($id, $work);
		$this->db->execute(
			"UPDATE {$this->t()} SET site_age_state = 'queued', site_age_attempts = %d, site_age_next_at = %s, site_age_locked_until = NULL WHERE id = %d",
			$attempts,
			gmdate('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]),
			$id
		);

		return ['finished' => false, 'retry' => true];
	}

	/**
	 * @param array<string, mixed> $company
	 * @param array<string, mixed> $work
	 */
	private function finalize(array $company, array $work, string $state = 'done'): void
	{
		$samples = [];

		foreach ((array) ($work['results'] ?? []) as $ts => $r) {
			$samples[] = ['ts' => (string) $ts, 'verdict' => $r['verdict'], 'similarity' => $r['similarity'] ?? null, 'reason' => $r['reason'] ?? ''];
		}

		usort($samples, fn ($a, $b) => strcmp($b['ts'], $a['ts']));
		$pairs = [];

		for ($i = 0; $i + 1 < count($samples); $i++) {
			$a = $work['fps'][$samples[$i]['ts']] ?? null;
			$b = $work['fps'][$samples[$i + 1]['ts']] ?? null;

			if ($a && $b) {
				$pairs[] = ['newer' => $samples[$i]['ts'], 'older' => $samples[$i + 1]['ts'], 'verdict' => FingerprintComparator::compare($a, $b)['verdict']];
			}
		}

		$e = AgeEstimator::estimate($samples, $pairs, $work['first_ts'] ?? null, (int) ($work['count'] ?? 0), (array) ($work['tech'] ?? []), (string) ($work['archive'] ?? 'unavailable'), (int) gmdate('Y'));

		if (! empty($work['last_error'])) {
			$e['evidence'][] = ['source' => 'wayback', 'text' => 'Ostatni błąd archiwum: ' . mb_substr((string) $work['last_error'], 0, 160)];
		}

		$this->companies->update((int) $company['id'], [
			'site_age_state' => $state,
			'site_age_locked_until' => null,
			'site_age_next_at' => null,
			'site_age_status' => $e['status'],
			'site_age_confidence' => $e['confidence'],
			'site_launch_year' => $e['launch_year'],
			'site_launch_from' => $e['launch_from'],
			'site_launch_to' => $e['launch_to'],
			'site_age_years' => $e['age_years'],
			'site_last_redesign_year' => $e['last_redesign_year'],
			'site_modernity' => $e['modernity'],
			'site_age_evidence' => wp_json_encode([
				'summary' => $e['summary'],
				'age_max' => $e['age_max'],
				'first_seen_year' => $e['first_seen_year'],
				'earlier_redesigns' => $e['earlier_redesigns'],
				'archive' => $e['archive'],
				'samples' => $e['samples'],
				'evidence' => array_slice($e['evidence'], 0, 20),
				'sources' => array_values(array_unique(array_merge($e['archive'] === 'ok' ? ['Wayback Machine (Internet Archive)'] : [], ! empty($work['tech']) ? ['Analiza HTML / CSS / JS obecnej strony'] : []))),
			]),
			'site_age_checked_at' => Db::now(),
		]);
		delete_transient(self::workKey((int) $company['id']));
		do_action('ubr/company/changed', (int) $company['id'], 'site_age_done', null);
	}

	/**
	 * Sekcja „Website Age Intelligence” w karcie firmy.
	 *
	 * @param array<string, mixed> $company
	 * @return array<string, mixed>
	 */
	public static function present(array $company): array
	{
		$evidence = json_decode((string) ($company['site_age_evidence'] ?? ''), true) ?: [];
		$status = $company['site_age_status'] ?? null;
		$from = self::intOrNull($company['site_launch_from'] ?? null);
		$to = self::intOrNull($company['site_launch_to'] ?? null);
		$year = self::intOrNull($company['site_launch_year'] ?? null);
		$age = isset($company['site_age_years']) && $company['site_age_years'] !== null ? (int) $company['site_age_years'] : null;
		$ageMax = isset($evidence['age_max']) ? (int) $evidence['age_max'] : null;

		return [
			'state' => $company['site_age_state'] ?? null,
			'state_label' => self::STATE_LABELS[$company['site_age_state'] ?? ''] ?? 'Nie sprawdzano',
			'status' => $status,
			'status_label' => $status ? (AgeEstimator::STATUS_LABELS[$status] ?? $status) : null,
			'confidence' => $company['site_age_confidence'] ?? null,
			'confidence_label' => ! empty($company['site_age_confidence']) ? (AgeEstimator::CONFIDENCE_LABELS[$company['site_age_confidence']] ?? $company['site_age_confidence']) : null,
			'launch_label' => $year ? (string) $year : ($from && $to ? $from . '–' . $to : ($to ? 'co najmniej od ' . $to : null)),
			'age_label' => $age === null ? null : ($ageMax !== null && $ageMax > $age ? sprintf('około %d–%d %s', $age, $ageMax, AgeEstimator::years($ageMax)) : ($from ? sprintf('około %d %s', $age, AgeEstimator::years($age)) : sprintf('co najmniej %d %s', $age, AgeEstimator::years($age)))),
			'last_redesign_label' => ! empty($company['site_last_redesign_year']) ? 'prawdopodobnie ' . (int) $company['site_last_redesign_year'] : 'nie wykryto w archiwum',
			'modernity' => $company['site_modernity'] ?? null,
			'modernity_label' => AgeEstimator::MODERNITY_LABELS[$company['site_modernity'] ?? 'unknown'] ?? null,
			'summary' => $evidence['summary'] ?? null,
			'sources' => array_values((array) ($evidence['sources'] ?? [])),
			'evidence' => array_values((array) ($evidence['evidence'] ?? [])),
			'samples' => array_values((array) ($evidence['samples'] ?? [])),
			'earlier_redesigns' => (int) ($evidence['earlier_redesigns'] ?? 0),
			'checked_at' => ! empty($company['site_age_checked_at']) ? gmdate('c', strtotime($company['site_age_checked_at'] . ' UTC')) : null,
			'short' => self::shortFor($company),
		];
	}

	/** Kompaktowa informacja na listę (WWW: ~2018 / ~8 lat / nowa / wiek nieznany / sprawdzanie). */
	public static function shortFor(array $r): ?string
	{
		$state = $r['site_age_state'] ?? null;

		if ($state === null && empty($r['site_age_status'])) {
			return null;
		}

		if (in_array($state, ['queued', 'running'], true) && empty($r['site_age_status'])) {
			return 'sprawdzanie';
		}

		return AgeEstimator::short($r['site_age_status'] ?? null, self::intOrNull($r['site_launch_year'] ?? null), self::intOrNull($r['site_launch_from'] ?? null), self::intOrNull($r['site_launch_to'] ?? null), isset($r['site_age_years']) && $r['site_age_years'] !== null ? (int) $r['site_age_years'] : null);
	}

	private static function intOrNull(mixed $v): ?int
	{
		return $v === null || (int) $v === 0 ? null : (int) $v;
	}

	public static function workKey(int $companyId): string
	{
		return 'ubr_site_age_work_' . $companyId;
	}

	public static function usedToday(): int
	{
		$data = (array) get_option('ubr_site_age_day', []);

		return ($data['date'] ?? '') === gmdate('Y-m-d') ? (int) ($data['count'] ?? 0) : 0;
	}

	private static function countToday(): void
	{
		update_option('ubr_site_age_day', ['date' => gmdate('Y-m-d'), 'count' => self::usedToday() + 1], false);
	}

	/** @param array<string, mixed> $work */
	private function save(int $companyId, array $work): void
	{
		set_transient(self::workKey($companyId), $work, 2 * DAY_IN_SECONDS);
		$this->db->execute("UPDATE {$this->t()} SET site_age_locked_until = %s WHERE id = %d AND site_age_state = 'running'", gmdate('Y-m-d H:i:s', time() + 60), $companyId);
	}

	private function t(): string
	{
		return $this->db->table(Tables::COMPANIES);
	}
}
