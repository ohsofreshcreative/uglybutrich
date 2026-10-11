<?php

namespace App\Ubr\Domain\Analysis\Age;

use App\Ubr\Domain\Analysis\HtmlDocument;

/**
 * Prompt 10.4.3 — poszlaki technologiczne i metadane obecnej strony (Website Age Intelligence, metody 2–3).
 *
 * Wyłącznie poszlaki: wersja biblioteki nie oznacza, że cała strona powstała w tym roku (biblioteki aktualizuje się
 * na starym projekcie i odwrotnie), a data publikacji artykułu czy aktualizacji treści nie jest datą wdrożenia projektu.
 * Na ich podstawie oceniana jest NOWOCZESNOŚĆ techniczna (modern / mixed / dated), nigdy rok wdrożenia.
 */
final class TechSignals
{
	/** Rok wydania wersji głównej/pobocznej (przybliżony; do opisu poszlak). */
	private const JQUERY = ['1.4' => 2010, '1.5' => 2011, '1.6' => 2011, '1.7' => 2011, '1.8' => 2012, '1.9' => 2013, '1.10' => 2013, '1.11' => 2014, '1.12' => 2016, '2.0' => 2013, '2.1' => 2014, '2.2' => 2015, '3.0' => 2016, '3.1' => 2016, '3.2' => 2017, '3.3' => 2018, '3.4' => 2019, '3.5' => 2020, '3.6' => 2021, '3.7' => 2023];

	private const BOOTSTRAP = ['2' => 2012, '3' => 2013, '4' => 2018, '5' => 2021];

	private const FONT_AWESOME = ['3' => 2012, '4' => 2013, '5' => 2017, '6' => 2022];

