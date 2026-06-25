<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MatomoAnalytics\Bots\DefaultBotDetector;
use MatomoAnalytics\Buffer\ArrayHitBuffer;
use MatomoAnalytics\Buffer\BufferManager;
use MatomoAnalytics\Buffer\ConsecutiveFailures;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Console\FlushCommand;
use MatomoAnalytics\Console\ForgetCommand;
use MatomoAnalytics\Console\InstallCommand;
use MatomoAnalytics\Console\ReplayCommand;
use MatomoAnalytics\Console\ReportCommand;
use MatomoAnalytics\Console\TestConnectionCommand;
use MatomoAnalytics\Console\WorkCommand;
use MatomoAnalytics\Contracts\BotDetector;
use MatomoAnalytics\Contracts\GdprClient;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\ReportClient;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Contracts\TrackingGate;
use MatomoAnalytics\Contracts\VisitorIdResolver;
use MatomoAnalytics\Gates\DefaultTrackingGate;
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
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'matomo-analytics');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'matomo-analytics');
        $this->loadRoutesFrom(__DIR__.'/../routes/matomo-analytics.php');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->commands([FlushCommand::class, ForgetCommand::class, InstallCommand::class, ReplayCommand::class, ReportCommand::class, TestConnectionCommand::class, WorkCommand::class]);
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

        if (Config::bool('matomo-analytics.middleware.auto', false)) {
            Route::pushMiddlewareToGroup('web', TrackPageViews::class);
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
        $this->publishes([
            __DIR__.'/../config/matomo-analytics.php' => config_path('matomo-analytics.php'),
        ], 'matomo-analytics-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'matomo-analytics-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/matomo-analytics'),
        ], 'matomo-analytics-views');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/matomo-analytics'),
        ], 'matomo-analytics-lang');
    }
}
