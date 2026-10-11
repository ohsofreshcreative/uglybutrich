<?php

namespace App\Ubr\Domain\Pipeline;

/**
 * Słowniki Sales Pipeline: statusy, priorytety, kwalifikacja, typy aktywności, statusy follow-upów.
 *
 * Statusy są domyślnym zestawem; filtr `ubr/pipeline/statuses` (z organization_id) jest miejscem na przyszłą
 * konfigurację per organizacja — bez kreatora CRM. Klucze są zapisywane w bazie małymi literami.
 */
final class PipelineCatalog
{
	public const STATUSES = [
		'new' => ['label' => 'Nowy', 'description' => 'Dodany do Prospects, jeszcze nieopracowany.', 'closed' => false],
		'qualified' => ['label' => 'Zakwalifikowany', 'description' => 'Sprawdzony i uznany za potencjalnego klienta.', 'closed' => false],
		'to_contact' => ['label' => 'Do kontaktu', 'description' => 'Wybrano osobę lub kanał kontaktu.', 'closed' => false],
		'mailing' => ['label' => 'Mailing', 'description' => 'Firma przeznaczona do sekwencji e-mail (wysyłka dopiero po zatwierdzeniu sekwencji).', 'closed' => false],
		'contacted' => ['label' => 'Skontaktowano', 'description' => 'Wykonano pierwszą próbę kontaktu.', 'closed' => false],
		'follow_up' => ['label' => 'Ponowienie', 'description' => 'Potrzebne ponowienie kontaktu.', 'closed' => false],
		'in_discussion' => ['label' => 'W rozmowach', 'description' => 'Trwa rozmowa z firmą.', 'closed' => false],
		'proposal_sent' => ['label' => 'Oferta wysłana', 'description' => 'Wysłano ofertę.', 'closed' => false],
		'won' => ['label' => 'Wygrany', 'description' => 'Pozyskano klienta.', 'closed' => true],
		'lost' => ['label' => 'Utracony', 'description' => 'Szansa sprzedażowa utracona.', 'closed' => true],
		'not_a_fit' => ['label' => 'Nie pasuje', 'description' => 'Firma nie pasuje do oferty.', 'closed' => true],
	];

	/** Statusy wymagające (zalecające) powodu. */
	public const REASON_STATUSES = ['lost', 'not_a_fit'];

	public const PRIORITIES = ['low' => 'Niski', 'medium' => 'Średni', 'high' => 'Wysoki', 'urgent' => 'Pilny'];

	public const QUALIFICATIONS = [
		'good_candidate' => 'Dobry kandydat',
		'needs_review' => 'Wymaga weryfikacji',
		'low_potential' => 'Niski potencjał',
		'not_a_fit' => 'Nie pasuje',
	];

	public const ACTIVITY_TYPES = [
		'call' => 'Telefon',
		'email' => 'E-mail',
		'linkedin' => 'LinkedIn',
		'meeting' => 'Spotkanie',
		'follow_up' => 'Ponowienie kontaktu',
		'note' => 'Notatka',
		'other' => 'Inne',
	];

	public const FOLLOWUP_STATUSES = ['open' => 'Otwarte', 'completed' => 'Wykonane', 'cancelled' => 'Anulowane'];

	/** Wynik rozmowy telefonicznej — zapisywany ŚWIADOMIE przez użytkownika (kliknięcie tel: niczego nie zapisuje). */
	public const CALL_OUTCOMES = [
		'no_answer' => 'Nie odebrał',
		'call_back' => 'Oddzwonić później',
		'not_interested' => 'Nie zainteresowany',
		'interested' => 'Zainteresowany',
		'meeting_booked' => 'Umówiono spotkanie',
		'wrong_number' => 'Błędny numer',
	];

	/** Aktywności będące kontaktem z firmą (ustawiają „ostatni kontakt”). */
	public const CONTACT_ACTIVITY_TYPES = ['call', 'email', 'linkedin', 'meeting'];

	/** Etapy, w których kontakt z firmą jest prowadzony (status przypisania IN_PROGRESS). */
	public const IN_PROGRESS_STATUSES = ['contacted', 'follow_up', 'in_discussion', 'proposal_sent'];

	public static function outcomeLabel(?string $outcome): ?string
	{
		if ($outcome === null || $outcome === '') {
			return null;
		}

		return self::CALL_OUTCOMES[$outcome] ?? self::ACTIVITY_TYPES[$outcome] ?? $outcome;
	}

	/**
	 * @return array<string, array{label: string, description: string, closed: bool}>
	 */
	public static function statuses(?int $organizationId = null): array
	{
		$statuses = (array) apply_filters('ubr/pipeline/statuses', self::STATUSES, $organizationId);

		// Konfiguracja może zmienić etykiety / kolejność, ale nie usunąć statusów używanych w danych.
		return array_intersect_key($statuses + self::STATUSES, self::STATUSES);
	}

	public static function isStatus(string $status): bool
	{
		return isset(self::STATUSES[$status]);
	}

	public static function statusLabel(?string $status): string
	{
		return self::STATUSES[(string) $status]['label'] ?? (string) $status;
	}

	/**
	 * Podpowiedź kwalifikacji z istniejących wyników (bez nowego scoringu). Decyzja zawsze należy do użytkownika —
	 * niski Opportunity Score nie blokuje dodania ani kwalifikacji (bez AI Vision ocena wizualna nie jest pełna).
	 *
	 * @param array<string, mixed> $company
	 * @return array{key: string, label: string, reasons: list<string>}
	 */
	public static function suggestQualification(array $company, ?int $relevance): array
	{
		$reasons = [];
		$opp = isset($company['opportunity_score']) && $company['opportunity_score'] !== null ? (int) $company['opportunity_score'] : null;
		$cps = isset($company['top_contact_score']) && $company['top_contact_score'] !== null ? (int) $company['top_contact_score'] : null;
		$status = (string) ($company['verification_status'] ?? '');

		if (in_array($status, ['inactive', 'suspended'], true) || ($company['domain_status'] ?? '') === 'mismatch') {
			return ['key' => 'not_a_fit', 'label' => self::QUALIFICATIONS['not_a_fit'], 'reasons' => ['Firma nieaktywna / zawieszona albo domena niezgodna z firmą.']];
		}

		$missing = [];

		if ($opp === null) {
			$missing[] = 'brak analizy strony';
		}

		if ($cps === null) {
			$missing[] = 'brak decydenta';
		}

		if ($status !== 'verified') {
			$missing[] = 'firma niezweryfikowana';
		}

		if ($missing) {
			return ['key' => 'needs_review', 'label' => self::QUALIFICATIONS['needs_review'], 'reasons' => ['Do uzupełnienia: ' . implode(', ', $missing) . '.']];
		}

		$reasons[] = sprintf('Trafność %s, Opportunity %d, Contact Priority %d.', $relevance ?? '—', $opp, $cps);

		if (($relevance === null || $relevance >= 60) && $opp >= 40 && $cps >= 50) {
			return ['key' => 'good_candidate', 'label' => self::QUALIFICATIONS['good_candidate'], 'reasons' => $reasons];
		}

		$reasons[] = 'Opportunity Score ma ograniczone pokrycie (bez oceny wizualnej) — oceń stronę ręcznie przed odrzuceniem.';

		return ['key' => 'low_potential', 'label' => self::QUALIFICATIONS['low_potential'], 'reasons' => $reasons];
	}
}
