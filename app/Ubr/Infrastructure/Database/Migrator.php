<?php

namespace App\Ubr\Infrastructure\Database;

use App\Ubr\Infrastructure\Database\Migrations\M001InitialSchema;
use App\Ubr\Infrastructure\Database\Migrations\M002ApiUsageAndCompanyFacts;
use App\Ubr\Infrastructure\Database\Migrations\M003CompanyVerification;
use App\Ubr\Infrastructure\Database\Migrations\M004WebsiteAnalysis;
use App\Ubr\Infrastructure\Database\Migrations\M005DecisionMakers;
use App\Ubr\Infrastructure\Database\Migrations\M006SalesPipelineClickUp;
use App\Ubr\Infrastructure\Database\Migrations\M007LeadIntelligence;
use App\Ubr\Infrastructure\Database\Migrations\M008ActionCenter;
use App\Ubr\Infrastructure\Database\Migrations\M009ManualWebsiteReview;
use App\Ubr\Infrastructure\Database\Migrations\M010PhonesOwnership;
use App\Ubr\Infrastructure\Database\Migrations\M011DecisionMakerPhones;
use App\Ubr\Infrastructure\Database\Migrations\M012ApolloContactMatching;
use App\Ubr\Infrastructure\Database\Migrations\Migration;
use Throwable;

/**
 * Wersjonowane, idempotentne migracje. Aktualna wersja w opcji `ubr_db_version`.
 * Nowe migracje dopisuj na końcu listy — nigdy nie zmieniaj już wdrożonych.
 */
class Migrator
{
	public const OPTION = 'ubr_db_version';

	private const LOCK = 'ubr_migration_lock';

	/** Ostatni błąd migracji: {at, version, message} — wstrzymuje ponawianie przy żądaniach WWW. */
	public const ERROR_OPTION = 'ubr_migration_error';

	public function __construct(private Db $db)
	{
	}

	/**
	 * @return list<Migration>
	 */
	public function migrations(): array
	{
		return [
			new M001InitialSchema(),
			new M002ApiUsageAndCompanyFacts(),
			new M003CompanyVerification(),
			new M004WebsiteAnalysis(),
			new M005DecisionMakers(),
			new M006SalesPipelineClickUp(),
			new M007LeadIntelligence(),
			new M008ActionCenter(),
			new M009ManualWebsiteReview(),
			new M010PhonesOwnership(),
			new M011DecisionMakerPhones(),
			new M012ApolloContactMatching(),
		];
	}

	public function latestVersion(): int
	{
		return max(array_map(fn (Migration $m) => $m->version(), $this->migrations()));
	}

	public function currentVersion(): int
	{
		return (int) get_option(self::OPTION, 0);
	}

	public function needsMigration(): bool
	{
		return $this->currentVersion() < $this->latestVersion();
	}

	/**
	 * @return list<int> zastosowane wersje
	 */
	public function migrate(): array
	{
		if (! $this->needsMigration() || ! $this->acquireLock()) {
			return [];
		}

		$applied = [];
		$version = null;

		try {
			// Wersja czytana z bazy PO uzyskaniu blokady (nie z cache opcji tego żądania) — inny proces mógł
			// właśnie skończyć migrację; bez tego ostatnia migracja wykonywałaby się drugi raz.
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete(self::OPTION, 'options');

			foreach ($this->migrations() as $migration) {
				if ($migration->version() <= $this->currentVersion()) {
					continue;
				}

				$version = $migration->version();
				$migration->up($this->db);
				update_option(self::OPTION, $migration->version(), true);
				$applied[] = $migration->version();
			}

			delete_option(self::ERROR_OPTION);
		} catch (Throwable $e) {
			error_log('[ubr] Migration failed: ' . $e->getMessage());
			update_option(self::ERROR_OPTION, ['at' => time(), 'version' => $version, 'message' => mb_substr($e->getMessage(), 0, 500)], false);

			throw $e;
		} finally {
			$this->releaseLock();
		}

		if ($applied !== []) {
			do_action('ubr/migrated', $applied);
		}

		return $applied;
	}

	/**
	 * @return array<string, bool>
	 */
	public function tableStatus(): array
	{
		$status = [];

		foreach (Tables::all() as $table) {
			$name = $this->db->table($table);
			$status[$table] = $this->db->value('SHOW TABLES LIKE %s', $name) === $name;
		}

		return $status;
	}

	/**
	 * @return array{at: int, version: ?int, message: string}|null
	 */
	public function lastError(): ?array
	{
		$e = get_option(self::ERROR_OPTION, null);

		return is_array($e) && isset($e['at']) ? ['at' => (int) $e['at'], 'version' => isset($e['version']) ? (int) $e['version'] : null, 'message' => (string) ($e['message'] ?? '')] : null;
	}

	/**
	 * Blokada na poziomie MySQL (GET_LOCK) — atomowa i zwalniana automatycznie, gdy proces zginie (koniec połączenia).
	 * Blokada w opcji (add_option = upsert) pozwalała dwóm równoległym żądaniom „zdobyć” ją jednocześnie.
	 */
	private function acquireLock(): bool
	{
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName())) === 1;
	}

	private function releaseLock(): void
	{
		global $wpdb;

		$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
		delete_option(self::LOCK); // pozostałość po starszej wersji blokady
	}

	private function lockName(): string
	{
		global $wpdb;

		return substr(DB_NAME . '.' . $wpdb->prefix . self::LOCK, 0, 64);
	}
}
