<?php

namespace App\Ubr\Infrastructure\Database;

use wpdb;

/**
 * Cienka warstwa nad $wpdb: bezpieczne zapytania (prepare), wyjątki zamiast cichych błędów.
 */
class Db
{
	public function __construct(private wpdb $wpdb)
	{
	}

	public function wpdb(): wpdb
	{
		return $this->wpdb;
	}

	public function table(string $table): string
	{
		return $this->wpdb->prefix . $table;
	}

	public static function now(): string
	{
		return gmdate('Y-m-d H:i:s');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert(string $table, array $data): int
	{
		$result = $this->wpdb->insert($this->table($table), $data);

		if ($result === false) {
			throw new DatabaseException($this->wpdb->last_error ?: 'Insert failed', $this->isDuplicate());
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update(string $table, array $data, array $where): int
	{
		$result = $this->wpdb->update($this->table($table), $data, $where);

		if ($result === false) {
			throw new DatabaseException($this->wpdb->last_error ?: 'Update failed', $this->isDuplicate());
		}

		return (int) $result;
	}

	/**
	 * @param array<string, mixed> $where
	 */
	public function delete(string $table, array $where): int
	{
		$result = $this->wpdb->delete($this->table($table), $where);

		if ($result === false) {
			throw new DatabaseException($this->wpdb->last_error ?: 'Delete failed');
		}

		return (int) $result;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function row(string $sql, mixed ...$args): ?array
	{
		$row = $this->wpdb->get_row($this->prepare($sql, $args), ARRAY_A);
		$this->assertNoError();

		return $row ?: null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function rows(string $sql, mixed ...$args): array
	{
		$rows = $this->wpdb->get_results($this->prepare($sql, $args), ARRAY_A);
		$this->assertNoError();

		return $rows ?: [];
	}

	public function value(string $sql, mixed ...$args): mixed
	{
		$value = $this->wpdb->get_var($this->prepare($sql, $args));
		$this->assertNoError();

		return $value;
	}

	/**
	 * @return list<mixed>
	 */
	public function column(string $sql, mixed ...$args): array
	{
		$values = $this->wpdb->get_col($this->prepare($sql, $args));
		$this->assertNoError();

		return $values ?: [];
	}

	public function execute(string $sql, mixed ...$args): int
	{
		$result = $this->wpdb->query($this->prepare($sql, $args));

		if ($result === false) {
			throw new DatabaseException($this->wpdb->last_error ?: 'Query failed', $this->isDuplicate());
		}

		return (int) $result;
	}

	/**
	 * Placeholdery dla listy wartości IN (...).
	 *
	 * @param list<int|string> $values
	 */
	public static function placeholders(array $values, string $type = '%d'): string
	{
		return implode(',', array_fill(0, max(1, count($values)), $type));
	}

	/**
	 * @param list<mixed> $args
	 */
	private function prepare(string $sql, array $args): string
	{
		if ($args === []) {
			return $sql;
		}

		if (count($args) === 1 && is_array($args[0])) {
			$args = $args[0];
		}

		return $this->wpdb->prepare($sql, $args);
	}

	private function assertNoError(): void
	{
		if ($this->wpdb->last_error !== '') {
			throw new DatabaseException($this->wpdb->last_error);
		}
	}

	private function isDuplicate(): bool
	{
		return str_contains(strtolower($this->wpdb->last_error), 'duplicate');
	}
}
