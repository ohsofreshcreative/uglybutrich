<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 10.5 — rezygnacja odbiorcy z kolejnych wiadomości (link w stopce + nagłówek List-Unsubscribe, RFC 8058).
 *
 * Link `/?ubr_unsubscribe=<org>.<sekwencja>.<podpis HMAC>` — bez adresu e-mail w URL. GET pokazuje stronę z przyciskiem
 * potwierdzenia (skanery linków nie wypisują odbiorcy przypadkiem); POST (także „one-click” z programu pocztowego)
 * trwale blokuje adres w organizacji i anuluje jego aktywne sekwencje.
 */
class UnsubscribeService
{
	public const QUERY = 'ubr_unsubscribe';

	public function __construct(private Db $db)
	{
	}

	public function token(int $organizationId, int $sequenceId): string
	{
		return $organizationId . '.' . $sequenceId . '.' . $this->sign($organizationId, $sequenceId);
	}

	public function url(int $organizationId, int $sequenceId): string
	{
		return add_query_arg(self::QUERY, $this->token($organizationId, $sequenceId), home_url('/'));
	}

	/** @return array{organization_id: int, sequence: array<string, mixed>}|null */
	public function verify(string $token): ?array
	{
		if (! preg_match('/^(\d{1,10})\.(\d{1,12})\.([a-f0-9]{32})$/', $token, $m)) {
			return null;
		}

		if (! hash_equals($this->sign((int) $m[1], (int) $m[2]), $m[3])) {
			return null;
		}

		$s = $this->db->row("SELECT * FROM {$this->db->table(Tables::SEQ_SEQUENCES)} WHERE id = %d AND organization_id = %d", (int) $m[2], (int) $m[1]);

		return $s && ! empty($s['recipient_email']) ? ['organization_id' => (int) $m[1], 'sequence' => $s] : null;
	}

	/** Obsługa żądania (hook `init`) — strona publiczna bez logowania. */
	public function handleRequest(): void
	{
		if (empty($_GET[self::QUERY])) {
			return;
		}

		$token = sanitize_text_field(wp_unslash((string) $_GET[self::QUERY]));
		$data = $this->verify($token);
		nocache_headers();
		header('X-Robots-Tag: noindex, nofollow');
		header('Referrer-Policy: no-referrer');

		if (! $data) {
			status_header(404);
			$this->page('Link nieważny', 'Ten link rezygnacji jest nieprawidłowy lub wygasł. Odpowiedz na wiadomość, a nadawca usunie Twój adres ręcznie.');
		}

		$email = (string) $data['sequence']['recipient_email'];

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			app(SequenceService::class)->suppress($data['organization_id'], $email, 'unsubscribed', null, (int) $data['sequence']['id'], 'Rezygnacja przez link w wiadomości.');
			status_header(200);
			$this->page('Wypisano', 'Nie wyślemy więcej wiadomości na adres ' . $this->mask($email) . '.');
		}

		$this->page('Rezygnacja z wiadomości', 'Czy na pewno nie chcesz otrzymywać kolejnych wiadomości na adres ' . $this->mask($email) . '?', true, $token);
	}

	private function page(string $title, string $text, bool $form = false, string $token = ''): never
	{
		header('Content-Type: text/html; charset=utf-8');
		$action = esc_url(add_query_arg(self::QUERY, $token, home_url('/')));
		echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html($title) . '</title>'
			. '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f5f1;color:#1b1b1b;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}main{background:#fff;border-radius:16px;padding:32px;max-width:440px;box-shadow:0 8px 30px rgba(0,0,0,.08)}h1{font-size:20px;margin:0 0 12px}p{line-height:1.5}button{background:#1b1b1b;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-size:15px;cursor:pointer}</style></head><body><main>'
			. '<h1>' . esc_html($title) . '</h1><p>' . esc_html($text) . '</p>'
			. ($form ? '<form method="post" action="' . $action . '"><button type="submit">Tak, nie wysyłaj więcej wiadomości</button></form>' : '')
			. '</main></body></html>';
		exit;
	}

	private function mask(string $email): string
	{
		[$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

		return mb_substr($local, 0, 2) . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
	}

	private function sign(int $organizationId, int $sequenceId): string
	{
		return substr(hash_hmac('sha256', 'ubr-unsubscribe|' . $organizationId . '|' . $sequenceId, wp_salt('auth') . 'ubr-seq'), 0, 32);
	}
}
