<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Domain\Sequences\SequenceCatalog;
use App\Ubr\Domain\Sequences\TemplateRenderer;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Support\UbrException;

/**
 * Prompt 10.5 — biblioteka kampanii (szablonów sekwencji).
 *
 * Uprawnienia (propozycja z analizy ról): kampanie WSPÓLNE organizacji tworzy i edytuje owner / admin
 * (`sequences.templates`) — pracownicy ich używają; każdy pracownik może tworzyć PRYWATNE kampanie (widoczne dla niego
 * i adminów). Kampania ma dowolną liczbę kroków (wiadomości) z własnym tematem, treścią, odstępem i aktywnością.
 * Każda zmiana kroków podnosi `version`; sekwencje firm są niezależnymi kopiami (zmiana szablonu ich nie zmienia).
 */
class CampaignService
{
	public const MAX_STEPS = 30;

	public function __construct(private Db $db, private SequenceSettings $settings)
	{
	}

	/** @return list<array<string, mixed>> */
	public function list(TenantContext $ctx, array $query = []): array
	{
		$where = ['organization_id = %d', "(scope = 'shared' OR owner_user_id = %d" . ($ctx->can('sequences.team') ? " OR scope = 'personal'" : '') . ')'];
		$args = [$ctx->organizationId, $ctx->userId];
		$status = sanitize_key((string) ($query['status'] ?? ''));

		if (isset(SequenceCatalog::CAMPAIGN_STATUSES[$status])) {
			$where[] = 'status = %s';
			$args[] = $status;
		} elseif ($status !== 'all') {
			$where[] = "status <> 'archived'";
		}

		$rows = $this->db->rows("SELECT * FROM {$this->t()} WHERE " . implode(' AND ', $where) . " ORDER BY scope = 'shared' DESC, status = 'active' DESC, name ASC LIMIT 300", ...$args);
		$ids = array_map(fn ($r) => (int) $r['id'], $rows);
		$counts = $ids ? $this->db->rows("SELECT campaign_id, COUNT(*) n, SUM(is_active) a, SUM(CASE WHEN is_active = 1 THEN delay_days ELSE 0 END) d FROM {$this->steps()} WHERE campaign_id IN (" . implode(',', $ids) . ') GROUP BY campaign_id') : [];
		$byId = [];

		foreach ($counts as $c) {
			$byId[(int) $c['campaign_id']] = $c;
		}

		$usage = $ids ? $this->db->rows("SELECT campaign_id, COUNT(*) n FROM {$this->db->table(Tables::SEQ_SEQUENCES)} WHERE campaign_id IN (" . implode(',', $ids) . ") AND status IN ('scheduled','active','paused','error') GROUP BY campaign_id") : [];
		$used = array_column($usage, 'n', 'campaign_id');

		return array_map(fn ($r) => $this->present($r, null, $ctx) + ['step_count' => (int) ($byId[(int) $r['id']]['n'] ?? 0), 'active_steps' => (int) ($byId[(int) $r['id']]['a'] ?? 0), 'total_days' => (int) ($byId[(int) $r['id']]['d'] ?? 0), 'live_sequences' => (int) ($used[$r['id']] ?? 0)], $rows);
	}

	public function get(TenantContext $ctx, int $id): array
	{
		$c = $this->visible($ctx, $id);

		return $this->present($c, $this->stepRows($id), $ctx);
	}

