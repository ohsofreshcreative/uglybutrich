<?php

namespace App\Ubr\Domain\Analysis;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parsowany dokument HTML (bez wykonywania JS) z wygodnymi selektorami dla analizatorów.
 * To analiza STRUKTURY, nie wyglądu — wygląd ocenia wyłącznie analiza obrazu (AI Vision).
 */
final class HtmlDocument
{
	private DOMDocument $dom;

	private DOMXPath $xpath;

	private ?string $text = null;

	public function __construct(public readonly string $html, public readonly string $url)
	{
		$this->dom = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$html = $html !== '' ? $html : '<html></html>';
		// Wymuszenie UTF-8 (DOMDocument domyślnie zakłada ISO-8859-1).
		$this->dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$this->xpath = new DOMXPath($this->dom);
	}

	public function host(): string
	{
		return strtolower(preg_replace('/^www\./', '', (string) parse_url($this->url, PHP_URL_HOST)) ?? '');
	}

	/**
	 * @return list<DOMElement>
	 */
	public function all(string $xpath): array
	{
		$out = [];

		foreach ($this->xpath->query($xpath) ?: [] as $node) {
			if ($node instanceof DOMElement) {
				$out[] = $node;
			}
		}

		return $out;
	}

	public function first(string $xpath): ?DOMElement
	{
		return $this->all($xpath)[0] ?? null;
	}

	public function title(): ?string
	{
		$t = $this->first('//title');

		return $t ? self::clean($t->textContent) : null;
	}

	public function meta(string $name): ?string
	{
		$el = $this->first(sprintf('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"]', $name));

		return $el ? trim($el->getAttribute('content')) : null;
	}

	public function canonical(): ?string
	{
		$el = $this->first('//link[contains(translate(@rel,"CANONICAL","canonical"),"canonical")]');

		return $el ? trim($el->getAttribute('href')) : null;
	}

	public function lang(): ?string
	{
		$el = $this->first('//html');

		return $el && $el->getAttribute('lang') !== '' ? $el->getAttribute('lang') : null;
	}

	/**
	 * @return list<string>
	 */
	public function headings(string $tag): array
	{
		return array_values(array_filter(array_map(fn ($el) => self::clean($el->textContent), $this->all('//' . $tag))));
	}

	/**
	 * @return list<array{href: string, text: string, internal: bool, in_nav: bool, in_header: bool}>
	 */
	public function links(): array
	{
		$out = [];

		foreach ($this->all('//a[@href]') as $a) {
			$href = trim($a->getAttribute('href'));

			if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
				continue;
			}

			$host = strtolower(preg_replace('/^www\./', '', (string) parse_url($href, PHP_URL_HOST)) ?? '');
			$isHttp = preg_match('#^(https?:)?//#i', $href) === 1;
			// mailto:, tel:, sms: itp. nie są podstronami serwisu.
			$otherScheme = ! $isHttp && preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href) === 1;
			$text = self::clean($a->textContent . ' ' . $a->getAttribute('aria-label') . ' ' . $a->getAttribute('title'));

