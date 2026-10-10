<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\ActionCenter\ActionPriorityEngine;
use App\Ubr\Domain\ActionCenter\QueueCatalog;
use App\Ubr\Domain\Lead\LeadRecommender;
use App\Ubr\Domain\Pipeline\PipelineCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\ActionCenterRepository;
use App\Ubr\Infrastructure\Repositories\PipelineRepository;
use App\Ubr\Support\UbrException;

/**
 * Prospecting Dashboard & Action Center (Prompt 6): Today's Priorities, KPI, Smart Queues, follow-upy, aktywność, pokrycie.
 *
 * Tylko odczyt zapisanych danych (Lead Intelligence, firmy, Pipeline) — nigdy nie uruchamia analiz, crawlera, Apollo,
 * Decision Maker Findera ani ClickUp. Jedyny zapis: ręczne odłożenie działania (ubr_action_snoozes, M008).
 * Liczniki są cache'owane 60 s z wersjonowaniem (globalna wersja + wersja organizacji) — zmiana danych = nowy klucz.
 */
class ActionCenterService
{
	/** Today's Priorities: maksymalnie 5 działań na raz (kolejne strony na żądanie). */
	public const PRIORITIES_PER_PAGE = 5;

	public const SNOOZE_DAYS = [1, 3, 7, 14];

	private const FOLLOWUP_GROUP_LIMIT = 500;

	public function __construct(
		private ActionCenterRepository $repo,
		private PipelineRepository $pipeline,
		private PipelineService $pipelineService,
	) {
	}

	/* ------------------------------------ widok główny ------------------------------------ */

