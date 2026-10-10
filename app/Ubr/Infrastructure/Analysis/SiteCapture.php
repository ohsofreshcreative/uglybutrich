<?php

namespace App\Ubr\Infrastructure\Analysis;

/**
 * Uruchamia pomocniczy komponent `tools/site-capture` (Node.js + Playwright + Chromium) przez proc_open.
 *
 * - bez powłoki (tablica argumentów), z twardym limitem czasu (zabicie procesu),
 * - zadanie przekazywane plikiem JSON w katalogu tymczasowym, wynik JSON ze stdout,
 * - jedna instancja przeglądarki naraz (kontroluje to kolejka analiz),
 * - konfiguracja: `UBR_NODE_BINARY`, `UBR_CHROME_PATH` (wp-config.php); bez nich — wykrywanie w typowych lokalizacjach.
 */
class SiteCapture
{
	public const TOOL_DIR = 'tools/site-capture';

	public function helperPath(): string
	{
		return get_theme_file_path(self::TOOL_DIR . '/capture.mjs');
	}

	public function nodeBinary(): ?string
	{
		$candidates = [];

		if (defined('UBR_NODE_BINARY') && UBR_NODE_BINARY) {
			$candidates[] = (string) UBR_NODE_BINARY;
		}

		$home = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : (getenv('HOME') ?: null);

		array_push($candidates, '/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node');

		if ($home) {
			$nvm = glob($home . '/.nvm/versions/node/*/bin/node') ?: [];
			usort($nvm, 'version_compare');
			$candidates = array_merge($candidates, array_reverse($nvm), [$home . '/.volta/bin/node', $home . '/.local/share/fnm/aliases/default/bin/node']);
		}

		foreach (apply_filters('ubr/capture/node_candidates', $candidates) as $path) {
			if ($path && @is_file($path) && @is_executable($path)) {
				return $path;
			}
		}

		return null;
	}

	public function chromePath(): ?string
	{
		return defined('UBR_CHROME_PATH') && UBR_CHROME_PATH ? (string) UBR_CHROME_PATH : null;
	}

	public function dependenciesInstalled(): bool
	{
		return is_dir(get_theme_file_path(self::TOOL_DIR . '/node_modules/playwright-core'));
	}

	/**
	 * Diagnostyka środowiska (Settings → Website Analyzer). Uruchamia `capture.mjs --check` (bez przeglądarki).
	 *
	 * @return array<string, mixed>
	 */
	public function check(): array
	{
		$node = $this->nodeBinary();
		$info = [
			'proc_open' => function_exists('proc_open'),
			'node' => $node ? basename(dirname(dirname($node))) . '/' . basename($node) : null,
			'node_found' => (bool) $node,
			'dependencies' => $this->dependenciesInstalled(),
			'node_version' => null,
			'playwright' => null,
			'axe' => null,
			'browser_found' => false,
			'ready' => false,
			'error' => null,
		];

		if (! $info['proc_open']) {
			$info['error'] = 'PHP: funkcja proc_open jest wyłączona.';

			return $info;
		}

		if (! $node) {
			$info['error'] = 'Nie znaleziono Node.js — ustaw stałą UBR_NODE_BINARY w wp-config.php (np. wynik `which node`).';

			return $info;
		}

		if (! $info['dependencies']) {
			$info['error'] = 'Brak zależności komponentu — uruchom `npm install` w katalogu tools/site-capture motywu.';

			return $info;
		}

		$run = $this->exec([$node, $this->helperPath(), '--check'], 20);
		$data = json_decode($run['stdout'], true);

		if (! is_array($data)) {
			$info['error'] = 'Komponent nie odpowiedział poprawnie' . ($run['timed_out'] ? ' (timeout).' : '.') . ' ' . mb_substr(trim($run['stderr']), 0, 200);

			return $info;
		}

		$info['node_version'] = $data['node'] ?? null;
		$info['playwright'] = $data['playwright'] ?? null;
		$info['axe'] = $data['axe'] ?? null;
		$info['browser_found'] = ! empty($data['chrome']) || $this->chromePath() !== null;
		$info['browser'] = ! empty($data['chrome']) ? basename((string) $data['chrome']) : null;
		$info['ready'] = $info['browser_found'] && $info['playwright'];

		if (! $info['browser_found']) {
			$info['error'] = 'Nie znaleziono Chrome/Chromium — zainstaluj Google Chrome albo ustaw UBR_CHROME_PATH.';
		}

		return $info;
	}

