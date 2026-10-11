<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Domain\Sequences\SequenceCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Support\UbrException;

/**
 * Prompt 10.5 — ustawienia modułu (Sequences → Settings), lista kontrolna przed włączeniem automatycznej wysyłki
 * i liczniki zakładek. Odczyt niczego nie uruchamia.
 */
class SequenceAdminService
{
	public const HEARTBEAT_OPTION = 'ubr_seq_runner_heartbeat';

	public function __construct(private Db $db, private SequenceSettings $settings)
	{
	}

	public function overview(TenantContext $ctx): array
	{
		$own = $ctx->can('sequences.team') ? '' : ' AND owner_user_id = ' . (int) $ctx->userId;
		$s = $this->db->table(Tables::SEQ_SEQUENCES);
		$m = $this->db->table(Tables::SEQ_MESSAGES);
		$counts = $this->db->rows("SELECT status, COUNT(*) n FROM {$s} WHERE organization_id = %d{$own} GROUP BY status", $ctx->organizationId);
		$byStatus = array_column($counts, 'n', 'status');

		return [
			'counts' => [
				'campaigns' => (int) $this->db->value("SELECT COUNT(*) FROM {$this->db->table(Tables::SEQ_CAMPAIGNS)} WHERE organization_id = %d AND status <> 'archived' AND (scope = 'shared' OR owner_user_id = %d)", $ctx->organizationId, $ctx->userId),
				'active' => array_sum(array_map('intval', array_intersect_key($byStatus, array_flip(SequenceCatalog::LIVE)))),
				'scheduled' => (int) $this->db->value("SELECT COUNT(*) FROM {$m} msg JOIN {$s} seq ON seq.id = msg.sequence_id WHERE seq.organization_id = %d" . str_replace('owner_user_id', 'seq.owner_user_id', $own) . " AND msg.status IN ('scheduled','sending') AND seq.status IN ('scheduled','active','paused','error')", $ctx->organizationId),
				'attention' => (int) ($byStatus['error'] ?? 0),
				'unknown' => (int) $this->db->value("SELECT COUNT(*) FROM {$m} msg JOIN {$s} seq ON seq.id = msg.sequence_id WHERE seq.organization_id = %d" . str_replace('owner_user_id', 'seq.owner_user_id', $own) . " AND msg.status = 'unknown_result'", $ctx->organizationId),
				'mailboxes' => (int) $this->db->value("SELECT COUNT(*) FROM {$this->db->table(Tables::SEQ_MAILBOXES)} WHERE organization_id = %d AND user_id = %d AND status <> 'disabled'", $ctx->organizationId, $ctx->userId),
			],
			'sending_enabled' => $this->settings->sendingEnabled($ctx->organizationId),
			'kill_switch' => SequenceSettings::killSwitchOff(),
			'abilities' => ['templates' => $ctx->can('sequences.templates'), 'team' => $ctx->can('sequences.team'), 'settings' => $ctx->can('sequences.settings')],
		];
	}

	public function get(TenantContext $ctx): array
	{
		$s = $this->settings->get($ctx->organizationId);

		return [
			'settings' => [
				'sending_enabled' => $this->settings->sendingEnabled($ctx->organizationId),
				'window' => $s['window'],
				'window_label' => SendWindow::describe($s['window']),
				'caps' => $s['caps'],
				'mailbox_defaults' => $s['mailbox_defaults'],
				'append_unsubscribe' => (bool) $s['append_unsubscribe'],
				'unsubscribe_text' => $s['unsubscribe_text'],
				'enabled_at' => $s['enabled_at'],
			],
			'checklist' => $this->checklist($ctx),
			'kill_switch' => SequenceSettings::killSwitchOff(),
			'can_manage' => $ctx->can('sequences.settings'),
			'timezones' => ['Europe/Warsaw', 'Europe/London', 'Europe/Berlin', 'UTC'],
			'cron_hint' => 'Zalecany cron serwera (co 5 min): wp ubr queue-work --all',
		];
	}

