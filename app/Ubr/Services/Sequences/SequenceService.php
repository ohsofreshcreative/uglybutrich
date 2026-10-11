<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Sequences\EmailAddress;
use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Domain\Sequences\SequenceCatalog;
use App\Ubr\Domain\Sequences\TemplateRenderer;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\DatabaseException;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Repositories\CompanyRepository;
use App\Ubr\Support\Lock;
use App\Ubr\Support\UbrException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Prompt 10.5 — sekwencje przypisane do firm.
 *
 * - Przypisanie kampanii tworzy SZKIC z niezależną kopią kroków (snapshot) — edycja dotyczy tylko tej firmy, szablon
 *   w bibliotece bez zmian; późniejsza edycja szablonu nie zmienia sekwencji (zachowane id / nazwa / wersja źródła).
 * - Wysyłka dopiero po ŚWIADOMYM zatwierdzeniu (odbiorca, skrzynka, treść, harmonogram, `confirm`). Status „Mailing”
 *   w pipeline niczego nie wysyła.
 * - Jedna aktywna sekwencja na adres odbiorcy w organizacji (UNIQUE `active_recipient`; obejmuje też ten sam adres
 *   w różnych firmach). Konflikt → wybór: zostaw obecną / zakończ obecną i uruchom nową / anuluj.
 * - Wysyłka WYŁĄCZNIE przez worker (SequenceRunner) — żądania użytkownika tylko planują / zmieniają stan.
 * - Edycja kroków i wysyłka danej sekwencji są wzajemnie wykluczone blokadą `ubr_seq_{id}`.
 */
class SequenceService
{
	public function __construct(
		private Db $db,
		private CompanyRepository $companies,
		private CampaignService $campaigns,
		private MailboxService $mailboxes,
		private SequenceSettings $settings,
	) {
	}

	/* ------------------------------------------------------------------ odczyt */

	/** Sekcja „Mailing” w karcie firmy. */
	public function forCompany(TenantContext $ctx, int $companyId): array
	{
		$company = $this->companyOr404($ctx, $companyId);
		$prospect = $this->prospect($ctx->organizationId, $companyId);
		$rows = $this->db->rows("SELECT * FROM {$this->t()} WHERE organization_id = %d AND company_id = %d ORDER BY id DESC LIMIT 20", $ctx->organizationId, $companyId);

		return [
			'company' => ['id' => (int) $company['id'], 'name' => $company['name']],
			'prospect' => $prospect ? ['id' => (int) $prospect['id'], 'owner_user_id' => $prospect['owner_user_id'] ? (int) $prospect['owner_user_id'] : null, 'status' => $prospect['status'], 'do_not_contact' => (bool) $prospect['do_not_contact']] : null,
			'can_manage' => $this->canManageCompany($ctx, $prospect),
			'manage_hint' => $prospect ? ($this->canManageCompany($ctx, $prospect) ? null : 'Sekwencje prowadzi pracownik, do którego przypisana jest firma.') : 'Najpierw przejmij firmę (dodaj do swoich Prospects).',
			'sequences' => array_map(fn ($r) => $this->summary($r), $rows),
			'sending_enabled' => $this->settings->sendingEnabled($ctx->organizationId),
		];
	}

	/** Opcje odbiorcy: ogólny e-mail firmy, e-maile decydentów, adres ręczny. */
	public function recipients(TenantContext $ctx, int $companyId): array
	{
		$company = $this->companyOr404($ctx, $companyId);
		$out = [];

		if (! empty($company['general_email'])) {
			$out[] = $this->recipientOption('company_general', (string) $company['general_email'], null, null, 'Ogólny adres firmy — nie jest adresem decydenta.', $ctx->organizationId, $company);
		}

		$contacts = $this->db->rows(
			"SELECT id, full_name, first_name, last_name, job_title, public_business_email, person_key, verification_status FROM {$this->db->table(Tables::CONTACTS)}
			WHERE company_id = %d AND active = 1 AND public_business_email IS NOT NULL AND public_business_email <> '' AND org_key IN (0, %d) ORDER BY rank_position IS NULL, rank_position ASC LIMIT 10",
			$companyId,
			$ctx->organizationId
		);

		foreach ($contacts as $c) {
			$out[] = $this->recipientOption('contact', (string) $c['public_business_email'], (int) $c['id'], trim((string) $c['full_name']), trim((string) $c['job_title']) ?: null, $ctx->organizationId, $company, $c);
		}

		return ['options' => $out, 'manual_allowed' => true, 'note' => 'Wybierz adres świadomie. Ogólny e-mail firmy nie jest adresem decydenta.'];
	}

	public function get(TenantContext $ctx, int $id): array
	{
		$s = $this->visible($ctx, $id);
		$messages = $this->messageRows($id);
		$company = $this->companies->find((int) $s['company_id']);
		$values = $this->values($s, $company);
		$settings = $this->seqSettings($s);
		$mailbox = $s['mailbox_id'] ? $this->mailboxes->find((int) $s['mailbox_id']) : null;
		$unsent = array_values(array_filter($messages, fn ($m) => ! in_array($m['status'], SequenceCatalog::FINAL_MESSAGES, true)));
		$texts = $unsent ? [$this->footer($ctx->organizationId)] : [];

		foreach ($unsent as $m) {
			if ((int) $m['is_active']) {
				$texts[] = (string) $m['subject'];
				$texts[] = (string) $m['body'];
			}
		}

		$missing = TemplateRenderer::missing($texts, $values);
		$blockers = $s['recipient_email'] ? $this->blockers($ctx->organizationId, (int) $s['company_id'], (string) $s['recipient_email'], $s['contact_id'] ? (int) $s['contact_id'] : null) : [];
		$conflict = $s['recipient_email'] ? $this->conflict($ctx->organizationId, (string) $s['recipient_email'], (int) $s['id']) : null;

		return $this->summary($s) + [
			'settings' => $settings,
			'window_label' => SendWindow::describe($settings['window']),
			'variables' => array_map(fn ($k, $label) => ['key' => $k, 'label' => $label, 'auto' => $this->autoValues($s, $company)[$k] ?? null, 'override' => $this->overrides($s)[$k] ?? null, 'system' => in_array($k, TemplateRenderer::SYSTEM, true)], array_keys(TemplateRenderer::VARIABLES), TemplateRenderer::VARIABLES),
			'missing' => $missing,
			'blockers' => $blockers,
			'conflict' => $conflict,
			'mailbox' => $mailbox ? $this->mailboxes->present($mailbox) : null,
			'messages' => array_map(fn ($m) => $this->presentMessage($m, $values), $messages),
			'preview' => $s['status'] === 'draft' ? CampaignService::preview(array_map(fn ($m) => ['subject' => $m['subject'], 'body' => $m['body'], 'delay_days' => (int) $m['delay_days'], 'is_active' => (bool) $m['is_active']], $messages), $settings['window'], $values + ['unsubscribe_link' => '[link rezygnacji]'], $this->startBase($settings)) : null,
			'footer' => $this->settings->get($ctx->organizationId)['append_unsubscribe'] ? $this->footer($ctx->organizationId) : null,
			'can_manage' => $this->canManage($ctx, $s),
			'test_mailboxes_allowed' => MailboxService::testMailboxesAllowed(),
		];
	}

	/* ------------------------------------------------------------------ szkic */

