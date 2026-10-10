<?php

namespace App\Ubr\Infrastructure\Analysis;

/**
 * Prywatny magazyn screenshotów.
 *
 * Domyślnie POZA katalogiem publicznym WordPressa (LocalWP: `app/ubr-private/screenshots`, obok `app/public`),
 * a gdy to niemożliwe — w `wp-content/uploads/ubr-private-{losowy}` z blokadą listowania i dostępu (.htaccess, index.php).
 * Pliki serwuje wyłącznie endpoint z kontrolą uprawnień (admin-ajax `ubr_screenshot`), nazwy plików są
 * generowane przez aplikację (bez danych wejściowych użytkownika). Ścieżki serwera nie trafiają do API.
 */
class ScreenshotStorage
{
	public function baseDir(): string
	{
		$filtered = apply_filters('ubr/screenshots/base_dir', null);

		if (is_string($filtered) && $filtered !== '') {
			return rtrim($filtered, '/');
		}

		if (defined('UBR_PRIVATE_DIR') && UBR_PRIVATE_DIR) {
			return rtrim((string) UBR_PRIVATE_DIR, '/') . '/screenshots';
		}

		$outside = dirname(rtrim(ABSPATH, '/')) . '/ubr-private';

		if ((is_dir($outside) && is_writable($outside)) || (! is_dir($outside) && is_writable(dirname($outside)))) {
			return $outside . '/screenshots';
		}

		$uploads = wp_upload_dir(null, false);
		$suffix = substr(hash_hmac('sha256', 'ubr-private', wp_salt('auth')), 0, 16);

		return rtrim($uploads['basedir'], '/') . '/ubr-private-' . $suffix . '/screenshots';
	}

	public function dirForCompany(int $companyId): string
	{
		$dir = $this->baseDir() . '/' . $companyId;

		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
			$this->protect(dirname($dir));
			$this->protect($dir);
		}

		return $dir;
	}

	/**
	 * Bezpieczna ścieżka pliku screenshotu (tylko nazwy wygenerowane przez aplikację).
	 */
	public function path(int $companyId, string $file): ?string
	{
		if (! preg_match('/^a\d+-[a-f0-9]{8}-(desktop|mobile)\.jpg$/', $file)) {
			return null;
		}

		$path = $this->baseDir() . '/' . $companyId . '/' . $file;

		return is_file($path) ? $path : null;
	}

	public function prefix(int $analysisId): string
	{
		return 'a' . $analysisId . '-' . substr(hash_hmac('sha256', 'shot-' . $analysisId, wp_salt('auth')), 0, 8);
	}

	public function usageBytes(): int
	{
		$total = 0;
		$base = $this->baseDir();

		if (! is_dir($base)) {
			return 0;
		}

		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
			$total += $file->getSize();
		}

		return $total;
	}

	private function protect(string $dir): void
	{
		if (! is_dir($dir)) {
			return;
		}

		if (! file_exists($dir . '/index.php')) {
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
		}

		if (! file_exists($dir . '/.htaccess')) {
			@file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
		}
	}
}
