<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;

/**
 * Migracja schematu. Musi być idempotentna (CREATE TABLE IF NOT EXISTS, sprawdzanie kolumn)
 * i nigdy nie może kasować danych.
 */
interface Migration
{
	public function version(): int;

	public function description(): string;

	public function up(Db $db): void;
}
