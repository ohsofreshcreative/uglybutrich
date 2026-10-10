<?php

namespace App\Ubr\Infrastructure\Queue;

/**
 * Asynchroniczny worker oparty o nieblokujące żądanie loopback do admin-ajax.php
 * (ten sam mechanizm co WP Background Processing / spawn_cron, ale bez ograniczeń WP-Cron:
 * brak blokady `doing_cron`, brak limitu "raz na 60 s", brak zależności od ruchu na stronie).
 *
 * Każdy krok zadania po zakończeniu zleca kolejny krok kolejnym żądaniem loopback,
 * więc zadanie wykonuje się do końca bez otwartej karty przeglądarki.
 * Żądania są podpisane HMAC (sól WordPressa) i ważne 10 minut.
 */
class AsyncWorker
{
	public const ACTION = 'ubr_worker';

	public const PING_ACTION = 'ubr_worker_ping';

	public const HEARTBEAT_OPTION = 'ubr_worker_heartbeat';

	public const LAST_DISPATCH_OPTION = 'ubr_worker_last_dispatch';

	public function url(): string
	{
		return (string) apply_filters('ubr/queue/worker_url', admin_url('admin-ajax.php'));
	}

	public function sign(int $searchId, int $expires): string
	{
		return hash_hmac('sha256', self::ACTION . '|' . $searchId . '|' . $expires, wp_salt('auth'));
	}

	public function verify(int $searchId, int $expires, string $token): bool
	{
		return $expires >= time() && hash_equals($this->sign($searchId, $expires), $token);
	}

	/**
	 * Zleca krok zadania w osobnym procesie PHP (nie czeka na odpowiedź).
	 */
	public function dispatch(int $searchId): bool
	{
		if (! apply_filters('ubr/queue/async', true)) {
			return false;
		}

		$expires = time() + 600;
		$response = wp_remote_post(add_query_arg('action', self::ACTION, $this->url()), [
			'timeout' => 0.5,
			'blocking' => false,
			'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
			'body' => ['search_id' => $searchId, 'expires' => $expires, 'token' => $this->sign($searchId, $expires)],
			'cookies' => [],
			'headers' => ['Cache-Control' => 'no-cache'],
		]);

		update_option(self::LAST_DISPATCH_OPTION, time(), false);

		if (is_wp_error($response)) {
			error_log('[ubr] Async worker dispatch failed: ' . $response->get_error_message());

			return false;
		}

		return true;
	}

	public const JOB_ACTION = 'ubr_job_worker';

	public function signJob(string $job, int $id, int $expires): string
	{
		return hash_hmac('sha256', self::JOB_ACTION . '|' . $job . '|' . $id . '|' . $expires, wp_salt('auth'));
	}

	public function verifyJob(string $job, int $id, int $expires, string $token): bool
	{
		return $expires >= time() && hash_equals($this->signJob($job, $id, $expires), $token);
	}

	/**
	 * Zleca zadanie innego typu niż krok wyszukiwania (np. 'verify' — kolejka weryfikacji firm).
	 */
	public function dispatchJob(string $job, int $id): bool
	{
		if (! apply_filters('ubr/queue/async', true)) {
			return false;
		}

		$expires = time() + 600;
		$response = wp_remote_post(add_query_arg('action', self::JOB_ACTION, $this->url()), [
			'timeout' => 0.5,
			'blocking' => false,
			'sslverify' => (bool) apply_filters('https_local_ssl_verify', false),
			'body' => ['job' => $job, 'id' => $id, 'expires' => $expires, 'token' => $this->signJob($job, $id, $expires)],
			'cookies' => [],
			'headers' => ['Cache-Control' => 'no-cache'],
		]);

		update_option(self::LAST_DISPATCH_OPTION, time(), false);

		return ! is_wp_error($response);
	}

	/**
	 * Znacznik życia workera — pozwala wykryć, że loopback nie działa.
	 */
	public function heartbeat(): void
	{
		update_option(self::HEARTBEAT_OPTION, time(), false);
	}

	public function lastHeartbeat(): int
	{
		return (int) get_option(self::HEARTBEAT_OPTION, 0);
	}

	public function lastDispatch(): int
	{
		return (int) get_option(self::LAST_DISPATCH_OPTION, 0);
	}

	/**
	 * Loopback uznajemy za niedziałający, jeśli zlecenie było > 45 s temu, a worker nie odpowiedział od tamtej pory.
	 */
	public function looksBroken(): bool
	{
		$dispatch = $this->lastDispatch();

		return $dispatch > 0 && time() - $dispatch > 45 && $this->lastHeartbeat() < $dispatch;
	}

	/**
	 * Blokujący test loopbacku (WP-CLI `wp ubr doctor`).
	 *
	 * @return array{ok: bool, http_code: ?int, ms: int, error: ?string, url: string}
	 */
	public function ping(): array
	{
		$url = add_query_arg('action', self::PING_ACTION, $this->url());
		$start = microtime(true);
		$response = wp_remote_post($url, ['timeout' => 15, 'sslverify' => false, 'body' => ['t' => time()]]);
		$ms = (int) round((microtime(true) - $start) * 1000);

		if (is_wp_error($response)) {
			return ['ok' => false, 'http_code' => null, 'ms' => $ms, 'error' => $response->get_error_message(), 'url' => $url];
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = trim((string) wp_remote_retrieve_body($response));

		return ['ok' => $code === 200 && $body === 'ubr-pong', 'http_code' => $code, 'ms' => $ms, 'error' => $body === 'ubr-pong' ? null : mb_substr($body, 0, 200), 'url' => $url];
	}
}
