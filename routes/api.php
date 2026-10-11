<?php

/**
 * Ugly but Rich — REST API (namespace ubr/v1).
 *
 * Format: [metody, ścieżka, [Kontroler, metoda], wymagane uprawnienie].
 * Uprawnienie 'auth' = tylko zalogowany użytkownik (bez kontekstu organizacji).
 * Każde inne uprawnienie wymaga członkostwa w aktywnej organizacji z odpowiednią rolą.
 */

use App\Ubr\Http\Controllers\AnalysisController;
use App\Ubr\Http\Controllers\CallingController;
use App\Ubr\Http\Controllers\ClickUpController;
use App\Ubr\Http\Controllers\CompanyController;
use App\Ubr\Http\Controllers\ContactController;
use App\Ubr\Http\Controllers\ActionCenterController;
use App\Ubr\Http\Controllers\DashboardController;
use App\Ubr\Http\Controllers\DiscoveryController;
use App\Ubr\Http\Controllers\IntegrationController;
use App\Ubr\Http\Controllers\LeadIntelligenceController;
use App\Ubr\Http\Controllers\MeController;
use App\Ubr\Http\Controllers\OrganizationController;
use App\Ubr\Http\Controllers\PipelineController;
use App\Ubr\Http\Controllers\ProspectController;
use App\Ubr\Http\Controllers\SequenceController;

