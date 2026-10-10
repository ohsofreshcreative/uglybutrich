<?php

namespace App\Ubr\Infrastructure\Contacts;

use App\Ubr\Domain\Analysis\HtmlDocument;
use App\Ubr\Domain\Contacts\PersonName;
use App\Ubr\Infrastructure\Http\PageFetcher;

/**
 * Pobieranie WYBRANYCH publicznych podstron firmy (zarząd, zespół, o nas, kontakt, media) — nie crawler całej witryny.
 *
 * - tylko linki opublikowane na stronie (bez zgadywania adresów), ta sama domena,
 * - limit podstron (konfigurowalny), limit zapytań i czasu, każdy adres i przekierowanie przez UrlGuard (SSRF) w PageFetcher,
 * - robots.txt respektowany (grupa `*` i nasz User-Agent), 403/429/anty-bot = koniec, bez obchodzenia.
 */
class ContactPageCrawler
{
	/** Typy podstron i wzorce (ścieżka lub tekst linku, bez diakrytyków) — kolejność = priorytet. */
	public const PAGE_KINDS = [
		'management' => ['score' => 100, 're' => '/(zarzad|wladze|wladz|management|leadership|kierownictwo|kadra[- ]zarzadzajaca|board|vorstand|geschaftsleitung|our[- ]leaders)/'],
		'team' => ['score' => 80, 're' => '/(zespol|^team|our[- ]team|nasz[- ]zespol|ludzie|people|osoby[- ]kontaktowe|kontakty|nasi[- ]specjalisci|who[- ]we[- ]are)/'],
		'investors' => ['score' => 60, 're' => '/(relacje[- ]inwestorskie|investor|dla[- ]akcjonariuszy|dla[- ]inwestorow|lad[- ]korporacyjny|corporate[- ]governance)/'],
		'about' => ['score' => 55, 're' => '/(o[- ]nas|o[- ]firmie|o[- ]spolce|poznaj[- ]nas|nasza[- ]firma|about|profil[- ]firmy|firma$|company$|historia|kim[- ]jestesmy|uber[- ]uns)/'],
		'contact' => ['score' => 50, 're' => '/(kontakt|contact|impressum)/'],
		'press' => ['score' => 30, 're' => '/(media|press|prasa|biuro[- ]prasowe|newsroom|dla[- ]mediow)/'],
	];

	private const EXCLUDE = '/(kariera|praca|jobs|career|rekrutacja|polityka|privacy|rodo|cookies|regulamin|sklep|shop|koszyk|cart|login|logowanie|\.(pdf|jpe?g|png|zip|docx?)$|wp-admin|feed|tag\/|category\/|\/\d{4}\/\d{2}\/)/';

	public function __construct(private PageFetcher $fetcher, private RobotsPolicy $robots)
	{
	}