	/** @param array<string, mixed> $input */
	public function save(TenantContext $ctx, array $input): array
	{
		if (! $ctx->can('sequences.settings')) {
			throw UbrException::forbidden('Ustawienia modułu zmienia właściciel lub administrator.');
		}

		$changes = [];

		if (isset($input['window']) && is_array($input['window'])) {
			$changes['window'] = SendWindow::normalize($input['window']);
		}

		if (isset($input['caps']) && is_array($input['caps'])) {
			$changes['caps'] = ['per_day' => max(1, min(2000, (int) ($input['caps']['per_day'] ?? 100))), 'per_hour' => max(1, min(500, (int) ($input['caps']['per_hour'] ?? 25)))];
		}

		if (isset($input['mailbox_defaults']) && is_array($input['mailbox_defaults'])) {
			$changes['mailbox_defaults'] = ['per_day' => max(1, min(500, (int) ($input['mailbox_defaults']['per_day'] ?? 30))), 'per_hour' => max(1, min(200, (int) ($input['mailbox_defaults']['per_hour'] ?? 8))), 'min_interval_minutes' => max(1, min(240, (int) ($input['mailbox_defaults']['min_interval_minutes'] ?? 4)))];
		}

		if (array_key_exists('append_unsubscribe', $input)) {
			$changes['append_unsubscribe'] = (bool) $input['append_unsubscribe'];
		}

		if (array_key_exists('unsubscribe_text', $input)) {
			$text = mb_substr(trim(CampaignService::plain((string) $input['unsubscribe_text'])), 0, 500);

			if (! str_contains($text, '{{unsubscribe_link}}')) {
				throw UbrException::invalid('Tekst stopki musi zawierać {{unsubscribe_link}}.');
			}

			$changes['unsubscribe_text'] = $text;
		}

		if (! empty($input['confirm_duplicates'])) {
			$changes['duplicates_confirmed_at'] = Db::now();
		}

		if (array_key_exists('sending_enabled', $input)) {
			if ($input['sending_enabled']) {
				$this->settings->save($ctx->organizationId, $changes);
				$missing = array_values(array_filter($this->checklist($ctx), fn ($c) => ! $c['ok']));

				if ($missing) {
					throw new UbrException('Nie można włączyć wysyłki — brakuje: ' . implode('; ', array_map(fn ($c) => $c['label'], $missing)) . '.', 'ubr_sequences_checklist', 422);
				}

				$changes = ['sending_enabled' => true, 'enabled_by' => $ctx->userId, 'enabled_at' => Db::now()];
			} else {
				$changes['sending_enabled'] = false;
			}
		}

		$this->settings->save($ctx->organizationId, $changes);

		return $this->get($ctx);
	}

	/**
	 * Lista kontrolna przed włączeniem automatycznej wysyłki.
	 *
	 * @return list<array{key: string, label: string, ok: bool, detail: string}>
	 */
	public function checklist(TenantContext $ctx): array
	{
		$s = $this->settings->get($ctx->organizationId);
		$mb = $this->db->table(Tables::SEQ_MAILBOXES);
		$tested = (int) $this->db->value("SELECT COUNT(*) FROM {$mb} WHERE organization_id = %d AND provider = 'smtp' AND status = 'connected' AND last_tested_at >= %s", $ctx->organizationId, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS));
		$heartbeat = (int) get_option(self::HEARTBEAT_OPTION, 0);
		$testSent = (int) $this->db->value("SELECT COUNT(*) FROM {$this->db->table(Tables::SEQ_MESSAGES)} WHERE organization_id = %d AND transport = 'test' AND status = 'sent' AND sent_at >= %s", $ctx->organizationId, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS));

		return [
			['key' => 'mailbox', 'label' => 'Skrzynka SMTP połączona i przetestowana (30 dni)', 'ok' => $tested > 0, 'detail' => $tested > 0 ? $tested . ' skrzynek gotowych.' : 'Podłącz skrzynkę i wykonaj test połączenia.'],
			['key' => 'worker', 'label' => 'Worker wysyłki działa (ostatnie 15 min)', 'ok' => $heartbeat >= time() - 15 * MINUTE_IN_SECONDS, 'detail' => $heartbeat ? 'Ostatnie uruchomienie: ' . gmdate('Y-m-d H:i', $heartbeat) . ' UTC.' : 'Brak uruchomień — skonfiguruj cron serwera: wp ubr queue-work --all.'],
			['key' => 'schedule', 'label' => 'Harmonogram przetestowany sekwencją w trybie testowym', 'ok' => $testSent > 0, 'detail' => $testSent > 0 ? 'Przetworzono wiadomości testowe: ' . $testSent . '.' : 'Uruchom dowolną sekwencję w trybie testowym (nic nie wychodzi) i poczekaj na pierwszą wiadomość.'],
			['key' => 'duplicates', 'label' => 'Potwierdzone zabezpieczenia przed duplikatami', 'ok' => ! empty($s['duplicates_confirmed_at']), 'detail' => 'Jedna aktywna sekwencja na adres, wynik nieznany bez automatycznego ponowienia — potwierdź, że zasady są zrozumiałe.'],
			['key' => 'kill_switch', 'label' => 'Wysyłka nie jest zablokowana w wp-config.php', 'ok' => ! SequenceSettings::killSwitchOff(), 'detail' => SequenceSettings::killSwitchOff() ? 'UBR_SEQUENCES_SENDING = false.' : 'OK.'],
		];
	}
}