			$out[] = [
				'href' => $href,
				'text' => mb_substr($text, 0, 120),
				'internal' => $otherScheme ? false : ! $isHttp || $host === $this->host() || str_ends_with($host, '.' . $this->host()),
				'in_nav' => $this->hasAncestor($a, ['nav']) || $this->hasRoleAncestor($a, 'navigation'),
				'in_header' => $this->hasAncestor($a, ['header']),
			];
		}

		return $out;
	}

	/**
	 * @return list<array{src: string, alt: ?string}>
	 */
	public function images(): array
	{
		return array_map(fn ($img) => ['src' => $img->getAttribute('src') ?: $img->getAttribute('data-src'), 'alt' => $img->hasAttribute('alt') ? $img->getAttribute('alt') : null], $this->all('//img'));
	}

	/**
	 * Pola formularzy wymagające etykiety (bez hidden/submit/button).
	 *
	 * @return array{total: int, unlabeled: int, forms: int}
	 */
	public function formFields(): array
	{
		$labelsFor = [];

		foreach ($this->all('//label[@for]') as $label) {
			$labelsFor[$label->getAttribute('for')] = true;
		}

		$total = 0;
		$unlabeled = 0;

		foreach ($this->all('//input[not(@type) or not(contains("hidden submit button image reset", translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")))] | //select | //textarea') as $field) {
			$total++;
			$labelled = ($field->getAttribute('id') !== '' && isset($labelsFor[$field->getAttribute('id')]))
				|| $field->getAttribute('aria-label') !== ''
				|| $field->getAttribute('aria-labelledby') !== ''
				|| $field->getAttribute('title') !== ''
				|| $this->hasAncestor($field, ['label']);

			if (! $labelled) {
				$unlabeled++;
			}
		}

		return ['total' => $total, 'unlabeled' => $unlabeled, 'forms' => count($this->all('//form'))];
	}

	/**
	 * Tekst treści (bez skryptów, stylów, szablonów), znormalizowany.
	 */
	public function text(): string
	{
		if ($this->text !== null) {
			return $this->text;
		}

		$body = $this->first('//body');

		if (! $body) {
			return $this->text = '';
		}

		$clone = $body->cloneNode(true);
		$xp = new DOMXPath($this->dom);

		foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
			foreach (iterator_to_array($xp->query('.//' . $tag, $clone) ?: []) as $node) {
				$node->parentNode?->removeChild($node);
			}
		}

		return $this->text = self::clean($clone->textContent);
	}

	public function wordCount(): int
	{
		return count(preg_split('/\s+/u', $this->text(), -1, PREG_SPLIT_NO_EMPTY) ?: []);
	}

	/**
	 * @return list<string>
	 */
	public function jsonLdTypes(): array
	{
		$types = [];

		foreach ($this->all('//script[@type="application/ld+json"]') as $script) {
			$data = json_decode(trim($script->textContent), true);

			if (! is_array($data)) {
				continue;
			}

			$nodes = isset($data['@graph']) ? (array) $data['@graph'] : (array_is_list($data) ? $data : [$data]);

			foreach ($nodes as $n) {
				foreach ((array) ($n['@type'] ?? []) as $t) {
					$types[] = (string) $t;
				}
			}
		}

		return array_values(array_unique($types));
	}

	/**
	 * @return list<string>
	 */
	public function scriptSources(): array
	{
		return array_values(array_filter(array_map(fn ($s) => $s->getAttribute('src'), $this->all('//script[@src]'))));
	}

	/**
	 * Zasoby ładowane po http:// na stronie https (mixed content) — z atrybutów src/href zasobów.
	 *
	 * @return list<string>
	 */
	public function insecureResources(): array
	{
		$out = [];

		foreach ($this->all('//script[@src] | //img[@src] | //link[@rel="stylesheet"][@href] | //iframe[@src]') as $el) {
			$url = $el->getAttribute('src') ?: $el->getAttribute('href');

			if (str_starts_with(strtolower($url), 'http://')) {
				$out[] = mb_substr($url, 0, 160);
			}
		}

		return array_slice(array_values(array_unique($out)), 0, 10);
	}

	public function count(string $xpath): int
	{
		return count($this->all($xpath));
	}

	public static function clean(string $value): string
	{
		return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
	}

	private function hasAncestor(DOMElement $el, array $tags): bool
	{
		for ($n = $el->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
			if (in_array(strtolower($n->tagName), $tags, true)) {
				return true;
			}
		}

		return false;
	}

	private function hasRoleAncestor(DOMElement $el, string $role): bool
	{
		for ($n = $el->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
			if (strtolower($n->getAttribute('role')) === $role) {
				return true;
			}
		}

		return false;
	}
}
