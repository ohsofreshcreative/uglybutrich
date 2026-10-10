<?php

namespace App\Ubr\Services;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\ClickUp\ClickUpClient;
use App\Ubr\Infrastructure\ClickUp\ClickUpError;
use App\Ubr\Infrastructure\Repositories\ClickUpRepository;
use App\Ubr\Support\UbrException;

/**
 * Miejsce eksportu: Workspace → Space → Folder (opcjonalny) → List, pobierane na żywo z ClickUp API tokenem
 * BIEŻĄCEGO użytkownika (nie zakładamy dostępu do wszystkich Workspace organizacji). Zapis per połączenie.
 * Mapowanie Custom Fields: tylko istniejące pola listy o zgodnym typie — pól nie tworzymy.
 */
class ClickUpDestinationService
{
	/** Pola zarządzane przez Ugly but Rich, które można zmapować, i dopuszczalne typy pól ClickUp. */
	public const MAPPABLE = [
		'company_name' => ['label' => 'Company Name', 'types' => ['short_text', 'text']],
		'website' => ['label' => 'Website', 'types' => ['url', 'short_text', 'text']],
		'relevance_score' => ['label' => 'Relevance Score', 'types' => ['number', 'short_text', 'text']],
		'opportunity_score' => ['label' => 'Opportunity Score', 'types' => ['number', 'short_text', 'text']],
		'contact_priority_score' => ['label' => 'Contact Priority Score', 'types' => ['number', 'short_text', 'text']],
		'pipeline_status' => ['label' => 'Pipeline Status', 'types' => ['short_text', 'text', 'drop_down']],
		'priority' => ['label' => 'Priority', 'types' => ['short_text', 'text', 'drop_down']],
	];

	public function __construct(private ClickUpRepository $repo, private ClickUpClient $client, private ClickUpOAuthService $oauth)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function options(TenantContext $ctx, string $level, string $parentId = ''): array
	{
		[$connection, $token] = $this->tokenFor($ctx);
		$parentId = preg_replace('/[^A-Za-z0-9_-]/', '', $parentId) ?? '';

		$res = match ($level) {
			'teams' => $this->client->teams($token),
			'spaces' => $this->client->spaces($token, $parentId),
			'folders' => $this->client->folders($token, $parentId),
			'folder_lists' => $this->client->folderLists($token, $parentId),
			'folderless_lists' => $this->client->folderlessLists($token, $parentId),
			'fields' => $this->client->listFields($token, $parentId),
			default => throw UbrException::invalid('Nieznany poziom.'),
		};

		if ($res['error']) {
			$this->handle($connection, $res['error']);
		}

		$key = ['teams' => 'teams', 'spaces' => 'spaces', 'folders' => 'folders', 'folder_lists' => 'lists', 'folderless_lists' => 'lists', 'fields' => 'fields'][$level];
		$items = [];

		foreach ((array) ($res['data'][$key] ?? []) as $item) {
			if (! is_array($item) || ! isset($item['id'])) {
				continue;
			}

			$row = ['id' => (string) $item['id'], 'name' => mb_substr((string) ($item['name'] ?? ''), 0, 200)];

			if ($level === 'fields') {
				$row['type'] = (string) ($item['type'] ?? '');
				$row['options'] = array_map(fn ($o) => ['id' => (string) ($o['id'] ?? ''), 'name' => (string) ($o['name'] ?? '')], (array) ($item['type_config']['options'] ?? []));
			}

			$items[] = $row;
		}

		return ['level' => $level, 'items' => $items, 'empty' => $items === []];
	}

