<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Services\ProspectService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;

class CompanyController
{
	public function __construct(private TenantResolver $tenants, private ProspectService $prospects)
	{
	}

	public function show(WP_REST_Request $request): array
	{
		return $this->prospects->company($this->tenants->require('companies.view'), (int) $request['id']);
	}

	/**
	 * Ręczna ponowna weryfikacja (kolejka w tle — odpowiedź natychmiast, wynik w profilu po chwili).
	 */
	public function verify(WP_REST_Request $request): array
	{
		return app(\App\Ubr\Services\VerificationService::class)->requestCompany($this->tenants->require('companies.verify'), (int) $request['id']);
	}
}
