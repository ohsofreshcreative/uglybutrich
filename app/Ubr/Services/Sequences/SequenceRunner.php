<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Sequences\EmailAddress;
use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Domain\Sequences\TemplateRenderer;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Mail\LogTransport;
use App\Ubr\Infrastructure\Mail\OutgoingMessage;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Services\VerificationRunner;
use App\Ubr\Support\Lock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Prompt 10.5 — worker wysyłki sekwencji (trwała kolejka w MySQL: `ubr_seq_messages`).
 *
 * Uruchamiany przez cron serwera (`wp ubr queue-work --all` — zalecane na Hostingerze), łańcuchowo przez pętlę zwrotną
 * i zapasowo przez WP-Cron / healthcheck. NIGDY w żądaniu użytkownika (listy, filtry, paginacja niczego nie wysyłają).
 *
 * - Jeden worker naraz (blokada MySQL `ubr_seq_send`, zwalnia się sama po przerwaniu procesu), krótki krok czasowy.
 * - Najwyżej JEDNA wiadomość danej sekwencji w kroku; kolejny krok liczony od faktycznej wysyłki poprzedniego —
 *   po awarii workera zaległe wiadomości nie wychodzą „hurtem”.
 * - Przed wysyłką: włączona wysyłka (albo tryb testowy), okno wysyłki, data zakończenia, skrzynka, blokady adresu
 *   (rezygnacja, trwały błąd, DO_NOT_CONTACT, BLOCKED, kontakt wyłączony), limity skrzynki, komplet zmiennych.
 * - Zajęcie wiadomości atomowo (`scheduled` → `sending` + unikalny `send_token` i Message-ID zapisane PRZED wysyłką).
 *   Przerwany proces w trakcie wysyłki → `unknown_result` (bez automatycznego ponowienia — ryzyko duplikatu).
 */
class SequenceRunner
{
	public const CRON_HOOK = 'ubr_sequences_tick';

	public const LOCK = 'ubr_seq_send';

	public const CLAIM_SECONDS = 180;

	public const RETRY_DELAYS = [900, 3600, 14400];

	public function __construct(
		private Db $db,
		private SequenceService $sequences,
		private MailboxService $mailboxes,
		private SequenceSettings $settings,
		private UnsubscribeService $unsubscribe,
		private CompanyRepository $companies,
		private AsyncWorker $worker,
	) {
	}

	/**
	 * @return array{processed: int, sent: int, deferred: int, failed: int, unknown: int, recovered: int, remaining_due: int}
	 */
	public function tick(?int $budgetSeconds = null, bool $chain = true): array
	{
		$stats = ['processed' => 0, 'sent' => 0, 'deferred' => 0, 'failed' => 0, 'unknown' => 0, 'recovered' => 0, 'remaining_due' => 0];

		if (! Lock::acquire(self::LOCK)) {
			return $stats;
		}

		update_option(SequenceAdminService::HEARTBEAT_OPTION, time(), false);

		try {
			$stats['recovered'] = $this->recoverInterrupted();
			$deadline = microtime(true) + ($budgetSeconds ?? VerificationRunner::stepSeconds());
			$done = [];

			while (microtime(true) < $deadline - 1.0) {
				$due = $this->due(25, array_keys($done));

				if (! $due) {
					break;
				}

				foreach ($due as $row) {
					if (microtime(true) >= $deadline - 1.0) {
						break 2;
					}

					$done[(int) $row['sequence_id']] = true;
					$r = $this->processOne((int) $row['id'], (int) $row['sequence_id']);
					$stats['processed']++;
					$stats[$r] = ($stats[$r] ?? 0) + 1;
				}
			}
		} finally {
			Lock::release(self::LOCK);
		}

		$stats['remaining_due'] = $this->countDue();

		if ($chain && $stats['remaining_due'] > 0 && $stats['processed'] > 0) {
			$this->worker->dispatchJob('sequences', 0);
		}

		return $stats;
	}

	public function kick(): bool
	{
		if ($this->countDue() === 0) {
			return false;
		}

		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_single_event(time() + 90, self::CRON_HOOK);
		}

