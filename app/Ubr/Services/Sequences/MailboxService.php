<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Sequences\EmailAddress;
use App\Ubr\Domain\Sequences\SequenceCatalog;
use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;
use App\Ubr\Infrastructure\Mail\LogTransport;
use App\Ubr\Infrastructure\Mail\MailTransport;
use App\Ubr\Infrastructure\Mail\SmtpTransport;
use App\Ubr\Infrastructure\Security\SecretBox;
use App\Ubr\Support\UbrException;

/**
 * Prompt 10.5 — skrzynki nadawcze pracowników (Mailboxes).
 *
 * - Każdy pracownik podłącza WŁASNĄ skrzynkę; wysyłać z niej może tylko jej właściciel (także admin nie wysyła z cudzej).
 * - Pierwsza wersja: SMTP z hasłem aplikacji (dostawca skrzynek nieznany — bez zbędnych integracji OAuth);
 *   `MailTransport` pozwala dodać później np. Gmail API / Microsoft Graph (OAuth).
 * - Hasło zaszyfrowane `SecretBox` (libsodium, klucz `UBR_ENCRYPTION_KEY` poza bazą), nigdy nie wraca w odpowiedziach
 *   ani logach. Nie da się odszyfrować (zmiana klucza) → status „Wymaga ponownego podania hasła”.
 * - Skrzynka „test” (tylko środowiska nieprodukcyjne lub `UBR_MAIL_TEST_MAILBOXES`) — nic nie wysyła.
 */
class MailboxService
{
	public function __construct(private Db $db, private SecretBox $box, private SequenceSettings $settings)
	{
	}

	public static function testMailboxesAllowed(): bool
	{
		return (bool) apply_filters('ubr/mail/test_mailboxes_allowed', (defined('UBR_MAIL_TEST_MAILBOXES') && constant('UBR_MAIL_TEST_MAILBOXES')) || wp_get_environment_type() !== 'production');
	}

	public function transport(array $mailbox): MailTransport
	{
		$transport = apply_filters('ubr/mail/transport', null, $mailbox);

		if ($transport instanceof MailTransport) {
			return $transport;
		}

		return ($mailbox['provider'] ?? 'smtp') === 'test' ? new LogTransport() : new SmtpTransport();
	}

	/** @return list<array<string, mixed>> */
	public function list(TenantContext $ctx): array
	{
		$rows = $this->db->rows("SELECT * FROM {$this->t()} WHERE organization_id = %d AND user_id = %d AND status <> 'disabled' ORDER BY is_default DESC, id ASC", $ctx->organizationId, $ctx->userId);

		return array_map(fn ($r) => $this->present($r), $rows);
	}

	/** @return array<string, mixed>|null */
	public function find(int $id): ?array
	{
		return $this->db->row("SELECT * FROM {$this->t()} WHERE id = %d", $id);
	}

	/** Skrzynka pracownika (wyłącznie właściciel). */
	public function own(TenantContext $ctx, int $id): array
	{
		$m = $this->find($id);

		if (! $m || (int) $m['organization_id'] !== $ctx->organizationId || (int) $m['user_id'] !== $ctx->userId || $m['status'] === 'disabled') {
			throw UbrException::notFound('Nie znaleziono skrzynki.');
		}

		return $m;
	}

