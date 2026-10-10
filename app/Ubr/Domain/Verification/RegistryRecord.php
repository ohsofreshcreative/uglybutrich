<?php

namespace App\Ubr\Domain\Verification;

/**
 * Rekord podmiotu z rejestru publicznego (KRS, biała lista VAT, CEIDG, GUS/REGON) — tylko dane firmowe.
 *
 * Nie przechowujemy danych osobowych (właściciele, reprezentanci, PESEL) ani numerów rachunków bankowych.
 */
final class RegistryRecord
{
	public const ACTIVE = 'active';

	public const INACTIVE = 'inactive';

	public const SUSPENDED = 'suspended';

	public const LIQUIDATION = 'liquidation';

	public const BANKRUPTCY = 'bankruptcy';

	public const UNKNOWN = 'unknown';

	public const SOURCE_LABELS = [
		'krs' => 'KRS',
		'vat' => 'Biała lista VAT',
		'ceidg' => 'CEIDG',
		'gus' => 'GUS / REGON',
	];

	/**
	 * @param array<string, mixed> $extra dodatkowe fakty do historii (bez danych osobowych)
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $lookupKey,
		public readonly ?string $name = null,
		public readonly ?string $nip = null,
		public readonly ?string $regon = null,
		public readonly ?string $krs = null,
		public readonly ?string $legalForm = null,
		public readonly string $status = self::UNKNOWN,
		public readonly ?string $statusDetail = null,
		public readonly ?string $street = null,
		public readonly ?string $postalCode = null,
		public readonly ?string $city = null,
		public readonly ?string $region = null,
		public readonly ?string $pkdMain = null,
		public readonly ?string $pkdDescription = null,
		public readonly ?string $website = null,
		public readonly ?string $registeredAt = null,
		public readonly ?string $sourceUrl = null,
		public readonly array $extra = [],
	) {
	}

	public function label(): string
	{
		return self::SOURCE_LABELS[$this->source] ?? $this->source;
	}

	/**
	 * Czy rejestr pozwala ocenić aktywność przedsiębiorstwa (a nie tylko np. status VAT).
	 */
	public function isActivityRegistry(): bool
	{
		return in_array($this->source, ['krs', 'ceidg', 'gus'], true);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return array_filter([
			'source' => $this->source,
			'lookup' => $this->lookupKey,
			'name' => $this->name,
			'nip' => $this->nip,
			'regon' => $this->regon,
			'krs' => $this->krs,
			'legal_form' => $this->legalForm,
			'status' => $this->status,
			'status_detail' => $this->statusDetail,
			'street' => $this->street,
			'postal_code' => $this->postalCode,
			'city' => $this->city,
			'region' => $this->region,
			'pkd_main' => $this->pkdMain,
			'pkd_description' => $this->pkdDescription,
			'website' => $this->website,
			'registered_at' => $this->registeredAt,
			'source_url' => $this->sourceUrl,
		] + $this->extra, fn ($v) => $v !== null && $v !== '' && $v !== []);
	}
}
