<?php

namespace App\Ubr\Infrastructure\Database\Migrations;

use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Database\Tables;

/**
 * Prompt 10.4 — wspólna, ograniczona czasowo pamięć podręczna odpowiedzi HTTP stron firm (`ubr_page_cache`).
 *
 * Jeden wiersz = jedna odpowiedź na żądanie GET pod danym adresem (krok przekierowania osobno), wspólna dla
 * weryfikacji, Website Analyzera, wyszukiwania telefonów i Decision Maker Findera. Tylko publiczne strony pobierane
 * anonimowo (bez ciasteczek i nagłówków autoryzacji) — bez danych organizacji. Treść skompresowana (base64 + gzip).
 * Wiersze `origin:` = krótkotrwała informacja „serwer nie odpowiada” (bez ponownych prób przez kilka minut).
 * Tylko nowa tabela; bez zmian istniejących danych.
 */
final class M013PageCache implements Migration
{
	public function version(): int
	{
		return 13;
	}

	public function description(): string
	{
		return 'Shared page cache for company websites (verification, analyzer, phones, decision makers)';
	}

	public function up(Db $db): void
	{
		$c = $db->wpdb()->get_charset_collate();

		$db->execute("CREATE TABLE IF NOT EXISTS {$db->table(Tables::PAGE_CACHE)} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_hash CHAR(40) NOT NULL,
			url VARCHAR(1000) NOT NULL,
			origin VARCHAR(255) NOT NULL,
			http_status SMALLINT UNSIGNED NULL,
			location VARCHAR(1000) NULL,
			headers TEXT NULL,
			body MEDIUMTEXT NULL,
			body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
			byte_limit INT UNSIGNED NOT NULL DEFAULT 0,
			fetch_error VARCHAR(250) NULL,
			fetch_ms INT UNSIGNED NULL,
			fetched_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY url_hash (url_hash),
			KEY expires_at (expires_at),
			KEY origin (origin(191))
		) ENGINE=InnoDB {$c}");
	}
}
