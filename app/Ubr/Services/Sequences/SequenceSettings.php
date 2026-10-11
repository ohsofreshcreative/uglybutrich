<?php

namespace App\Ubr\Services\Sequences;

use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Infrastructure\Repositories\OrganizationRepository;

/**
 * Prompt 10.5 — ustawienia modułu Sequences organizacji (`ubr_organizations.settings.sequences`).
 *
 * Automatyczna wysyłka jest DOMYŚLNIE WYŁĄCZONA. Włącza ją właściciel / admin po spełnieniu listy kontrolnej
 * (skrzynka połączona i przetestowana, działający worker, przetestowany harmonogram w trybie testowym, potwierdzenie
 * zabezpieczeń przed duplikatami). Stała `UBR_SEQUENCES_SENDING=false` w wp-config.php wyłącza wysyłkę zawsze
 * (wyłącznik awaryjny). Sekwencje w trybie testowym przetwarza worker także przy wyłączonej wysyłce (nic nie wychodzi).
 */
class SequenceSettings
{
	public const DEFAULTS = [
		'sending_enabled' => false,
		'window' => SendWindow::DEFAULT,
		// Górne limity, których nie może przekroczyć ustawienie skrzynki (zarządza admin).
		'caps' => ['per_day' => 100, 'per_hour' => 25],
		// Domyślne (ostrożne) limity nowej skrzynki.
		'mailbox_defaults' => ['per_day' => 30, 'per_hour' => 8, 'min_interval_minutes' => 4],
		'append_unsubscribe' => true,
		'unsubscribe_text' => 'Jeśli nie chcesz otrzymywać kolejnych wiadomości, kliknij: {{unsubscribe_link}}',
		'duplicates_confirmed_at' => null,
		'enabled_by' => null,
		'enabled_at' => null,
	];

	public function __construct(private OrganizationRepository $orgs)
	{
	}

	/** @return array<string, mixed> */
	public function get(int $organizationId): array
	{
		$org = $this->orgs->find($organizationId);
		$stored = (array) ($org['settings']['sequences'] ?? []);
		$s = array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
		$s['window'] = SendWindow::normalize((array) $s['window']);
		$s['caps'] = ['per_day' => max(1, min(2000, (int) ($s['caps']['per_day'] ?? 100))), 'per_hour' => max(1, min(500, (int) ($s['caps']['per_hour'] ?? 25)))];
		$s['mailbox_defaults'] = array_replace(self::DEFAULTS['mailbox_defaults'], (array) $s['mailbox_defaults']);

		return $s;
	}

	/** @param array<string, mixed> $changes */
	public function save(int $organizationId, array $changes): array
	{
		$org = $this->orgs->find($organizationId);
		$settings = (array) ($org['settings'] ?? []);
		$settings['sequences'] = array_intersect_key(array_replace($this->get($organizationId), $changes), self::DEFAULTS);
		$this->orgs->update($organizationId, ['settings' => $settings]);

		return $this->get($organizationId);
	}

	public static function killSwitchOff(): bool
	{
		return defined('UBR_SEQUENCES_SENDING') && ! constant('UBR_SEQUENCES_SENDING');
	}

	public function sendingEnabled(int $organizationId): bool
	{
		return ! self::killSwitchOff() && (bool) apply_filters('ubr/sequences/sending_enabled', (bool) $this->get($organizationId)['sending_enabled'], $organizationId);
	}
}
