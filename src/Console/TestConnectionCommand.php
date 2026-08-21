<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config as ConfigFacade;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Support\Config;
use Throwable;

final class TestConnectionCommand extends Command
{
    protected $signature = 'matomo:test';

    protected $description = 'Send a test hit to Matomo and report connectivity.';

    public function handle(Connection $connection, Sender $sender): int
    {
        if (! $connection->isConfigured()) {
            $this->error('Matomo is not configured. Set MATOMO_HOST and MATOMO_SITE_ID.');

            return self::FAILURE;
        }

        // Connectivity and activity are different questions, and this command is
        // asked precisely when someone is wondering why nothing arrives. Reaching
        // Matomo while the master switch is off would answer "OK" to a package that
        // is tracking nobody — so say it, and keep probing anyway: knowing the
        // credentials work is exactly what you want before you flip the switch.
        if (! Config::bool('matomo-analytics.enabled', false)) {
            $this->warn('Tracking is DISABLED (matomo-analytics.enabled = false) — nothing is being tracked. Set MATOMO_ENABLED=true to turn it on. Probing the connection anyway:');
        }

        $this->reportConfigDrift();

        try {
            $result = $sender->send([$this->probe($connection)]);
        } catch (Throwable $e) {
            $this->error(sprintf('Could not reach Matomo at %s: %s', $connection->trackingUrl(), $e->getMessage()));

            return self::FAILURE;
        }

        if ($result->failed()) {
            $this->error(sprintf('Matomo returned HTTP %d at %s.', $result->status, $connection->trackingUrl()));

            return self::FAILURE;
        }

        $this->info(sprintf('Matomo OK — test hit accepted at %s (HTTP %d).', $connection->trackingUrl(), $result->status));

        return self::SUCCESS;
    }

    /**
     * Warn about settings the SHIPPED config declares that the running application cannot see.
     *
     * WHAT THIS CATCHES, and it is narrower than it first looks. A published config that simply
     * omits a newer key is NOT a problem: `mergeConfigRecursivelyFrom()` puts it back, which is
     * measurable — override the whole `batch` block without `dead_letter.retention_days` and the
     * value still reads 30. That recursion exists precisely for this.
     *
     * The gap is one layer further out: the merge does not run at all when the configuration is
     * CACHED. A cache built before a package update, and never rebuilt, is then the whole truth,
     * and a key added since is simply absent. Nothing throws — an absent key yields the code
     * fallback, which `ConfigFallbackParityTest` forces to equal the shipped value, so it is
     * usually even correct. Usually is not always, and the exceptions are exactly the settings
     * somebody set on purpose.
     *
     * Advisory, never fatal: this command is asked when something is already wrong, and turning
     * a diagnostic into a failure removes the diagnosis.
     */
    private function reportConfigDrift(): void
    {
        // Cast rather than a guard, the same way Support\Config reads this file. `require`
        // is typed `mixed`, but the file is an array literal, so an `is_array()` branch is one
        // no run can enter -- and coverage says so rather than the reader having to notice.
        /** @var array<string, mixed> $shipped */
        $shipped = (array) require __DIR__.'/../../config/matomo-analytics.php';

        $missing = array_values(array_filter(
            $this->settingPaths($shipped, ''),
            static fn (string $path): bool => ! ConfigFacade::has('matomo-analytics.'.$path),
        ));

        if ($missing === []) {
            return;
        }

        $app = App::getFacadeRoot();
        $cached = $app instanceof CachesConfiguration && $app->configurationIsCached();

        $this->warn(sprintf(
            '%d setting(s) this version ships are not visible to the running application:',
            count($missing),
        ));

        foreach ($missing as $path) {
            $this->line('  matomo-analytics.'.$path);
        }

        $this->warn($cached
            ? 'The configuration is CACHED, and the cache predates these keys. Rebuild it: php artisan config:cache'
            : 'Publish the config again with --force, or add these lines to your copy.');
    }

    /**
     * Every SETTING path in a config array, with a list counted as one leaf rather than walked.
     *
     * `Arr::dot()` would expand `privacy.redact.keys` into `.0`, `.1`, … and then report every
     * element a consumer trimmed from a list as a missing setting, which is noise: a list is a
     * value, not a namespace.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    private function settingPaths(array $config, string $prefix): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = [...$paths, ...$this->settingPaths($value, $path)];

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @return array<string, scalar>
     */
    private function probe(Connection $connection): array
    {
        return [
            'idsite' => $connection->siteId,
            'rec' => 1,
            'apiv' => 1,
            'send_image' => 0,
            'action_name' => 'Matomo Analytics connection test',
            'url' => $connection->host.'/matomo-analytics/connection-test',
            '_id' => '00000000000000aa',
        ];
    }
}