	/**
	 * @param array<string, mixed> $query owner
	 * @return array<string, mixed>
	 */
	public function overview(TenantContext $ctx, array $query = []): array
	{
		$this->assert($ctx, 'companies.view');
		$owner = $this->owner($query['owner'] ?? 'all');
		$summary = $this->summary($ctx, $owner);
		$pipelineView = $ctx->can('pipeline.view');

		return [
			'owner' => $owner,
			'kpis' => $this->kpis($summary, $pipelineView),
			'queues' => $this->queueList($summary, $pipelineView),
			'priorities' => $this->priorities($ctx, ['owner' => $owner, 'page' => 1]),
			'followups' => $pipelineView ? [
				'overdue' => $this->followupList($ctx, 'overdue', $owner, 5),
				'today' => $this->followupList($ctx, 'today', $owner, 5),
				'upcoming' => $this->followupList($ctx, 'upcoming', $owner, 5),
			] : null,
			'activity' => $pipelineView ? $this->activity($ctx) : [],
			'coverage' => $this->coverage($ctx, $summary),
			'snooze_days' => self::SNOOZE_DAYS,
			'filter_options' => [
				'regions' => $this->repo->regions($ctx->organizationId),
				'redesign' => array_map(fn ($k) => ['key' => $k, 'label' => \App\Ubr\Domain\Lead\RedesignAssessor::LABELS[$k] ?? $k], \App\Ubr\Infrastructure\Repositories\LeadFilters::REDESIGN),
				'recommendations' => array_map(fn ($k) => ['key' => $k, 'label' => LeadRecommender::ACTIONS[$k] ?? $k], \App\Ubr\Infrastructure\Repositories\LeadFilters::ACTIONS),
				'manual_review' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v['label']], array_keys(ManualReviewService::STATUSES), ManualReviewService::STATUSES),
			],
			'generated_at' => $summary['generated_at'],
		];
	}

	/**
	 * Liczniki (cache 60 s, wersjonowany klucz).
	 *
	 * @return array<string, mixed>
	 */
	public function summary(TenantContext $ctx, string $owner = 'all'): array
	{
		$key = sprintf('ubr_ac_%d_%s_%d_%d_%d', $ctx->organizationId, $owner, $owner === 'mine' ? $ctx->userId : 0, self::globalVersion(), self::orgVersion($ctx->organizationId));
		$cached = get_transient($key);

		if (is_array($cached)) {
			return $cached;
		}

		$data = [
			'counts' => $this->repo->counts($ctx->organizationId, $owner, $ctx->userId),
			'followups' => $this->repo->followupCounts($ctx->organizationId, $owner, $ctx->userId),
			'coverage' => $this->repo->coverage($ctx->organizationId),
			// Total Prospects respektuje przełącznik My Work / Unassigned (jak pozostałe KPI).
			'pipeline' => $this->pipeline->statusCounts($ctx->organizationId, match ($owner) {
				'mine' => ['owner' => (string) $ctx->userId],
				'unassigned' => ['owner' => 'none'],
				default => [],
			}),
			'exported' => $this->pipeline->countExported($ctx->organizationId),
			'generated_at' => gmdate('c'),
		];
		set_transient($key, $data, 60);

		return $data;
	}

	/* ------------------------------------ Today's Priorities ------------------------------------ */

	/**
	 * Lista działań: follow-upy (zaległe, dziś — po jednym na firmę) + działania z rekomendacji w kolejności silnika.
	 *
	 * @param array<string, mixed> $query owner, page
	 * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function priorities(TenantContext $ctx, array $query): array
	{
		$this->assert($ctx, 'companies.view');
		$owner = $this->owner($query['owner'] ?? 'all');
		$page = max(1, (int) ($query['page'] ?? 1));
		$per = max(1, min(50, (int) ($query['per_page'] ?? self::PRIORITIES_PER_PAGE)));
		$offset = ($page - 1) * $per;

		$groups = $ctx->can('pipeline.view') ? $this->followupGroups($ctx, $owner) : [];
		$recTotal = (int) ($this->summary($ctx, $owner)['counts']['actionable'] ?? 0);
		$items = array_slice($groups, $offset, $per);
		$need = $per - count($items);

		if ($need > 0) {
			$recOffset = max(0, $offset - count($groups));
			foreach ($this->repo->recommendedActions($ctx->organizationId, $owner, $ctx->userId, $need, $recOffset) as $row) {
				$items[] = $this->presentAction($ctx, $row);
			}
		}

		return ['items' => $items, 'total' => count($groups) + $recTotal, 'page' => $page, 'per_page' => $per];
	}

	/**
	 * Follow-upy zaległe i na dziś zgrupowane per firma (deduplikacja): najwcześniejszy termin + liczba pozostałych.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function followupGroups(TenantContext $ctx, string $owner): array
	{
		[$now] = ActionCenterRepository::bounds();
		$groups = [];

		foreach ($this->repo->followups($ctx->organizationId, 'due', $owner, $ctx->userId, self::FOLLOWUP_GROUP_LIMIT)['items'] as $f) {
			$cid = (int) $f['company_id'];

			if (isset($groups[$cid])) {
				$groups[$cid]['followup']['more']++;
				continue;
			}

			$type = $f['due_at'] < $now ? ActionPriorityEngine::FOLLOW_UP_OVERDUE : ActionPriorityEngine::FOLLOW_UP_DUE;
			$groups[$cid] = $this->presentFollowupAction($ctx, $f, $type);
		}

		return array_values($groups);
	}

	/**
	 * @param array<string, mixed> $f
	 * @return array<string, mixed>
	 */
	private function presentFollowupAction(TenantContext $ctx, array $f, string $type): array
	{
		$def = ActionPriorityEngine::TYPES[$type];

		return [
			'key' => $type . ':' . $f['company_id'] . ':' . $f['id'],
			'type' => $type,
			'type_label' => $def['label'],
			'type_label_pl' => $def['label_pl'],
			'tier' => $def['tier'],
			'priority' => $def['priority'],
			'reason' => ActionPriorityEngine::reason($type, []) . (! empty($f['has_blocker']) ? ' Uwaga: firma ma hard blocker — rozważ zamknięcie działania.' : ''),
			'company' => $this->companyBrief($f),
			'lead' => $this->leadBrief($f),
			'due_at' => $this->iso($f['due_at']),
			'followup' => ['id' => (int) $f['id'], 'title' => $f['title'], 'assigned' => $this->pipelineService->userBrief($f['assigned_user_id'] ? (int) $f['assigned_user_id'] : null), 'more' => 0],
			'prospect' => $this->prospectBrief($f['prospect_id'] ?? null, $f['prospect_status'] ?? null, $f['p_priority'] ?? null, $f['owner_user_id'] ?? null),
			'has_blocker' => ! empty($f['has_blocker']),
			'snoozable' => false,
			'cta' => ['action' => 'open_sales', 'label' => $def['cta']],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function presentAction(TenantContext $ctx, array $row): array
	{
		$type = (string) $row['action_type'];
		$def = ActionPriorityEngine::TYPES[$type];
		$rule = ActionPriorityEngine::classify($this->signals($row))['rule'] ?? '';

		return [
			'key' => $type . ':' . $row['company_id'],
			'type' => $type,
			'type_label' => $def['label'],
			'type_label_pl' => $def['label_pl'],
			'tier' => $def['tier'],
			'priority' => $def['priority'],
			'reason' => ActionPriorityEngine::reason($type, $row + ['rule' => $rule]),
			'company' => $this->companyBrief($row),
			'lead' => $this->leadBrief($row),
			'due_at' => null, // działania z rekomendacji nie mają terminu — nie wymyślamy go
			'followup' => null,
			'prospect' => $this->prospectBrief($row['prospect_id'] ?? null, $row['prospect_status'] ?? null, $row['p_priority'] ?? null, $row['owner_user_id'] ?? null),
			'analysis_status' => $row['analysis_status'],
			'contacts_status' => $row['contacts_status'],
			'has_blocker' => false,
			'snoozable' => true,
			'cta' => ['action' => $this->ctaAction($type), 'label' => $def['cta']],
		];
	}

	private function ctaAction(string $type): string
	{
		return match ($type) {
			ActionPriorityEngine::CONTACT_READY => 'open_contacts',
			ActionPriorityEngine::FIND_DECISION_MAKER => 'find_dm',
			ActionPriorityEngine::REVIEW_WEBSITE => 'preview',
			ActionPriorityEngine::VERIFY_COMPANY => 'verify',
			ActionPriorityEngine::ANALYSIS_MISSING => 'analyze',
			default => 'open_lead',
		};
	}

	/* ------------------------------------ Smart Queues ------------------------------------ */

	/**
	 * @param array<string, mixed> $query page, per_page, scope, owner, min_lead, confidence, search, sort, bucket (follow-upy)
	 * @return array<string, mixed>
	 */
	public function queue(TenantContext $ctx, string $key, array $query): array
	{
		$this->assert($ctx, 'companies.view');

		if (! QueueCatalog::exists($key)) {
			throw UbrException::notFound('Nieznana kolejka.');
		}

		$owner = $this->owner($query['owner'] ?? 'all');
		$page = max(1, (int) ($query['page'] ?? 1));
		$per = max(1, min(50, (int) ($query['per_page'] ?? 20)));
		$def = QueueCatalog::QUEUES[$key];

		if ($key === 'followups') {
			$this->assert($ctx, 'pipeline.view');
			$bucket = in_array($query['bucket'] ?? '', ['overdue', 'today', 'upcoming', 'due'], true) ? $query['bucket'] : 'open';
			$res = $this->repo->followups($ctx->organizationId, $bucket, $owner, $ctx->userId, $per, ($page - 1) * $per);
			[$now] = ActionCenterRepository::bounds();

			return [
				'queue' => ['key' => $key] + $def,
				'bucket' => $bucket,
				'items' => array_map(fn ($f) => $this->presentFollowupAction($ctx, $f, $f['due_at'] < $now ? ActionPriorityEngine::FOLLOW_UP_OVERDUE : ActionPriorityEngine::FOLLOW_UP_DUE) + ['bucket' => $this->bucketOf($f['due_at'])], $res['items']),
				'total' => $res['total'],
				'page' => $page,
				'per_page' => $per,
			];
		}

		$filters = [
			'scope' => in_array($query['scope'] ?? '', QueueCatalog::SCOPES, true) ? $query['scope'] : 'all',
			'min_lead' => (int) ($query['min_lead'] ?? 0),
			'confidence' => (string) ($query['confidence'] ?? ''),
			'search' => mb_substr(sanitize_text_field((string) ($query['search'] ?? '')), 0, 100),
			'sort' => in_array($query['sort'] ?? '', QueueCatalog::SORTS, true) ? $query['sort'] : (QueueCatalog::DEFAULT_SORT[$key] ?? 'priority'),
			'industry' => mb_substr(sanitize_text_field((string) ($query['industry'] ?? '')), 0, 100),
			'region' => mb_substr(sanitize_text_field((string) ($query['region'] ?? '')), 0, 60),
			'redesign' => sanitize_key((string) ($query['redesign'] ?? '')),
			'recommendation' => sanitize_key((string) ($query['recommendation'] ?? '')),
			'manual_review' => sanitize_key((string) ($query['manual_review'] ?? '')),
		];
		$res = $this->repo->queue($ctx->organizationId, $key, $owner, $ctx->userId, $filters, $per, ($page - 1) * $per);

		return [
			'queue' => ['key' => $key] + $def,
			'filters' => $filters,
			'items' => array_map(fn ($r) => $this->presentQueueRow($r), $res['items']),
			'total' => $res['total'],
			'page' => $page,
			'per_page' => $per,
		];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function presentQueueRow(array $r): array
	{
		$type = $r['action_type'] ? (string) $r['action_type'] : null;

		return [
			'company' => $this->companyBrief($r),
			'lead' => $this->leadBrief($r) + ['redesign' => $r['redesign'], 'recommendation' => $r['recommendation'], 'recommendation_label' => LeadRecommender::ACTIONS[$r['recommendation']] ?? $r['recommendation'], 'status' => $r['score_status'], 'stale' => (bool) $r['stale']],
			'action' => $type ? ['type' => $type, 'label' => ActionPriorityEngine::TYPES[$type]['label'], 'label_pl' => ActionPriorityEngine::TYPES[$type]['label_pl'], 'cta' => ['action' => $this->ctaAction($type), 'label' => ActionPriorityEngine::TYPES[$type]['cta']]] : null,
			'reason' => $r['has_blocker'] ? ($r['blocker_label'] ?: 'Hard blocker') : ($r['li_reason'] ?? ''),
			'prospect' => $this->prospectBrief($r['prospect_id'] ?? null, $r['prospect_status'] ?? null, $r['p_priority'] ?? null, $r['owner_user_id'] ?? null),
			'verification_status' => $r['verification_status'],
			'domain_status' => $r['domain_status'],
			'analysis_status' => $r['analysis_status'],
			'contacts_status' => $r['contacts_status'],
			'contacts_count' => (int) $r['contacts_count'],
			'location' => implode(', ', array_filter([$r['city'], $r['region']])),
			'has_blocker' => (bool) $r['has_blocker'],
			'snoozed_until' => ! empty($r['snoozed_until']) ? $this->iso($r['snoozed_until']) : null,
			'snoozable' => $type !== null && in_array($type, ActionPriorityEngine::SNOOZABLE, true),
			'industry' => $r['industry'] ?: null,
			'manual_review' => ['status' => $r['manual_review'], 'label' => ManualReviewService::STATUSES[$r['manual_review']]['label'] ?? $r['manual_review'], 'sentence' => $r['manual_review'] !== 'not_reviewed' ? (ManualReviewService::STATUSES[$r['manual_review']]['sentence'] ?? null) : null],
			// Tylko zapisane dane Decision Maker Findera — bez generowania e-maili i zgadywania telefonów.
			'contact' => $r['top_contact_name'] ? ['name' => $r['top_contact_name'], 'title' => $r['top_contact_title'] ?: null, 'confidence' => $r['top_contact_confidence'] ?: null] : null,
			'channels' => $this->channels($r['company_channels'] ?? null),
			'issues' => $this->issues($r['li_warnings'] ?? null, $type),
		];
	}

	/**
	 * Kanały firmowe znalezione przez Decision Maker Finder (etykiety, bez wartości — szczegóły w karcie firmy).
	 *
	 * @return list<string>
	 */
	private function channels(?string $json): array
	{
		$out = [];

		foreach ((array) (json_decode((string) $json, true) ?: []) as $ch) {
			$out[] = ($ch['type'] ?? '') === 'COMPANY_GENERAL_EMAIL' ? 'E-mail ogólny firmy' : 'E-mail działu: ' . (['marketing' => 'marketing', 'management' => 'zarząd / sekretariat', 'sales' => 'sprzedaż'][$ch['department'] ?? ''] ?? ($ch['department'] ?? 'inny'));
		}

		return array_values(array_unique($out));
	}

	/**
	 * Dodatkowe braki (soft warnings z Lead Intelligence) jako informacje pomocnicze — bez ostrzeżenia, które jest już
	 * główną kategorią (np. brak analizy przy „Missing website analysis”).
	 *
	 * @return list<string>
	 */
	private function issues(?string $json, ?string $type): array
	{
		$skip = match ($type) {
			ActionPriorityEngine::ANALYSIS_MISSING => ['no_analysis', 'analysis_failed'],
			ActionPriorityEngine::FIND_DECISION_MAKER => ['contacts_not_researched', 'no_decision_makers'],
			ActionPriorityEngine::VERIFY_COMPANY => ['company_unverified', 'company_uncertain', 'domain_unconfirmed'],
			default => [],
		};
		$out = [];

		foreach ((array) (json_decode((string) $json, true) ?: []) as $w) {
			if (isset($w['label']) && ! in_array($w['key'] ?? '', $skip, true)) {
				$out[] = (string) $w['label'];
			}
		}

		return array_slice($out, 0, 3);
	}

	/* ------------------------------------ odłożenie działania ------------------------------------ */

	/**
	 * Odłóż działanie z rekomendacji do końca dnia za N dni (strefa WP). Ponowne odłożenie nadpisuje termin.
	 *
	 * @param array<string, mixed> $input company_id, action_type, days, reason
	 * @return array<string, mixed>
	 */
	public function snooze(TenantContext $ctx, array $input): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$companyId = (int) ($input['company_id'] ?? 0);
		$type = sanitize_key((string) ($input['action_type'] ?? ''));
		$days = (int) ($input['days'] ?? 0);

		if (! in_array($type, ActionPriorityEngine::SNOOZABLE, true)) {
			throw UbrException::invalid('Tego działania nie można odłożyć (follow-up przełóż, zmieniając termin).');
		}

		if (! in_array($days, self::SNOOZE_DAYS, true)) {
			throw UbrException::invalid('Wybierz okres odłożenia: 1, 3, 7 lub 14 dni.');
		}

		if ($companyId <= 0 || ! $this->repo->visible($ctx->organizationId, $companyId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$until = (new \DateTimeImmutable('today', wp_timezone()))->modify('+' . ($days + 1) . ' days -1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$reason = mb_substr(sanitize_text_field((string) ($input['reason'] ?? '')), 0, 255);
		$this->repo->snooze($ctx->organizationId, $companyId, $type, $until, $ctx->userId, $reason !== '' ? $reason : null);
		self::bumpOrganization($ctx->organizationId);

		return ['company_id' => $companyId, 'action_type' => $type, 'snoozed_until' => $this->iso($until)];
	}

	/**
	 * @param array<string, mixed> $input company_id, action_type
	 * @return array<string, mixed>
	 */
	public function unsnooze(TenantContext $ctx, array $input): array
	{
		$this->assert($ctx, 'pipeline.manage');
		$companyId = (int) ($input['company_id'] ?? 0);
		$type = sanitize_key((string) ($input['action_type'] ?? ''));

		if ($companyId <= 0 || ! $this->repo->visible($ctx->organizationId, $companyId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		$this->repo->unsnooze($ctx->organizationId, $companyId, $type);
		self::bumpOrganization($ctx->organizationId);

		return ['company_id' => $companyId, 'action_type' => $type, 'snoozed_until' => null];
	}

	/* ------------------------------------ cache ------------------------------------ */

	public static function globalVersion(): int
	{
		return (int) get_option('ubr_ac_gv', 1);
	}

	public static function orgVersion(int $organizationId): int
	{
		return (int) get_option('ubr_ac_ov_' . $organizationId, 1);
	}

	/** Zmiana danych firmy wspólnych dla organizacji (weryfikacja, analiza, kontakty) → nowe klucze dla wszystkich. */
	public static function bumpGlobal(): void
	{
		update_option('ubr_ac_gv', self::globalVersion() + 1, false);
	}

	/** Zmiana prywatnych danych organizacji (pipeline, follow-upy, ocena Lead Intelligence, odłożenie) → nowe klucze organizacji. */
	public static function bumpOrganization(int $organizationId): void
	{
		update_option('ubr_ac_ov_' . $organizationId, self::orgVersion($organizationId) + 1, false);
	}

	/* ------------------------------------ prezentacja ------------------------------------ */

	/**
	 * @param array<string, mixed> $summary
	 * @return list<array<string, mixed>>
	 */
	private function kpis(array $summary, bool $pipelineView): array
	{
		$out = [];

		foreach (QueueCatalog::KPIS as $key => $k) {
			if (in_array($key, ['followups_due', 'total_prospects'], true) && ! $pipelineView) {
				continue;
			}

			$f = $summary['followups'];
			$value = match ($key) {
				'total_prospects' => array_sum(array_map('intval', $summary['pipeline'])),
				'followups_due' => $f['overdue'] + $f['today'],
				'needs_review' => (int) ($summary['counts']['review_website'] ?? 0),
				default => (int) ($summary['counts'][$key] ?? 0),
			};
			$out[] = [
				'key' => $key,
				'label' => $k['label'],
				'value' => $value,
				'detail' => match ($key) {
					'total_prospects' => 'Odkryte w wyszukiwaniach: ' . (int) ($summary['coverage']['pool'] ?? 0),
					'followups_due' => 'w tym zaległe: ' . $f['overdue'] . ' · na dziś: ' . $f['today'],
					default => null,
				},
				'definition' => $k['definition'] ?? QueueCatalog::QUEUES[$k['queue']]['definition'],
				'queue' => $k['queue'],
				'link' => $k['queue'] === null ? 'prospects' : null,
				'bucket' => $k['bucket'] ?? null,
				'tone' => match ($key) {
					'followups_due' => $f['overdue'] > 0 ? 'danger' : 'neutral',
					'ready_to_contact' => $value > 0 ? 'money' : 'neutral',
					default => 'neutral',
				},
			];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $summary
	 * @return list<array<string, mixed>>
	 */
	private function queueList(array $summary, bool $pipelineView): array
	{
		$out = [];

		foreach (QueueCatalog::QUEUES as $key => $q) {
			if ($key === 'followups' && ! $pipelineView) {
				continue;
			}

			$f = $summary['followups'];
			$out[] = ['key' => $key, 'label' => $q['label'], 'label_pl' => $q['label_pl'], 'definition' => $q['definition'], 'scope' => $q['scope'],
				'count' => $key === 'followups' ? $f['overdue'] + $f['today'] + $f['upcoming'] + $f['later'] : (int) ($summary['counts'][$key] ?? 0)];
		}

		return $out;
	}

	/**
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private function followupList(TenantContext $ctx, string $bucket, string $owner, int $limit): array
	{
		$res = $this->repo->followups($ctx->organizationId, $bucket, $owner, $ctx->userId, $limit);
		[$now] = ActionCenterRepository::bounds();

		return [
			'items' => array_map(fn ($f) => [
				'id' => (int) $f['id'],
				'title' => $f['title'],
				'due_at' => $this->iso($f['due_at']),
				'overdue' => $f['due_at'] < $now,
				'company' => $this->companyBrief($f),
				'prospect_id' => (int) $f['prospect_id'],
				'assigned' => $this->pipelineService->userBrief($f['assigned_user_id'] ? (int) $f['assigned_user_id'] : null),
			], $res['items']),
			'total' => $res['total'],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function activity(TenantContext $ctx): array
	{
		return array_map(function ($r) {
			if ($r['kind'] === 'activity') {
				$text = (PipelineCatalog::ACTIVITY_TYPES[$r['type']] ?? $r['type']) . ($r['description'] ? ': ' . mb_strimwidth((string) $r['description'], 0, 120, '…') : '');
				$entry = ['type' => 'activity_' . $r['type'], 'text' => $text, 'user' => $this->pipelineService->userBrief($r['user_id'] ? (int) $r['user_id'] : null), 'at' => $this->iso($r['at'])];
			} else {
				$entry = $this->pipelineService->presentEvent(['type' => $r['type'], 'old_value' => $r['old_value'], 'new_value' => $r['new_value'], 'user_id' => $r['user_id'], 'created_at' => $r['at']]);
			}

			return $entry + ['company' => ['id' => (int) $r['company_id'], 'name' => $r['company_name']]];
		}, $this->repo->recentActivity($ctx->organizationId, 10));
	}

	/**
	 * @param array<string, mixed> $summary
	 * @return array<string, mixed>
	 */
	private function coverage(TenantContext $ctx, array $summary): array
	{
		$c = $summary['coverage'];
		$p = $summary['pipeline'];
		$sum = fn (array $keys) => array_sum(array_map(fn ($k) => (int) ($p[$k] ?? 0), $keys));
		$items = [
			['key' => 'pool', 'label' => 'Firmy w puli', 'value' => (int) ($c['pool'] ?? 0), 'definition' => 'Unikalne firmy z wyników wyszukiwań organizacji + aktywni prospekci.'],
			['key' => 'verified', 'label' => 'Zweryfikowane', 'value' => (int) ($c['verified'] ?? 0), 'definition' => 'Status weryfikacji w rejestrach: VERIFIED.'],
			['key' => 'domain_confirmed', 'label' => 'Domena potwierdzona', 'value' => (int) ($c['domain_confirmed'] ?? 0), 'definition' => 'Domena potwierdzona jako należąca do firmy.'],
			['key' => 'analyzed', 'label' => 'Strona przeanalizowana', 'value' => (int) ($c['analyzed'] ?? 0), 'definition' => 'Zakończona analiza strony (COMPLETED lub PARTIAL).'],
			['key' => 'contacts_researched', 'label' => 'Decydenci wyszukani', 'value' => (int) ($c['contacts_researched'] ?? 0), 'definition' => 'Decision Maker Finder zakończył wyszukiwanie (z wynikiem lub bez).'],
			['key' => 'with_contacts', 'label' => 'Decydent znaleziony', 'value' => (int) ($c['with_contacts'] ?? 0), 'definition' => 'Firmy z co najmniej jedną znalezioną osobą decyzyjną (Decision Maker Finder).'],
			['key' => 'evaluated', 'label' => 'Ocenione (Lead Intelligence)', 'value' => (int) ($c['evaluated'] ?? 0), 'definition' => 'Firmy z zapisaną oceną Lead Potential (w tym wstępną).'],
			['key' => 'qualified', 'label' => 'Zakwalifikowane', 'value' => (int) ($c['qualified'] ?? 0), 'definition' => 'Aktywni prospekci z kwalifikacją „Dobry kandydat”.'],
			['key' => 'prospects', 'label' => 'Zapisane (Prospects)', 'value' => array_sum(array_map('intval', $p)), 'definition' => 'Aktywni (niezarchiwizowani) prospekci organizacji.'],
			['key' => 'in_conversation', 'label' => 'W kontakcie', 'value' => $sum(ActionPriorityEngine::CONVERSATION_STATUSES), 'definition' => 'Obecny status: Skontaktowano, Ponowienie, W rozmowach lub Oferta wysłana.'],
			['key' => 'won', 'label' => 'Wygrane', 'value' => (int) ($p['won'] ?? 0), 'definition' => 'Obecny status: Wygrany.'],
			['key' => 'lost', 'label' => 'Utracone / nie pasuje', 'value' => $sum(['lost', 'not_a_fit']), 'definition' => 'Obecny status: Utracony lub Nie pasuje.'],
		];

		return [
			'items' => $items,
			'awaiting_evaluation' => max(0, (int) ($c['pool'] ?? 0) - (int) ($c['evaluated'] ?? 0)),
			'stale' => (int) ($c['stale'] ?? 0),
			'exported' => (int) $summary['exported'],
			'by_status' => array_map(fn ($k, $d) => ['key' => $k, 'label' => $d['label'], 'count' => (int) ($p[$k] ?? 0)], array_keys(PipelineCatalog::STATUSES), PipelineCatalog::STATUSES),
			'note' => 'Niezależne liczniki aktualnego stanu — nie lejek konwersji. Etapy nie są liniowe (np. firma może być prospektem bez analizy), a historia przejść istnieje dopiero od wdrożenia Pipeline.',
		];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function companyBrief(array $r): array
	{
		return ['id' => (int) $r['company_id'], 'name' => $r['name'], 'domain' => $r['domain'] ?: null, 'website_url' => $r['website_url'] ?? null];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	private function leadBrief(array $r): array
	{
		return [
			'score' => isset($r['lead_score']) && $r['lead_score'] !== null ? (int) $r['lead_score'] : null,
			'confidence' => $r['confidence'] ?? null,
			'coverage' => isset($r['coverage']) && $r['coverage'] !== null ? (int) $r['coverage'] : null,
		];
	}

	private function prospectBrief(mixed $id, ?string $status, ?string $priority, mixed $owner): ?array
	{
		if (! $id) {
			return null;
		}

		return ['id' => (int) $id, 'status' => $status, 'status_label' => PipelineCatalog::statusLabel($status), 'priority' => $priority, 'owner' => $this->pipelineService->userBrief($owner ? (int) $owner : null)];
	}

	/**
	 * @param array<string, mixed> $r
	 * @return array<string, mixed>
	 */
	public function signals(array $r): array
	{
		return [
			'has_li' => ! empty($r['li_id']),
			'has_blocker' => (int) ($r['has_blocker'] ?? 0) === 1,
			'recommendation' => $r['recommendation'] ?? null,
			'analysis_status' => $r['analysis_status'] ?? null,
			'analyzed_at' => $r['analyzed_at'] ?? null,
			'domain' => $r['domain'] ?? null,
			'prospect_status' => $r['prospect_status'] ?? null,
			'prospect_archived' => false,
			'has_open_followup' => ! empty($r['next_action_at']),
		];
	}

	private function bucketOf(string $dueUtc): string
	{
		[$now, $todayEnd, $weekEnd] = ActionCenterRepository::bounds();

		return match (true) {
			$dueUtc < $now => 'overdue',
			$dueUtc <= $todayEnd => 'today',
			$dueUtc <= $weekEnd => 'upcoming',
			default => 'later',
		};
	}

	private function owner(mixed $owner): string
	{
		return in_array($owner, QueueCatalog::OWNERS, true) ? $owner : 'all';
	}

	private function iso(?string $utc): ?string
	{
		return $utc ? gmdate('c', strtotime($utc . ' UTC')) : null;
	}

	private function assert(TenantContext $ctx, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}
	}
}