	/**
	 * @param array<string, string> $headers
	 * @return array{signals: list<array{key: string, label: string, kind: string, year: ?int}>, modernity: string, copyright_year: ?int, dates: list<array{source: string, value: string}>}
	 */
	public static function analyze(string $html, string $url, array $headers = [], ?int $nowYear = null): array
	{
		$nowYear ??= (int) gmdate('Y');
		$doc = new HtmlDocument($html, $url);
		$signals = [];
		$add = function (string $key, string $label, string $kind, ?int $year = null) use (&$signals): void {
			$signals[] = compact('key', 'label', 'kind', 'year');
		};
		$assets = implode(' ', array_merge(
			array_map(fn ($e) => (string) $e->getAttribute('href'), $doc->all('//link[@href]')),
			array_map(fn ($e) => (string) $e->getAttribute('src'), $doc->all('//script[@src]'))
		));

		if (preg_match('/jquery[.-]?(\d+)\.(\d+)(?:\.\d+)?(?:\.min)?\.js|jquery\.js\?ver=(\d+)\.(\d+)/i', $assets, $m)) {
			$ver = ($m[1] !== '' ? $m[1] . '.' . $m[2] : $m[3] . '.' . $m[4]);
			$year = self::JQUERY[$ver] ?? null;
			$add('jquery', 'jQuery ' . $ver . ($year ? ' (wydanie ok. ' . $year . ')' : ''), $year !== null && $year <= $nowYear - 8 ? 'dated' : 'neutral', $year);
		}

		if (preg_match('/bootstrap(?:[\/.-]v?|\.min\.css\?ver=|\.css\?ver=)(\d)(?:\.\d+)*/i', $assets, $m) || preg_match('/Bootstrap v(\d)\.\d+/i', $html, $m)) {
			$year = self::BOOTSTRAP[$m[1]] ?? null;
			$add('bootstrap', 'Bootstrap ' . $m[1] . ($year ? ' (od ' . $year . ')' : ''), in_array($m[1], ['4', '5'], true) ? 'modern' : 'dated', $year);
		}

		if (preg_match('/font-?awesome(?:[\/.-]v?|.*?ver=)(\d)(?:\.\d+)*/i', $assets, $m)) {
			$year = self::FONT_AWESOME[$m[1]] ?? null;
			$add('fontawesome', 'Font Awesome ' . $m[1], in_array($m[1], ['5', '6'], true) ? 'modern' : 'neutral', $year);
		}

		$generator = (string) $doc->meta('generator');

		if ($generator !== '') {
			$add('generator', 'Platforma: ' . mb_substr($generator, 0, 60), 'neutral');
		}

		if (! $doc->meta('viewport')) {
			$add('no_viewport', 'Brak meta viewport — strona nieprzystosowana do urządzeń mobilnych', 'dated');
		} else {
			$add('viewport', 'Meta viewport (strona responsywna lub mobilna)', 'modern');
		}

		$head = strtolower(substr(ltrim($html), 0, 300));

		if (str_contains($head, 'xhtml') || str_contains($head, 'html 4.01') || str_contains($head, 'transitional')) {
			$add('old_doctype', 'Stary typ dokumentu (XHTML / HTML 4.01)', 'dated');
		}

		if (preg_match('/swfobject|\.swf["\'?]|application\/x-shockwave-flash/i', $html)) {
			$add('flash', 'Elementy Flash (technologia wycofana w 2020)', 'dated', 2010);
		}

		if ($doc->count('//table//table') >= 2 || $doc->count('//font | //center | //marquee') >= 2) {
			$add('table_layout', 'Układ tabelowy lub przestarzałe znaczniki (font/center)', 'dated');
		}

		if (preg_match('#/(?:mootools|prototype|scriptaculous)[\w.\-]*\.js#i', $html)) {
			$add('legacy_js', 'Wycofane biblioteki JavaScript (MooTools / Prototype / script.aculo.us)', 'dated', 2010);
		}

		if (preg_match('/<!--\[if (lt |lte )?IE/i', $html)) {
			$add('ie_conditional', 'Komentarze warunkowe dla Internet Explorera', 'dated');
		}

		if ($doc->count('//img[@loading="lazy"] | //iframe[@loading="lazy"]') > 0) {
			$add('lazy', 'Natywne leniwe ładowanie obrazów (od 2019)', 'modern', 2019);
		}

		if (preg_match('/\.(webp|avif)(\?|["\'\s])/i', $html)) {
			$add('modern_images', 'Nowoczesne formaty obrazów (WebP/AVIF)', 'modern', 2019);
		}

		if (preg_match('/class="[^"]*\bwp-block-/i', $html)) {
			$add('gutenberg', 'Bloki edytora WordPress (od 2018)', 'modern', 2018);
		}

		if (preg_match('/elementor|\/_next\/|__nuxt|data-reactroot|webflow|wix\.com|squarespace/i', $html)) {
			$add('modern_builder', 'Współczesny kreator / framework (Elementor, Next.js, Nuxt, Webflow…)', 'modern');
		}

		$copyright = null;

		if (preg_match_all('/(?:©|&copy;|\(c\)|copyright)\s*(?:\d{4}\s*[\-–]\s*)?(\d{4})/iu', $doc->text(), $mm)) {
			$years = array_filter(array_map('intval', $mm[1]), fn ($y) => $y >= 1995 && $y <= $nowYear + 1);
			$copyright = $years ? max($years) : null;

			if ($copyright !== null && $copyright <= $nowYear - 3) {
				$add('copyright_old', 'Rok w stopce: ' . $copyright . ' — stopka nieaktualizowana od ' . ($nowYear - $copyright) . ' lat', 'dated', $copyright);
			}
		}

		$dates = [];

		foreach (['article:modified_time', 'article:published_time', 'og:updated_time'] as $prop) {
			$el = $doc->first('//meta[@property="' . $prop . '"]');

			if ($el && $el->getAttribute('content') !== '') {
				$dates[] = ['source' => $prop, 'value' => mb_substr($el->getAttribute('content'), 0, 40)];
			}
		}

		if (preg_match('/"dateModified"\s*:\s*"([^"]{8,40})"/', $html, $m)) {
			$dates[] = ['source' => 'JSON-LD dateModified', 'value' => $m[1]];
		}

		if (! empty($headers['last-modified'])) {
			$dates[] = ['source' => 'Nagłówek Last-Modified', 'value' => mb_substr($headers['last-modified'], 0, 40)];
		}

		$modern = count(array_filter($signals, fn ($s) => $s['kind'] === 'modern' && $s['key'] !== 'viewport'));
		$dated = count(array_filter($signals, fn ($s) => $s['kind'] === 'dated'));
		$modernity = match (true) {
			$dated === 0 && $modern === 0 => 'unknown',
			$dated >= 2 && $dated > $modern => 'dated',
			$modern >= 1 && $dated === 0 => 'modern',
			default => 'mixed',
		};

		return ['signals' => $signals, 'modernity' => $modernity, 'copyright_year' => $copyright, 'dates' => $dates];
	}
}