	/**
	 * Przypisanie kampanii do firmy → szkic z niezależną kopią kroków.
	 *
	 * @param array<string, mixed> $input campaign_id (null = pusta sekwencja), recipient {source, email, contact_id}, mailbox_id
	 */
	public function create(TenantContext $ctx, int $companyId, array $input): array
	{
		$company = $this->companyOr404($ctx, $companyId);
		$prospect = $this->prospect($ctx->organizationId, $companyId);

		if (! $prospect) {
			throw UbrException::invalid('Najpierw przejmij firmę (dodaj do swoich Prospects).');
		}

		if (! $this->canManageCompany($ctx, $prospect)) {
			throw UbrException::forbidden('Sekwencje prowadzi pracownik, do którego przypisana jest firma.');
		}

		$campaign = ! empty($input['campaign_id']) ? $this->campaigns->visible($ctx, (int) $input['campaign_id']) : null;

		if ($campaign && $campaign['status'] !== 'active') {
			throw UbrException::invalid('Przypisać można tylko aktywną kampanię.');
		}

		$steps = $campaign ? $this->campaigns->stepRows((int) $campaign['id']) : [];
		$window = $campaign ? ((json_decode((string) $campaign['settings'], true) ?: [])['window'] ?? null) : null;
		$mailboxId = $this->mailboxIdFor($ctx, $input['mailbox_id'] ?? null);
		$now = Db::now();
		$id = $this->db->insert(Tables::SEQ_SEQUENCES, [
			'organization_id' => $ctx->organizationId,
			'company_id' => $companyId,
			'prospect_id' => (int) $prospect['id'],
			'owner_user_id' => $ctx->userId,
			'campaign_id' => $campaign ? (int) $campaign['id'] : null,
			'campaign_version' => $campaign ? (int) $campaign['version'] : null,
			'campaign_name' => $campaign ? $campaign['name'] : null,
			'name' => mb_substr($campaign ? $campaign['name'] : 'Indywidualna sekwencja', 0, 160),
			'mailbox_id' => $mailboxId,
			'status' => 'draft',
			'settings' => wp_json_encode(['window' => SendWindow::normalize((array) ($window ?? $this->settings->get($ctx->organizationId)['window'])), 'start_at' => null, 'end_at' => null]),
			'variables' => wp_json_encode([]),
			'step_count' => count(array_filter($steps, fn ($s) => (int) $s['is_active'])),
			'created_at' => $now,
			'updated_at' => $now,
		]);

		foreach (array_values($steps) as $i => $st) {
			$this->insertMessage($id, $ctx->organizationId, $i + 1, ['subject' => $st['subject'], 'body' => $st['body'], 'delay_days' => (int) $st['delay_days'], 'is_active' => (bool) $st['is_active']]);
		}

		if (! empty($input['recipient'])) {
			$this->setRecipient($ctx, $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id), $company, (array) $input['recipient']);
		}

