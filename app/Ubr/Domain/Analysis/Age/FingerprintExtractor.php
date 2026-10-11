<?php

namespace App\Ubr\Domain\Analysis\Age;

use App\Ubr\Domain\Analysis\HtmlDocument;
use DOMElement;

/**
 * Prompt 10.4.3 — Website Age Intelligence: „odcisk” PROJEKTU strony głównej (nie treści).
 *
 * Cechy porównywane między wersjami strony (obecną i archiwalnymi z Wayback Machine):
 * - zasoby CSS/JS (ścieżki znormalizowane: bez wersji, hashy, parametrów),
 * - motyw / szablon CMS (np. wp-content/themes/<nazwa>) i platforma (meta generator, bez numeru wersji),
 * - struktura nagłówka / nawigacji / stopki (klasy elementów), szkielet DOM (sekwencje znaczników do głębokości 3),
 * - teksty pozycji menu.
 * Treść (artykuły, zdjęcia, numery telefonów, pojedyncze teksty) celowo NIE wchodzi do odcisku — jej zmiany
 * nie są redesignem.
 */
final class FingerprintExtractor
{
	private const MAX_ITEMS = 80;

	/**
	 * @return array{assets: list<string>, theme: ?string, platform: ?string, nav: list<string>, classes: list<string>, structure: list<string>, features: int}
	 */
	public static function extract(string $html, string $url): array
	{
		$doc = new HtmlDocument($html, $url);
		$host = $doc->host();

		$assets = [];

		foreach ($doc->all('//link[@href]') as $link) {
			$rel = strtolower((string) $link->getAttribute('rel'));

			if (str_contains($rel, 'stylesheet')) {
				$assets[] = self::asset((string) $link->getAttribute('href'), $host);
			}
		}

		foreach ($doc->all('//script[@src]') as $script) {
			$assets[] = self::asset((string) $script->getAttribute('src'), $host);
		}

		$theme = null;

		if (preg_match('#/(?:wp-content/themes|templates|themes|skins)/([a-z0-9_\-]{2,60})/#i', $html, $m)) {
			$theme = strtolower($m[1]);
		}

		$generator = $doc->meta('generator');
		$platform = $generator ? strtolower(trim((string) preg_replace('/[\d.]+.*$/', '', $generator))) : null;

		$nav = [];

		foreach ($doc->all('//nav//a | //header//a | //*[contains(concat(" ", normalize-space(@class), " "), " menu ") or contains(@id, "menu") or contains(@class, "nav")]//a') as $a) {
			$text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $a->textContent)));

			if (mb_strlen($text) >= 2 && mb_strlen($text) <= 30) {
				$nav[] = $text;
			}

			if (count($nav) >= 30) {
				break;
			}
		}

		$classes = [];

		foreach ($doc->all('//body | //header | //footer | //nav | //body/* | //body/*/* | //*[@id="header" or @id="footer" or @id="menu" or @id="nav"]') as $el) {
			foreach (self::classTokens($el) as $token) {
				$classes[] = $token;
			}

			if (count($classes) >= self::MAX_ITEMS) {
				break;
			}
		}

		$structure = [];
		$body = $doc->first('//body');

		if ($body) {
			$sequence = [];
			self::walk($body, 0, $sequence);

			for ($i = 0; $i + 2 < count($sequence) && count($structure) < self::MAX_ITEMS; $i++) {
				$structure[] = $sequence[$i] . '>' . $sequence[$i + 1] . '>' . $sequence[$i + 2];
			}
		}

		$assets = array_values(array_unique(array_filter($assets)));
		$nav = array_values(array_unique($nav));
		$classes = array_values(array_unique($classes));
		$structure = array_values(array_unique($structure));

		return [
			'assets' => array_slice($assets, 0, self::MAX_ITEMS),
			'theme' => $theme,
			'platform' => $platform ?: null,
			'nav' => $nav,
			'classes' => array_slice($classes, 0, self::MAX_ITEMS),
			'structure' => $structure,
			'features' => count($assets) + count($classes) + count($structure) + count($nav),
		];
	}

	/** Ścieżka zasobu bez wersji, hashy i parametrów (np. /wp-content/themes/x/css/style.min.css?ver=5.2 → themes/x/css/style.css). */
	public static function asset(string $src, string $host): string
	{
		$src = trim(html_entity_decode($src));

		if ($src === '' || str_starts_with($src, 'data:')) {
			return '';
		}

		// Adresy przepisane przez Wayback (/web/20150101000000/http://firma.pl/...) → adres oryginalny.
		$src = (string) preg_replace('#^(?:https?://web\.archive\.org)?/web/\d{1,14}(?:[a-z]{2}_)?/#i', '', $src);
		$parts = parse_url($src);
		$assetHost = strtolower(preg_replace('/^www\./', '', (string) ($parts['host'] ?? '')) ?? '');
		$path = strtolower((string) ($parts['path'] ?? ''));
		$path = (string) preg_replace('/[0-9a-f]{8,}/', 'h', $path);
		$path = (string) preg_replace('/\d+(?:\.\d+)+/', 'v', $path);
		$path = str_replace(['.min.', '-min.'], '.', $path);
		$segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
		$tail = implode('/', array_slice($segments, -4));
		$prefix = ($assetHost === '' || $assetHost === $host || str_ends_with($assetHost, '.' . $host)) ? '' : $assetHost . ':';

		return $tail === '' ? '' : $prefix . $tail;
	}

	/** @return list<string> */
	private static function classTokens(DOMElement $el): array
	{
		$out = [];
		$value = trim($el->getAttribute('class') . ' ' . ($el->getAttribute('id') !== '' ? '#' . $el->getAttribute('id') : ''));

		foreach (preg_split('/\s+/', strtolower($value)) ?: [] as $token) {
			$token = (string) preg_replace('/\d+/', '', $token);

			// Klasy stanu / unikalne identyfikatory wpisów to treść, nie projekt.
			if (strlen($token) < 3 || preg_match('/^(postid|page-id|post-|is-|has-|active|current|show|open|hidden|loaded|js|no-js|wp-elements-)/', $token)) {
				continue;
			}

			$out[] = $el->nodeName . '.' . $token;
		}

		return $out;
	}

	/** @param list<string> $sequence */
	private static function walk(DOMElement $node, int $depth, array &$sequence): void
	{
		if ($depth > 3 || count($sequence) > 200) {
			return;
		}

		foreach ($node->childNodes as $child) {
			if (! $child instanceof DOMElement || in_array($child->nodeName, ['script', 'style', 'noscript', 'link', 'meta', 'br', 'img', 'svg', 'path', 'source', 'iframe'], true)) {
				continue;
			}

			$class = strtolower((string) (preg_split('/\s+/', trim($child->getAttribute('class')))[0] ?? ''));
			$sequence[] = $child->nodeName . ($class !== '' ? '.' . preg_replace('/\d+/', '', $class) : '');
			self::walk($child, $depth + 1, $sequence);
		}
	}
}
