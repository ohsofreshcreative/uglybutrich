<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Repositories\ActionCenterRepository;
use App\Ubr\Infrastructure\Repositories\ManualReviewRepository;
use App\Ubr\Support\UbrException;

/**
 * Manual Website Review (Prompt 6): subiektywna ocena strony przez człowieka (AI Vision wyłączone).
 * Prywatna dla organizacji, z pełną historią. NIE zmienia Opportunity Score, Lead Potential, Redesign Assessment ani
 * rekomendacji — nie wywołuje `ubr/company/changed`, więc Lead Intelligence nie jest przeliczane.
 */
class ManualReviewService
{
	public const STATUSES = [
		'not_reviewed' => ['label' => 'Brak oceny', 'sentence' => 'Strona nie została jeszcze oceniona ręcznie.'],
		'good' => ['label' => 'Dobra', 'sentence' => 'Strona została oceniona ręcznie jako dobra.'],
		'average' => ['label' => 'Przeciętna', 'sentence' => 'Strona została oceniona ręcznie jako przeciętna.'],
		'poor' => ['label' => 'Słaba', 'sentence' => 'Strona została oceniona ręcznie jako słaba.'],
		'very_poor' => ['label' => 'Bardzo słaba', 'sentence' => 'Strona została oceniona ręcznie jako bardzo słaba.'],
	];

	public function __construct(private ManualReviewRepository $repo, private ActionCenterRepository $pool, private PipelineService $pipeline)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		$this->assertVisible($ctx, $companyId, 'companies.view');

		return $this->present($ctx, $companyId);
	}

	/**
	 * @param array<string, mixed> $input review_status, review_note
	 * @return array<string, mixed>
	 */
	public function save(TenantContext $ctx, int $companyId, array $input): array
	{
		$this->assertVisible($ctx, $companyId, 'pipeline.manage');
		$status = sanitize_key((string) ($input['review_status'] ?? ''));

		if (! isset(self::STATUSES[$status])) {
			throw UbrException::invalid('Wybierz ocenę: dobra, przeciętna, słaba, bardzo słaba lub brak oceny.');
		}

		$note = trim(sanitize_textarea_field((string) ($input['review_note'] ?? '')));
		$note = $note !== '' ? mb_substr($note, 0, 1000) : null;
		$this->repo->save($ctx->organizationId, $companyId, $status, $note, $ctx->userId);
		ActionCenterService::bumpOrganization($ctx->organizationId); // filtry i listy Action Center

		return $this->present($ctx, $companyId);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present(TenantContext $ctx, int $companyId): array
	{
		$cur = $this->repo->current($ctx->organizationId, $companyId);
		$status = $cur['review_status'] ?? 'not_reviewed';
		$iso = fn (?string $utc) => $utc ? gmdate('c', strtotime($utc . ' UTC')) : null;

		return [
			'company_id' => $companyId,
			'status' => $status,
			'label' => self::STATUSES[$status]['label'],
			'sentence' => self::STATUSES[$status]['sentence'],
			'note' => $cur['review_note'] ?? null,
			'reviewer' => $cur ? $this->pipeline->userBrief((int) $cur['reviewer_id']) : null,
			'reviewed_at' => $iso($cur['reviewed_at'] ?? null),
			'statuses' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v['label']], array_keys(self::STATUSES), self::STATUSES),
			'history' => array_map(fn ($h) => [
				'status' => $h['review_status'],
				'label' => self::STATUSES[$h['review_status']]['label'] ?? $h['review_status'],
				'previous_label' => $h['previous_status'] ? (self::STATUSES[$h['previous_status']]['label'] ?? $h['previous_status']) : null,
				'note' => $h['review_note'],
				'reviewer' => $this->pipeline->userBrief((int) $h['reviewer_id']),
				'at' => $iso($h['reviewed_at']),
			], $this->repo->history($ctx->organizationId, $companyId)),
			'can_edit' => $ctx->can('pipeline.manage'),
			'disclaimer' => 'Ocena ręczna jest subiektywna — nie jest pomiarem i nie zmienia Opportunity Score, Lead Potential ani Redesign Assessment.',
		];
	}

	private function assertVisible(TenantContext $ctx, int $companyId, string $ability): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden();
		}

		if ($companyId <= 0 || ! $this->pool->visible($ctx->organizationId, $companyId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}
	}
}
