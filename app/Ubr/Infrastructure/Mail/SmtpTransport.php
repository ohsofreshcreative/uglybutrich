<?php

namespace App\Ubr\Infrastructure\Mail;

use App\Ubr\Infrastructure\Http\UrlGuard;

use PHPMailer\PHPMailer\SMTP;

/**
 * Prompt 10.5 — wysyłka przez SMTP skrzynki pracownika (port 465 SSL albo 587 STARTTLS; hasło aplikacji).
 *
 * Etapy rozmowy SMTP są śledzone, żeby rozróżnić błąd PRZED przekazaniem treści (bezpieczne ponowienie) od braku
 * odpowiedzi PO przekazaniu treści (wynik nieznany — możliwy duplikat przy ponowieniu). Krótkie limity czasu
 * (połączenie 15 s, odpowiedzi 30 s), żeby krok workera mieścił się w limitach hostingu.
 */
class SmtpTransport implements MailTransport
{
	public const CONNECT_TIMEOUT = 15;

	public const READ_TIMEOUT = 30;

	public function send(OutgoingMessage $message, array $mailbox, ?string $secret): array
	{
		$out = fn (string $status, ?string $response = null, ?string $error = null, bool $bounce = false) => ['status' => $status, 'response' => $response !== null ? mb_substr($response, 0, 500) : null, 'error' => $error !== null ? mb_substr($error, 0, 500) : null, 'bounce' => $bounce];

		try {
			$mime = $message->mime();
		} catch (\Throwable $e) {
			return $out('failed', null, 'Nie udało się zbudować wiadomości: ' . $e->getMessage());
		}

		$smtp = $this->client();
		$open = $this->open($smtp, $mailbox, $secret);

		if ($open !== null) {
			return $out($open['status'], $open['response'], $open['error']);
		}

		if (! $smtp->mail($message->fromEmail)) {
			return $this->finish($smtp, $out($this->classify($smtp->getLastReply()), $smtp->getLastReply(), 'Serwer odrzucił nadawcę.'));
		}

		if (! $smtp->recipient($message->toEmail)) {
			$reply = (string) $smtp->getLastReply();
			$status = $this->classify($reply);

			return $this->finish($smtp, $out($status, $reply, 'Serwer odrzucił adres odbiorcy.', $status === 'failed' && (bool) preg_match('/^55[0-3]|5\.1\.[0-9]/', trim($reply))));
		}

		if ($smtp->data($mime)) {
			$reply = (string) $smtp->getLastReply();
			$smtp->quit();
			$smtp->close();

			return $out('sent', trim($reply));
		}

		$reply = trim((string) $smtp->getLastReply());

		if ($smtp->lastCommand === 'DATA END') {
			// Treść przekazana. Jawna odpowiedź 4xx/5xx = wiadomość NIE przyjęta; brak odpowiedzi = wynik nieznany.
			$code = (int) substr($reply, 0, 3);
			$result = $code >= 400 ? $out($code >= 500 ? 'failed' : 'retry', $reply, 'Serwer odrzucił wiadomość po przekazaniu treści.') : $out('unknown', $reply !== '' ? $reply : null, 'Brak potwierdzenia serwera po przekazaniu treści (przerwane połączenie / limit czasu).');
		} else {
			$result = $out($this->classify($reply), $reply, 'Serwer nie przyjął polecenia DATA.');
		}

		return $this->finish($smtp, $result);
	}

	public function test(array $mailbox, ?string $secret): array
	{
		$smtp = $this->client();
		$open = $this->open($smtp, $mailbox, $secret);

		if ($open !== null) {
			return ['ok' => false, 'status' => $open['status'] === 'auth' ? 'auth_failed' : 'error', 'message' => (string) $open['error'] . ($open['response'] ? ' (' . mb_substr((string) $open['response'], 0, 160) . ')' : '')];
		}

		$smtp->quit();
		$smtp->close();

		return ['ok' => true, 'status' => 'connected', 'message' => 'Połączenie i logowanie poprawne (nie wysłano żadnej wiadomości).'];
	}

	/**
	 * Host skrzynki podaje pracownik — bez tej kontroli serwer aplikacji łączyłby się z adresami wewnętrznymi
	 * (SSRF / skanowanie portów). Wszystkie adresy z DNS muszą być publiczne; zwraca adres do połączenia.
	 * Lokalny serwer testowy tylko przez filtr `ubr/mail/allow_private_smtp`.
	 */
	protected function publicTarget(string $host): ?string
	{
		$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : apply_filters('ubr/url_guard/resolve', null, $host);

		if (! is_array($ips)) {
			$ips = gethostbynamel($host) ?: [];

			if (function_exists('dns_get_record')) {
				foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
					if (! empty($record['ipv6'])) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		$ips = array_values(array_unique(array_filter(array_map('strval', $ips))));

		if (! $ips) {
			return null;
		}

		$allowPrivate = (bool) apply_filters('ubr/mail/allow_private_smtp', false, $host);

		foreach ($ips as $ip) {
			if (! $allowPrivate && ! UrlGuard::isPublicIp($ip)) {
				return null;
			}
		}

		$ipv4 = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));

		return $ipv4 ? $ipv4[0] : '[' . $ips[0] . ']';
	}