	/**
	 * @param array{max_pages: int, max_requests: int, time_budget_seconds: int} $limits
	 * @return array{pages: list<array<string, mixed>>, docs: list<array{url: string, title: string, kind: string, html: string}>, errors: list<array<string, string>>, blocked: bool, requests: int}
	 */
	public function crawl(string $homeUrl, array $limits): array
	{
		$started = time();
		$requests = 0;
		$pages = [];
		$docs = [];
		$errors = [];
		$blocked = false;
		$deadline = $started + max(10, $limits['time_budget_seconds']);
		$maxRequests = max(2, $limits['max_requests']);

		// robots.txt (1 zapytanie, cache 1 dzień).
		$rules = $this->robots->rulesFor($homeUrl, $requests);

		if (! $this->robots->allowed($rules, (string) parse_url($homeUrl, PHP_URL_PATH) ?: '/')) {
			return ['pages' => [['url' => $homeUrl, 'kind' => 'home', 'status' => null, 'error' => 'robots.txt nie pozwala na pobranie strony']], 'docs' => [], 'errors' => [['stage' => 'robots', 'message' => 'robots.txt zabrania pobierania strony głównej — pominięto źródło.']], 'blocked' => true, 'requests' => $requests];
		}

		$home = $this->fetcher->fetch($homeUrl, 10);
		$requests++;
		$pages[] = ['url' => $homeUrl, 'kind' => 'home', 'status' => $home['status'], 'error' => $home['error']];

		if (! $home['ok']) {
			$blocked = in_array($home['status'], [401, 403, 429], true) || (bool) $home['blocked'];
			$errors[] = ['stage' => 'website', 'message' => $home['blocked'] ? 'Adres zablokowany (ochrona SSRF).' : 'Strona główna niedostępna (' . ($home['status'] ? 'HTTP ' . $home['status'] : mb_substr((string) $home['error'], 0, 80)) . ').'];

			return ['pages' => $pages, 'docs' => [], 'errors' => $errors, 'blocked' => $blocked, 'requests' => $requests];
		}

		$base = (string) ($home['final_url'] ?: $homeUrl);
		$homeDoc = new HtmlDocument($home['body'], $base);
		$docs[] = ['url' => $base, 'title' => (string) $homeDoc->title(), 'kind' => 'home', 'html' => $home['body']];

		$queue = $this->candidates($homeDoc, $base, [$base]);
		$seen = [rtrim(strtolower($base), '/') => true];
		$fetched = 0;

		while ($queue && $fetched < $limits['max_pages'] && $requests < $maxRequests && time() < $deadline) {
			$next = array_shift($queue);
			$key = rtrim(strtolower($next['url']), '/');

			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;

			if (! $this->robots->allowed($rules, (string) parse_url($next['url'], PHP_URL_PATH) ?: '/')) {
				$pages[] = ['url' => $next['url'], 'kind' => $next['kind'], 'status' => null, 'error' => 'robots.txt — pominięto'];
				continue;
			}

			$res = $this->fetcher->fetch($next['url'], 8);
			$requests++;
			$fetched++;
			$pages[] = ['url' => $next['url'], 'kind' => $next['kind'], 'status' => $res['status'], 'error' => $res['error']];

			if (in_array($res['status'], [403, 429], true)) {
				// Ochrona antybotowa / limit — przerywamy, bez obchodzenia.
				$blocked = true;
				$errors[] = ['stage' => 'website', 'message' => 'Serwis odmówił dostępu (HTTP ' . $res['status'] . ') — pobieranie przerwane.'];
				break;
			}

			if (! $res['ok']) {
				continue;
			}

			$doc = new HtmlDocument($res['body'], (string) ($res['final_url'] ?: $next['url']));
			$docs[] = ['url' => (string) ($res['final_url'] ?: $next['url']), 'title' => (string) $doc->title(), 'kind' => $next['kind'], 'html' => $res['body']];

			// Drugi poziom: z „o nas” / „relacje inwestorskie” prowadzą linki do „zarząd” (np. Profil firmy → Zarząd).
			if (in_array($next['kind'], ['about', 'investors'], true)) {
				foreach ($this->candidates($doc, $base, array_keys($seen)) as $sub) {
					if (in_array($sub['kind'], ['management', 'team'], true)) {
						$queue[] = $sub;
					}
				}

				usort($queue, fn ($a, $b) => [$b['score'], strlen($a['url'])] <=> [$a['score'], strlen($b['url'])]);
			}
		}

		if (time() >= $deadline) {
			$errors[] = ['stage' => 'website', 'message' => 'Przekroczony limit czasu wyszukiwania — wyniki częściowe.'];
		}

		return ['pages' => $pages, 'docs' => $docs, 'errors' => $errors, 'blocked' => $blocked, 'requests' => $requests];
	}

	/**
	 * Podstrony do sprawdzenia: linki wewnętrzne pasujące do typów (zarząd > zespół > relacje inw. > o nas > kontakt > media).
	 *
	 * @param list<string> $exclude
	 * @return list<array{url: string, kind: string, score: int}>
	 */
	public function candidates(HtmlDocument $doc, string $base, array $exclude): array
	{
		$baseHost = preg_replace('/^www\./', '', strtolower((string) parse_url($base, PHP_URL_HOST))) ?? '';
		$excluded = array_map(fn ($u) => rtrim(strtolower((string) strtok($u, '#')), '/'), $exclude);
		$out = [];

		foreach ($doc->links() as $l) {
			if (! $l['internal']) {
				continue;
			}

			$url = (string) strtok(PageFetcher::absolute($l['href'], $base), '#');
			$host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
			$path = strtolower((string) parse_url($url, PHP_URL_PATH));

			if ($host !== $baseHost || in_array(rtrim($path, '/'), ['', '/'], true) || in_array(rtrim(strtolower($url), '/'), $excluded, true)) {
				continue;
			}

			$foldedPath = PersonName::fold(rawurldecode($path));
			$foldedText = PersonName::fold($l['text']);

			if (preg_match(self::EXCLUDE, $foldedPath)) {
				continue;
			}

			foreach (self::PAGE_KINDS as $kind => $def) {
				// Ostatni segment ścieżki (np. /pl/zarzad) albo tekst linku.
				$segment = trim((string) strrchr('/' . trim($foldedPath, '/'), '/'), '/');

				if (preg_match($def['re'], $segment) || preg_match($def['re'], $foldedText)) {
					$score = $def['score'] + ($l['in_nav'] || $l['in_header'] ? 5 : 0);
					$key = rtrim(strtolower($url), '/');

					if (! isset($out[$key]) || $out[$key]['score'] < $score) {
						$out[$key] = ['url' => $url, 'kind' => $kind, 'score' => $score];
					}

					break;
				}
			}
		}

		$out = array_values($out);
		usort($out, fn ($a, $b) => [$b['score'], strlen($a['url']), $a['url']] <=> [$a['score'], strlen($b['url']), $b['url']]);

		return $out;
	}
}
