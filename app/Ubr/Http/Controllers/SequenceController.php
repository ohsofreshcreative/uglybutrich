<?php

namespace App\Ubr\Http\Controllers;

use App\Ubr\Domain\Sequences\SendWindow;
use App\Ubr\Domain\Sequences\TemplateRenderer;
use App\Ubr\Services\Sequences\CampaignService;
use App\Ubr\Services\Sequences\MailboxService;
use App\Ubr\Services\Sequences\OutreachAssistant;
use App\Ubr\Services\Sequences\SequenceAdminService;
use App\Ubr\Services\Sequences\SequenceService;
use App\Ubr\Services\TenantResolver;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Prompt 10.5 — Sequences (REST). Każdy endpoint sprawdza uprawnienia po stronie serwera (macierz ról + własność
 * skrzynki / sekwencji / firmy w usługach). Identyfikatory użytkownika i organizacji z żądania są ignorowane.
 * Żaden endpoint nie wysyła wiadomości — wysyłka wyłącznie w workerze.
 */
class SequenceController
{
	public function __construct(
		private TenantResolver $tenants,
		private CampaignService $campaigns,
		private MailboxService $mailboxes,
		private SequenceService $sequences,
		private SequenceAdminService $admin,
	) {
	}

	public function overview(): array
	{
		return $this->admin->overview($this->tenants->require('sequences.view')) + ['variables' => TemplateRenderer::VARIABLES, 'days' => SendWindow::DAY_LABELS];
	}

	/* --- kampanie --- */

	public function campaigns(WP_REST_Request $r): array
	{
		return ['items' => $this->campaigns->list($this->tenants->require('sequences.view'), $r->get_params())];
	}

	public function campaign(WP_REST_Request $r): array
	{
		return $this->campaigns->get($this->tenants->require('sequences.view'), (int) $r['id']);
	}

	public function createCampaign(WP_REST_Request $r): WP_REST_Response
	{
		return new WP_REST_Response($this->campaigns->create($this->tenants->require('sequences.manage'), $this->json($r)), 201);
	}

	public function updateCampaign(WP_REST_Request $r): array
	{
		return $this->campaigns->update($this->tenants->require('sequences.manage'), (int) $r['id'], $this->json($r));
	}

	public function duplicateCampaign(WP_REST_Request $r): WP_REST_Response
	{
		return new WP_REST_Response($this->campaigns->duplicate($this->tenants->require('sequences.manage'), (int) $r['id']), 201);
	}

	/** Podgląd sekwencji przed zapisem (kroki z edytora, wartości przykładowe — oznaczone nawiasami). */
	public function preview(WP_REST_Request $r): array
	{
		$ctx = $this->tenants->require('sequences.view');
		$input = $this->json($r);
		$steps = CampaignService::cleanSteps((array) ($input['steps'] ?? []));

		return ['items' => CampaignService::preview($steps, SendWindow::normalize((array) ($input['window'] ?? [])), CampaignService::sampleValues($ctx)), 'sample' => true];
	}

	/* --- skrzynki --- */

	public function mailboxes(): array
	{
		return ['items' => $this->mailboxes->list($this->tenants->require('sequences.manage')), 'test_allowed' => MailboxService::testMailboxesAllowed()];
	}

	public function createMailbox(WP_REST_Request $r): WP_REST_Response
	{
		return new WP_REST_Response($this->mailboxes->create($this->tenants->require('sequences.manage'), $this->json($r)), 201);
	}

	public function updateMailbox(WP_REST_Request $r): array
	{
		return $this->mailboxes->update($this->tenants->require('sequences.manage'), (int) $r['id'], $this->json($r));
	}