	/** @param array<string, mixed> $input */
	public function create(TenantContext $ctx, array $input): array
	{
		$scope = ($input['scope'] ?? 'personal') === 'shared' ? 'shared' : 'personal';

		if ($scope === 'shared') {
			$this->assert($ctx, 'sequences.templates', 'Wspólne kampanie tworzy właściciel lub administrator — możesz utworzyć kampanię prywatną.');
		}

		$id = $this->db->insert(Tables::SEQ_CAMPAIGNS, [
			'organization_id' => $ctx->organizationId,
			'scope' => $scope,
			'owner_user_id' => $ctx->userId,
			'name' => $this->name($input['name'] ?? ''),
			'description' => $this->description($input['description'] ?? ''),
			'status' => 'draft',
			'settings' => wp_json_encode(['window' => SendWindow::normalize((array) ($input['settings']['window'] ?? $this->settings->get($ctx->organizationId)['window']))]),
			'version' => 1,
			'created_at' => Db::now(),
			'updated_at' => Db::now(),
		]);

		if (isset($input['steps']) && is_array($input['steps'])) {
			$this->replaceSteps($id, $input['steps'], false);
		}

		return $this->get($ctx, $id);
	}

	/**
	 * Zapis kampanii: nazwa, opis, okno wysyłki i PEŁNA lista kroków w kolejności (dodawanie, usuwanie, zmiana kolejności,
	 * duplikowanie kroku = po stronie edytora). Status: draft / active / archived.
	 *
	 * @param array<string, mixed> $input
	 */
	public function update(TenantContext $ctx, int $id, array $input): array
	{
		$c = $this->editable($ctx, $id);
		$data = [];

		if (array_key_exists('name', $input)) {
			$data['name'] = $this->name($input['name']);
		}

		if (array_key_exists('description', $input)) {
			$data['description'] = $this->description($input['description']);
		}

		if (isset($input['settings']['window']) && is_array($input['settings']['window'])) {
			$data['settings'] = wp_json_encode(['window' => SendWindow::normalize($input['settings']['window'])]);
		}

		if (isset($input['steps']) && is_array($input['steps'])) {
			$this->replaceSteps($id, $input['steps'], true);
			$data['version'] = (int) $c['version'] + 1;
		}

		if (array_key_exists('status', $input)) {
			$status = sanitize_key((string) $input['status']);

			if (! isset(SequenceCatalog::CAMPAIGN_STATUSES[$status])) {
				throw UbrException::invalid('Nieznany status kampanii.');
			}

			if ($status === 'active' && ! (int) $this->db->value("SELECT COUNT(*) FROM {$this->steps()} WHERE campaign_id = %d AND is_active = 1", $id)) {
				throw UbrException::invalid('Aktywna kampania musi mieć co najmniej jedną aktywną wiadomość.');
			}

			$data['status'] = $status;
			$data['archived_at'] = $status === 'archived' ? Db::now() : null;
		}

		if ($data) {
			$this->db->update(Tables::SEQ_CAMPAIGNS, $data + ['updated_at' => Db::now()], ['id' => $id]);
		}

		return $this->get($ctx, $id);
	}

	/** Duplikat kampanii (zawsze szkic; wspólną kampanię pracownik duplikuje jako prywatną). */
	public function duplicate(TenantContext $ctx, int $id): array
	{
		$c = $this->visible($ctx, $id);
		$steps = $this->stepRows($id);
		$scope = $c['scope'] === 'shared' && $ctx->can('sequences.templates') ? 'shared' : 'personal';

		return $this->create($ctx, [
			'name' => mb_substr($c['name'] . ' (kopia)', 0, 160),
			'description' => $c['description'],
			'scope' => $scope,
			'settings' => json_decode((string) $c['settings'], true) ?: [],
			'steps' => array_map(fn ($s) => ['subject' => $s['subject'], 'body' => $s['body'], 'delay_days' => (int) $s['delay_days'], 'is_active' => (bool) $s['is_active']], $steps),
		]);
	}

