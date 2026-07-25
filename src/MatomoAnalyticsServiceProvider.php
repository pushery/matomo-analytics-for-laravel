<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MatomoAnalytics\Annotations\AnnotationsManager;
use MatomoAnalytics\Bots\DefaultBotDetector;
use MatomoAnalytics\Buffer\ArrayHitBuffer;
use MatomoAnalytics\Buffer\BufferManager;
use MatomoAnalytics\Buffer\ConsecutiveFailures;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Console\AnnotateCommand;
use MatomoAnalytics\Console\FlushCommand;
use MatomoAnalytics\Console\ForgetCommand;
use MatomoAnalytics\Console\InstallCommand;
use MatomoAnalytics\Console\LoadSimCommand;
use MatomoAnalytics\Console\ReplayCommand;
use MatomoAnalytics\Console\ReportCommand;
use MatomoAnalytics\Console\TestConnectionCommand;
use MatomoAnalytics\Console\WorkCommand;
use MatomoAnalytics\Contracts\AnnotationsClient;
use MatomoAnalytics\Contracts\BotDetector;
use MatomoAnalytics\Contracts\GdprClient;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\ReportClient;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Contracts\TrackingGate;
use MatomoAnalytics\Contracts\VisitorIdResolver;
use MatomoAnalytics\Gates\DefaultTrackingGate;
use MatomoAnalytics\Http\Middleware\TrackAiChatbots;
use MatomoAnalytics\Http\Middleware\TrackPageViews;
use MatomoAnalytics\Http\Middleware\TrackSiteSearch;
use MatomoAnalytics\Identity\CookielessVisitorId;
use MatomoAnalytics\Privacy\GdprManager;
use MatomoAnalytics\Privacy\UrlRedactor;
use MatomoAnalytics\Reporting\MatomoReports;
use MatomoAnalytics\Reporting\ReportCache;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use MatomoAnalytics\Transport\HttpSender;
use Override;

final class MatomoAnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead
     * (e.g. queue-mode apps that do not use the database batch buffer).
     */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/matomo-analytics.php', 'matomo-analytics');

        $this->app->singleton(Connection::class, static fn (): Connection => Connection::fromConfig());
        $this->app->singleton(Reporter::class);
        $this->app->singleton(UrlRedactor::class);
        $this->app->singleton(PayloadBuilder::class);
        $this->app->singleton(VisitorIdResolver::class, CookielessVisitorId::class);
        $this->app->singleton(BotDetector::class, DefaultBotDetector::class);
        $this->app->singleton(TrackingGate::class, DefaultTrackingGate::class);
        $this->app->singleton(Sender::class, HttpSender::class);
        // Scoped, not singleton: the array driver holds hits in memory, so under Octane it must
        // reset between requests (a long-lived worker would otherwise carry hits across requests).
        // Scoped behaves exactly like a singleton within a classic request lifecycle.
        $this->app->scoped(ArrayHitBuffer::class);
        $this->app->singleton(DeadLetterStore::class);
        $this->app->singleton(ConsecutiveFailures::class);
        $this->app->scoped(HitBuffer::class, static fn (): HitBuffer => app(BufferManager::class)->driver());
        $this->app->scoped(Tracker::class, TrackManager::class);

        $this->app->singleton(ReportCache::class);
        $this->app->scoped(ReportClient::class, MatomoReports::class);
        $this->app->scoped(GdprClient::class, GdprManager::class);
        $this->app->scoped(AnnotationsClient::class, AnnotationsManager::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'matomo-analytics');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'matomo-analytics');
        $this->loadRoutesFrom(__DIR__.'/../routes/matomo-analytics.php');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->commands([AnnotateCommand::class, FlushCommand::class, ForgetCommand::class, InstallCommand::class, LoadSimCommand::class, ReplayCommand::class, ReportCommand::class, TestConnectionCommand::class, WorkCommand::class]);
        $this->registerMiddleware();
        $this->registerBladeDirectives();
        $this->registerScheduledFlush();
        $this->registerTerminatingFlush();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    private function registerMiddleware(): void
    {
        Route::aliasMiddleware('matomo.track', TrackPageViews::class);
        Route::aliasMiddleware('matomo.search', TrackSiteSearch::class);
        Route::aliasMiddleware('matomo.chatbots', TrackAiChatbots::class);

        if (Config::bool('matomo-analytics.middleware.auto', false)) {
            Route::pushMiddlewareToGroup('web', TrackPageViews::class);
        }

        if (Config::bool('matomo-analytics.ai_chatbots.auto', false)) {
            Route::pushMiddlewareToGroup('web', TrackAiChatbots::class);
        }
    }

    private function registerBladeDirectives(): void
    {
        Blade::directive('matomoScript', static fn (string $expression): string => "<?php echo app(\\MatomoAnalytics\\View\\Snippet::class)->script({$expression}); ?>");
        Blade::directive('matomoOptOut', static fn (): string => '<?php echo app(\\MatomoAnalytics\\View\\Snippet::class)->optOut(); ?>');
        Blade::directive('matomoWebVitals', static fn (string $expression): string => "<?php echo app(\\MatomoAnalytics\\View\\Snippet::class)->webVitals({$expression}); ?>");
    }

    private function registerScheduledFlush(): void
    {
        if (Config::string('matomo-analytics.mode', 'queue') !== 'batch') {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('matomo:flush')->everyMinute()->withoutOverlapping();
        });
    }

    private function registerTerminatingFlush(): void
    {
        $this->app->terminating(function (): void {
            if ($this->app->resolved(Tracker::class)) {
                /** @var Tracker $tracker */
                $tracker = $this->app->make(Tracker::class);
                $tracker->flush();
            }
        });
    }

    private function registerPublishing(): void
    {
        // Resolve publish targets through the Application contract's path methods
        // (available via illuminate/contracts), NOT the config_path()/database_path()/
        // resource_path()/lang_path() global helpers. Those are Foundation helpers,
        // shipped ONLY with laravel/framework — which this lean package does not
        // require — so the helper form would freeze a wrong dependency contract and
        // fatal in a non-Foundation host. The method form is behavior-identical.
        //
        // Each group carries the bare 'matomo-analytics' umbrella tag on top of its
        // specific one, so `vendor:publish --tag=matomo-analytics` publishes every
        // resource at once — the tag convention Laravel's official package skeleton
        // establishes.
        $this->publishes([
            __DIR__.'/../config/matomo-analytics.php' => $this->app->configPath('matomo-analytics.php'),
        ], ['matomo-analytics', 'matomo-analytics-config']);

        // publishesMigrations(), not publishes(): it rewrites the bundled
        // 0001_01_01_000000 ordering prefix to the publish date, so a published
        // migration sorts AFTER the host app's own migrations instead of before all
        // of them — which is what the bundled prefix would otherwise force.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], ['matomo-analytics', 'matomo-analytics-migrations']);

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/matomo-analytics'),
        ], ['matomo-analytics', 'matomo-analytics-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/matomo-analytics'),
        ], ['matomo-analytics', 'matomo-analytics-lang']);
    }
}