		return $this->get($ctx, $id);
	}

	/**
	 * Zmiany sekwencji: nazwa, skrzynka, odbiorca (szkic), okno wysyłki, start / koniec, wartości ręczne zmiennych,
	 * NIEWYSŁANE kroki (pełna lista w kolejności), termin zaplanowanej wiadomości.
	 *
	 * @param array<string, mixed> $input
	 */
	public function update(TenantContext $ctx, int $id, array $input): array
	{
		$s = $this->manageable($ctx, $id);

		if (! in_array($s['status'], SequenceCatalog::EDITABLE, true)) {
			throw UbrException::invalid('Zakończonej lub anulowanej sekwencji nie można edytować.');
		}

		$this->withLock($id, function () use ($ctx, $id, $input) {
			$s = $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id);
			$data = [];

			if (array_key_exists('name', $input)) {
				$data['name'] = mb_substr(TemplateRenderer::oneLine(sanitize_text_field((string) $input['name'])), 0, 160) ?: $s['name'];
			}

			if (array_key_exists('mailbox_id', $input)) {
				$data['mailbox_id'] = $this->mailboxIdFor($ctx, $input['mailbox_id']);

				if ($s['status'] !== 'draft' && $s['error_code'] === 'mailbox') {
					$data['error_code'] = null;
					$data['error_message'] = null;
				}
			}

			if (array_key_exists('recipient', $input)) {
				if ($s['status'] !== 'draft') {
					throw UbrException::invalid('Odbiorcę można zmienić tylko w szkicu (zakończ sekwencję i utwórz nową dla innego adresu).');
				}

				$this->setRecipient($ctx, $s, $this->companies->find((int) $s['company_id']), (array) $input['recipient']);
			}

			$settings = $this->seqSettings($s);

			if (isset($input['settings']) && is_array($input['settings'])) {
				if (isset($input['settings']['window'])) {
					$settings['window'] = SendWindow::normalize((array) $input['settings']['window']);
				}

				foreach (['start_at', 'end_at'] as $k) {
					if (array_key_exists($k, $input['settings'])) {
						$settings[$k] = $this->dateInput($input['settings'][$k], $settings['window']['timezone']);
					}
				}

				if ($settings['end_at'] && $settings['start_at'] && $settings['end_at'] <= $settings['start_at']) {
					throw UbrException::invalid('Data zakończenia musi być późniejsza niż data rozpoczęcia.');
				}

				$data['settings'] = wp_json_encode($settings);
			}

			if (isset($input['variables']) && is_array($input['variables'])) {
				$vars = $this->overrides($s);

				foreach ($input['variables'] as $k => $v) {
					$k = sanitize_key((string) $k);

					if (isset(TemplateRenderer::VARIABLES[$k]) && ! in_array($k, TemplateRenderer::SYSTEM, true)) {
						$clean = TemplateRenderer::clean((string) $v);
						$clean === '' ? (function () use (&$vars, $k) { unset($vars[$k]); })() : ($vars[$k] = $clean);
					}
				}

				$data['variables'] = wp_json_encode($vars);
			}

			if ($data) {
				$this->db->update(Tables::SEQ_SEQUENCES, $data + ['updated_at' => Db::now()], ['id' => $id]);
			}

			if (isset($input['steps']) && is_array($input['steps'])) {
				$this->replaceUnsent($id, (int) $s['organization_id'], $input['steps']);
			}

			if (! empty($input['scheduled_at']) && $s['status'] !== 'draft') {
				$this->reschedule($id, (string) $input['scheduled_at'], $settings);
			} elseif ((isset($input['steps']) || isset($input['settings'])) && $s['status'] !== 'draft') {
				$this->recomputeScheduled($id);
			}

			$this->refreshCounts($id);
		});

		return $this->get($ctx, $id);
	}

	/* ------------------------------------------------------------------ uruchomienie i sterowanie */

	/**
	 * Zatwierdzenie i uruchomienie harmonogramu. Wymaga `confirm` (świadoma decyzja pracownika).
	 *
	 * @param array<string, mixed> $input confirm, replace (zakończ obecną sekwencję tego odbiorcy), test_mode
	 */
	public function start(TenantContext $ctx, int $id, array $input): array
	{
		$s = $this->manageable($ctx, $id);

		if ($s['status'] !== 'draft') {
			throw UbrException::invalid('Sekwencja jest już uruchomiona.');
		}

		if (empty($input['confirm'])) {
			throw UbrException::invalid('Potwierdź odbiorcę, treść i harmonogram przed uruchomieniem.');
		}

		$testMode = ! empty($input['test_mode']);
		$problems = $this->startProblems($ctx, $s, $testMode);

		if ($problems) {
			throw new UbrException(implode(' ', $problems), 'ubr_sequence_invalid', 422);
		}

		$email = EmailAddress::normalize((string) $s['recipient_email']);
		$conflict = $this->conflict($ctx->organizationId, $email, $id);

		if ($conflict) {
			if (empty($input['replace'])) {
				return ['started' => false, 'conflict' => $conflict, 'message' => 'Ten odbiorca ma już aktywną sekwencję: „' . $conflict['name'] . '” (' . $conflict['company_name'] . ').', 'options' => ['keep' => 'Pozostaw obecną sekwencję', 'replace' => 'Zakończ obecną i uruchom nową', 'cancel' => 'Anuluj']];
			}

			$existing = $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $conflict['id']);

			if (! $this->canManage($ctx, $existing)) {
				throw UbrException::forbidden('Obecną sekwencję prowadzi inny pracownik — poproś go albo administratora o jej zakończenie.');
			}

			$this->finish((int) $existing['id'], 'cancelled', 'Zastąpiona nową sekwencją #' . $id . '.');
		}

		$settings = $this->seqSettings($s);
		$first = $this->db->row("SELECT * FROM {$this->m()} WHERE sequence_id = %d AND is_active = 1 AND status = 'pending' ORDER BY position ASC LIMIT 1", $id);
		$at = SendWindow::due($this->startBase($settings), (int) $first['delay_days'], $settings['window']);

		try {
			$this->db->update(Tables::SEQ_SEQUENCES, [
				'status' => 'scheduled',
				'test_mode' => $testMode ? 1 : 0,
				'active_recipient' => $ctx->organizationId . '|' . $email,
				'approved_by' => $ctx->userId,
				'approved_at' => Db::now(),
				'started_at' => Db::now(),
				'next_send_at' => SendWindow::mysql($at),
				'error_code' => null,
				'error_message' => null,
				'updated_at' => Db::now(),
			], ['id' => $id]);
		} catch (DatabaseException $e) {
			if ($e->duplicate) {
				// Równoległe uruchomienie dla tego samego adresu — wygrała druga operacja.
				throw new UbrException('Ten odbiorca ma już aktywną sekwencję (uruchomioną przed chwilą).', 'ubr_sequence_conflict', 409);
			}

			throw $e;
		}

		$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'scheduled_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => (int) $first['id']]);
		$this->refreshCounts($id);
		app(SequenceRunner::class)->kick();

		return ['started' => true, 'message' => $testMode ? 'Sekwencja uruchomiona w TRYBIE TESTOWYM — wiadomości nie wyjdą do odbiorcy.' : ($this->settings->sendingEnabled($ctx->organizationId) ? 'Sekwencja zaplanowana. Pierwsza wiadomość: ' . $this->localLabel($at, $settings['window']['timezone']) . '.' : 'Sekwencja zaplanowana, ale automatyczna wysyłka jest wyłączona w ustawieniach — wiadomości poczekają na włączenie.'), 'sequence' => $this->get($ctx, $id)];
	}

	public function pause(TenantContext $ctx, int $id): array
	{
		$s = $this->manageable($ctx, $id);

		if (! in_array($s['status'], ['scheduled', 'active', 'error'], true)) {
			throw UbrException::invalid('Wstrzymać można zaplanowaną lub aktywną sekwencję.');
		}

		$this->db->update(Tables::SEQ_SEQUENCES, ['status' => 'paused', 'paused_at' => Db::now(), 'updated_at' => Db::now()], ['id' => $id]);
		$sending = (int) $this->db->value("SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status = 'sending'", $id);

		return ['paused' => true, 'message' => $sending ? 'Sekwencja wstrzymana. Jedna wiadomość była już w trakcie wysyłki — tej nie da się zatrzymać.' : 'Sekwencja wstrzymana — kolejne wiadomości nie zostaną wysłane.', 'sequence' => $this->get($ctx, $id)];
	}

	/**
	 * Wznowienie: zaplanowana wiadomość dostaje termin ≥ teraz (w oknie wysyłki) — NIGDY kilka zaległych naraz,
	 * kolejne kroki liczone od faktycznej wysyłki poprzedniego.
	 */
	public function resume(TenantContext $ctx, int $id): array
	{
		$s = $this->manageable($ctx, $id);

		if (! in_array($s['status'], ['paused', 'error'], true)) {
			throw UbrException::invalid('Wznowić można wstrzymaną sekwencję albo sekwencję z błędem.');
		}

		if ((int) $this->db->value("SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status = 'unknown_result'", $id)) {
			throw UbrException::invalid('Najpierw potwierdź wynik wiadomości o nieznanym statusie (wysłana / niewysłana).');
		}

		$problems = $this->startProblems($ctx, $s, (bool) $s['test_mode'], true);

		if ($problems) {
			throw new UbrException(implode(' ', $problems), 'ubr_sequence_invalid', 422);
		}

		$this->withLock($id, function () use ($id) {
			$this->recomputeScheduled($id, true);
			$sent = (int) $this->db->value("SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status = 'sent'", $id);
			$this->db->update(Tables::SEQ_SEQUENCES, ['status' => $sent ? 'active' : 'scheduled', 'paused_at' => null, 'error_code' => null, 'error_message' => null, 'updated_at' => Db::now()], ['id' => $id]);
			$this->refreshCounts($id);
		});
		app(SequenceRunner::class)->kick();

		return ['resumed' => true, 'message' => 'Sekwencja wznowiona.', 'sequence' => $this->get($ctx, $id)];
	}

	public function cancel(TenantContext $ctx, int $id): array
	{
		$s = $this->manageable($ctx, $id);

		if (in_array($s['status'], ['completed', 'cancelled'], true)) {
			throw UbrException::invalid('Sekwencja jest już zakończona.');
		}

		$sending = (int) $this->db->value("SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status = 'sending'", $id);
		$this->finish($id, 'cancelled', 'Anulowana przez pracownika.');

		return ['cancelled' => true, 'message' => $sending ? 'Sekwencja anulowana. Wiadomość w trakcie wysyłki mogła już zostać przekazana do serwera poczty — tego nie da się cofnąć.' : 'Sekwencja anulowana — przyszłe wiadomości nie zostaną wysłane. Historia wysłanych zachowana.', 'sequence' => $this->get($ctx, $id)];
	}

	/** Usunięcie SZKICU (nic nie zostało wysłane). */
	public function deleteDraft(TenantContext $ctx, int $id): array
	{
		$s = $this->manageable($ctx, $id);

		if ($s['status'] !== 'draft') {
			throw UbrException::invalid('Usunąć można tylko szkic — uruchomioną sekwencję anuluj.');
		}

		$this->db->delete(Tables::SEQ_MESSAGES, ['sequence_id' => $id]);
		$this->db->delete(Tables::SEQ_SEQUENCES, ['id' => $id]);

		return ['deleted' => true];
	}

	/**
	 * Wiadomość o nieznanym wyniku: pracownik sprawdza folder Wysłane i potwierdza.
	 * `sent` → historia + kolejny krok; `not_sent` → ponowna wysyłka (nowy identyfikator operacji).
	 */
	public function resolveUnknown(TenantContext $ctx, int $messageId, string $outcome): array
	{
		$m = $this->db->row("SELECT * FROM {$this->m()} WHERE id = %d AND organization_id = %d", $messageId, $ctx->organizationId);

		if (! $m || $m['status'] !== 'unknown_result') {
			throw UbrException::notFound('Nie znaleziono wiadomości o nieznanym wyniku.');
		}

		$s = $this->manageable($ctx, (int) $m['sequence_id']);

		$this->withLock((int) $s['id'], function () use ($m, $s, $outcome) {
			if ($outcome === 'sent') {
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'sent', 'sent_at' => $m['sent_at'] ?: ($m['locked_until'] ?: Db::now()), 'error' => 'Wynik potwierdzony ręcznie przez pracownika (wiadomość widoczna w folderze Wysłane).', 'locked_until' => null, 'updated_at' => Db::now()], ['id' => (int) $m['id']]);
				$this->afterSent((int) $s['id'], SendWindow::utc((string) ($m['sent_at'] ?: Db::now())));
			} elseif ($outcome === 'not_sent') {
				$settings = $this->seqSettings($s);
				$at = SendWindow::next(new DateTimeImmutable('now', new DateTimeZone('UTC')), $settings['window']);
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'scheduled_at' => SendWindow::mysql($at), 'send_token' => null, 'message_id' => null, 'locked_until' => null, 'error' => 'Pracownik potwierdził, że wiadomość nie wyszła — ponowna wysyłka.', 'updated_at' => Db::now()], ['id' => (int) $m['id']]);
				$this->db->update(Tables::SEQ_SEQUENCES, ['next_send_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => (int) $s['id']]);
			} else {
				throw UbrException::invalid('Wybierz: wysłana albo niewysłana.');
			}

			if ($s['status'] === 'error' && $s['error_code'] === 'unknown_result') {
				$this->db->update(Tables::SEQ_SEQUENCES, ['status' => 'paused', 'paused_at' => Db::now(), 'error_code' => null, 'error_message' => 'Wynik potwierdzony — wznów sekwencję, aby kontynuować.', 'updated_at' => Db::now()], ['id' => (int) $s['id']]);
			}
		});

		return $this->get($ctx, (int) $s['id']);
	}

	/* ------------------------------------------------------------------ blokady adresów */

	/** Wykluczenie adresu (rezygnacja / trwały błąd / ręcznie) — anuluje aktywne sekwencje tego adresu w organizacji. */
	public function suppress(int $organizationId, string $email, string $reason, ?int $userId = null, ?int $sequenceId = null, ?string $note = null): int
	{
		$email = EmailAddress::normalize($email);

		if (! isset(SequenceCatalog::SUPPRESSION_REASONS[$reason]) || $email === '') {
			return 0;
		}

		$this->db->execute(
			"INSERT INTO {$this->db->table(Tables::SEQ_SUPPRESSIONS)} (organization_id, email, reason, note, sequence_id, created_by, created_at) VALUES (%d, %s, %s, %s, %d, %d, %s)
			ON DUPLICATE KEY UPDATE reason = VALUES(reason), note = VALUES(note)",
			$organizationId,
			$email,
			$reason,
			mb_substr((string) $note, 0, 500),
			(int) $sequenceId,
			(int) $userId,
			Db::now()
		);
		$live = $this->db->column("SELECT id FROM {$this->t()} WHERE organization_id = %d AND recipient_email = %s AND status IN ('draft','scheduled','active','paused','error')", $organizationId, $email);

		foreach ($live as $sid) {
			$this->finish((int) $sid, 'cancelled', SequenceCatalog::SUPPRESSION_REASONS[$reason] . ' — adres zablokowany dla kolejnych wiadomości.');
		}

		return count($live);
	}

	public function suppressManual(TenantContext $ctx, string $email, string $reason, ?string $note): array
	{
		if (! EmailAddress::syntaxOk($email)) {
			throw UbrException::invalid('Niepoprawny adres e-mail.');
		}

		$cancelled = $this->suppress($ctx->organizationId, $email, in_array($reason, ['unsubscribed', 'manual'], true) ? $reason : 'manual', $ctx->userId, null, $note);

		return ['suppressed' => true, 'cancelled_sequences' => $cancelled];
	}

	public function unsuppress(TenantContext $ctx, string $email): array
	{
		if (! $ctx->can('sequences.settings')) {
			throw UbrException::forbidden('Odblokować adres może właściciel lub administrator.');
		}

		return ['removed' => $this->db->delete(Tables::SEQ_SUPPRESSIONS, ['organization_id' => $ctx->organizationId, 'email' => EmailAddress::normalize($email)])];
	}

	public function suppressions(TenantContext $ctx, array $query): array
	{
		$search = '%' . $this->db->wpdb()->esc_like(EmailAddress::normalize((string) ($query['search'] ?? ''))) . '%';
		$rows = $this->db->rows("SELECT * FROM {$this->db->table(Tables::SEQ_SUPPRESSIONS)} WHERE organization_id = %d AND email LIKE %s ORDER BY id DESC LIMIT 200", $ctx->organizationId, $search);

		return ['items' => array_map(fn ($r) => ['email' => $r['email'], 'reason' => $r['reason'], 'reason_label' => SequenceCatalog::label(SequenceCatalog::SUPPRESSION_REASONS, $r['reason']), 'note' => $r['note'], 'created_at' => gmdate('c', strtotime($r['created_at'] . ' UTC'))], $rows), 'can_remove' => $ctx->can('sequences.settings')];
	}

	/**
	 * Powody, dla których nie wolno pisać na adres (respektowane przy uruchomieniu i przed każdą wysyłką).
	 *
	 * @return list<array{code: string, message: string}>
	 */
	public function blockers(int $organizationId, int $companyId, string $email, ?int $contactId = null): array
	{
		$out = [];
		$email = EmailAddress::normalize($email);
		$sup = $this->db->row("SELECT reason FROM {$this->db->table(Tables::SEQ_SUPPRESSIONS)} WHERE organization_id = %d AND email = %s", $organizationId, $email);

		if ($sup) {
			$out[] = ['code' => 'suppressed', 'message' => 'Adres zablokowany: ' . SequenceCatalog::label(SequenceCatalog::SUPPRESSION_REASONS, $sup['reason']) . '.'];
		}

		$p = $this->prospect($organizationId, $companyId);

		if ($p && ((int) $p['do_not_contact'] === 1 || $p['status'] === 'not_a_fit' || $p['qualification'] === 'not_a_fit')) {
			$out[] = ['code' => 'do_not_contact', 'message' => 'Firma ma zakaz kontaktu (DO_NOT_CONTACT) lub oznaczenie „nie pasuje”.'];
		}

		$company = $this->companies->find($companyId);

		if ($company && in_array((string) ($company['verification_status'] ?? ''), ['inactive', 'suspended'], true)) {
			$out[] = ['code' => 'blocked', 'message' => 'Firma nieaktywna lub zawieszona w rejestrze (BLOCKED).'];
		}

		if ($contactId) {
			$c = $this->db->row("SELECT person_key, active FROM {$this->db->table(Tables::CONTACTS)} WHERE id = %d", $contactId);

			if ($c && ((int) $c['active'] !== 1 || $this->db->value("SELECT id FROM {$this->db->table(Tables::CONTACT_SUPPRESSIONS)} WHERE company_id = %d AND person_key = %s", $companyId, (string) $c['person_key']))) {
				$out[] = ['code' => 'contact_excluded', 'message' => 'Kontakt wyłączony ręcznie przez pracownika.'];
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ listy Sequences */

	/** Active: sekwencje (bez szkiców i zakończonych domyślnie). Paginacja po stronie serwera; bez efektów ubocznych. */
	public function listSequences(TenantContext $ctx, array $q): array
	{
		[$where, $args] = $this->scopeWhere($ctx, $q, 's');
		$status = sanitize_key((string) ($q['status'] ?? ''));

		if (isset(SequenceCatalog::SEQUENCE_STATUSES[$status])) {
			$where[] = 's.status = %s';
			$args[] = $status;
		} elseif ($status !== 'all') {
			$where[] = "s.status IN ('scheduled','active','paused','error')";
		}

		if (! empty($q['campaign_id'])) {
			$where[] = 's.campaign_id = %d';
			$args[] = (int) $q['campaign_id'];
		}

		if (! empty($q['mailbox_id'])) {
			$where[] = 's.mailbox_id = %d';
			$args[] = (int) $q['mailbox_id'];
		}

		if (! empty($q['next_before'])) {
			$where[] = 's.next_send_at IS NOT NULL AND s.next_send_at <= %s';
			$args[] = gmdate('Y-m-d 23:59:59', strtotime((string) $q['next_before']) ?: time());
		}

		[$page, $per] = $this->paging($q);
		$sql = "FROM {$this->t()} s JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = s.company_id WHERE " . implode(' AND ', $where);
		$total = (int) $this->db->value("SELECT COUNT(*) {$sql}", ...$args);
		$rows = $this->db->rows("SELECT s.*, c.name AS company_name {$sql} ORDER BY s.next_send_at IS NULL, s.next_send_at ASC, s.id DESC LIMIT %d OFFSET %d", ...array_merge($args, [$per, ($page - 1) * $per]));

		return ['items' => array_map(fn ($r) => $this->summary($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $per, 'filters' => $this->filterOptions($ctx)];
	}

	/** Scheduled: zaplanowane wiadomości (kolejna wiadomość aktywnych sekwencji). */
	public function scheduled(TenantContext $ctx, array $q): array
	{
		[$where, $args] = $this->scopeWhere($ctx, $q, 's');
		$where[] = "m.status IN ('scheduled','sending')";
		$where[] = "s.status IN ('scheduled','active','paused','error')";
		[$page, $per] = $this->paging($q);
		$sql = "FROM {$this->m()} m JOIN {$this->t()} s ON s.id = m.sequence_id JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = s.company_id WHERE " . implode(' AND ', $where);
		$total = (int) $this->db->value("SELECT COUNT(*) {$sql}", ...$args);
		$rows = $this->db->rows("SELECT m.*, s.name AS sequence_name, s.status AS sequence_status, s.recipient_email, s.mailbox_id AS seq_mailbox_id, s.owner_user_id, s.company_id, s.step_count, s.test_mode, c.name AS company_name {$sql} ORDER BY m.scheduled_at ASC LIMIT %d OFFSET %d", ...array_merge($args, [$per, ($page - 1) * $per]));

		return ['items' => array_map(fn ($r) => $this->listMessage($r), $rows), 'total' => $total, 'page' => $page, 'per_page' => $per, 'filters' => $this->filterOptions($ctx)];
	}

	/** Sent: historia (przekazane / błędy / nieznany wynik). */
	public function sent(TenantContext $ctx, array $q): array
	{
		[$where, $args] = $this->scopeWhere($ctx, $q, 's');
		$status = sanitize_key((string) ($q['status'] ?? ''));
		$where[] = in_array($status, ['sent', 'failed', 'unknown_result'], true) ? 'm.status = %s' : "m.status IN ('sent','failed','unknown_result')";

		if (in_array($status, ['sent', 'failed', 'unknown_result'], true)) {
			$args[] = $status;
		}

		[$page, $per] = $this->paging($q);
		$sql = "FROM {$this->m()} m JOIN {$this->t()} s ON s.id = m.sequence_id JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = s.company_id WHERE " . implode(' AND ', $where);
		$total = (int) $this->db->value("SELECT COUNT(*) {$sql}", ...$args);
		$rows = $this->db->rows("SELECT m.*, s.name AS sequence_name, s.status AS sequence_status, s.recipient_email, s.mailbox_id AS seq_mailbox_id, s.owner_user_id, s.company_id, s.step_count, s.test_mode, c.name AS company_name {$sql} ORDER BY COALESCE(m.sent_at, m.updated_at) DESC LIMIT %d OFFSET %d", ...array_merge($args, [$per, ($page - 1) * $per]));

		return ['items' => array_map(fn ($r) => $this->listMessage($r, true), $rows), 'total' => $total, 'page' => $page, 'per_page' => $per, 'filters' => $this->filterOptions($ctx)];
	}

	/** Podgląd treści wiadomości (zaplanowanej — render z aktualnymi danymi; wysłanej — dokładnie wysłana treść). */
	public function message(TenantContext $ctx, int $messageId): array
	{
		$m = $this->db->row("SELECT * FROM {$this->m()} WHERE id = %d AND organization_id = %d", $messageId, $ctx->organizationId);

		if (! $m) {
			throw UbrException::notFound('Nie znaleziono wiadomości.');
		}

		$s = $this->visible($ctx, (int) $m['sequence_id']);
		$values = $this->values($s, $this->companies->find((int) $s['company_id']));

		return $this->presentMessage($m, $values) + ['sequence' => $this->summary($s), 'can_manage' => $this->canManage($ctx, $s)];
	}

	/** Edycja pojedynczej niewysłanej wiadomości (Scheduled). Wiadomość w trakcie wysyłki — zablokowana. */
	public function updateMessage(TenantContext $ctx, int $messageId, array $input): array
	{
		$m = $this->db->row("SELECT * FROM {$this->m()} WHERE id = %d AND organization_id = %d", $messageId, $ctx->organizationId);

		if (! $m) {
			throw UbrException::notFound('Nie znaleziono wiadomości.');
		}

		$s = $this->manageable($ctx, (int) $m['sequence_id']);

		$this->withLock((int) $s['id'], function () use ($m, $input, $s) {
			$clean = CampaignService::cleanSteps([['subject' => $input['subject'] ?? $m['subject'], 'body' => $input['body'] ?? $m['body'], 'delay_days' => $input['delay_days'] ?? $m['delay_days'], 'is_active' => $input['is_active'] ?? (bool) $m['is_active']]])[0];
			$updated = $this->db->execute(
				"UPDATE {$this->m()} SET subject = %s, body = %s, delay_days = %d, is_active = %d, updated_at = %s WHERE id = %d AND status IN ('pending','scheduled')",
				$clean['subject'],
				$clean['body'],
				(int) ($input['delay_days'] ?? $m['delay_days']),
				$clean['is_active'] ? 1 : 0,
				Db::now(),
				(int) $m['id']
			);

			if ($updated === 0 && ! in_array($m['status'], ['pending', 'scheduled'], true)) {
				throw UbrException::invalid('Tej wiadomości nie można już edytować (wysłana lub w trakcie wysyłki).');
			}

			if ($s['status'] !== 'draft') {
				if (! empty($input['scheduled_at']) && $m['status'] === 'scheduled') {
					$this->reschedule((int) $s['id'], (string) $input['scheduled_at'], $this->seqSettings($s));
				} else {
					$this->recomputeScheduled((int) $s['id']);
				}
			}

			$this->refreshCounts((int) $s['id']);
		});

		return $this->message($ctx, $messageId);
	}

	/* ------------------------------------------------------------------ wewnętrzne (także dla workera) */

	/**
	 * Po wysłaniu kroku: kolejny aktywny krok dostaje termin (od FAKTYCZNEJ wysyłki + odstęp, w oknie); brak → koniec.
	 */
	public function afterSent(int $sequenceId, DateTimeImmutable $sentAt): void
	{
		$s = $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $sequenceId);
		$settings = $this->seqSettings($s);
		$next = $this->db->row("SELECT * FROM {$this->m()} WHERE sequence_id = %d AND is_active = 1 AND status = 'pending' ORDER BY position ASC LIMIT 1", $sequenceId);
		$sent = (int) $this->db->value("SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status = 'sent'", $sequenceId);
		$data = ['sent_count' => $sent, 'last_sent_at' => SendWindow::mysql($sentAt), 'updated_at' => Db::now()];

		if (in_array($s['status'], ['scheduled'], true)) {
			$data['status'] = 'active';
		}

		if (! $next) {
			$this->db->update(Tables::SEQ_SEQUENCES, $data, ['id' => $sequenceId]);
			$this->finish($sequenceId, 'completed', null);

			return;
		}

		$at = SendWindow::due($sentAt, (int) $next['delay_days'], $settings['window']);

		if ($settings['end_at'] && SendWindow::mysql($at) > $settings['end_at']) {
			$this->db->update(Tables::SEQ_SEQUENCES, $data, ['id' => $sequenceId]);
			$this->finish($sequenceId, 'completed', 'Osiągnięto datę zakończenia sekwencji — pozostałe wiadomości nie zostały wysłane.');

			return;
		}

		$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'scheduled_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => (int) $next['id']]);
		$this->db->update(Tables::SEQ_SEQUENCES, $data + ['next_send_at' => SendWindow::mysql($at)], ['id' => $sequenceId]);
	}

	/** Zakończenie / anulowanie: przyszłe wiadomości anulowane, adres odbiorcy zwolniony; historia bez zmian. */
	public function finish(int $sequenceId, string $status, ?string $message): void
	{
		$this->db->execute("UPDATE {$this->m()} SET status = 'cancelled', updated_at = %s WHERE sequence_id = %d AND status IN ('pending','scheduled')", Db::now(), $sequenceId);
		$this->db->update(Tables::SEQ_SEQUENCES, ['status' => $status, 'active_recipient' => null, 'next_send_at' => null, 'finished_at' => Db::now(), 'error_message' => $message, 'updated_at' => Db::now()], ['id' => $sequenceId]);
	}

	/** Błąd sekwencji (wymaga uwagi pracownika) — przyszłe wiadomości wstrzymane, adres nadal zarezerwowany. */
	public function fail(int $sequenceId, string $code, ?string $message = null): void
	{
		$this->db->update(Tables::SEQ_SEQUENCES, ['status' => 'error', 'error_code' => $code, 'error_message' => mb_substr($message ?? SequenceCatalog::label(SequenceCatalog::ERROR_LABELS, $code), 0, 500), 'updated_at' => Db::now()], ['id' => $sequenceId]);
	}

	/**
	 * Wartości zmiennych: dane z Ugly but Rich + wartości ręczne (pierwszeństwo). Nic nie jest zgadywane.
	 *
	 * @return array<string, string>
	 */
	public function values(array $s, ?array $company): array
	{
		return array_filter(array_replace($this->autoValues($s, $company), $this->overrides($s)), fn ($v) => $v !== null && $v !== '');
	}

	public function footer(int $organizationId): string
	{
		$s = $this->settings->get($organizationId);

		return $s['append_unsubscribe'] ? (string) $s['unsubscribe_text'] : '';
	}

	/** @return array{window: array, start_at: ?string, end_at: ?string} */
	public function seqSettings(array $s): array
	{
		$raw = json_decode((string) ($s['settings'] ?? ''), true) ?: [];

		return ['window' => SendWindow::normalize((array) ($raw['window'] ?? [])), 'start_at' => $raw['start_at'] ?? null, 'end_at' => $raw['end_at'] ?? null];
	}

	public function withLock(int $sequenceId, callable $fn): mixed
	{
		if (! Lock::acquireWait('ubr_seq_' . $sequenceId, 5)) {
			throw UbrException::invalid('Trwa wysyłka wiadomości tej sekwencji — spróbuj ponownie za chwilę.');
		}

		try {
			return $fn();
		} finally {
			Lock::release('ubr_seq_' . $sequenceId);
		}
	}

	public function canManage(TenantContext $ctx, ?array $s): bool
	{
		return $s && $ctx->can('sequences.manage') && ((int) $s['owner_user_id'] === $ctx->userId || $ctx->can('sequences.team'));
	}

	/* ------------------------------------------------------------------ pomocnicze */

	private function startProblems(TenantContext $ctx, array $s, bool $testMode, bool $resume = false): array
	{
		$problems = [];
		$mailbox = $s['mailbox_id'] ? $this->mailboxes->find((int) $s['mailbox_id']) : null;

		if (! $mailbox || $mailbox['status'] === 'disabled' || (int) $mailbox['user_id'] !== (int) $s['owner_user_id'] || (int) $mailbox['organization_id'] !== $ctx->organizationId) {
			$problems[] = 'Wybierz własną skrzynkę nadawczą.';
		} elseif (! $testMode && $mailbox['status'] !== 'connected') {
			$problems[] = 'Skrzynka nadawcza nie jest przetestowana / połączona — wykonaj test połączenia.';
		} elseif (! $testMode && $mailbox['provider'] === 'test') {
			$problems[] = 'Skrzynka testowa nie wysyła wiadomości — uruchom sekwencję w trybie testowym albo wybierz prawdziwą skrzynkę.';
		}

		if ($mailbox && (int) $mailbox['user_id'] !== $ctx->userId && ! $resume) {
			$problems[] = 'Możesz wysyłać tylko z własnej skrzynki.';
		}

		$email = (string) $s['recipient_email'];
		$check = EmailAddress::check($email);

		if (! $check['ok']) {
			$problems[] = 'Odbiorca: ' . $check['message'];
		} else {
			foreach ($this->blockers($ctx->organizationId, (int) $s['company_id'], $email, $s['contact_id'] ? (int) $s['contact_id'] : null) as $b) {
				$problems[] = $b['message'];
			}
		}

		$active = $this->db->rows("SELECT subject, body FROM {$this->m()} WHERE sequence_id = %d AND is_active = 1 AND status IN ('pending','scheduled')", (int) $s['id']);

		if (! $active) {
			$problems[] = 'Sekwencja nie ma aktywnych wiadomości do wysłania.';
		}

		$texts = [$this->footer($ctx->organizationId)];

		foreach ($active as $m) {
			$texts[] = (string) $m['subject'];
			$texts[] = (string) $m['body'];
		}

		$missing = TemplateRenderer::missing($texts, $this->values($s, $this->companies->find((int) $s['company_id'])));

		if ($missing) {
			$problems[] = 'Brakujące dane personalizacji: ' . implode(', ', array_map(fn ($k) => '{{' . $k . '}}', $missing)) . ' — uzupełnij wartości ręcznie.';
		}

		$settings = $this->seqSettings($s);

		if ($settings['end_at'] && $settings['end_at'] <= Db::now()) {
			$problems[] = 'Data zakończenia sekwencji już minęła.';
		}

		return $problems;
	}

	/** Zastąpienie NIEWYSŁANYCH kroków pełną listą (dodawanie, usuwanie, kolejność). Wysłane kroki bez zmian. */
	private function replaceUnsent(int $sequenceId, int $organizationId, array $steps): void
	{
		$clean = CampaignService::cleanSteps($steps);
		$lastSent = (int) $this->db->value("SELECT COALESCE(MAX(position), 0) FROM {$this->m()} WHERE sequence_id = %d AND status IN ('sending','sent','failed','unknown_result','cancelled')", $sequenceId);
		$wasScheduled = $this->db->row("SELECT id, scheduled_at FROM {$this->m()} WHERE sequence_id = %d AND status = 'scheduled'", $sequenceId);
		$this->db->execute("DELETE FROM {$this->m()} WHERE sequence_id = %d AND status IN ('pending','scheduled')", $sequenceId);

		foreach ($clean as $i => $st) {
			$this->insertMessage($sequenceId, $organizationId, $lastSent + $i + 1, $st);
		}

		if ($wasScheduled) {
			// Nowy pierwszy niewysłany aktywny krok przejmuje rolę zaplanowanego (termin przeliczany niżej).
			$first = $this->db->row("SELECT id FROM {$this->m()} WHERE sequence_id = %d AND is_active = 1 AND status = 'pending' ORDER BY position ASC LIMIT 1", $sequenceId);

			if ($first) {
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'scheduled_at' => $wasScheduled['scheduled_at']], ['id' => (int) $first['id']]);
			}
		}
	}

	/**
	 * Termin zaplanowanej wiadomości od nowa: poprzednia wysyłka (albo start) + odstęp, w oknie; przy wznowieniu ≥ teraz.
	 */
	private function recomputeScheduled(int $sequenceId, bool $notBeforeNow = true): void
	{
		$s = $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $sequenceId);
		$settings = $this->seqSettings($s);
		$scheduled = $this->db->row("SELECT * FROM {$this->m()} WHERE sequence_id = %d AND status = 'scheduled' ORDER BY position ASC LIMIT 1", $sequenceId);

		if (! $scheduled || ! (int) $scheduled['is_active']) {
			if ($scheduled) {
				$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'pending', 'scheduled_at' => null], ['id' => (int) $scheduled['id']]);
			}

			$scheduled = $this->db->row("SELECT * FROM {$this->m()} WHERE sequence_id = %d AND is_active = 1 AND status = 'pending' ORDER BY position ASC LIMIT 1", $sequenceId);

			if (! $scheduled) {
				if (! (int) $this->db->value("SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status IN ('sending','unknown_result')", $sequenceId)) {
					$this->finish($sequenceId, 'completed', null);
				}

				return;
			}
		}

		$lastSent = $this->db->value("SELECT MAX(sent_at) FROM {$this->m()} WHERE sequence_id = %d AND status = 'sent'", $sequenceId);
		$base = $lastSent ? SendWindow::utc((string) $lastSent) : $this->startBase($settings, $s['started_at'] ? SendWindow::utc((string) $s['started_at']) : null);
		$at = SendWindow::due($base, (int) $scheduled['delay_days'], $settings['window']);

		if ($notBeforeNow) {
			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$at = $at < $now ? SendWindow::next($now, $settings['window']) : $at;
		}

		$this->db->update(Tables::SEQ_MESSAGES, ['status' => 'scheduled', 'scheduled_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => (int) $scheduled['id']]);
		$this->db->update(Tables::SEQ_SEQUENCES, ['next_send_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => $sequenceId]);
	}

	private function reschedule(int $sequenceId, string $value, array $settings): void
	{
		$local = $this->dateInput($value, $settings['window']['timezone']);

		if (! $local) {
			throw UbrException::invalid('Niepoprawny termin wysyłki.');
		}

		$at = SendWindow::next(max(SendWindow::utc($local), new DateTimeImmutable('now', new DateTimeZone('UTC'))), $settings['window']);
		$updated = $this->db->execute("UPDATE {$this->m()} SET scheduled_at = %s, updated_at = %s WHERE sequence_id = %d AND status = 'scheduled'", SendWindow::mysql($at), Db::now(), $sequenceId);

		if ($updated) {
			$this->db->update(Tables::SEQ_SEQUENCES, ['next_send_at' => SendWindow::mysql($at), 'updated_at' => Db::now()], ['id' => $sequenceId]);
		}
	}

	private function startBase(array $settings, ?DateTimeImmutable $started = null): DateTimeImmutable
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$start = $settings['start_at'] ? SendWindow::utc((string) $settings['start_at']) : ($started ?? $now);

		return $start < $now && ! $started ? $now : $start;
	}

	/** Data z formularza (czas lokalny strefy sekwencji, „YYYY-MM-DDTHH:MM”) → UTC MySQL. */
	private function dateInput(mixed $v, string $tz): ?string
	{
		$v = trim((string) $v);

		if ($v === '') {
			return null;
		}

		try {
			$d = new DateTimeImmutable($v, new DateTimeZone($tz));
		} catch (\Throwable) {
			throw UbrException::invalid('Niepoprawna data.');
		}

		return SendWindow::mysql($d);
	}

	private function insertMessage(int $sequenceId, int $organizationId, int $position, array $st): void
	{
		$this->db->insert(Tables::SEQ_MESSAGES, ['sequence_id' => $sequenceId, 'organization_id' => $organizationId, 'position' => $position, 'subject' => $st['subject'], 'body' => $st['body'], 'delay_days' => (int) $st['delay_days'], 'is_active' => ! empty($st['is_active']) ? 1 : 0, 'status' => 'pending', 'created_at' => Db::now(), 'updated_at' => Db::now()]);
	}

	private function refreshCounts(int $sequenceId): void
	{
		$this->db->execute(
			"UPDATE {$this->t()} SET step_count = (SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND is_active = 1 AND status <> 'cancelled'), sent_count = (SELECT COUNT(*) FROM {$this->m()} WHERE sequence_id = %d AND status = 'sent') WHERE id = %d",
			$sequenceId,
			$sequenceId,
			$sequenceId
		);
	}

	private function setRecipient(TenantContext $ctx, array $s, ?array $company, array $r): void
	{
		$source = isset(SequenceCatalog::RECIPIENT_SOURCES[$r['source'] ?? '']) ? $r['source'] : 'manual';
		$email = EmailAddress::normalize((string) ($r['email'] ?? ''));
		$name = null;
		$contactId = null;

		if ($source === 'company_general') {
			$email = EmailAddress::normalize((string) ($company['general_email'] ?? ''));
		} elseif ($source === 'contact') {
			$c = $this->db->row("SELECT id, full_name, public_business_email FROM {$this->db->table(Tables::CONTACTS)} WHERE id = %d AND company_id = %d AND org_key IN (0, %d)", (int) ($r['contact_id'] ?? 0), (int) $s['company_id'], $ctx->organizationId);

			if (! $c || empty($c['public_business_email'])) {
				throw UbrException::invalid('Wybrany decydent nie ma adresu e-mail.');
			}

			$email = EmailAddress::normalize((string) $c['public_business_email']);
			$name = $c['full_name'];
			$contactId = (int) $c['id'];
		} else {
			$name = TemplateRenderer::clean((string) ($r['name'] ?? '')) ?: null;
		}

		if (! EmailAddress::syntaxOk($email)) {
			throw UbrException::invalid('Niepoprawny adres odbiorcy.');
		}

		$this->db->update(Tables::SEQ_SEQUENCES, ['recipient_email' => $email, 'recipient_name' => $name, 'recipient_source' => $source, 'contact_id' => $contactId, 'updated_at' => Db::now()], ['id' => (int) $s['id']]);
	}

	private function recipientOption(string $source, string $email, ?int $contactId, ?string $name, ?string $note, int $orgId, array $company, ?array $contact = null): array
	{
		$email = EmailAddress::normalize($email);
		$blockers = EmailAddress::syntaxOk($email) ? $this->blockers($orgId, (int) $company['id'], $email, $contactId) : [['code' => 'invalid', 'message' => 'Niepoprawny adres.']];
		$conflict = $this->conflict($orgId, $email, 0);

		return ['source' => $source, 'source_label' => SequenceCatalog::RECIPIENT_SOURCES[$source], 'email' => $email, 'contact_id' => $contactId, 'name' => $name, 'note' => $note, 'verification' => $contact['verification_status'] ?? null, 'blockers' => $blockers, 'conflict' => $conflict];
	}

	/** Aktywna (zaplanowana / aktywna / wstrzymana / z błędem) sekwencja tego adresu w organizacji — dowolna firma. */
	private function conflict(int $orgId, string $email, int $exceptId): ?array
	{
		$row = $this->db->row(
			"SELECT s.id, s.name, s.status, s.company_id, s.owner_user_id, c.name AS company_name FROM {$this->t()} s JOIN {$this->db->table(Tables::COMPANIES)} c ON c.id = s.company_id WHERE s.active_recipient = %s AND s.id <> %d",
			$orgId . '|' . EmailAddress::normalize($email),
			$exceptId
		);

		if (! $row) {
			return null;
		}

		$owner = get_userdata((int) $row['owner_user_id']);

		return ['id' => (int) $row['id'], 'name' => $row['name'], 'status' => $row['status'], 'status_label' => SequenceCatalog::label(SequenceCatalog::SEQUENCE_STATUSES, $row['status']), 'company_id' => (int) $row['company_id'], 'company_name' => $row['company_name'], 'owner' => $owner ? $owner->display_name : null];
	}

	/** @return array<string, ?string> */
	private function autoValues(array $s, ?array $company): array
	{
		$mailbox = $s['mailbox_id'] ? $this->mailboxes->find((int) $s['mailbox_id']) : null;
		$owner = get_userdata((int) $s['owner_user_id']);
		$contact = $s['contact_id'] ? $this->db->row("SELECT first_name, last_name, full_name, job_title FROM {$this->db->table(Tables::CONTACTS)} WHERE id = %d", (int) $s['contact_id']) : null;
		$website = (string) ($company['website_url'] ?? '');
		$host = $website !== '' ? (string) wp_parse_url($website, PHP_URL_HOST) : '';
		$fullName = $contact['full_name'] ?? null;
		$parts = $fullName ? preg_split('/\s+/u', trim((string) $fullName)) : [];

		return [
			'company_name' => $company['name'] ?? null,
			'company_website' => $host !== '' ? $host : null,
			'company_domain' => $company['domain'] ?? null,
			'company_city' => $company['city'] ?? null,
			'contact_first_name' => ($contact['first_name'] ?? null) ?: (count($parts) >= 2 ? $parts[0] : null),
			'contact_last_name' => ($contact['last_name'] ?? null) ?: (count($parts) >= 2 ? end($parts) : null),
			'contact_full_name' => $fullName ?: ($s['recipient_source'] === 'manual' ? $s['recipient_name'] : null),
			'contact_position' => $contact['job_title'] ?? null,
			'sender_first_name' => $owner ? ((string) $owner->first_name ?: null) : null,
			'sender_last_name' => $owner ? ((string) $owner->last_name ?: null) : null,
			'sender_name' => $mailbox['from_name'] ?? ($owner ? $owner->display_name : null),
			'sender_email' => $mailbox['email'] ?? null,
		];
	}

	/** @return array<string, string> */
	private function overrides(array $s): array
	{
		return array_filter((array) (json_decode((string) ($s['variables'] ?? ''), true) ?: []), 'is_string');
	}

	private function summary(array $r): array
	{
		$owner = get_userdata((int) $r['owner_user_id']);
		$mailbox = $r['mailbox_id'] ? $this->db->row("SELECT email, from_name, status, provider FROM {$this->db->table(Tables::SEQ_MAILBOXES)} WHERE id = %d", (int) $r['mailbox_id']) : null;
		$tz = $this->seqSettings($r)['window']['timezone'];

		return [
			'id' => (int) $r['id'],
			'name' => $r['name'],
			'company_id' => (int) $r['company_id'],
			'company_name' => $r['company_name'] ?? ($this->companies->find((int) $r['company_id'])['name'] ?? null),
			'campaign' => $r['campaign_id'] ? ['id' => (int) $r['campaign_id'], 'name' => $r['campaign_name'], 'version' => (int) $r['campaign_version']] : null,
			'status' => $r['status'],
			'status_label' => SequenceCatalog::label(SequenceCatalog::SEQUENCE_STATUSES, $r['status']),
			'test_mode' => (bool) $r['test_mode'],
			'owner' => $owner ? ['id' => (int) $owner->ID, 'name' => $owner->display_name] : null,
			'mailbox' => $mailbox ? ['id' => (int) $r['mailbox_id'], 'email' => $mailbox['email'], 'from_name' => $mailbox['from_name'], 'status' => $mailbox['status'], 'provider' => $mailbox['provider']] : null,
			'recipient' => ['email' => $r['recipient_email'], 'name' => $r['recipient_name'], 'source' => $r['recipient_source'], 'source_label' => $r['recipient_source'] ? SequenceCatalog::RECIPIENT_SOURCES[$r['recipient_source']] ?? null : null, 'contact_id' => $r['contact_id'] ? (int) $r['contact_id'] : null],
			'progress' => ['sent' => (int) $r['sent_count'], 'total' => (int) $r['step_count']],
			'next_send_at' => $r['next_send_at'] ? gmdate('c', strtotime($r['next_send_at'] . ' UTC')) : null,
			'next_send_label' => $r['next_send_at'] ? $this->localLabel(SendWindow::utc((string) $r['next_send_at']), $tz) : null,
			'last_sent_at' => $r['last_sent_at'] ? gmdate('c', strtotime($r['last_sent_at'] . ' UTC')) : null,
			'error' => $r['error_code'] || $r['error_message'] ? ['code' => $r['error_code'], 'message' => $r['error_message'] ?: SequenceCatalog::label(SequenceCatalog::ERROR_LABELS, $r['error_code'])] : null,
			'created_at' => gmdate('c', strtotime($r['created_at'] . ' UTC')),
		];
	}

	private function presentMessage(array $m, array $values): array
	{
		$final = in_array($m['status'], SequenceCatalog::FINAL_MESSAGES, true) && $m['sent_body'] !== null;
		$missing = [];
		$subject = $final ? (string) $m['sent_subject'] : TemplateRenderer::render((string) $m['subject'], $values, $missing, true);
		$body = $final ? (string) $m['sent_body'] : TemplateRenderer::render((string) $m['body'], $values + ['unsubscribe_link' => '[link rezygnacji]'], $missing);

		return [
			'id' => (int) $m['id'],
			'position' => (int) $m['position'],
			'subject_template' => $m['subject'],
			'body_template' => $m['body'],
			'delay_days' => (int) $m['delay_days'],
			'is_active' => (bool) $m['is_active'],
			'status' => $m['status'],
			'status_label' => SequenceCatalog::label(SequenceCatalog::MESSAGE_STATUSES, $m['status']),
			'editable' => in_array($m['status'], ['pending', 'scheduled'], true),
			'scheduled_at' => $m['scheduled_at'] ? gmdate('c', strtotime($m['scheduled_at'] . ' UTC')) : null,
			'sent_at' => $m['sent_at'] ? gmdate('c', strtotime($m['sent_at'] . ' UTC')) : null,
			'preview_subject' => $subject,
			'preview_body' => $body,
			'missing' => array_values(array_unique(array_diff($missing, TemplateRenderer::SYSTEM))),
			'message_id' => $m['message_id'],
			'from_email' => $m['from_email'],
			'to_email' => $m['to_email'],
			'transport' => $m['transport'],
			'smtp_response' => $m['smtp_response'],
			'error' => $m['error'],
			'attempts' => (int) $m['attempts'],
		];
	}

	private function listMessage(array $r, bool $history = false): array
	{
		$owner = get_userdata((int) $r['owner_user_id']);
		$mailbox = $r['seq_mailbox_id'] ? $this->db->row("SELECT email FROM {$this->db->table(Tables::SEQ_MAILBOXES)} WHERE id = %d", (int) $r['seq_mailbox_id']) : null;

		return [
			'id' => (int) $r['id'],
			'sequence_id' => (int) $r['sequence_id'],
			'sequence_name' => $r['sequence_name'],
			'sequence_status' => $r['sequence_status'],
			'sequence_status_label' => SequenceCatalog::label(SequenceCatalog::SEQUENCE_STATUSES, $r['sequence_status']),
			'company_id' => (int) $r['company_id'],
			'company_name' => $r['company_name'],
			'owner' => $owner ? $owner->display_name : null,
			'from_email' => $history ? ($r['from_email'] ?: ($mailbox['email'] ?? null)) : ($mailbox['email'] ?? null),
			'to_email' => $history ? ($r['to_email'] ?: $r['recipient_email']) : $r['recipient_email'],
			'subject' => $history && $r['sent_subject'] !== null ? $r['sent_subject'] : $r['subject'],
			'step' => (int) $r['position'],
			'step_total' => (int) $r['step_count'],
			'status' => $r['status'],
			'status_label' => SequenceCatalog::label(SequenceCatalog::MESSAGE_STATUSES, $r['status']),
			'scheduled_at' => $r['scheduled_at'] ? gmdate('c', strtotime($r['scheduled_at'] . ' UTC')) : null,
			'sent_at' => $r['sent_at'] ? gmdate('c', strtotime($r['sent_at'] . ' UTC')) : null,
			'message_id' => $r['message_id'],
			'smtp_response' => $r['smtp_response'],
			'error' => $r['error'],
			'test_mode' => (bool) $r['test_mode'] || $r['transport'] === 'test',
			'editable' => in_array($r['status'], ['pending', 'scheduled'], true),
		];
	}

	/** Zakres widoczności list: pracownik — własne sekwencje; owner / admin — zespół (filtr pracownika). */
	private function scopeWhere(TenantContext $ctx, array $q, string $a): array
	{
		$where = ["{$a}.organization_id = %d"];
		$args = [$ctx->organizationId];

		if (! $ctx->can('sequences.team')) {
			$where[] = "{$a}.owner_user_id = %d";
			$args[] = $ctx->userId;
		} elseif (! empty($q['owner'])) {
			$where[] = "{$a}.owner_user_id = %d";
			$args[] = $q['owner'] === 'me' ? $ctx->userId : (int) $q['owner'];
		}

		if (! empty($q['company_id'])) {
			$where[] = "{$a}.company_id = %d";
			$args[] = (int) $q['company_id'];
		}

		if (! empty($q['search'])) {
			$like = '%' . $this->db->wpdb()->esc_like(mb_substr((string) $q['search'], 0, 100)) . '%';
			$where[] = "(c.name LIKE %s OR {$a}.recipient_email LIKE %s)";
			$args[] = $like;
			$args[] = $like;
		}

		return [$where, $args];
	}

	private function filterOptions(TenantContext $ctx): array
	{
		$campaigns = $this->db->rows("SELECT DISTINCT campaign_id id, campaign_name name FROM {$this->t()} WHERE organization_id = %d AND campaign_id IS NOT NULL" . ($ctx->can('sequences.team') ? '' : ' AND owner_user_id = ' . (int) $ctx->userId) . ' ORDER BY campaign_name LIMIT 100', $ctx->organizationId);
		$mailboxes = $this->db->rows("SELECT id, email FROM {$this->db->table(Tables::SEQ_MAILBOXES)} WHERE organization_id = %d" . ($ctx->can('sequences.team') ? '' : ' AND user_id = ' . (int) $ctx->userId) . ' ORDER BY email LIMIT 100', $ctx->organizationId);
		$members = $ctx->can('sequences.team') ? array_map(fn ($m) => ['id' => (int) $m['user_id'], 'name' => $m['display_name'] ?? ('#' . $m['user_id'])], app(\App\Ubr\Infrastructure\Repositories\MemberRepository::class)->list($ctx->organizationId)) : [];

		return ['campaigns' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $campaigns), 'mailboxes' => array_map(fn ($m) => ['id' => (int) $m['id'], 'email' => $m['email']], $mailboxes), 'members' => $members, 'statuses' => SequenceCatalog::SEQUENCE_STATUSES, 'team' => $ctx->can('sequences.team')];
	}

	private function paging(array $q): array
	{
		$per = (int) ($q['per_page'] ?? 25);
		$per = in_array($per, [10, 25, 50, 100], true) ? $per : 25;

		return [max(1, (int) ($q['page'] ?? 1)), $per];
	}

	private function mailboxIdFor(TenantContext $ctx, mixed $id): ?int
	{
		if ($id === null || $id === '' || (int) $id === 0) {
			$default = $this->db->value("SELECT id FROM {$this->db->table(Tables::SEQ_MAILBOXES)} WHERE organization_id = %d AND user_id = %d AND status <> 'disabled' ORDER BY is_default DESC, id ASC LIMIT 1", $ctx->organizationId, $ctx->userId);

			return $default ? (int) $default : null;
		}

		return (int) $this->mailboxes->own($ctx, (int) $id)['id'];
	}

	private function companyOr404(TenantContext $ctx, int $companyId): array
	{
		$c = $this->companies->find($companyId);

		if (! $c || ! $this->companies->isVisibleTo($companyId, $ctx->organizationId)) {
			throw UbrException::notFound('Nie znaleziono firmy.');
		}

		return $c;
	}

	private function prospect(int $orgId, int $companyId): ?array
	{
		return $this->db->row("SELECT * FROM {$this->db->table(Tables::PROSPECTS)} WHERE organization_id = %d AND company_id = %d AND archived_at IS NULL", $orgId, $companyId);
	}

	private function canManageCompany(TenantContext $ctx, ?array $prospect): bool
	{
		return $prospect !== null && $ctx->can('sequences.manage') && ((int) $prospect['owner_user_id'] === $ctx->userId || $ctx->can('sequences.team'));
	}

	private function visible(TenantContext $ctx, int $id): array
	{
		$s = $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d AND organization_id = %d", $id, $ctx->organizationId);

		if (! $s || ((int) $s['owner_user_id'] !== $ctx->userId && ! $ctx->can('sequences.team'))) {
			throw UbrException::notFound('Nie znaleziono sekwencji.');
		}

		return $s;
	}

	private function manageable(TenantContext $ctx, int $id): array
	{
		$s = $this->visible($ctx, $id);

		if (! $this->canManage($ctx, $s)) {
			throw UbrException::forbidden('Brak uprawnień do tej sekwencji.');
		}

		return $s;
	}

	/** @return list<array<string, mixed>> */
	private function messageRows(int $sequenceId): array
	{
		return $this->db->rows("SELECT * FROM {$this->m()} WHERE sequence_id = %d ORDER BY position ASC, id ASC", $sequenceId);
	}

	private function localLabel(DateTimeImmutable $utc, string $tz): string
	{
		return $utc->setTimezone(new DateTimeZone($tz))->format('d.m.Y, H:i');
	}

	private function t(): string
	{
		return $this->db->table(Tables::SEQ_SEQUENCES);
	}

	private function m(): string
	{
		return $this->db->table(Tables::SEQ_MESSAGES);
	}
}