	/**
	 * @param array<string, mixed> $job url, outDir, prefix, views, timeoutMs, maxBytes
	 * @return array<string, mixed> wynik komponentu (lub opis błędu)
	 */
	public function capture(array $job, int $timeoutSeconds = 100): array
	{
		$node = $this->nodeBinary();

		if (! $node || ! $this->dependenciesInstalled() || ! function_exists('proc_open')) {
			return ['ok' => false, 'unavailable' => true, 'errors' => [['stage' => 'environment', 'message' => 'Komponent przeglądarki niedostępny (Node.js / Playwright) — screenshoty i metryki renderowania pominięte.']]];
		}

		$job += ['executablePath' => $this->chromePath(), 'allowHosts' => []];
		$job['allowHosts'] = array_values((array) apply_filters('ubr/capture/allow_hosts', $job['allowHosts']));
		// Tylko dla testów automatycznych (lokalne strony-fixture); w produkcji filtr nie jest używany.
		$job = (array) apply_filters('ubr/capture/job', $job);
		$tmp = wp_tempnam('ubr-capture');
		file_put_contents($tmp, wp_json_encode($job));
		@chmod($tmp, 0644);

		$command = apply_filters('ubr/capture/command', [$node, $this->helperPath(), $tmp]);
		$run = $this->exec($command, $timeoutSeconds);
		@unlink($tmp);

		$data = json_decode($run['stdout'], true);

		if (! is_array($data)) {
			return ['ok' => false, 'errors' => [['stage' => 'process', 'message' => $run['timed_out'] ? 'Przekroczono limit czasu komponentu przeglądarki.' : 'Komponent przeglądarki zakończył się błędem: ' . mb_substr(trim(preg_replace('#/[^\s:]+/#', '…/', $run['stderr']) ?? ''), 0, 200)]], 'timed_out' => $run['timed_out']];
		}

		return $data + ['timed_out' => $run['timed_out']];
	}

	/**
	 * @param list<string> $command
	 * @return array{stdout: string, stderr: string, code: ?int, timed_out: bool}
	 */
	private function exec(array $command, int $timeoutSeconds): array
	{
		$env = array_filter([
			'PATH' => '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin',
			'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
			'UBR_CHROME_PATH' => $this->chromePath(),
			'PLAYWRIGHT_BROWSERS_PATH' => getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null,
			'TMPDIR' => sys_get_temp_dir(),
		]);

		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, get_theme_file_path(self::TOOL_DIR), $env);

		if (! is_resource($process)) {
			return ['stdout' => '', 'stderr' => 'proc_open failed', 'code' => null, 'timed_out' => false];
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout = '';
		$stderr = '';
		$deadline = microtime(true) + $timeoutSeconds;
		$timedOut = false;

		while (true) {
			$stdout .= (string) stream_get_contents($pipes[1]);
			$stderr .= (string) stream_get_contents($pipes[2]);
			$status = proc_get_status($process);

			if (! $status['running']) {
				break;
			}

			if (microtime(true) > $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				break;
			}

			usleep(100000);
		}

		$stdout .= (string) stream_get_contents($pipes[1]);
		$stderr .= (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);

		return ['stdout' => $stdout, 'stderr' => mb_substr($stderr, 0, 4000), 'code' => $code, 'timed_out' => $timedOut];
	}
}
