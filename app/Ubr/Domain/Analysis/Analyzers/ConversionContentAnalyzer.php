<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Konwersja i treść: CTA, dostępność kontaktu (telefon, e-mail, formularz, strona kontaktowa), CTA w pierwszym ekranie
 * (render), aktualność strony (rok w stopce), ilość treści, strona oferty, elementy budujące zaufanie.
 * Ocena „czytelności oferty” i „prezentacji korzyści” wymaga analizy obrazu/treści przez AI — tu tylko sygnały obiektywne.
 */
final class ConversionContentAnalyzer
{
	public const CTA_RE = '/(skontaktuj|zapytaj|zapytanie|wycen|zam[oó]w|napisz do nas|zadzwo[nń]|um[oó]w|wy[sś]lij|bezp[lł]atn|konsultacj|popro[sś]|get a quote|request a quote|contact us|book)/iu';

	public const TRUST_RE = '/(referencj|klien(ci|tów)|zaufali|realizacj|portfolio|case stud|certyfikat|iso\s?9001|iso\s?14001|nagrod|wyróżnien|partner|lat doświadczenia|lat na rynku|od \d{4} roku|opinie)/iu';

	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		if (! $ctx->home) {
			return ['criteria' => [
				'a_conversion_static' => Criterion::notEvaluated(4, 'Brak HTML strony głównej.'),
				'a_conversion_fold' => Criterion::notEvaluated(3, 'Brak renderu.'),
				'a_content' => Criterion::notEvaluated(5, 'Brak HTML strony głównej.'),
			], 'checks' => [], 'findings' => []];
		}

		$criteria = [];
		$findings = [];
		$home = $ctx->home;
		$links = $home->links();
		$allHtml = $home->html . ($ctx->contact?->html ?? '');
		$tel = (bool) preg_match('/href=["\']tel:/i', $allHtml);
		$mail = (bool) preg_match('/href=["\']mailto:/i', $allHtml);
		$form = $home->count('//form') + ($ctx->contact ? $ctx->contact->count('//form') : 0);
		$contactPage = $ctx->contact !== null;
		$ctaTexts = array_values(array_unique(array_merge(
			array_map(fn ($l) => $l['text'], array_filter($links, fn ($l) => preg_match(self::CTA_RE, $l['text']))),
			array_map(fn ($b) => trim($b->textContent), array_filter($home->all('//button'), fn ($b) => preg_match(self::CTA_RE, $b->textContent)))
		)));
		$checks = ['tel_link' => $tel, 'mailto_link' => $mail, 'forms' => $form, 'contact_page' => $contactPage, 'cta_texts' => array_slice($ctaTexts, 0, 6)];

		$points = 0;
		$evidence = [];

		if (! $tel) {
			$points++;
			$evidence[] = 'Brak klikalnego numeru telefonu (tel:)';
		}

		if (! $mail && $form === 0) {
			$points++;
			$evidence[] = 'Brak formularza i klikalnego e-maila (mailto:)';
		}

		if (! $contactPage) {
			$points++;
			$evidence[] = 'Nie znaleziono strony kontaktowej';
		}

		if ($ctaTexts === []) {
			$points++;
			$evidence[] = 'Brak wezwań do działania (np. „Zapytaj o wycenę”, „Skontaktuj się”) na stronie głównej';
			$findings[] = Finding::make('conv_no_cta', 'conversion', 'high', 'Brak czytelnego CTA na stronie głównej', 'Na stronie głównej nie znaleziono linków ani przycisków zachęcających do kontaktu lub zapytania ofertowego.', $evidence[array_key_last($evidence)], 'Dodać wyraźne CTA (np. „Zapytaj o wycenę”) w pierwszym ekranie i po każdej sekcji oferty.');
		}

		if ($points >= 2 && ! array_filter($findings, fn ($f) => $f['key'] === 'conv_no_cta')) {
			$findings[] = Finding::make('conv_contact_hard', 'conversion', 'medium', 'Utrudniony kontakt', 'Brakuje kilku podstawowych ścieżek kontaktu.', $evidence, 'Dodać klikalny telefon, e-mail i krótki formularz zapytania widoczny na każdej stronie.');
		}

		$criteria['a_conversion_static'] = Criterion::measured($points, 4, 'Ścieżki kontaktu i CTA (HTML).', $evidence);

