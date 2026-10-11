<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Infrastructure\Repositories\ResultRepository;

/**
 * Treść zadania ClickUp z danych Ugly but Rich (tylko to, co wiemy — bez wymyślania).
 *
 * Opis zawiera WYDZIELONY BLOK zarządzany przez integrację (między liniami znacznikowymi). Aktualizacja zadania
 * zmienia tylko ten blok i zmapowane Custom Fields — komentarze, checklisty, załączniki, status i tekst poza
 * blokiem zostają nietknięte. Prywatne notatki, odrzucone / wykluczone kontakty i screenshoty nie są eksportowane.
 */
class ClickUpTaskBuilder
{
	public const START = '---- Ugly but Rich · sekcja aktualizowana automatycznie (nie edytuj) ----';

	public const END = '---- koniec sekcji Ugly but Rich ----';

	public function __construct(
		private CompanyRepository $companies,
		private ResultRepository $results,
		private PipelineRepository $pipeline,
	) {
	}

	/**
	 * @param array<string, mixed> $settings ustawienia połączenia (szablon nazwy, mapowanie)
	 * @return array{name: string, block: string, markdown: string, fields: array<string, mixed>, hash: string}
	 */
	public function build(TenantContext $ctx, int $companyId, array $settings): array
	{
		$c = $this->companies->find($companyId) ?? throw new \RuntimeException('Firma nie istnieje.');
		$best = $this->results->bestRelevanceForCompany($companyId, $ctx->organizationId);
		$relevance = $best && $best['relevance_score'] !== null ? (int) $best['relevance_score'] : null;
		$prospect = $this->pipeline->prospectForCompany($ctx->organizationId, $companyId);
		$verified = ($c['verification_status'] ?? '') === 'verified';
		$lines = [self::START, ''];

		// COMPANY
		$lines[] = '**COMPANY**';
		$lines[] = '- Nazwa: ' . $this->md($c['registry_name'] ?: $c['name']);
		$lines[] = '- Domena: ' . ($c['domain'] ? $this->md($c['domain']) : 'brak');
		$lines[] = '- Branża: ' . ($c['industry'] ? $this->md($c['industry']) : 'brak danych');

		if (in_array($c['location_confidence'] ?? '', ['confirmed'], true) || ($verified && ($c['city'] || $c['region']))) {
			$lines[] = '- Lokalizacja: ' . $this->md(implode(', ', array_filter([$c['city'], $c['region'] ? 'woj. ' . $c['region'] : null])));
		}

		if ($verified) {
			$lines[] = '- NIP / KRS: ' . ($c['nip'] ?: '—') . ' / ' . ($c['krs'] ?: '—') . ' (zweryfikowane w rejestrze)';
		}

		// SCORES
		$lines[] = '';
		$lines[] = '**SCORES**';
		$lines[] = '- Relevance Score: ' . ($relevance ?? 'brak');
		$lines[] = '- Opportunity Score: ' . ($c['opportunity_score'] !== null ? (int) $c['opportunity_score'] . '/100' . ($c['opportunity_coverage'] !== null ? ' (pokrycie danych ' . (int) $c['opportunity_coverage'] . '%, pewność ' . ($c['opportunity_confidence'] ?: '—') . '; bez oceny wizualnej AI)' : '') : 'brak analizy');
		$lines[] = '- Contact Priority Score (Top 1): ' . ($c['top_contact_score'] !== null ? (int) $c['top_contact_score'] . '/100' : 'brak');

		if ($prospect) {
			$lines[] = '- Pipeline: ' . PipelineCatalog::statusLabel($prospect['status']) . ', priorytet: ' . (PipelineCatalog::PRIORITIES[$prospect['priority']] ?? $prospect['priority']);
		}

		// WEBSITE
		$lines[] = '';
		$lines[] = '**WEBSITE**';
		$lines[] = '- URL: ' . ($c['website_url'] ? $c['website_url'] : 'brak');
		$analysis = $c['last_analysis_id'] ? app(\App\Ubr\Infrastructure\Repositories\AnalysisRepository::class)->find((int) $c['last_analysis_id']) : null;

		if ($analysis && in_array($analysis['status'], ['completed', 'partial'], true)) {
			$lines[] = '- Analiza z: ' . get_date_from_gmt((string) $analysis['finished_at'], 'Y-m-d');
			$top = array_slice(array_values(array_filter((array) $analysis['findings'], fn ($f) => in_array($f['severity'] ?? '', ['critical', 'high', 'medium'], true))), 0, 5);

			foreach ($top as $f) {
				$lines[] = '  - ' . $this->md((string) ($f['title'] ?? ''));
			}

			if (! $top) {
				$lines[] = '  - brak problemów o wysokim / średnim znaczeniu';
			}
		} else {
			$lines[] = '- Analiza strony: nie wykonano';
		}

		// DECISION MAKERS (z decyzjami organizacji: odrzuceni / wykluczeni / nieaktualni nie są eksportowani)
		$lines[] = '';
		$lines[] = '**DECISION MAKERS**';
		$contacts = null;

		try {
			$contacts = app(ContactResearchService::class)->forCompany($ctx, $companyId);
		} catch (\Throwable) {
			$contacts = null;
		}

		$personal = [];

		if ($contacts && $contacts['top']) {
			foreach ($contacts['top'] as $t) {
				$status = match ($t['verification']['status']) {
					'verified' => 'zweryfikowany',
					'probable' => 'prawdopodobny',
					default => 'NIEPOTWIERDZONY — wymaga weryfikacji',
				};
				$sources = implode(', ', array_filter(array_map(fn ($s) => $s['url'] ? $s['label'] . ': ' . $s['url'] : $s['label'], $t['sources'])));
				$lines[] = sprintf('- Top %d: %s — %s (%s, pewność %s, Contact Priority %s). Źródła: %s', $t['rank_position'], $this->md($t['full_name']), $this->md((string) $t['job_title']), $status, mb_strtolower($t['confidence']['label']), $t['score'] ?? '—', $sources ?: '—');

				if ($t['email']) {
					$personal[] = $t['full_name'] . ': ' . $t['email'] . ' (publiczny, służbowy)';
				}

				if ($t['phone']) {
					$personal[] = $t['full_name'] . ': tel. ' . $t['phone'] . ' (służbowy, z oficjalnej strony)';
				}
			}
		} else {
			$lines[] = '- brak znalezionych decydentów';
		}

		// CONTACT
		$lines[] = '';
		$lines[] = '**CONTACT**';

		foreach ($personal as $p) {
			$lines[] = '- ' . $this->md($p);
		}

		foreach ((array) ($contacts['company_channels'] ?? []) as $ch) {
			$lines[] = '- ' . $ch['value'] . ' (' . ($ch['type'] === 'COMPANY_GENERAL_EMAIL' ? 'ogólny kontakt firmy' : 'adres działu') . ')';
		}

		if (! $personal && empty($contacts['company_channels'])) {
			$lines[] = '- brak publicznych danych kontaktowych';
		}

		// LINKS
		$lines[] = '';
		$lines[] = '**LINKS**';

		if ($c['website_url']) {
			$lines[] = '- Strona firmy: ' . $c['website_url'];
		}

		$lines[] = '- Ugly but Rich: ' . add_query_arg('company', $companyId, \App\Ubr\Http\AppPages::url('prospects'));
		$lines[] = '';
		$lines[] = self::END;
		$lead = $this->leadValues($ctx, $c, $contacts);

		// Prompt 10.4.3 — stanowisko decydenta i opiekun firmy w opisie (pole Contact zawiera wyłącznie imię i nazwisko).
		$owner = $prospect && ! empty($prospect['owner_user_id']) ? get_userdata((int) $prospect['owner_user_id']) : null;
		$extra = array_filter([
			$lead['contact'] ? '- Decydent (pole Contact): ' . $this->md($lead['contact']) . ($lead['_dm_title'] ? ' — ' . $this->md((string) $lead['_dm_title']) : '') : null,
			$owner ? '- Opiekun w Ugly but Rich: ' . $this->md($owner->display_name) : null,
		]);

		if ($extra) {
			array_splice($lines, array_search(self::END, $lines, true) - 1, 0, array_merge(['', '**LEAD**'], $extra));
		}

		$block = implode("\n", $lines);
		// Prompt 10.4.3 — domyślnie nazwa zadania = nazwa firmy (zapisany szablon użytkownika ma pierwszeństwo).
		$template = (string) ($settings['task_name_template'] ?? '{company}');
		$name = mb_substr(strtr($template, ['{company}' => $c['name'], '{domain}' => (string) $c['domain'], '{city}' => (string) $c['city']]), 0, 250);
		$mapped = $this->mappedValues($c, $relevance, $prospect, (array) ($settings['field_mapping'] ?? []), $lead);

		return ['name' => $name, 'block' => $block, 'markdown' => $block, 'fields' => array_column(array_values($mapped), 'value', 'field_id'), 'mapped' => $mapped, 'lead' => $lead, 'hash' => self::hash($block)];
	}