return [
	['GET', '/me', [MeController::class, 'show'], 'auth'],
	['POST', '/me/active-organization', [MeController::class, 'switchOrganization'], 'auth'],
	['POST', '/me/profile', [MeController::class, 'updateProfile'], 'auth'],

	['GET', '/dashboard', [DashboardController::class, 'show'], 'organization.view'],
	['GET', '/action-center', [ActionCenterController::class, 'overview'], 'companies.view'],
	['GET', '/action-center/priorities', [ActionCenterController::class, 'priorities'], 'companies.view'],
	['GET', '/action-center/queues/(?P<key>[a-z_]+)', [ActionCenterController::class, 'queue'], 'companies.view'],
	['POST', '/action-center/snoozes', [ActionCenterController::class, 'snooze'], 'pipeline.manage'],
	['DELETE', '/action-center/snoozes', [ActionCenterController::class, 'unsnooze'], 'pipeline.manage'],
	['GET', '/companies/(?P<id>\d+)/manual-review', [ActionCenterController::class, 'manualReview'], 'companies.view'],
	['POST', '/companies/(?P<id>\d+)/manual-review', [ActionCenterController::class, 'saveManualReview'], 'pipeline.manage'],

	['GET', '/discovery/options', [DiscoveryController::class, 'options'], 'discovery.view'],
	['GET', '/discovery/searches', [DiscoveryController::class, 'index'], 'discovery.view'],
	['POST', '/discovery/searches', [DiscoveryController::class, 'store'], 'discovery.run'],
	['GET', '/discovery/searches/(?P<id>\d+)', [DiscoveryController::class, 'show'], 'discovery.view'],
	['POST', '/discovery/searches/(?P<id>\d+)/cancel', [DiscoveryController::class, 'cancel'], 'discovery.cancel'],
	['POST', '/discovery/searches/(?P<id>\d+)/retry', [DiscoveryController::class, 'retry'], 'discovery.run'],
	['GET', '/discovery/searches/(?P<id>\d+)/results', [DiscoveryController::class, 'results'], 'discovery.view'],
	['POST', '/discovery/searches/(?P<id>\d+)/verify', [DiscoveryController::class, 'verify'], 'companies.verify'],

	['GET', '/companies/(?P<id>\d+)', [CompanyController::class, 'show'], 'companies.view'],
	['POST', '/companies/(?P<id>\d+)/verify', [CompanyController::class, 'verify'], 'companies.verify'],

	['GET', '/companies/(?P<id>\d+)/analysis', [AnalysisController::class, 'forCompany'], 'analysis.view'],
	['POST', '/companies/(?P<id>\d+)/site-age', [AnalysisController::class, 'siteAge'], 'analysis.run'],
	['POST', '/companies/(?P<id>\d+)/analyze', [AnalysisController::class, 'analyzeCompany'], 'analysis.run'],
	['POST', '/analyses', [AnalysisController::class, 'bulk'], 'analysis.run'],
	['GET', '/analyses/compare', [AnalysisController::class, 'compare'], 'analysis.view'],
	['GET', '/analyses/(?P<id>\d+)', [AnalysisController::class, 'show'], 'analysis.view'],
	['GET', '/analyzer/status', [AnalysisController::class, 'status'], 'integrations.view'],

	['GET', '/companies/(?P<id>\d+)/contacts', [ContactController::class, 'forCompany'], 'contacts.view'],
	['POST', '/companies/(?P<id>\d+)/contacts/research', [ContactController::class, 'researchCompany'], 'contacts.run'],
	['POST', '/companies/(?P<id>\d+)/contacts', [ContactController::class, 'store'], 'contacts.manage'],
	['POST', '/contacts/research', [ContactController::class, 'bulk'], 'contacts.run'],
	['POST', '/contacts/(?P<id>\d+)/review', [ContactController::class, 'review'], 'contacts.manage'],
	['GET', '/contacts/status', [ContactController::class, 'status'], 'contacts.view'],

	['GET', '/prospects', [ProspectController::class, 'index'], 'prospects.view'],
	['POST', '/prospects', [ProspectController::class, 'store'], 'prospects.manage'],
	['DELETE', '/prospects/(?P<id>\d+)', [ProspectController::class, 'destroy'], 'prospects.manage'],

	['GET', '/pipeline/board', [PipelineController::class, 'board'], 'pipeline.view'],
	['GET', '/pipeline/agenda', [PipelineController::class, 'agenda'], 'pipeline.view'],
	['GET', '/companies/(?P<id>\d+)/sales', [PipelineController::class, 'sales'], 'pipeline.view'],
	['POST', '/prospects/(?P<id>\d+)', [PipelineController::class, 'update'], 'pipeline.manage'],
	['POST', '/prospects/(?P<id>\d+)/archive', [PipelineController::class, 'archive'], 'prospects.manage'],
	['POST', '/prospects/(?P<id>\d+)/notes', [PipelineController::class, 'addNote'], 'pipeline.manage'],
	['POST', '/notes/(?P<id>\d+)', [PipelineController::class, 'updateNote'], 'pipeline.manage'],
	['DELETE', '/notes/(?P<id>\d+)', [PipelineController::class, 'deleteNote'], 'pipeline.manage'],
	['POST', '/prospects/(?P<id>\d+)/activities', [PipelineController::class, 'addActivity'], 'pipeline.manage'],
	// Cold Calling Workspace (Prompt 10): telefony, odblokowanie (Apollo, płatne — tylko po potwierdzeniu), opiekun, wynik rozmowy.
	['GET', '/companies/(?P<id>\d+)/phones', [CallingController::class, 'phones'], 'companies.view'],
	['POST', '/companies/(?P<id>\d+)/phones/refresh', [CallingController::class, 'refreshPhones'], 'analysis.run'],
	['POST', '/companies/(?P<id>\d+)/phones', [CallingController::class, 'addPhone'], 'pipeline.manage'],
	['DELETE', '/companies/(?P<id>\d+)/phones/(?P<phone_id>\d+)', [CallingController::class, 'deletePhone'], 'pipeline.manage'],
	// Direct Decision Maker Phones (Prompt 10.2): powiązania numer ↔ osoba z dowodem; decyzje organizacji prywatne.
	['POST', '/companies/(?P<id>\d+)/decision-phones/scan', [CallingController::class, 'scanDecisionPhones'], 'contacts.run'],
	['GET', '/contacts/(?P<id>\d+)/phone-links', [CallingController::class, 'contactPhoneLinks'], 'contacts.view'],
	['POST', '/contacts/(?P<id>\d+)/phone-links', [CallingController::class, 'decidePhoneLink'], 'contacts.manage'],
	['DELETE', '/contacts/(?P<id>\d+)/phone-links/(?P<link_id>\d+)', [CallingController::class, 'removePhoneLink'], 'contacts.manage'],
	['GET', '/contacts/(?P<id>\d+)/phone-reveal', [CallingController::class, 'revealPreflight'], 'contacts.view'],
	['POST', '/contacts/(?P<id>\d+)/phone-reveal', [CallingController::class, 'reveal'], 'contacts.reveal'],
	// Apollo Contact Matching (Prompt 10.3): kandydaci z People API Search, dopasowania prywatne dla organizacji.
	['GET', '/companies/(?P<id>\d+)/apollo-candidates', [CallingController::class, 'apolloCandidates'], 'contacts.view'],
	['POST', '/companies/(?P<id>\d+)/apollo-candidates/search', [CallingController::class, 'apolloSearch'], 'contacts.run'],
	['POST', '/apollo-matches/(?P<id>\d+)', [CallingController::class, 'apolloDecide'], 'contacts.manage'],
	['POST', '/apollo-candidates/(?P<id>\d+)/link', [CallingController::class, 'apolloLink'], 'contacts.manage'],
	['GET', '/apollo-matches/(?P<id>\d+)/history', [CallingController::class, 'apolloHistory'], 'contacts.view'],
	['POST', '/companies/(?P<id>\d+)/claim', [CallingController::class, 'claim'], 'prospects.manage'],
	['GET', '/companies/(?P<id>\d+)/claim-status', [CallingController::class, 'claimStatus'], 'companies.view'],
	['POST', '/companies/(?P<id>\d+)/claim-research', [CallingController::class, 'claimResearch'], 'companies.view'],
	['POST', '/companies/(?P<id>\d+)/call-outcome', [CallingController::class, 'callOutcome'], 'pipeline.manage'],
	['POST', '/prospects/(?P<id>\d+)/transfer', [CallingController::class, 'transfer'], 'pipeline.manage'],
	['POST', '/prospects/(?P<id>\d+)/do-not-contact', [CallingController::class, 'doNotContact'], 'pipeline.manage'],
	['POST', '/prospects/(?P<id>\d+)/followups', [PipelineController::class, 'addFollowup'], 'pipeline.manage'],
	['POST', '/followups/(?P<id>\d+)', [PipelineController::class, 'updateFollowup'], 'pipeline.manage'],

	['GET', '/companies/(?P<id>\d+)/lead-intelligence', [LeadIntelligenceController::class, 'show'], 'companies.view'],
	['POST', '/companies/(?P<id>\d+)/lead-intelligence/recalculate', [LeadIntelligenceController::class, 'recalculate'], 'companies.view'],
	['GET', '/lead-intelligence/top', [LeadIntelligenceController::class, 'top'], 'companies.view'],

	['GET', '/clickup/status', [ClickUpController::class, 'status'], 'clickup.connect'],
	['POST', '/clickup/oauth/start', [ClickUpController::class, 'start'], 'clickup.connect'],
	['POST', '/clickup/test', [ClickUpController::class, 'test'], 'clickup.connect'],
	['POST', '/clickup/disconnect', [ClickUpController::class, 'disconnect'], 'clickup.connect'],
	['GET', '/clickup/options', [ClickUpController::class, 'options'], 'clickup.connect'],
	['POST', '/clickup/destination', [ClickUpController::class, 'saveDestination'], 'clickup.connect'],
	['POST', '/clickup/settings', [ClickUpController::class, 'saveSettings'], 'clickup.connect'],
	['POST', '/clickup/claim-sync', [ClickUpController::class, 'saveClaimSync'], 'organization.update'],
	['POST', '/clickup/exports', [ClickUpController::class, 'export'], 'clickup.export'],
	['GET', '/clickup/exports/recent', [ClickUpController::class, 'recent'], 'clickup.export'],
	['GET', '/clickup/batches/(?P<batch>[a-f0-9-]{36})', [ClickUpController::class, 'batch'], 'clickup.export'],
	['POST', '/clickup/exports/(?P<id>\d+)/reconcile', [ClickUpController::class, 'reconcile'], 'clickup.export'],
	['GET', '/companies/(?P<id>\d+)/clickup', [ClickUpController::class, 'company'], 'pipeline.view'],
	['GET', '/clickup/rules', [ClickUpController::class, 'rules'], 'clickup.export'],
	['POST', '/clickup/rules', [ClickUpController::class, 'createRule'], 'clickup.rules'],
	['POST', '/clickup/rules/(?P<id>\d+)', [ClickUpController::class, 'updateRule'], 'clickup.rules'],
	['DELETE', '/clickup/rules/(?P<id>\d+)', [ClickUpController::class, 'deleteRule'], 'clickup.export'],
	['POST', '/clickup/rules/(?P<id>\d+)/preview', [ClickUpController::class, 'previewRule'], 'clickup.export'],
	['POST', '/clickup/rules/(?P<id>\d+)/enable', [ClickUpController::class, 'enableRule'], 'clickup.rules'],
	['POST', '/clickup/rules/(?P<id>\d+)/disable', [ClickUpController::class, 'disableRule'], 'clickup.export'],
	['POST', '/clickup/rules/(?P<id>\d+)/backfill', [ClickUpController::class, 'backfillRule'], 'clickup.rules'],

	['GET', '/integrations/apollo', [IntegrationController::class, 'apollo'], 'integrations.view'],
	['POST', '/integrations/apollo/test', [IntegrationController::class, 'test'], 'integrations.manage'],
	['POST', '/integrations/apollo/test-search', [IntegrationController::class, 'testSearch'], 'integrations.manage'],
	['POST', '/integrations/apollo/people-test', [IntegrationController::class, 'peopleTest'], 'integrations.manage'],

	['GET', '/organization', [OrganizationController::class, 'show'], 'organization.view'],
	['POST', '/organization', [OrganizationController::class, 'update'], 'organization.update'],
	['POST', '/organizations', [OrganizationController::class, 'store'], 'auth'],
	['POST', '/organization/members', [OrganizationController::class, 'addMember'], 'members.manage'],
	['POST', '/organization/members/(?P<user_id>\d+)', [OrganizationController::class, 'updateMember'], 'members.manage'],
	['DELETE', '/organization/members/(?P<user_id>\d+)', [OrganizationController::class, 'removeMember'], 'organization.view'],
	// Prompt 10.5 — Sequences (kampanie e-mail). Wysyłka wyłącznie w workerze; żaden endpoint nie wysyła wiadomości.
	['GET', '/sequences/overview', [SequenceController::class, 'overview'], 'sequences.view'],
	['GET', '/sequences/campaigns', [SequenceController::class, 'campaigns'], 'sequences.view'],
	['POST', '/sequences/campaigns', [SequenceController::class, 'createCampaign'], 'sequences.manage'],
	['GET', '/sequences/campaigns/(?P<id>\d+)', [SequenceController::class, 'campaign'], 'sequences.view'],
	['POST', '/sequences/campaigns/(?P<id>\d+)', [SequenceController::class, 'updateCampaign'], 'sequences.manage'],
	['POST', '/sequences/campaigns/(?P<id>\d+)/duplicate', [SequenceController::class, 'duplicateCampaign'], 'sequences.manage'],
	['POST', '/sequences/preview', [SequenceController::class, 'preview'], 'sequences.view'],
	['GET', '/sequences/mailboxes', [SequenceController::class, 'mailboxes'], 'sequences.manage'],
	['POST', '/sequences/mailboxes', [SequenceController::class, 'createMailbox'], 'sequences.manage'],
	['POST', '/sequences/mailboxes/(?P<id>\d+)', [SequenceController::class, 'updateMailbox'], 'sequences.manage'],
	['POST', '/sequences/mailboxes/(?P<id>\d+)/test', [SequenceController::class, 'testMailbox'], 'sequences.manage'],
	['POST', '/sequences/mailboxes/(?P<id>\d+)/dns', [SequenceController::class, 'dnsMailbox'], 'sequences.manage'],
	['DELETE', '/sequences/mailboxes/(?P<id>\d+)', [SequenceController::class, 'deleteMailbox'], 'sequences.manage'],
	['GET', '/companies/(?P<id>\d+)/sequences', [SequenceController::class, 'forCompany'], 'sequences.view'],
	['GET', '/companies/(?P<id>\d+)/sequence-recipients', [SequenceController::class, 'recipients'], 'sequences.manage'],
	['POST', '/companies/(?P<id>\d+)/sequences', [SequenceController::class, 'assign'], 'sequences.manage'],
	['GET', '/sequences/items', [SequenceController::class, 'list'], 'sequences.view'],
	['GET', '/sequences/items/(?P<id>\d+)', [SequenceController::class, 'show'], 'sequences.view'],
	['POST', '/sequences/items/(?P<id>\d+)', [SequenceController::class, 'update'], 'sequences.manage'],
	['DELETE', '/sequences/items/(?P<id>\d+)', [SequenceController::class, 'destroy'], 'sequences.manage'],
	['POST', '/sequences/items/(?P<id>\d+)/start', [SequenceController::class, 'start'], 'sequences.manage'],
	['POST', '/sequences/items/(?P<id>\d+)/pause', [SequenceController::class, 'pause'], 'sequences.manage'],
	['POST', '/sequences/items/(?P<id>\d+)/resume', [SequenceController::class, 'resume'], 'sequences.manage'],
	['POST', '/sequences/items/(?P<id>\d+)/cancel', [SequenceController::class, 'cancel'], 'sequences.manage'],
	['GET', '/sequences/scheduled', [SequenceController::class, 'scheduled'], 'sequences.view'],
	['GET', '/sequences/sent', [SequenceController::class, 'sent'], 'sequences.view'],
	['GET', '/sequences/messages/(?P<id>\d+)', [SequenceController::class, 'message'], 'sequences.view'],
	['POST', '/sequences/messages/(?P<id>\d+)', [SequenceController::class, 'updateMessage'], 'sequences.manage'],
	['POST', '/sequences/messages/(?P<id>\d+)/resolve', [SequenceController::class, 'resolve'], 'sequences.manage'],
	['POST', '/sequences/messages/(?P<id>\d+)/ai-suggest', [SequenceController::class, 'aiSuggest'], 'sequences.manage'],
	['GET', '/sequences/settings', [SequenceController::class, 'settings'], 'sequences.view'],
	['POST', '/sequences/settings', [SequenceController::class, 'saveSettings'], 'sequences.settings'],
	['GET', '/sequences/suppressions', [SequenceController::class, 'suppressions'], 'sequences.view'],
	['POST', '/sequences/suppressions', [SequenceController::class, 'suppress'], 'sequences.manage'],
	['DELETE', '/sequences/suppressions', [SequenceController::class, 'unsuppress'], 'sequences.settings'],
];