		$d = $ctx->view('desktop');
		$m = $ctx->view('mobile');

		if ($d || $m) {
			$points = 0;
			$evidence = [];

			if ($d && empty($d['cta_above_fold'])) {
				$points += 2;
				$evidence[] = 'Desktop 1440×900: brak CTA/telefonu w pierwszym ekranie';
			}

			if ($m && empty($m['cta_above_fold'])) {
				$points += 1;
				$evidence[] = 'Mobile 390×844: brak CTA/telefonu w pierwszym ekranie';
			}

			if ($evidence) {
				$findings[] = Finding::make('conv_no_cta_fold', 'conversion', 'medium', 'Brak wezwania do działania w pierwszym ekranie', 'Po wejściu na stronę użytkownik nie widzi przycisku kontaktu, zapytania ani numeru telefonu bez przewijania.', $evidence, 'Umieścić CTA i numer telefonu w sekcji hero / nagłówku.');
			}

			$criteria['a_conversion_fold'] = Criterion::measured($points, 3, 'CTA w pierwszym ekranie (render w przeglądarce).', $evidence);
		} else {
			$criteria['a_conversion_fold'] = Criterion::notEvaluated(3, 'Brak renderu w przeglądarce.');
		}

		// Treść.
		$points = 0;
		$evidence = [];
		$text = $home->text() . ' ' . ($ctx->offer?->text() ?? '');
		$year = null;

		if (preg_match_all('/(?:©|&copy;|copyright|\(c\))\s*(?:\d{4}\s*[-–]\s*)?((?:19|20)\d{2})/iu', $home->text(), $mm)) {
			$year = max(array_map('intval', $mm[1]));
		}

		$checks['copyright_year'] = $year;

		if ($year !== null && $year <= $ctx->year() - 3) {
			$points += 2;
			$evidence[] = 'Rok w stopce: ' . $year;
			$findings[] = Finding::make('content_outdated_year', 'content', 'medium', 'Strona wygląda na nieaktualizowaną', sprintf('W stopce widnieje rok %d — strona prawdopodobnie nie była rozwijana od kilku lat (wymaga potwierdzenia).', $year), 'Stopka: © ' . $year, 'Zaktualizować treści i stopkę; rozważyć odświeżenie strony.');
		} elseif ($year !== null && $year <= $ctx->year() - 2) {
			$points += 1;
			$evidence[] = 'Rok w stopce: ' . $year;
		}

		$checks['home_words'] = $home->wordCount();

		if ($home->wordCount() < 150) {
			$points++;
			$evidence[] = 'Strona główna: ' . $home->wordCount() . ' słów';
		}

		$checks['offer_page'] = $ctx->offer !== null;

		if ($ctx->offer === null) {
			$points++;
			$evidence[] = 'Nie znaleziono podstrony oferty/produktów/usług';
			$findings[] = Finding::make('content_no_offer', 'content', 'medium', 'Oferta jest trudna do odnalezienia', 'Ze strony głównej nie prowadzi link do podstrony oferty, produktów ani usług.', $evidence[array_key_last($evidence)], 'Przygotować czytelną stronę oferty z opisem usług/produktów i korzyści.');
		}

		$trust = preg_match_all(self::TRUST_RE, $text, $tm);
		$checks['trust_signals'] = array_slice(array_values(array_unique(array_map('mb_strtolower', $tm[0] ?? []))), 0, 6);

		if (! $trust) {
			$points++;
			$evidence[] = 'Brak elementów budujących zaufanie (referencje, klienci, certyfikaty, realizacje)';
			$findings[] = Finding::make('content_no_trust', 'conversion', 'low', 'Brak elementów budujących zaufanie', 'W treści strony głównej i oferty nie znaleziono referencji, realizacji, klientów ani certyfikatów.', $evidence[array_key_last($evidence)], 'Dodać realizacje, logotypy klientów, certyfikaty i liczby (np. lata na rynku).');
		}

		$criteria['a_content'] = Criterion::measured($points, 5, 'Treść: aktualność, ilość, oferta, zaufanie (HTML).', $evidence);

		return ['criteria' => $criteria, 'checks' => $checks, 'findings' => $findings];
	}
}
