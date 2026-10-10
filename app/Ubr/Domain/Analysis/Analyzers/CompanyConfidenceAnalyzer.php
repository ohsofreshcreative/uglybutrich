<?php

namespace App\Ubr\Domain\Analysis\Analyzers;

use App\Ubr\Domain\Analysis\AnalysisContext;
use App\Ubr\Domain\Analysis\Criterion;
use App\Ubr\Domain\Analysis\Finding;

/**
 * Wiarygodność firmy (10 pkt): status w rejestrze, zgodność domeny, aktualność weryfikacji (z modułu 1.2).
 */
final class CompanyConfidenceAnalyzer
{
	/**
	 * @return array{criteria: array<string, array<string, mixed>>, checks: array<string, mixed>, findings: list<array<string, mixed>>}
	 */
	public function analyze(AnalysisContext $ctx): array
	{
		$c = $ctx->company;
		$status = (string) ($c['verification_status'] ?? 'pending');
		$domain = (string) ($c['domain_status'] ?? 'unchecked');
		$criteria = [];
		$findings = [];

		$criteria['d_verification'] = match ($status) {
			'verified' => Criterion::measured(5, 5, 'Firma zweryfikowana w rejestrze' . (! empty($c['verification_source']) ? ' (' . $c['verification_source'] . ')' : '') . '.', ['status: verified']),
			'uncertain' => Criterion::measured(2, 5, 'Firma niepotwierdzona w rejestrze (brak NIP/KRS lub rozbieżności).', ['status: uncertain']),
			'inactive', 'suspended' => Criterion::measured(0, 5, 'Firma ' . ($status === 'inactive' ? 'nieaktywna' : 'zawieszona') . ' w rejestrze.', ['status: ' . $status]),
			default => Criterion::notEvaluated(5, 'Firma jeszcze nie weryfikowana.'),
		};

		if (in_array($status, ['inactive', 'suspended'], true)) {
			$findings[] = Finding::make('company_inactive', 'company', 'critical', $status === 'inactive' ? 'Firma nieaktywna w rejestrze' : 'Działalność zawieszona', 'Rejestr wskazuje, że firma nie prowadzi działalności — nie jest prospektem (Opportunity Score ograniczony do 10).', 'verification_status: ' . $status, 'Pominąć w sprzedaży lub zweryfikować ręcznie.');
		}

		$criteria['d_domain'] = match ($domain) {
			'confirmed' => Criterion::measured(3, 3, 'Domena potwierdzona jako strona firmy.', ['domain_status: confirmed']),
			'uncertain' => Criterion::measured(1, 3, 'Domena niepotwierdzona.', ['domain_status: uncertain']),
			'mismatch', 'unavailable' => Criterion::measured(0, 3, 'Domena niezgodna / niedostępna.', ['domain_status: ' . $domain]),
			default => Criterion::notEvaluated(3, 'Domena jeszcze nie weryfikowana.'),
		};

		$checked = $c['verification_checked_at'] ?? null;

		if ($checked) {
			$days = (int) floor((time() - strtotime($checked . ' UTC')) / DAY_IN_SECONDS);
			$criteria['d_freshness'] = Criterion::measured($days <= 90 ? 2 : ($days <= 365 ? 1 : 0), 2, 'Weryfikacja sprzed ' . $days . ' dni.', ['verification_checked_at: ' . $checked]);
		} else {
			$criteria['d_freshness'] = Criterion::notEvaluated(2, 'Brak weryfikacji.');
		}

		return ['criteria' => $criteria, 'checks' => ['verification_status' => $status, 'domain_status' => $domain], 'findings' => $findings];
	}
}