	protected function client(): TrackingSmtp
	{
		if (! class_exists(SMTP::class)) {
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		}

		$smtp = new TrackingSmtp();
		[$connect, $read] = $this->timeouts();
		$smtp->Timeout = $connect;
		$smtp->Timelimit = $read;
		$smtp->do_debug = 0;

		return $smtp;
	}

	/**
	 * Połączenie, EHLO, STARTTLS, logowanie. null = gotowe do wysyłki.
	 *
	 * @return array{status: string, response: ?string, error: string}|null
	 */
	private function open(TrackingSmtp $smtp, array $mailbox, ?string $secret): ?array
	{
		$host = (string) ($mailbox['host'] ?? '');
		$port = (int) ($mailbox['port'] ?? 587);
		$enc = (string) ($mailbox['encryption'] ?? 'tls');

		if ($host === '' || $port <= 0) {
			return ['status' => 'failed', 'response' => null, 'error' => 'Brak konfiguracji serwera SMTP.'];
		}

		if ($secret === null || $secret === '') {
			return ['status' => 'auth', 'response' => null, 'error' => 'Brak hasła skrzynki (podaj je ponownie).'];
		}

		$options = (array) apply_filters('ubr/mail/smtp_options', ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]], $mailbox);
		$target = $this->publicTarget($host);

		if ($target === null) {
			return ['status' => 'failed', 'response' => null, 'error' => 'Serwer SMTP ' . $host . ' nie wskazuje na publiczny adres IP — połączenie zablokowane.'];
		}

		// Połączenie z ZWERYFIKOWANYM adresem IP (bez ponownego DNS), certyfikat sprawdzany dla nazwy serwera.
		$options['ssl'] = (array) ($options['ssl'] ?? []) + ['peer_name' => $host, 'SNI_enabled' => true];

		if (! $smtp->connect(($enc === 'ssl' ? 'ssl://' : '') . $target, $port, $this->timeouts()[0], $options)) {
			return ['status' => 'retry', 'response' => null, 'error' => 'Nie można połączyć się z serwerem SMTP ' . $host . ':' . $port . '.'];
		}

		$helo = (string) apply_filters('ubr/mail/helo', (string) wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost');

		if (! $smtp->hello($helo)) {
			return $this->fail($smtp, 'retry', 'Serwer SMTP nie odpowiedział na EHLO.');
		}

		if ($enc === 'tls') {
			if (! $smtp->startTLS()) {
				return $this->fail($smtp, 'retry', 'Nie udało się włączyć szyfrowania STARTTLS.');
			}

			if (! $smtp->hello($helo)) {
				return $this->fail($smtp, 'retry', 'Serwer SMTP nie odpowiedział na EHLO po STARTTLS.');
			}
		}

		if (! $smtp->authenticate((string) ($mailbox['username'] ?: $mailbox['email']), $secret)) {
			$reply = (string) $smtp->getLastReply();

			return $this->fail($smtp, str_starts_with(trim($reply), '4') ? 'retry' : 'auth', 'Logowanie do skrzynki nie powiodło się (sprawdź login i hasło aplikacji).');
		}

		return null;
	}

	/** @return array{0: int, 1: int} limit połączenia i odpowiedzi (s); filtr `ubr/mail/smtp_timeouts` (testy). */
	private function timeouts(): array
	{
		$t = (array) apply_filters('ubr/mail/smtp_timeouts', [self::CONNECT_TIMEOUT, self::READ_TIMEOUT]);

		return [max(1, (int) ($t[0] ?? self::CONNECT_TIMEOUT)), max(1, (int) ($t[1] ?? self::READ_TIMEOUT))];
	}

	/** @return array{status: string, response: ?string, error: string} */
	private function fail(TrackingSmtp $smtp, string $status, string $error): array
	{
		$reply = (string) $smtp->getLastReply();
		$smtp->close();

		return ['status' => $status, 'response' => $reply !== '' ? trim($reply) : null, 'error' => $error];
	}

	/** Odpowiedź przed przekazaniem treści: 5xx → trwały błąd, inaczej (4xx, brak odpowiedzi) → bezpieczne ponowienie. */
	private function classify(?string $reply): string
	{
		return str_starts_with(trim((string) $reply), '5') ? 'failed' : 'retry';
	}

	private function finish(TrackingSmtp $smtp, array $result): array
	{
		if ($smtp->connected()) {
			$smtp->reset();
			$smtp->quit();
		}

		$smtp->close();

		return $result;
	}
}