	/**
	 * Prompt 10.4.3 — dane leadu do pól ClickUp:
	 * - Contact: imię i nazwisko najlepszego decydenta (Top 1, zweryfikowany lub prawdopodobny, nazwisko niezamaskowane),
	 * - Decision maker phone: wyłącznie numer powiązany z tą osobą z potwierdzeniem (VERIFIED) albo odblokowany w Apollo
	 *   dla tej osoby; nigdy telefon centrali / firmowy; niepewne (LIKELY / UNCERTAIN) — do ręcznej weryfikacji,
	 * - Contact number: główny telefon firmowy (Discover), Lead email: ogólny e-mail firmy, Source / Funnel step stałe.
	 *
	 * @param array<string, mixed> $c
	 * @param array<string, mixed>|null $contacts widok Decision Makers organizacji
	 * @return array<string, mixed>
	 */
	public function leadValues(TenantContext $ctx, array $c, ?array $contacts): array
	{
		$companyId = (int) $c['id'];
		$rows = app(\App\Ubr\Infrastructure\Repositories\CompanyPhoneRepository::class)->forCompanies([$companyId], $ctx->organizationId)[$companyId] ?? [];
		$main = CompanyPhoneService::mainFor($rows);
		$mainE164 = $main ? (string) ($main['e164'] ?? '') : '';
		$dm = null;
		$title = null;
		$phone = null;
		$top = $contacts['top'][0] ?? null;

		if ($top
			&& (int) ($top['rank_position'] ?? 0) === 1
			&& in_array($top['verification']['status'] ?? '', ['verified', 'probable'], true)
			&& ! str_contains((string) $top['full_name'], '*')
			&& preg_match('/\S+\s+\S+/u', (string) $top['full_name'])
		) {
			$dm = (string) $top['full_name'];
			$title = $top['job_title'] ?: null;
			$links = app(\App\Ubr\Infrastructure\Repositories\ContactPhoneLinkRepository::class)->effectiveFor($ctx->organizationId, [(int) $top['id']])[(int) $top['id']] ?? [];

			foreach ($links as $l) {
				if (($l['link_status'] ?? '') === 'VERIFIED' && (string) $l['e164'] !== '' && (string) $l['e164'] !== $mainE164) {
					$phone = (string) $l['e164'];
					break;
				}
			}

			if ($phone === null) {
				$revealed = (string) app(\App\Ubr\Infrastructure\Database\Db::class)->value(
					'SELECT e164 FROM ' . app(\App\Ubr\Infrastructure\Database\Db::class)->table(\App\Ubr\Infrastructure\Database\Tables::CONTACT_PHONES) . ' WHERE organization_id = %d AND contact_id = %d AND e164 <> %s ORDER BY id DESC LIMIT 1',
					$ctx->organizationId,
					(int) $top['id'],
					$mainE164
				);
				$phone = $revealed !== '' ? $revealed : null;
			}
		}

		$li = app(\App\Ubr\Infrastructure\Repositories\LeadIntelligenceRepository::class)->current($ctx->organizationId, $companyId);

		return [
			'contact' => $dm,
			'decision_maker_phone' => $phone,
			'contact_number' => $mainE164 !== '' ? $mainE164 : null,
			'lead_email' => CompanyPresenter::generalEmail($c),
			'source' => 'Ugly but Rich',
			'funnel_step' => 'Leads',
			'lead_potential_score' => $li && $li['lead_score'] !== null ? (int) $li['lead_score'] : null,
			'company_card_url' => add_query_arg('company', $companyId, \App\Ubr\Http\AppPages::url('prospects')),
			'_dm_title' => $title,
		];
	}

