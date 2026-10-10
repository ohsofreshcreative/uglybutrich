<?php

/**
 * Ugly but Rich — REST API (namespace ubr/v1).
 *
 * Format: [metody, ścieżka, [Kontroler, metoda], wymagane uprawnienie].
 * Uprawnienie 'auth' = tylko zalogowany użytkownik (bez kontekstu organizacji).
 * Każde inne uprawnienie wymaga członkostwa w aktywnej organizacji z odpowiednią rolą.
 */

use App\Ubr\Http\Controllers\AnalysisController;
use App\Ubr\Http\Controllers\ClickUpController;
use App\Ubr\Http\Controllers\CompanyController;
use App\Ubr\Http\Controllers\ContactController;
use App\Ubr\Http\Controllers\DashboardController;
use App\Ubr\Http\Controllers\DiscoveryController;
use App\Ubr\Http\Controllers\IntegrationController;
use App\Ubr\Http\Controllers\MeController;
use App\Ubr\Http\Controllers\OrganizationController;
use App\Ubr\Http\Controllers\PipelineController;
use App\Ubr\Http\Controllers\ProspectController;

return [
	['GET', '/me', [MeController::class, 'show'], 'auth'],
	['POST', '/me/active-organization', [MeController::class, 'switchOrganization'], 'auth'],
	['POST', '/me/profile', [MeController::class, 'updateProfile'], 'auth'],

	['GET', '/dashboard', [DashboardController::class, 'show'], 'organization.view'],

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
	['POST', '/prospects/(?P<id>\d+)/followups', [PipelineController::class, 'addFollowup'], 'pipeline.manage'],
	['POST', '/followups/(?P<id>\d+)', [PipelineController::class, 'updateFollowup'], 'pipeline.manage'],

	['GET', '/clickup/status', [ClickUpController::class, 'status'], 'clickup.connect'],
	['POST', '/clickup/oauth/start', [ClickUpController::class, 'start'], 'clickup.connect'],
	['POST', '/clickup/test', [ClickUpController::class, 'test'], 'clickup.connect'],
	['POST', '/clickup/disconnect', [ClickUpController::class, 'disconnect'], 'clickup.connect'],
	['GET', '/clickup/options', [ClickUpController::class, 'options'], 'clickup.connect'],
	['POST', '/clickup/destination', [ClickUpController::class, 'saveDestination'], 'clickup.connect'],
	['POST', '/clickup/settings', [ClickUpController::class, 'saveSettings'], 'clickup.connect'],
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

	['GET', '/organization', [OrganizationController::class, 'show'], 'organization.view'],
	['POST', '/organization', [OrganizationController::class, 'update'], 'organization.update'],
	['POST', '/organizations', [OrganizationController::class, 'store'], 'auth'],
	['POST', '/organization/members', [OrganizationController::class, 'addMember'], 'members.manage'],
	['POST', '/organization/members/(?P<user_id>\d+)', [OrganizationController::class, 'updateMember'], 'members.manage'],
	['DELETE', '/organization/members/(?P<user_id>\d+)', [OrganizationController::class, 'removeMember'], 'organization.view'],
];