	/**
	 * Podgląd całej sekwencji: przykładowy harmonogram (od teraz) + render z wartościami przykładowymi / firmy.
	 *
	 * @param list<array<string, mixed>> $steps kroki z edytora (niezapisane) albo z bazy
	 * @param array<string, string|null> $values
	 * @return list<array<string, mixed>>
	 */
	public static function preview(array $steps, array $window, array $values, ?\DateTimeImmutable $start = null): array
	{
		$at = SendWindow::next($start ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $window);
		$out = [];
		$first = true;

		foreach ($steps as $i => $s) {
			if (empty($s['is_active'])) {
				$out[] = ['position' => $i + 1, 'active' => false, 'subject' => (string) $s['subject'], 'body' => (string) $s['body'], 'missing' => [], 'at' => null, 'day_offset' => null];
				continue;
			}

			if (! $first) {
				$at = SendWindow::due($at, (int) $s['delay_days'], $window);
			}

			$first = false;
			$missing = [];
			$subject = TemplateRenderer::render((string) $s['subject'], $values, $missing, true);
			$body = TemplateRenderer::render((string) $s['body'], $values, $missing);
			$out[] = ['position' => $i + 1, 'active' => true, 'subject' => $subject, 'body' => $body, 'missing' => array_values(array_unique(array_diff($missing, TemplateRenderer::SYSTEM))), 'at' => gmdate('c', $at->getTimestamp()), 'delay_days' => (int) $s['delay_days']];
		}

		return $out;
	}

	/** Wartości przykładowe do podglądu szablonu w bibliotece (jawnie oznaczone jako przykład). */
	public static function sampleValues(TenantContext $ctx, ?array $mailbox = null): array
	{
		$u = get_userdata($ctx->userId);

		return [
			'company_name' => '[Nazwa firmy]', 'company_website' => '[strona-firmy.pl]', 'company_domain' => '[strona-firmy.pl]', 'company_city' => '[Miasto]',
			'contact_first_name' => '[Imię]', 'contact_last_name' => '[Nazwisko]', 'contact_full_name' => '[Imię Nazwisko]', 'contact_position' => '[Stanowisko]',
			'sender_first_name' => $u ? (string) $u->first_name : '', 'sender_last_name' => $u ? (string) $u->last_name : '', 'sender_name' => $mailbox['from_name'] ?? ($u ? $u->display_name : ''),
			'sender_email' => $mailbox['email'] ?? ($u ? $u->user_email : ''), 'unsubscribe_link' => '[link rezygnacji]',
		];
	}

	/** Kampania widoczna dla użytkownika (wspólna albo własna; admin — wszystkie). */
	public function visible(TenantContext $ctx, int $id): array
	{
		$c = $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d AND organization_id = %d", $id, $ctx->organizationId);

		if (! $c || ($c['scope'] === 'personal' && (int) $c['owner_user_id'] !== $ctx->userId && ! $ctx->can('sequences.team'))) {
			throw UbrException::notFound('Nie znaleziono kampanii.');
		}

		return $c;
	}

	/** @return list<array<string, mixed>> */
	public function stepRows(int $campaignId): array
	{
		return $this->db->rows("SELECT * FROM {$this->steps()} WHERE campaign_id = %d ORDER BY position ASC, id ASC", $campaignId);
	}

	/** @param list<mixed> $steps */
	public static function cleanSteps(array $steps): array
	{
		if (count($steps) > self::MAX_STEPS) {
			throw UbrException::invalid('Maksymalnie ' . self::MAX_STEPS . ' wiadomości w kampanii.');
		}

		$out = [];

		foreach (array_values($steps) as $i => $s) {
			$s = (array) $s;
			// Treść wysyłana jako tekst (text/plain) i wyświetlana w UI przez x-text — bez obcinania znaków typu „&” / „<”;
			// usuwane tylko znaki sterujące, temat w jednej linii (ochrona nagłówków).
			$subject = mb_substr(TemplateRenderer::oneLine(self::plain((string) ($s['subject'] ?? ''))), 0, 255);
			$body = mb_substr(self::plain(str_replace(["\r\n", "\r"], "\n", (string) ($s['body'] ?? ''))), 0, 20000);

			if (trim($subject) === '' || trim($body) === '') {
				throw UbrException::invalid(sprintf('Wiadomość %d: temat i treść są wymagane.', $i + 1));
			}

			$out[] = ['subject' => $subject, 'body' => $body, 'delay_days' => $i === 0 ? max(0, min(365, (int) ($s['delay_days'] ?? 0))) : max(0, min(365, (int) ($s['delay_days'] ?? 3))), 'is_active' => ! array_key_exists('is_active', $s) || (bool) $s['is_active']];
		}

		return $out;
	}