	/**
	 * Wartości zmapowanych Custom Fields (tylko gdy dane istnieją; drop_down = id opcji o tej samej nazwie).
	 *
	 * @param array<string, mixed> $c
	 * @param array<string, mixed>|null $prospect
	 * @param array<string, array<string, mixed>> $mapping
	 * @return array<string, mixed> field_id => wartość
	 */
	public function fieldValues(array $c, ?int $relevance, ?array $prospect, array $mapping, array $extra = []): array
	{
		return array_column(array_values($this->mappedValues($c, $relevance, $prospect, $mapping, $extra)), 'value', 'field_id');
	}

	/**
	 * Wartości pól według klucza aplikacji (do ochrony ręcznych zmian: porównanie z ostatnio zapisanymi wartościami).
	 *
	 * @param array<string, mixed> $c
	 * @param array<string, mixed>|null $prospect
	 * @param array<string, array<string, mixed>> $mapping
	 * @param array<string, mixed> $extra wartości leadu (leadValues)
	 * @return array<string, array{field_id: string, value: mixed, type: string}>
	 */
	public function mappedValues(array $c, ?int $relevance, ?array $prospect, array $mapping, array $extra = []): array
	{
		$raw = array_diff_key($extra, ['_dm_title' => true]) + [
			'company_name' => $c['name'],
			'website' => $c['website_url'] ?: null,
			'relevance_score' => $relevance,
			'opportunity_score' => $c['opportunity_score'] !== null ? (int) $c['opportunity_score'] : null,
			'contact_priority_score' => $c['top_contact_score'] !== null ? (int) $c['top_contact_score'] : null,
			'pipeline_status' => $prospect ? PipelineCatalog::statusLabel($prospect['status']) : null,
			'priority' => $prospect ? (PipelineCatalog::PRIORITIES[$prospect['priority']] ?? null) : null,
		];
		$out = [];

		foreach ($mapping as $key => $m) {
			$value = $raw[$key] ?? null;

			if ($value === null || empty($m['field_id'])) {
				continue;
			}

			if ($m['type'] === 'drop_down' || $m['type'] === 'labels') {
				$option = array_values(array_filter((array) $m['options'], fn ($o) => mb_strtolower($o['name']) === mb_strtolower((string) $value)))[0] ?? null;

				if (! $option) {
					continue;
				}

				$value = $m['type'] === 'labels' ? [$option['id']] : $option['id'];
			} elseif ($m['type'] === 'number') {
				$value = (int) $value;
			} else {
				$value = (string) $value;
			}

			$out[$key] = ['field_id' => (string) $m['field_id'], 'value' => $value, 'type' => (string) $m['type']];
		}

		return $out;
	}

	/**
	 * Zastąpienie bloku w opisie. null = brak znaczników albo blok zmieniony ręcznie (nie nadpisujemy).
	 */
	public static function replaceBlock(string $current, string $newBlock, ?string $expectedHash): ?string
	{
		$start = mb_strpos($current, self::START);
		$end = mb_strpos($current, self::END);

		if ($start === false || $end === false || $end < $start) {
			return null;
		}

		$existing = mb_substr($current, $start, $end - $start + mb_strlen(self::END));

		if ($expectedHash !== null && self::hash($existing) !== $expectedHash) {
			return null;
		}

		return mb_substr($current, 0, $start) . $newBlock . mb_substr($current, $end + mb_strlen(self::END));
	}

	/**
	 * Skrót odporny na przeformatowanie markdown przez ClickUp (tylko litery i cyfry).
	 */
	public static function hash(string $block): string
	{
		return hash('sha256', mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $block)));
	}

	private function md(string $text): string
	{
		return trim(str_replace(["\n", "\r", '*', '_', '`', '[', ']'], [' ', ' ', '', '', '', '(', ')'], $text));
	}
}
