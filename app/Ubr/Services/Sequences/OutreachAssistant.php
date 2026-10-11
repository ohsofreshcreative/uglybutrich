<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Tenancy\TenantContext;

/**
 * Prompt 10.5 — przygotowanie pod AI Outreach Assistant („Spersonalizuj z AI”).
 *
 * Przyszła implementacja zaproponuje temat i treść jednego kroku na podstawie ZAPISANYCH danych (analiza strony,
 * Opportunity Score, Website Age, powody redesignu, branża, wielkość) — bez niepotwierdzonych twierdzeń; pracownik
 * zatwierdza lub zmienia propozycję (nic nie jest zapisywane ani wysyłane automatycznie). Płatne wywołania API tylko
 * po zgodzie właściciela. Implementację podmienia się w kontenerze (`app()->bind(OutreachAssistant::class, ...)`).
 */
interface OutreachAssistant
{
	public function available(): bool;

	/**
	 * @param array<string, mixed> $context dane firmy i kroku
	 * @return array{subject: string, body: string, notes: list<string>}
	 */
	public function suggest(TenantContext $ctx, array $context): array;
}
