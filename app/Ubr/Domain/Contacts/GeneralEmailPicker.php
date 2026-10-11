<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Prompt 10.4.3 — ogólny e-mail firmowy (biuro@, kontakt@, info@ …) z adresów opublikowanych na stronie firmy.
 *
 * - tylko adresy w domenie firmy (ta sama domena, subdomena lub ta sama nazwa z inną końcówką),
 * - nigdy adresy osobowe (jan.kowalski@) ani techniczne / przeznaczone do innych celów (noreply, rodo, faktury,
 *   rekrutacja, newsletter …),
 * - kolejność: adresy ogólne (biuro, kontakt, info …), potem działowe (handlowy, sprzedaż, marketing …).
 */
final class GeneralEmailPicker
{
	public const PREFERRED = ['biuro', 'kontakt', 'info', 'office', 'sekretariat', 'contact', 'hello', 'recepcja', 'reception', 'centrala', 'firma', 'company', 'mail', 'email', 'poczta', 'bok', 'obsluga'];

	public const DEPARTMENT = ['handlowy', 'handel', 'sprzedaz', 'sales', 'oferty', 'oferta', 'zamowienia', 'order', 'orders', 'export', 'eksport', 'marketing', 'zarzad', 'dyrekcja', 'management', 'board'];

	public const EXCLUDED = ['noreply', 'donotreply', 'newsletter', 'rodo', 'iod', 'dpo', 'gdpr', 'hr', 'kadry', 'rekrutacja', 'kariera', 'praca', 'jobs', 'career', 'careers', 'faktury', 'invoice', 'invoices', 'billing', 'ksiegowosc', 'webmaster', 'admin', 'administrator', 'reklamacje', 'abuse', 'postmaster', 'it'];

	/**
	 * @param list<string> $emails
	 */
	public static function pick(array $emails, string $companyDomain): ?string
	{
		$best = null;
		$bestRank = PHP_INT_MAX;

		foreach ($emails as $email) {
			$email = strtolower(trim((string) $email));

			if (! is_email($email) || ! self::sameDomain($email, $companyDomain)) {
				continue;
			}

			$rank = self::rank($email);

			if ($rank !== null && $rank < $bestRank) {
				$best = $email;
				$bestRank = $rank;
			}
		}

		return $best;
	}

	/** Pozycja adresu (niższa = lepsza) albo null, gdy adres nie jest ogólnym adresem firmy. */
	public static function rank(string $email): ?int
	{
		$local = PersonName::fold(strstr(strtolower($email), '@', true) ?: '');
		$local = preg_replace('/\+.*$/', '', $local) ?? $local;
		$word = (string) (preg_split('/[\d._\-]/', $local)[0] ?? '');

		if (in_array($word, self::EXCLUDED, true) || in_array(str_replace(['-', '_', '.'], '', $local), ['noreply', 'donotreply'], true)) {
			return null;
		}

		if (($i = array_search($word, self::PREFERRED, true)) !== false) {
			return (int) $i;
		}

		if (($i = array_search($word, self::DEPARTMENT, true)) !== false) {
			return 100 + (int) $i;
		}

		// Pozostałe adresy ogólne z katalogu PeopleExtractor (np. serwis@, kontakt.firma@) — na końcu; osobowe odrzucone.
		return PeopleExtractor::isGenericEmail($email) && ! in_array($word, self::EXCLUDED, true) ? 200 : null;
	}

	public static function sameDomain(string $email, string $companyDomain): bool
	{
		$domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
		$base = preg_replace('/^www\./', '', strtolower($companyDomain)) ?? '';

		if ($domain === '' || $base === '') {
			return false;
		}

		if ($domain === $base || str_ends_with($domain, '.' . $base)) {
			return true;
		}

		// Ta sama nazwa w innej końcówce (firma.pl ↔ firma.com.pl ↔ firma.eu).
		$name = fn (string $d) => (string) preg_replace('/\.(com|net|org|co|biz)?\.?[a-z]{2,3}$/', '', $d);

		return $name($domain) !== '' && $name($domain) === $name($base);
	}
}