		return $this->worker->dispatchJob('sequences', 0);
	}

	/** Liczba wiadomości gotowych do przetworzenia (sekwencje testowe albo organizacje z włączoną wysyłką). */
	public function countDue(): int
	{
		$rows = $this->db->rows(
			"SELECT s.organization_id, s.test_mode, COUNT(*) n FROM {$this->m()} m JOIN {$this->s()} s ON s.id = m.sequence_id
			WHERE m.status = 'scheduled' AND m.scheduled_at <= %s AND s.status IN ('scheduled','active') GROUP BY s.organization_id, s.test_mode",
			Db::now()
		);
		$n = 0;

		foreach ($rows as $r) {
			if ((int) $r['test_mode'] === 1 || $this->settings->sendingEnabled((int) $r['organization_id'])) {
				$n += (int) $r['n'];
			}
		}

		return $n;
	}

	/** Wiadomości „w trakcie wysyłki” po przekroczeniu czasu zajęcia (przerwany proces) → wynik nieznany. */
	public function recoverInterrupted(): int
	{
		$rows = $this->db->rows("SELECT id, sequence_id FROM {$this->m()} WHERE status = 'sending' AND locked_until < %s LIMIT 50", Db::now());

		foreach ($rows as $r) {
			$changed = $this->db->execute("UPDATE {$this->m()} SET status = 'unknown_result', locked_until = NULL, error = %s, updated_at = %s WHERE id = %d AND status = 'sending'", 'Proces wysyłki został przerwany — nie wiadomo, czy wiadomość wyszła. Sprawdź folder Wysłane.', Db::now(), (int) $r['id']);

			if ($changed) {
				$this->sequences->fail((int) $r['sequence_id'], 'unknown_result');
			}
		}

		return count($rows);
	}

	/** @return list<array{id: string, sequence_id: string}> */
	private function due(int $limit, array $skipSequences): array
	{
		$skip = $skipSequences ? ' AND m.sequence_id NOT IN (' . implode(',', array_map('intval', $skipSequences)) . ')' : '';

		return $this->db->rows(
			"SELECT m.id, m.sequence_id FROM {$this->m()} m JOIN {$this->s()} s ON s.id = m.sequence_id
			WHERE m.status = 'scheduled' AND m.scheduled_at <= %s AND s.status IN ('scheduled','active'){$skip}
			ORDER BY m.scheduled_at ASC, m.id ASC LIMIT %d",
			Db::now(),
			$limit
		);
	}

	/** @return string klucz statystyki: sent / deferred / failed / unknown / skipped */
	private function processOne(int $messageId, int $sequenceId): string
	{
		if (! Lock::acquire('ubr_seq_' . $sequenceId)) {
			return 'deferred';
		}

		try {
			return $this->send($messageId, $sequenceId);
		} catch (\Throwable $e) {
			error_log('[ubr] Sequence message #' . $messageId . ' failed: ' . $e->getMessage());

			return 'failed';
		} finally {
			Lock::release('ubr_seq_' . $sequenceId);
		}
	}

	private function send(int $messageId, int $sequenceId): string
	{
		$m = $this->db->row("SELECT * FROM {$this->m()} WHERE id = %d", $messageId);
		$s = $this->db->row("SELECT * FROM {$this->s()} WHERE id = %d", $sequenceId);

		if (! $m || ! $s || $m['status'] !== 'scheduled' || ! in_array($s['status'], ['scheduled', 'active'], true) || $m['scheduled_at'] > Db::now()) {
			return 'skipped';
		}

		$orgId = (int) $s['organization_id'];
		$test = (bool) $s['test_mode'];
		$settings = $this->sequences->seqSettings($s);
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		if (! $test && ! $this->settings->sendingEnabled($orgId)) {
			return 'skipped';
		}

		if ($settings['end_at'] && Db::now() > $settings['end_at']) {
			$this->sequences->finish($sequenceId, 'completed', 'Osiągnięto datę zakończenia sekwencji — pozostałe wiadomości nie zostały wysłane.');

			return 'skipped';
		}

		if (! SendWindow::allows($now, $settings['window'])) {
			return $this->defer($m, $s, SendWindow::next($now, $settings['window']));
		}

		$mailbox = $s['mailbox_id'] ? $this->mailboxes->find((int) $s['mailbox_id']) : null;

		if (! $mailbox || (int) $mailbox['user_id'] !== (int) $s['owner_user_id'] || $mailbox['status'] === 'disabled' || (! $test && $mailbox['status'] !== 'connected')) {
			$this->sequences->fail($sequenceId, 'mailbox');

			return 'failed';
		}

		$email = EmailAddress::normalize((string) $s['recipient_email']);
		$blockers = $this->sequences->blockers($orgId, (int) $s['company_id'], $email, $s['contact_id'] ? (int) $s['contact_id'] : null);

		if ($blockers) {
			$this->sequences->finish($sequenceId, 'cancelled', 'Wysyłka zatrzymana: ' . $blockers[0]['message']);

			return 'skipped';
		}

		if (! $test) {
			$wait = $this->limitWait($mailbox, $now);

			if ($wait !== null) {
				return $this->defer($m, $s, SendWindow::next($wait, $settings['window']));
			}
		}

		$company = $this->companies->find((int) $s['company_id']);
		$values = $this->sequences->values($s, $company);
		$values['unsubscribe_link'] = $this->unsubscribe->url($orgId, $sequenceId);
		$missing = [];
		$footer = $this->sequences->footer($orgId);
		$subject = TemplateRenderer::render((string) $m['subject'], $values, $missing, true);
		$body = TemplateRenderer::render((string) $m['body'], $values, $missing);

		if ($footer !== '' && ! str_contains((string) $m['body'], '{{unsubscribe_link}}')) {
			$body = rtrim($body) . "\n\n--\n" . TemplateRenderer::render($footer, $values, $missing);
		}

		if ($missing) {
			$this->sequences->fail($sequenceId, 'missing_variables', 'Brakujące dane personalizacji: ' . implode(', ', array_map(fn ($k) => '{{' . $k . '}}', array_unique($missing))) . ' — uzupełnij wartości i wznów sekwencję.');

			return 'failed';
		}

		$token = bin2hex(random_bytes(16));
		$domain = EmailAddress::domain((string) $mailbox['email']) ?: 'localhost';
		$messageIdHeader = '<ubr.' . $messageId . '.' . substr($token, 0, 12) . '@' . $domain . '>';
		$transportName = $test || $mailbox['provider'] === 'test' ? 'test' : (string) $mailbox['provider'];
		$claimed = $this->db->execute(
			"UPDATE {$this->m()} SET status = 'sending', send_token = %s, message_id = %s, attempts = attempts + 1, locked_until = %s, mailbox_id = %d, from_email = %s, to_email = %s, sent_subject = %s, sent_body = %s, transport = %s, updated_at = %s WHERE id = %d AND status = 'scheduled'",
			$token,
			$messageIdHeader,
			gmdate('Y-m-d H:i:s', time() + self::CLAIM_SECONDS),
			(int) $mailbox['id'],
			(string) $mailbox['email'],
			$email,
			mb_substr($subject, 0, 255),
			$body,
			$transportName,
			Db::now(),
			$messageId
		);

		if ($claimed !== 1) {
			return 'skipped';
		}

		$outgoing = new OutgoingMessage((string) $mailbox['email'], (string) ($mailbox['from_name'] ?? ''), $email, (string) ($s['recipient_name'] ?? ''), $subject, $body, $messageIdHeader, [
			'List-Unsubscribe' => '<' . $values['unsubscribe_link'] . '>, <mailto:' . $mailbox['email'] . '?subject=' . rawurlencode('Rezygnacja') . '>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		]);
		$transport = $transportName === 'test' ? new LogTransport() : $this->mailboxes->transport($mailbox);
		$secret = $transportName === 'test' ? null : $this->mailboxes->secret($mailbox);

		if ($transportName !== 'test' && $secret === null) {
			$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'send_token' => null, 'message_id' => null, 'locked_until' => null, 'attempts' => max(0, (int) $m['attempts']), 'updated_at' => Db::now()], ['id' => $messageId]);
			$this->db->update(Tables::SEQ_MAILBOXES, ['status' => 'reconnect', 'last_error' => 'Nie można odczytać hasła skrzynki — podaj je ponownie.', 'updated_at' => Db::now()], ['id' => (int) $mailbox['id']]);
			$this->sequences->fail($sequenceId, 'mailbox');

			return 'failed';
		}

		$r = $transport->send($outgoing, $mailbox, $secret);

		return $this->handle($r, $m, $s, $mailbox, $settings);
	}

	private function handle(array $r, array $m, array $s, array $mailbox, array $settings): string
	{
		$messageId = (int) $m['id'];
		$sequenceId = (int) $s['id'];
		$now = Db::now();

		switch ($r['status']) {
			case 'sent':
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'sent', 'sent_at' => $now, 'locked_until' => null, 'smtp_response' => $r['response'], 'error' => null, 'updated_at' => $now], ['id' => $messageId]);
				$this->db->update(Tables::SEQ_MAILBOXES, ['last_sent_at' => $now, 'updated_at' => $now], ['id' => (int) $mailbox['id']]);
				$this->sequences->afterSent($sequenceId, SendWindow::utc($now));

				return 'sent';

			case 'retry':
				$attempts = (int) $m['attempts'] + 1;

				if ($attempts > count(self::RETRY_DELAYS)) {
					$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'failed', 'locked_until' => null, 'smtp_response' => $r['response'], 'error' => $r['error'], 'updated_at' => $now], ['id' => $messageId]);
					$this->sequences->fail($sequenceId, 'failed', 'Wysyłka nie powiodła się po ' . $attempts . ' próbach: ' . (string) $r['error']);

					return 'failed';
				}

				$at = SendWindow::next(new DateTimeImmutable('+' . self::RETRY_DELAYS[$attempts - 1] . ' seconds', new DateTimeZone('UTC')), $settings['window']);
				// Wiadomość NA PEWNO nie została przyjęta — ponowienie z nowym identyfikatorem operacji.
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'scheduled_at' => SendWindow::mysql($at), 'send_token' => null, 'message_id' => null, 'locked_until' => null, 'smtp_response' => $r['response'], 'error' => 'Ponowienie ' . $attempts . '/' . count(self::RETRY_DELAYS) . ': ' . (string) $r['error'], 'updated_at' => $now], ['id' => $messageId]);
				$this->db->update(Tables::SEQ_SEQUENCES, ['next_send_at' => SendWindow::mysql($at), 'updated_at' => $now], ['id' => $sequenceId]);

				return 'deferred';

			case 'auth':
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'send_token' => null, 'message_id' => null, 'locked_until' => null, 'attempts' => (int) $m['attempts'], 'error' => $r['error'], 'updated_at' => $now], ['id' => $messageId]);
				$this->db->update(Tables::SEQ_MAILBOXES, ['status' => 'auth_failed', 'last_error' => $r['error'], 'updated_at' => $now], ['id' => (int) $mailbox['id']]);
				$this->sequences->fail($sequenceId, 'mailbox', 'Logowanie do skrzynki nadawczej nie powiodło się — popraw dane skrzynki i wznów sekwencję.');

				return 'failed';

			case 'unknown':
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'unknown_result', 'locked_until' => null, 'smtp_response' => $r['response'], 'error' => $r['error'], 'updated_at' => $now], ['id' => $messageId]);
				$this->sequences->fail($sequenceId, 'unknown_result');

				return 'unknown';

			default:
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'failed', 'locked_until' => null, 'smtp_response' => $r['response'], 'error' => $r['error'], 'updated_at' => $now], ['id' => $messageId]);

				if (! empty($r['bounce'])) {
					$this->sequences->suppress((int) $s['organization_id'], (string) $s['recipient_email'], 'bounced', null, $sequenceId, mb_substr((string) $r['response'], 0, 300));
				} else {
					$this->sequences->fail($sequenceId, 'failed', 'Serwer poczty odrzucił wiadomość: ' . (string) ($r['response'] ?: $r['error']));
				}

				return 'failed';
		}
	}

	/**
	 * Limity skrzynki (na dzień, na godzinę, minimalny odstęp). null = można wysłać; inaczej najbliższy moment.
	 */
	public function limitWait(array $mailbox, DateTimeImmutable $now): ?DateTimeImmutable
	{
		$limits = $this->mailboxes->limits((array) (json_decode((string) ($mailbox['limits'] ?? ''), true) ?: []), (int) $mailbox['organization_id']);
		$id = (int) $mailbox['id'];
		$base = "SELECT {expr} FROM {$this->m()} WHERE mailbox_id = %d AND status IN ('sent','sending','unknown_result') AND transport <> 'test' AND sent_at IS NOT NULL AND sent_at >= %s";
		$waits = [];
		$dayAgo = gmdate('Y-m-d H:i:s', $now->getTimestamp() - DAY_IN_SECONDS);
		$hourAgo = gmdate('Y-m-d H:i:s', $now->getTimestamp() - HOUR_IN_SECONDS);

		if ((int) $this->db->value(str_replace('{expr}', 'COUNT(*)', $base), $id, $dayAgo) >= $limits['per_day']) {
			$oldest = (string) $this->db->value(str_replace('{expr}', 'MIN(sent_at)', $base), $id, $dayAgo);
			$waits[] = SendWindow::utc($oldest)->modify('+1 day +1 minute');
		}

		if ((int) $this->db->value(str_replace('{expr}', 'COUNT(*)', $base), $id, $hourAgo) >= $limits['per_hour']) {
			$oldest = (string) $this->db->value(str_replace('{expr}', 'MIN(sent_at)', $base), $id, $hourAgo);
			$waits[] = SendWindow::utc($oldest)->modify('+1 hour +1 minute');
		}

		$last = $this->db->value("SELECT MAX(sent_at) FROM {$this->m()} WHERE mailbox_id = %d AND transport <> 'test' AND status IN ('sent','sending','unknown_result')", $id);

		if ($last && SendWindow::utc((string) $last)->modify('+' . $limits['min_interval_minutes'] . ' minutes') > $now) {
			$waits[] = SendWindow::utc((string) $last)->modify('+' . $limits['min_interval_minutes'] . ' minutes');
		}

		return $waits ? max($waits) : null;
	}

	private function defer(array $m, array $s, DateTimeImmutable $at): string
	{
		$this->db->execute("UPDATE {$this->m()} SET scheduled_at = %s, updated_at = %s WHERE id = %d AND status = 'scheduled'", SendWindow::mysql($at), Db::now(), (int) $m['id']);
		$this->db->update(Tables::SEQ_SEQUENCES, ['next_send_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => (int) $s['id']]);

		return 'deferred';
	}

	private function m(): string
	{
		return $this->db->table(Tables::SEQ_MESSAGES);
	}

	private function s(): string
	{
		return $this->db->table(Tables::SEQ_SEQUENCES);
	}
}