	public static function plain(string $text): string
	{
		return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
	}

	private function replaceSteps(int $campaignId, array $steps, bool $delete): void
	{
		$clean = self::cleanSteps($steps);

		if ($delete) {
			$this->db->delete(Tables::SEQ_CAMPAIGN_STEPS, ['campaign_id' => $campaignId]);
		}

		foreach ($clean as $i => $s) {
			$this->db->insert(Tables::SEQ_CAMPAIGN_STEPS, ['campaign_id' => $campaignId, 'position' => $i + 1, 'subject' => $s['subject'], 'body' => $s['body'], 'delay_days' => $s['delay_days'], 'is_active' => $s['is_active'] ? 1 : 0, 'created_at' => Db::now(), 'updated_at' => Db::now()]);
		}
	}

	private function editable(TenantContext $ctx, int $id): array
	{
		$c = $this->visible($ctx, $id);

		if ($c['scope'] === 'shared' ? ! $ctx->can('sequences.templates') : (int) $c['owner_user_id'] !== $ctx->userId && ! $ctx->can('sequences.team')) {
			throw UbrException::forbidden('Wspólne kampanie edytuje właściciel lub administrator — zduplikuj kampanię, aby ją dostosować.');
		}

		return $c;
	}

	/** @param list<array<string, mixed>>|null $steps */
	private function present(array $c, ?array $steps, TenantContext $ctx): array
	{
		$owner = get_userdata((int) $c['owner_user_id']);
		$settings = json_decode((string) $c['settings'], true) ?: [];
		$out = [
			'id' => (int) $c['id'],
			'name' => $c['name'],
			'description' => $c['description'],
			'scope' => $c['scope'],
			'scope_label' => $c['scope'] === 'shared' ? 'Wspólna' : 'Prywatna',
			'status' => $c['status'],
			'status_label' => SequenceCatalog::label(SequenceCatalog::CAMPAIGN_STATUSES, $c['status']),
			'version' => (int) $c['version'],
			'window' => SendWindow::normalize((array) ($settings['window'] ?? [])),
			'owner' => $owner ? ['id' => (int) $owner->ID, 'name' => $owner->display_name] : null,
			'can_edit' => $c['scope'] === 'shared' ? $ctx->can('sequences.templates') : ((int) $c['owner_user_id'] === $ctx->userId || $ctx->can('sequences.team')),
			'updated_at' => gmdate('c', strtotime($c['updated_at'] . ' UTC')),
		];

		if ($steps !== null) {
			$out['steps'] = array_map(fn ($s) => ['id' => (int) $s['id'], 'position' => (int) $s['position'], 'subject' => $s['subject'], 'body' => $s['body'], 'delay_days' => (int) $s['delay_days'], 'is_active' => (bool) $s['is_active']], $steps);
		}

		return $out;
	}

	private function name(mixed $v): string
	{
		$name = mb_substr(TemplateRenderer::oneLine(sanitize_text_field((string) $v)), 0, 160);

		if ($name === '') {
			throw UbrException::invalid('Podaj nazwę kampanii.');
		}

		return $name;
	}

	private function description(mixed $v): ?string
	{
		return mb_substr(sanitize_textarea_field((string) $v), 0, 2000) ?: null;
	}

	private function assert(TenantContext $ctx, string $ability, string $message): void
	{
		if (! $ctx->can($ability)) {
			throw UbrException::forbidden($message);
		}
	}

	private function t(): string
	{
		return $this->db->table(Tables::SEQ_CAMPAIGNS);
	}

	private function steps(): string
	{
		return $this->db->table(Tables::SEQ_CAMPAIGN_STEPS);
	}
}
