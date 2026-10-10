<?php

namespace App\Ubr\Domain\Verification;

/**
 * Reguły statusu domeny (czyste, deterministyczne). Status domeny jest niezależny od aktywności przedsiębiorstwa.
 *
 * CONFIRMED wymaga dowodu z treści/rejestru, nigdy samego podobieństwa nazwy domeny:
 * - NIP/KRS opublikowany na stronie wskazuje w rejestrze tę firmę, albo
 * - rejestr (KRS/CEIDG) podaje tę stronę jako adres firmy i strona zawiera nazwę firmy, albo
 * - nazwa firmy w tytule / og:site_name / JSON-LD / stopce ORAZ dane kontaktowe na stronie (e-mail w tej domenie lub adres).
 */
final class DomainDecider
{
	/**
	 * @param array<string, mixed>|null $site wynik WebsiteInspector::inspect()
	 * @param string $nameMatch strong|medium|weak|none
	 * @return array{status: string, reasons: list<string>}
	 */
	public static function decide(?string $domain, ?array $site, string $nameMatch, bool $identifierConfirmed, ?string $identifierOtherName, bool $registryWebsiteMatch): array
	{
		if (! $domain) {
			return self::r(DomainStatus::UNAVAILABLE, 'Brak strony WWW w danych firmy.');
		}

		if (! $site || ! $site['reachable']) {
			$why = $site['error'] ?? null ? 'błąd połączenia: ' . $site['error'] : 'HTTP ' . ($site['http_code'] ?? '—');

			return self::r(DomainStatus::UNAVAILABLE, 'Strona nie odpowiada (' . $why . ').');
		}

		$redirect = ! empty($site['redirected_domain']) ? ' Przekierowanie na ' . $site['final_domain'] . '.' : '';

		if (! empty($site['parked'])) {
			return self::r(DomainStatus::MISMATCH, 'Domena zaparkowana / wystawiona na sprzedaż.' . $redirect);
		}

		if (in_array($site['final_domain_type'] ?? 'official', ['directory', 'social', 'marketplace'], true)) {
			return self::r(DomainStatus::MISMATCH, sprintf('Adres prowadzi do serwisu typu %s (%s), nie do własnej strony firmy.', $site['final_domain_type'], $site['final_domain']));
		}

		if ($identifierConfirmed) {
			return self::r(DomainStatus::CONFIRMED, 'Na stronie opublikowany NIP/KRS/REGON, który w rejestrze należy do tej firmy.' . $redirect);
		}

		if ($registryWebsiteMatch && $nameMatch !== 'none') {
			return self::r(DomainStatus::CONFIRMED, 'Rejestr podaje tę stronę jako adres firmy, a strona zawiera jej nazwę.' . $redirect);
		}

		if ($identifierOtherName !== null) {
			return in_array($nameMatch, ['strong', 'medium'], true)
				? self::r(DomainStatus::UNCERTAIN, sprintf('Strona zawiera nazwę firmy, ale publikuje dane innego podmiotu („%s”) — np. spółki matki lub grupy.', $identifierOtherName))
				: self::r(DomainStatus::MISMATCH, sprintf('Strona publikuje dane innego podmiotu („%s”) i nie zawiera nazwy firmy.', $identifierOtherName));
		}

		if (! empty($site['redirected_domain']) && in_array($nameMatch, ['none', 'weak'], true)) {
			return self::r(DomainStatus::MISMATCH, sprintf('Przekierowanie na inną domenę (%s) bez nazwy firmy w treści.', $site['final_domain']));
		}

		if (! empty($site['placeholder'])) {
			return self::r(DomainStatus::UNCERTAIN, 'Strona w budowie / domyślna strona serwera — brak treści do weryfikacji.');
		}

		$contact = ((int) ($site['emails_same_domain'] ?? 0)) > 0 || ! empty($site['postal_codes']);

		if ($nameMatch === 'strong' && $contact) {
			return self::r(DomainStatus::CONFIRMED, 'Nazwa firmy w tytule/stopce/danych strukturalnych strony oraz dane kontaktowe (adres lub e-mail w tej domenie).' . $redirect);
		}

		if ($nameMatch === 'none' && (int) ($site['text_length'] ?? 0) > 800) {
			return self::r(DomainStatus::MISMATCH, 'Treść strony nie zawiera nazwy firmy.' . $redirect);
		}

		return self::r(DomainStatus::UNCERTAIN, match ($nameMatch) {
			'strong' => 'Nazwa firmy na stronie, ale brak danych kontaktowych/identyfikatorów potwierdzających właściciela.',
			'medium' => 'Nazwa firmy w treści strony, ale nie w tytule/stopce; brak NIP/KRS na stronie.',
			'weak' => 'Tylko częściowa zgodność nazwy (pojedyncze słowa) — za mało, by potwierdzić.',
			default => 'Mało treści na stronie — nie można potwierdzić właściciela.',
		} . $redirect);
	}

	/**
	 * @return array{status: string, reasons: list<string>}
	 */
	private static function r(string $status, string $reason): array
	{
		return ['status' => $status, 'reasons' => [trim($reason)]];
	}
}
