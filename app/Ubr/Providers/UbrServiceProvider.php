<?php

namespace App\Ubr\Providers;

use App\Ubr\Console\UbrCommand;
use App\Ubr\Http\AppPages;
use App\Ubr\Http\RestRegistrar;
use App\Ubr\Http\WorkerEndpoint;
use App\Ubr\Infrastructure\Queue\AsyncWorker;
use App\Ubr\Infrastructure\Database\Db;
use App\Ubr\Infrastructure\Queue\JobDispatcher;
use App\Ubr\Infrastructure\Repositories\MemberRepository;
use App\Ubr\Services\Bootstrapper;
use App\Ubr\Services\DiscoveryRunner;
use App\Ubr\Services\QueueMonitor;
use App\Ubr\Services\RelevanceService;
use App\Ubr\Services\VerificationRunner;
use App\Ubr\Services\TenantResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Moduł aplikacji Ugly but Rich w motywie Sage (bez osobnego pluginu).
 * Spina: migracje, REST API, panel /app, zadania w tle i WP-CLI z cyklem życia WordPressa.
 */
class UbrServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->app->singleton(Db::class, fn () => new Db($GLOBALS['wpdb']));
		$this->app->singleton(TenantResolver::class);
		$this->app->singleton(AsyncWorker::class);
		$this->app->singleton(JobDispatcher::class);
		$this->app->singleton(\App\Ubr\Infrastructure\Http\WebsiteInspector::class);
	}

	public function boot(): void
	{
		// Migracje + organizacja domyślna (idempotentnie, tanio gdy aktualne).
		add_action('init', fn () => $this->safely(fn () => $this->app->make(Bootstrapper::class)->maybeRun()), 1);
		add_action('admin_notices', function (): void {
			$error = current_user_can('manage_options') ? $this->app->make(\App\Ubr\Infrastructure\Database\Migrator::class)->lastError() : null;

			if ($error) {
				printf('<div class="notice notice-error"><p><strong>Ugly but Rich:</strong> migracja bazy danych nie powiodła się (%s): %s. Uruchom <code>wp ubr migrate</code> po usunięciu przyczyny.</p></div>', esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $error['at']), 'Y-m-d H:i')), esc_html($error['message']));
			}
		});

		// Panel /app.
		add_action('init', fn () => $this->app->make(AppPages::class)->registerRewrites(), 10);
		add_filter('query_vars', fn ($vars) => $this->app->make(AppPages::class)->queryVars($vars));
		add_action('template_redirect', fn () => $this->app->make(AppPages::class)->maybeRender(), 0);
		add_filter('redirect_canonical', fn ($redirect) => $this->app->make(AppPages::class)->disableCanonicalRedirect($redirect), 10, 1);

		// REST API.
		add_action('rest_api_init', fn () => $this->app->make(RestRegistrar::class)->register());

		// Zadania w tle: worker async (loopback admin-ajax) + zapasowo WP-Cron + healthcheck co minutę.
		add_action('wp_ajax_nopriv_' . AsyncWorker::ACTION, fn () => $this->app->make(WorkerEndpoint::class)->handle());
		add_action('wp_ajax_' . AsyncWorker::ACTION, fn () => $this->app->make(WorkerEndpoint::class)->handle());
		add_action('wp_ajax_nopriv_' . AsyncWorker::JOB_ACTION, fn () => $this->app->make(WorkerEndpoint::class)->handleJob());
		add_action('wp_ajax_' . AsyncWorker::JOB_ACTION, fn () => $this->app->make(WorkerEndpoint::class)->handleJob());
		add_action(\App\Ubr\Services\WebsiteAnalysisRunner::CRON_HOOK, fn () => $this->safely(fn () => $this->app->make(\App\Ubr\Services\WebsiteAnalysisRunner::class)->tick()));
		add_action(\App\Ubr\Services\ContactResearchRunner::CRON_HOOK, fn () => $this->safely(fn () => $this->app->make(\App\Ubr\Services\ContactResearchRunner::class)->tick()));
		add_action(\App\Ubr\Services\ClickUpRunner::CRON_HOOK, fn () => $this->safely(fn () => $this->app->make(\App\Ubr\Services\ClickUpRunner::class)->tick()));
		add_action(\App\Ubr\Services\LeadIntelligenceRunner::CRON_HOOK, fn () => $this->safely(fn () => $this->app->make(\App\Ubr\Services\LeadIntelligenceRunner::class)->tick()));
		// Zmiana danych firmy → ocena Lead Intelligence do przeliczenia (tylko zapisane dane; worker w tle).
		add_action('ubr/company/changed', function ($companyId, $reason = '', $organizationId = null) {
			$this->safely(function () use ($companyId, $reason, $organizationId) {
				$this->app->make(\App\Ubr\Services\LeadIntelligenceService::class)->invalidate((int) $companyId, (string) $reason, $organizationId ? (int) $organizationId : null);
				$this->app->make(\App\Ubr\Services\LeadIntelligenceRunner::class)->kick();
			});
		}, 20, 3);
		// Action Center: zmiana danych firmy → nowe klucze cache liczników (organizacja albo wszystkie, gdy dane są wspólne).
		add_action('ubr/company/changed', fn ($companyId, $reason = '', $organizationId = null) => $this->safely(fn () => $organizationId ? \App\Ubr\Services\ActionCenterService::bumpOrganization((int) $organizationId) : \App\Ubr\Services\ActionCenterService::bumpGlobal()), 30, 3);
		// Zdarzenia firm (dodanie do Prospects, weryfikacja, analiza, kontakty, zmiana statusu) → reguły ClickUp (tylko kolejka).
		add_action('ubr/company/changed', fn ($companyId, $reason = '', $organizationId = null) => $this->safely(fn () => $this->app->make(\App\Ubr\Services\ClickUpRuleService::class)->trigger((int) $companyId, (string) $reason, $organizationId ? (int) $organizationId : null)), 10, 3);
		add_action('wp_ajax_ubr_screenshot', fn () => $this->app->make(\App\Ubr\Http\ScreenshotEndpoint::class)->handle());
		add_action(VerificationRunner::CRON_HOOK, fn () => $this->safely(fn () => $this->app->make(VerificationRunner::class)->tick(20)));
		add_action('wp_ajax_nopriv_' . AsyncWorker::PING_ACTION, fn () => $this->app->make(WorkerEndpoint::class)->ping());
		add_action('wp_ajax_' . AsyncWorker::PING_ACTION, fn () => $this->app->make(WorkerEndpoint::class)->ping());
		add_action(JobDispatcher::HOOK, fn ($searchId = 0) => $this->app->make(DiscoveryRunner::class)->tick((int) $searchId), 10, 2);
		add_filter('cron_schedules', function ($schedules) {
			$schedules['ubr_every_minute'] = ['interval' => 60, 'display' => 'Ugly but Rich: co minutę'];

			return $schedules;
		});
		add_action(JobDispatcher::HEALTHCHECK_HOOK, function () {
			$this->safely(fn () => $this->app->make(QueueMonitor::class)->recover());
			// Kolejka weryfikacji: wznowienie, jeśli są zaległe pozycje, a worker nie działa.
			$this->safely(fn () => $this->app->make(VerificationRunner::class)->kick());
			// Kolejka analiz stron: wznowienie zaległych.
			$this->safely(fn () => $this->app->make(\App\Ubr\Services\WebsiteAnalysisRunner::class)->kick());
			// Kolejka wyszukiwań decydentów: wznowienie zaległych.
			$this->safely(fn () => $this->app->make(\App\Ubr\Services\ContactResearchRunner::class)->kick());
			// Kolejka operacji ClickUp: wznowienie zaległych.
			$this->safely(fn () => $this->app->make(\App\Ubr\Services\ClickUpRunner::class)->kick());
			// Lead Intelligence: przeliczenie zaległych ocen.
			$this->safely(fn () => $this->app->make(\App\Ubr\Services\LeadIntelligenceRunner::class)->kick());
		});

		// Po migracji 1.2: trafność dla istniejących wyników (bez zapytań zewnętrznych).
		add_action('ubr/migrated', fn ($versions) => in_array(3, (array) $versions, true) ? $this->safely(fn () => $this->app->make(RelevanceService::class)->backfill(2000)) : null);
		add_action('init', fn () => $this->safely(fn () => $this->app->make(JobDispatcher::class)->ensureHealthcheckScheduled()), 20);

		// Sprzątanie członkostw po usunięciu użytkownika WP.
		add_action('deleted_user', fn ($userId) => $this->app->make(MemberRepository::class)->removeUserEverywhere((int) $userId));

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('ubr', UbrCommand::class);
		}
	}

	private function safely(callable $callback): void
	{
		try {
			$callback();
		} catch (\Throwable $e) {
			error_log('[ubr] Bootstrap error: ' . $e->getMessage());
		}
	}
}
