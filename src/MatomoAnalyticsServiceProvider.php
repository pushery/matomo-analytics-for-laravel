<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\App;
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
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/matomo-analytics.php', 'matomo-analytics');

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
        $this->app->scoped(HitBuffer::class, static fn (): HitBuffer => App::make(BufferManager::class)->driver());
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
        $this->registerScheduledDeadLetterPrune();
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
        // EVERY class in the emitted PHP is fully qualified, `App` included, and that
        // is not style. A directive's return value is written into the host
        // application's compiled view — a plain PHP file in the GLOBAL namespace, where
        // this file's `use` statements do not reach. A bare `App` there resolves only
        // through the `App` class alias, which an application is free not to register;
        // the Snippet class was already written out in full, so the two halves of the
        // same string disagreed about whether that could be relied on.
        //
        // It arrived with the move off Foundation helpers: `app()` is a global function
        // and needs no alias, `App::make()` does. Trading one for the other inside
        // GENERATED code moves the dependency from the package into the consumer's
        // configuration, where this package cannot see it fail.
        $resolve = '\\Illuminate\\Support\\Facades\\App::make(\\MatomoAnalytics\\View\\Snippet::class)';

        Blade::directive('matomoScript', static fn (string $expression): string => "<?php echo {$resolve}->script({$expression}); ?>");
        // For placing the no-JS pixel inside <body>, where an <img> is legal — see
        // Snippet::noscript(). Opt-in: script() still emits it by default.
        Blade::directive('matomoNoscript', static fn (): string => "<?php echo {$resolve}->noscript(); ?>");
        Blade::directive('matomoOptOut', static fn (): string => "<?php echo {$resolve}->optOut(); ?>");
        Blade::directive('matomoWebVitals', static fn (string $expression): string => "<?php echo {$resolve}->webVitals({$expression}); ?>");
    }

    private function registerScheduledFlush(): void
    {
        if (Config::string('matomo-analytics.mode', 'queue') !== 'batch') {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            // Bound the overlap lock to the run cadence: a hard-killed (SIGKILL/OOM)
            // flush must not hold the mutex for the framework default of 1440 minutes
            // (24h), which would silently stall the every-minute drain for a full day.
            $schedule->command('matomo:flush')->everyMinute()->withoutOverlapping(10);
        });
    }

    /**
     * Delete dead letters past the retention window, once a day.
     *
     * NOT gated on `mode === 'batch'`, unlike the flush above, and that is the easy mistake
     * here: a batch is dead-lettered from BOTH delivery modes — `SendHitsJob` parks an
     * exhausted batch in queue mode, the flusher does it in batch mode — so a prune that
     * only registered for batch would leave the queue-mode table growing exactly as it does
     * today.
     */
    private function registerScheduledDeadLetterPrune(): void
    {
        if (! Config::bool('matomo-analytics.batch.dead_letter.enabled', true)) {
            return;
        }

        // The fallback is 30 because the SHIPPED config says 30, and those two have to
        // agree — `ConfigFallbackParityTest` enforces it, and it caught this line reading 0.
        // The reason is not tidiness: a consumer whose published config predates this key,
        // or who trimmed it, would silently get the opposite behavior from the one the file
        // they are reading describes, with nothing to notice it by.
        //
        // "Keep forever" is therefore 0, not null — the same convention this package already
        // uses for --max-runs, --max-time and --memory, where 0 means "no limit". A null or
        // unparsable value reads as absent and gets the shipped default, which is exactly
        // what the parity rule asks for.
        $days = Config::int('matomo-analytics.batch.dead_letter.retention_days', 30);
        if ($days < 1) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($days): void {
            $schedule->command('matomo:replay', ['--prune-older-than' => (string) $days])
                ->daily()
                ->withoutOverlapping(10);
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

    /**
     * Merge the shipped config into the app's, recursing into nested sections.
     *
     * The framework's own `mergeConfigFrom` is a flat `array_merge`, which replaces a
     * top-level key WHOLESALE. This config is nested three levels deep and is read that
     * way everywhere, so for anyone who published `config/matomo-analytics.php` the flat
     * merge froze every section at the shape it had on publication day. A subkey added by
     * a later release did not fall back to its default — it was simply ABSENT, and the
     * readers that hurt most take no default at all: `Config::stringList()` answers `[]`.
     *
     * What that meant in practice, and why this is not cosmetic:
     *
     *   - `privacy.redact.query_params` / `.keys` — URL redaction silently off, entirely
     *   - `spa.adapters` — a config older than the `spa` block got NO adapter
     *   - `bots.allow` / `bots.deny`, `web_vitals.metrics` — empty rather than defaulted
     *
     * The obvious framework alternative is a trap: `replaceConfigRecursivelyFrom` uses
     * `array_replace_recursive`, which merges LISTS BY INDEX. That would resurrect
     * entries an operator deliberately deleted from `bots.deny` or from the redaction
     * list — turning a privacy setting back on behind their back. So the recursion here
     * is list-aware, and the rule it encodes is:
     *
     *   a map is a namespace, and gets merged · a list is a value, and is taken whole
     *
     * The app always wins on a scalar; recursion only ever ADDS what the app's file never
     * mentioned. Pattern adopted from WireKit's `mergeConfigRecursivelyFrom`, which fixed
     * the identical defect in its v2.18.0 for the identical reason.
     *
     * NOTE the early return, because it bounds what this can rescue: a CACHED config is
     * never merged at all, by the framework's design. For those installations the
     * published file is the whole truth, and the only thing standing between them and a
     * missing key is the read-site fallback — which is why those fallbacks have to agree
     * with the shipped defaults (`ConfigFallbackParityTest`). The two fixes are halves of
     * one guarantee.
     */
    private function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $shipped = require $path;

        $repository = $this->app->make(Repository::class);
        $existing = $repository->get($key);

        // Both sides are read off disk / out of the container, so neither is provably
        // string-keyed here — a config array is just an array. The recursion below is
        // written for exactly that: it asks whether a value is a list, never whether a
        // key is a string.
        $repository->set($key, $this->mergeConfigSections(
            is_array($shipped) ? $shipped : [],
            is_array($existing) ? $existing : [],
        ));
    }

    /**
     * @param  array<array-key, mixed>  $shipped
     * @param  array<array-key, mixed>  $published
     * @return array<array-key, mixed>
     */
    private function mergeConfigSections(array $shipped, array $published): array
    {
        foreach ($shipped as $key => $value) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $value;

                continue;
            }

            // Recurse only where BOTH sides are maps. If either is a list, the published
            // one stands as written — see the list-aware rule above.
            if (is_array($value) && is_array($published[$key]) && ! array_is_list($value) && ! array_is_list($published[$key])) {
                $published[$key] = $this->mergeConfigSections($value, $published[$key]);
            }
        }

        return $published;
    }
}