	/** @param array<string, mixed> $input */
	public function create(TenantContext $ctx, array $input): array
	{
		$provider = ($input['provider'] ?? 'smtp') === 'test' ? 'test' : 'smtp';

		if ($provider === 'test' && ! self::testMailboxesAllowed()) {
			throw UbrException::invalid('Skrzynki testowe są dostępne tylko w środowisku testowym.');
		}

		$email = EmailAddress::normalize((string) ($input['email'] ?? ''));

		if (! EmailAddress::syntaxOk($email)) {
			throw UbrException::invalid('Podaj poprawny adres e-mail skrzynki.');
		}

		if ($this->db->value("SELECT id FROM {$this->t()} WHERE organization_id = %d AND user_id = %d AND email = %s AND status <> 'disabled'", $ctx->organizationId, $ctx->userId, $email)) {
			throw UbrException::invalid('Ta skrzynka jest już podłączona.');
		}

		$data = ['provider' => $provider, 'email' => $email, 'from_name' => $this->fromName($input['from_name'] ?? '', $ctx->userId), 'limits' => wp_json_encode($this->limits((array) ($input['limits'] ?? []), $ctx->organizationId))];

		if ($provider === 'smtp') {
			$data += $this->smtpFields($input, $email, true);
		}

		$hasDefault = (bool) $this->db->value("SELECT id FROM {$this->t()} WHERE organization_id = %d AND user_id = %d AND is_default = 1 AND status <> 'disabled'", $ctx->organizationId, $ctx->userId);
		$disabled = $this->db->value("SELECT id FROM {$this->t()} WHERE organization_id = %d AND user_id = %d AND email = %s", $ctx->organizationId, $ctx->userId, $email);
		$row = $data + ['status' => $provider === 'test' ? 'connected' : 'untested', 'is_default' => $hasDefault ? 0 : 1, 'last_error' => null, 'updated_at' => Db::now()];

		if ($disabled) {
			// Ponowne podłączenie odłączonej wcześniej skrzynki — ten sam rekord (historia wysyłek zachowana).
			$this->db->update(Tables::SEQ_MAILBOXES, $row, ['id' => (int) $disabled]);
			$id = (int) $disabled;
		} else {
			$id = $this->db->insert(Tables::SEQ_MAILBOXES, $row + ['organization_id' => $ctx->organizationId, 'user_id' => $ctx->userId, 'created_at' => Db::now()]);
		}

		return $this->present($this->find($id));
	}

	/** @param array<string, mixed> $input */
	public function update(TenantContext $ctx, int $id, array $input): array
	{
		$m = $this->own($ctx, $id);
		$data = [];

		if (array_key_exists('from_name', $input)) {
			$data['from_name'] = $this->fromName($input['from_name'], $ctx->userId);
		}

		if (array_key_exists('limits', $input)) {
			$data['limits'] = wp_json_encode($this->limits((array) $input['limits'], $ctx->organizationId));
		}

		if ($m['provider'] === 'smtp' && array_intersect(array_keys($input), ['host', 'port', 'encryption', 'username', 'password'])) {
			$data += $this->smtpFields($input + ['host' => $m['host'], 'port' => $m['port'], 'encryption' => $m['encryption'], 'username' => $m['username']], (string) $m['email'], false);
			$data['status'] = 'untested';
		}

		if (! empty($input['is_default'])) {
			$this->db->execute("UPDATE {$this->t()} SET is_default = 0 WHERE organization_id = %d AND user_id = %d", $ctx->organizationId, $ctx->userId);
			$data['is_default'] = 1;
		}

		if ($data) {
			$this->db->update(Tables::SEQ_MAILBOXES, $data + ['updated_at' => Db::now()], ['id' => $id]);
		}

		return $this->present($this->find($id));
	}

	/** Test połączenia i logowania (bez wysyłania wiadomości). */
	public function test(TenantContext $ctx, int $id): array
	{
		$m = $this->own($ctx, $id);
		$secret = $m['provider'] === 'smtp' ? $this->secret($m) : null;

		if ($m['provider'] === 'smtp' && $secret === null) {
			$this->db->update(Tables::SEQ_MAILBOXES, ['status' => 'reconnect', 'last_error' => 'Nie można odczytać zapisanego hasła (zmiana klucza szyfrowania) — podaj hasło ponownie.', 'last_tested_at' => Db::now(), 'updated_at' => Db::now()], ['id' => $id]);

			return ['ok' => false, 'message' => 'Podaj hasło skrzynki ponownie.', 'mailbox' => $this->present($this->find($id))];
		}

		$r = $this->transport($m)->test($m, $secret);
		$this->db->update(Tables::SEQ_MAILBOXES, ['status' => $r['status'], 'last_error' => $r['ok'] ? null : mb_substr($r['message'], 0, 500), 'last_tested_at' => Db::now(), 'updated_at' => Db::now()], ['id' => $id]);

		return ['ok' => $r['ok'], 'message' => $r['message'], 'mailbox' => $this->present($this->find($id))];
	}