	/**
	 * Zapis miejsca eksportu (weryfikacja: lista musi być dostępna dla tokenu użytkownika).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function save(TenantContext $ctx, array $input): array
	{
		[$connection, $token] = $this->tokenFor($ctx);
		$clean = fn ($k) => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input[$k] ?? '')) ?? '';
		$listId = $clean('list_id');
		$teamId = $clean('team_id');

		if ($listId === '' || $teamId === '') {
			throw UbrException::invalid('Wybierz Workspace i listę.');
		}

		if (! in_array($teamId, array_column((array) $connection['authorized_teams'], 'id'), true)) {
			$teams = $this->client->teams($token);

			if ($teams['error'] || ! in_array($teamId, array_map(fn ($t) => (string) ($t['id'] ?? ''), (array) ($teams['data']['teams'] ?? [])), true)) {
				throw UbrException::invalid('Ten Workspace nie jest autoryzowany dla Twojego konta ClickUp.');
			}
		}

		// Sprawdzenie dostępu do listy (pola listy = lekki odczyt).
		$fields = $this->client->listFields($token, $listId);

		if ($fields['error']) {
			$this->handle($connection, $fields['error']);
		}

		$destination = [
			'team_id' => $teamId, 'team_name' => mb_substr(sanitize_text_field((string) ($input['team_name'] ?? '')), 0, 200),
			'space_id' => $clean('space_id'), 'space_name' => mb_substr(sanitize_text_field((string) ($input['space_name'] ?? '')), 0, 200),
			'folder_id' => $clean('folder_id') ?: null, 'folder_name' => mb_substr(sanitize_text_field((string) ($input['folder_name'] ?? '')), 0, 200) ?: null,
			'list_id' => $listId, 'list_name' => mb_substr(sanitize_text_field((string) ($input['list_name'] ?? '')), 0, 200),
		];
		$settings = (array) ($connection['settings'] ?? []);

		// Zmiana listy unieważnia mapowanie pól poprzedniej listy.
		if (($connection['destination']['list_id'] ?? null) !== $listId) {
			$settings['field_mapping'] = [];
		}

		$this->repo->updateConnection((int) $connection['id'], ['destination' => $destination, 'settings' => $settings]);

		return $this->oauth->status($ctx);
	}

	/**
	 * Szablon nazwy zadania i mapowanie Custom Fields (tylko pola istniejące na wybranej liście, o zgodnym typie).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function saveSettings(TenantContext $ctx, array $input): array
	{
		[$connection, $token] = $this->tokenFor($ctx);
		$settings = (array) ($connection['settings'] ?? []);

		if (isset($input['task_name_template'])) {
			$tpl = mb_substr(sanitize_text_field((string) $input['task_name_template']), 0, 150);

			if (! str_contains($tpl, '{company}')) {
				throw UbrException::invalid('Szablon nazwy musi zawierać {company}.');
			}

			$settings['task_name_template'] = $tpl;
		}

		if (isset($input['field_mapping']) && is_array($input['field_mapping'])) {
			$listId = (string) ($connection['destination']['list_id'] ?? '');

			if ($listId === '') {
				throw UbrException::invalid('Najpierw wybierz listę ClickUp.');
			}

			$res = $this->client->listFields($token, $listId);

			if ($res['error']) {
				$this->handle($connection, $res['error']);
			}

			$fields = [];

			foreach ((array) ($res['data']['fields'] ?? []) as $f) {
				$fields[(string) ($f['id'] ?? '')] = $f;
			}

			$mapping = [];

			foreach ($input['field_mapping'] as $key => $fieldId) {
				$fieldId = (string) $fieldId;

				if ($fieldId === '' || ! isset(self::MAPPABLE[$key])) {
					continue;
				}

				$field = $fields[$fieldId] ?? throw UbrException::invalid('Pole „' . self::MAPPABLE[$key]['label'] . '” nie istnieje na wybranej liście.');

				if (! in_array((string) $field['type'], self::MAPPABLE[$key]['types'], true)) {
					throw UbrException::invalid(sprintf('Pole „%s” ma typ %s — niezgodny z „%s”.', $field['name'] ?? $fieldId, $field['type'] ?? '?', self::MAPPABLE[$key]['label']));
				}

				$mapping[$key] = ['field_id' => $fieldId, 'type' => (string) $field['type'], 'name' => mb_substr((string) ($field['name'] ?? ''), 0, 120), 'options' => array_map(fn ($o) => ['id' => (string) ($o['id'] ?? ''), 'name' => (string) ($o['name'] ?? '')], (array) ($field['type_config']['options'] ?? []))];
			}

			$settings['field_mapping'] = $mapping;
		}

		$this->repo->updateConnection((int) $connection['id'], ['settings' => $settings]);

		return $this->oauth->status($ctx);
	}

	/**
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	private function tokenFor(TenantContext $ctx): array
	{
		if (! $ctx->can('clickup.connect')) {
			throw UbrException::forbidden();
		}

		$connection = $this->repo->connection($ctx->organizationId, $ctx->userId) ?? throw UbrException::invalid('Konto ClickUp nie jest połączone.');
		$token = $this->oauth->token($connection) ?? throw UbrException::invalid('Połączenie ClickUp wymaga ponownej autoryzacji.');

		return [$connection, $token];
	}

	/**
	 * @param array<string, mixed> $connection
	 */
	private function handle(array $connection, ClickUpError $e): never
	{
		if ($e->needsReconnect()) {
			$this->oauth->markReconnect((int) $connection['id'], $e->getMessage());
		}

		throw new UbrException($e->getMessage(), 'ubr_clickup_' . $e->kind, $e->kind === ClickUpError::RATE_LIMITED ? 429 : 422);
	}
}
