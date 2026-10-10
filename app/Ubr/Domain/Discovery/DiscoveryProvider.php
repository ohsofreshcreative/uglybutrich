<?php

namespace App\Ubr\Domain\Discovery;

/**
 * Dostawca danych Discovery. Każdy dostawca zwraca wyłącznie dane faktycznie obecne w źródle.
 */
interface DiscoveryProvider
{
	public function key(): string;

	public function label(): string;

	/**
	 * Czy dostawca jest skonfigurowany (np. ma klucz API).
	 */
	public function isAvailable(): bool;

	/**
	 * Informacja dla użytkownika: zasięg, licencja, czego wymaga.
	 */
	public function description(): string;

	/**
	 * Jedna porcja wyników. `cursor` = stan paginacji zwrócony poprzednio (pusta tablica na start).
	 *
	 * @param array<string, mixed> $cursor
	 *
	 * @throws ProviderException
	 */
	public function fetch(DiscoveryCriteria $criteria, array $cursor): ProviderPage;
}