	/**
	 * Odłączenie: dane logowania usunięte, rekord zostaje (historia wysyłek). Sekwencje używające skrzynki — wstrzymane.
	 */
	public function disconnect(TenantContext $ctx, int $id): array
	{
		$this->own($ctx, $id);
		$paused = $this->db->execute(
			"UPDATE {$this->db->table(Tables::SEQ_SEQUENCES)} SET status = 'paused', paused_at = %s, error_code = 'mailbox', error_message = %s, updated_at = %s WHERE mailbox_id = %d AND status IN ('scheduled','active','error')",
			Db::now(),
			'Skrzynka nadawcza została odłączona — wybierz inną skrzynkę i wznów.',
			Db::now(),
			$id
		);
		$this->db->update(Tables::SEQ_MAILBOXES, ['status' => 'disabled', 'secret_ciphertext' => null, 'key_id' => null, 'is_default' => 0, 'updated_at' => Db::now()], ['id' => $id]);

		return ['disconnected' => true, 'paused_sequences' => $paused];
	}

	/**
	 * Kontrola DNS domeny nadawcy (SPF, DMARC, DKIM dla typowych selektorów) — informacyjnie; dobra konfiguracja DNS
	 * nie gwarantuje dostarczalności.
	 */
	public function dns(TenantContext $ctx, int $id): array
	{
		$m = $this->own($ctx, $id);
		$domain = EmailAddress::domain((string) $m['email']);
		$txt = function (string $name): array {
			$filtered = apply_filters('ubr/mail/dns_txt', null, $name);

			if (is_array($filtered)) {
				return $filtered;
			}

			$records = function_exists('dns_get_record') ? @dns_get_record($name, DNS_TXT) : false;

			return array_values(array_filter(array_map(fn ($r) => (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))), is_array($records) ? $records : [])));
		};
		$spf = array_values(array_filter($txt($domain), fn ($v) => str_starts_with(strtolower($v), 'v=spf1')));
		$dmarc = array_values(array_filter($txt('_dmarc.' . $domain), fn ($v) => str_starts_with(strtolower($v), 'v=dmarc1')));
		$dkim = [];

		foreach (['google', 'default', 'selector1', 'selector2', 'k1', 'mail', 'dkim', 's1'] as $selector) {
			if (array_filter($txt($selector . '._domainkey.' . $domain), fn ($v) => str_contains(strtolower($v), 'v=dkim1') || str_contains(strtolower($v), 'p='))) {
				$dkim[] = $selector;
			}
		}

		$result = [
			'domain' => $domain,
			'spf' => ['ok' => count($spf) === 1, 'value' => $spf[0] ?? null, 'note' => count($spf) > 1 ? 'Więcej niż jeden rekord SPF — popraw (dozwolony jeden).' : (count($spf) === 0 ? 'Brak rekordu SPF.' : null)],
			'dmarc' => ['ok' => (bool) $dmarc, 'value' => $dmarc[0] ?? null, 'note' => $dmarc ? null : 'Brak rekordu DMARC (_dmarc).'],
			'dkim' => ['ok' => (bool) $dkim, 'selectors' => $dkim, 'note' => $dkim ? null : 'Nie znaleziono DKIM dla typowych selektorów — sprawdź u dostawcy poczty (selektor może mieć inną nazwę).'],
			'checked_at' => gmdate('c'),
			'disclaimer' => 'Poprawne SPF / DKIM / DMARC nie gwarantują dostarczenia wiadomości do skrzynki odbiorczej.',
		];
		$this->db->update(Tables::SEQ_MAILBOXES, ['dns_check' => wp_json_encode($result), 'updated_at' => Db::now()], ['id' => $id]);

		return $result;
	}

	/** Odszyfrowane hasło (tylko worker / test). null = brak albo nie da się odszyfrować. */
	public function secret(array $mailbox): ?string
	{
		if (empty($mailbox['secret_ciphertext'])) {
			return null;
		}

		return $this->box->decrypt((string) $mailbox['secret_ciphertext']);
	}

	/** @return array{per_day: int, per_hour: int, min_interval_minutes: int} */
	public function limits(array $input, int $organizationId): array
	{
		$s = $this->settings->get($organizationId);
		$d = $s['mailbox_defaults'];

		return [
			'per_day' => max(1, min($s['caps']['per_day'], (int) ($input['per_day'] ?? $d['per_day']))),
			'per_hour' => max(1, min($s['caps']['per_hour'], (int) ($input['per_hour'] ?? $d['per_hour']))),
			'min_interval_minutes' => max(1, min(240, (int) ($input['min_interval_minutes'] ?? $d['min_interval_minutes']))),
		];
	}

	/** @return array<string, mixed> */
	public function present(?array $m): array
	{
		if (! $m) {
			return [];
		}

		$limits = json_decode((string) ($m['limits'] ?? ''), true) ?: [];

		return [
			'id' => (int) $m['id'],
			'provider' => $m['provider'],
			'provider_label' => $m['provider'] === 'test' ? 'Testowa (bez wysyłki)' : 'SMTP',
			'email' => $m['email'],
			'from_name' => $m['from_name'],
			'host' => $m['host'],
			'port' => $m['port'] !== null ? (int) $m['port'] : null,
			'encryption' => $m['encryption'],
			'username' => $m['username'],
			'has_password' => ! empty($m['secret_ciphertext']),
			'status' => $m['status'],
			'status_label' => SequenceCatalog::label(SequenceCatalog::MAILBOX_STATUSES, $m['status']),
			'is_default' => (bool) $m['is_default'],
			'limits' => $limits,
			'dns_check' => json_decode((string) ($m['dns_check'] ?? ''), true) ?: null,
			'last_tested_at' => $m['last_tested_at'] ? gmdate('c', strtotime($m['last_tested_at'] . ' UTC')) : null,
			'last_error' => $m['last_error'],
			'last_sent_at' => $m['last_sent_at'] ? gmdate('c', strtotime($m['last_sent_at'] . ' UTC')) : null,
			'sent_today' => (int) $this->db->value("SELECT COUNT(*) FROM {$this->db->table(Tables::SEQ_MESSAGES)} WHERE mailbox_id = %d AND status IN ('sent','sending','unknown_result') AND transport <> 'test' AND sent_at >= %s", (int) $m['id'], gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)),
		];
	}

	/** @return array<string, mixed> */
	private function smtpFields(array $input, string $email, bool $requirePassword): array
	{
		$host = strtolower(trim((string) ($input['host'] ?? '')));
		$port = (int) ($input['port'] ?? 0);
		$enc = in_array($input['encryption'] ?? '', ['ssl', 'tls'], true) ? $input['encryption'] : ($port === 465 ? 'ssl' : 'tls');

		if (! preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $host)) {
			throw UbrException::invalid('Podaj poprawny adres serwera SMTP (np. smtp.twojadomena.pl).');
		}

		if (! in_array($port, [465, 587, 25, 2525], true)) {
			throw UbrException::invalid('Dozwolone porty SMTP: 465 (SSL), 587 (STARTTLS), 25, 2525.');
		}

		$out = ['host' => $host, 'port' => $port, 'encryption' => $enc, 'username' => mb_substr(trim((string) ($input['username'] ?? '')) ?: $email, 0, 190)];
		$password = (string) ($input['password'] ?? '');

		if ($password !== '') {
			if (! SecretBox::available()) {
				throw UbrException::invalid('Szyfrowanie niedostępne — skonfiguruj UBR_ENCRYPTION_KEY w wp-config.php.');
			}

			$out['secret_ciphertext'] = $this->box->encrypt($password);
			$out['key_id'] = SecretBox::keyId();
		} elseif ($requirePassword) {
			throw UbrException::invalid('Podaj hasło skrzynki (zalecane: hasło aplikacji).');
		}

		return $out;
	}

	private function fromName(mixed $value, int $userId): string
	{
		$name = mb_substr(trim(\App\Ubr\Domain\Sequences\TemplateRenderer::clean((string) $value)), 0, 120);

		if ($name === '') {
			$u = get_userdata($userId);
			$name = $u ? trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name : '';
		}

		return $name;
	}

	private function t(): string
	{
		return $this->db->table(Tables::SEQ_MAILBOXES);
	}
}