	public function testMailbox(WP_REST_Request $r): array
	{
		return $this->mailboxes->test($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function dnsMailbox(WP_REST_Request $r): array
	{
		return $this->mailboxes->dns($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function deleteMailbox(WP_REST_Request $r): array
	{
		return $this->mailboxes->disconnect($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	/* --- sekwencje firm --- */

	public function forCompany(WP_REST_Request $r): array
	{
		return $this->sequences->forCompany($this->tenants->require('sequences.view'), (int) $r['id']);
	}

	public function recipients(WP_REST_Request $r): array
	{
		return $this->sequences->recipients($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function assign(WP_REST_Request $r): WP_REST_Response
	{
		return new WP_REST_Response($this->sequences->create($this->tenants->require('sequences.manage'), (int) $r['id'], $this->json($r)), 201);
	}

	public function list(WP_REST_Request $r): array
	{
		return $this->sequences->listSequences($this->tenants->require('sequences.view'), $r->get_params());
	}

	public function show(WP_REST_Request $r): array
	{
		return $this->sequences->get($this->tenants->require('sequences.view'), (int) $r['id']);
	}

	public function update(WP_REST_Request $r): array
	{
		return $this->sequences->update($this->tenants->require('sequences.manage'), (int) $r['id'], $this->json($r));
	}

	public function start(WP_REST_Request $r): WP_REST_Response
	{
		$res = $this->sequences->start($this->tenants->require('sequences.manage'), (int) $r['id'], $this->json($r));

		return new WP_REST_Response($res, ! empty($res['conflict']) && empty($res['started']) ? 409 : 200);
	}

	public function pause(WP_REST_Request $r): array
	{
		return $this->sequences->pause($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function resume(WP_REST_Request $r): array
	{
		return $this->sequences->resume($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function cancel(WP_REST_Request $r): array
	{
		return $this->sequences->cancel($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function destroy(WP_REST_Request $r): array
	{
		return $this->sequences->deleteDraft($this->tenants->require('sequences.manage'), (int) $r['id']);
	}

	public function scheduled(WP_REST_Request $r): array
	{
		return $this->sequences->scheduled($this->tenants->require('sequences.view'), $r->get_params());
	}

	public function sent(WP_REST_Request $r): array
	{
		return $this->sequences->sent($this->tenants->require('sequences.view'), $r->get_params());
	}

	public function message(WP_REST_Request $r): array
	{
		return $this->sequences->message($this->tenants->require('sequences.view'), (int) $r['id']);
	}

	public function updateMessage(WP_REST_Request $r): array
	{
		return $this->sequences->updateMessage($this->tenants->require('sequences.manage'), (int) $r['id'], $this->json($r));
	}

	public function resolve(WP_REST_Request $r): array
	{
		return $this->sequences->resolveUnknown($this->tenants->require('sequences.manage'), (int) $r['id'], sanitize_key((string) ($this->json($r)['outcome'] ?? '')));
	}

	/** „Spersonalizuj z AI” — przygotowane; domyślnie 501 (brak płatnych wywołań bez zgody). */
	public function aiSuggest(WP_REST_Request $r): array
	{
		$ctx = $this->tenants->require('sequences.manage');

		return app(OutreachAssistant::class)->suggest($ctx, $this->json($r) + ['message_id' => (int) $r['id']]);
	}

	/* --- ustawienia i blokady --- */

	public function settings(): array
	{
		return $this->admin->get($this->tenants->require('sequences.view'));
	}

	public function saveSettings(WP_REST_Request $r): array
	{
		return $this->admin->save($this->tenants->require('sequences.settings'), $this->json($r));
	}

	public function suppressions(WP_REST_Request $r): array
	{
		return $this->sequences->suppressions($this->tenants->require('sequences.view'), $r->get_params());
	}

	public function suppress(WP_REST_Request $r): array
	{
		$in = $this->json($r);

		return $this->sequences->suppressManual($this->tenants->require('sequences.manage'), (string) ($in['email'] ?? ''), (string) ($in['reason'] ?? 'manual'), isset($in['note']) ? (string) $in['note'] : null);
	}

	public function unsuppress(WP_REST_Request $r): array
	{
		return $this->sequences->unsuppress($this->tenants->require('sequences.settings'), (string) $r->get_param('email'));
	}

	/** @return array<string, mixed> */
	private function json(WP_REST_Request $r): array
	{
		return (array) ($r->get_json_params() ?: $r->get_body_params());
	}
}
