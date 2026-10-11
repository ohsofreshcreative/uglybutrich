<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Tenancy\TenantContext;
use App\Ubr\Support\UbrException;

/** Domyślna implementacja: asystent AI niedostępny (brak płatnych wywołań). */
class DisabledOutreachAssistant implements OutreachAssistant
{
	public function available(): bool
	{
		return false;
	}

	public function suggest(TenantContext $ctx, array $context): array
	{
		throw new UbrException('AI Outreach Assistant nie jest jeszcze włączony (wymaga zgody właściciela na płatne API).', 'ubr_ai_unavailable', 501);
	}
}
