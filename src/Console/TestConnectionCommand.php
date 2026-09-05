<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config as ConfigFacade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\HitBuffer;
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
        $this->reportPlaintextHost();
        $this->reportRedisEvictionPolicy();
        $this->reportRedisPersistence();
        $this->reportPostgresTimeouts();
        $this->reportBacklog();

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
     * Warn when the Redis buffer runs on an instance that is allowed to evict it.
     *
     * The buffer's durability claim -- claim a batch, remove it only on a confirmed 200 --
     * holds for the `redis` driver only while Redis is not permitted to throw its keys away.
     * The buffer sets no TTL on any of them, because they are pending work rather than
     * cache; under an `allkeys-*` `maxmemory-policy` that makes them exactly as evictable as
     * everything else in the keyspace.
     *
     * WHAT AN EVICTION LOOKS LIKE IS NOTHING. `LLEN` answers 0, the claim comes back empty,
     * the flush ends, and `matomo:flush` prints "Flushed 0 Matomo hit(s)." and exits zero --
     * the same output an idle minute produces. Hits vanish and every signal stays green,
     * which is why this belongs in the command someone runs when they are already wondering
     * where the data went.
     *
     * Advisory, never fatal, like every other line this command prints: it is a diagnostic,
     * and a diagnostic that fails the run removes the diagnosis. Anything unreadable is
     * skipped in silence -- a Redis that does not answer CONFIG is a managed instance with
     * the command disabled, which is common and is not itself a finding.
     */
    /**
     * Whether the host this package talks to is reachable without TLS.
     *
     * THIS IS A WARNING AND NOT A REFUSAL, DELIBERATELY. Matomo on a private network without
     * TLS is a legitimate deployment, and refusing it would break installations that are fine.
     * What is NOT fine is that it happens silently: `token_auth` travels in the request BODY on
     * every hit, so a plaintext host puts an admin-capable credential on the wire each time —
     * and until now the only feedback was this command answering "Matomo OK".
     *
     * It sits beside the eviction warning for the same reason: this is the surface an operator
     * reaches for when they want to know whether the setup is sound.
     */
    /**
     * How much is waiting, and how much has been given up on.
     *
     * THE PACKAGE SHIPPED NO SUPPORTED WAY TO ASK EITHER QUESTION. `HitBuffer::size()` had
     * exactly one caller in the shipped tree — the load simulator — and `matomo:flush` reports
     * only the pass it just made, so "the buffer grows and never drains", which the
     * troubleshooting guide names as a symptom, could not be observed with anything the
     * package hands you. This is the command someone runs while wondering where the data went.
     *
     * Silent when both are zero, and silent about the buffer outside `batch` mode: the shipped
     * default is `queue`, where nothing writes to the buffer, so asking would stand a table up
     * to report a number that cannot be anything but zero. A line on every healthy run is a
     * line nobody reads.
     */
    /**
     * Say when the Redis holding the buffer would not survive its own restart.
     *
     * The claim-before-send contract is about a crashing PROCESS and says nothing about the
     * store. Measured: 5,000 hits buffered, `kill -9` on the server, restart — `size()`
     * answers 0, the next flush delivers 0 and exits zero, which is what an idle minute also
     * looks like. Persistence appeared nowhere in this package's documentation or output, and
     * neither `appendonly` nor a tight `save` is the default anywhere.
     */
    private function reportRedisPersistence(): void
    {
        if (Config::string('matomo-analytics.mode', 'queue') !== 'batch'
            || Config::string('matomo-analytics.batch.driver', 'database') !== 'redis') {
            return;
        }

        try {
            $connection = Redis::connection(Config::nullableString('matomo-analytics.batch.redis_connection') ?? 'default');
            $appendonly = $connection->command('config', ['GET', 'appendonly']);
            $save = $connection->command('config', ['GET', 'save']);
        } catch (Throwable) {
            return;
        }

        $aof = is_array($appendonly) ? ($appendonly['appendonly'] ?? $appendonly[1] ?? null) : null;
        $rdb = is_array($save) ? ($save['save'] ?? $save[1] ?? null) : null;

        if ($aof === 'yes') {
            return;
        }

        $this->warn(is_string($rdb) && trim($rdb) !== ''
            ? sprintf('Redis has appendonly off and saves on "%s" — a restart loses every hit buffered since the last save.', trim($rdb))
            : 'Redis has appendonly off and no save points — a restart loses the whole buffer.');
    }

    /**
     * Report the three PostgreSQL timeouts, because none of them can be set from Laravel.
     *
     * With no `lock_timeout`, the buffer write waits exactly as long as a lock on the table is
     * held — measured at 22.9 seconds against a 22.9-second `ACCESS EXCLUSIVE`, with no upper
     * bound, from the framework's `terminating()` callback and therefore inside a worker.
     * Laravel's `pgsql` connector has no option for these, so they live on the role or the
     * database, and a diagnostic is the only place a consumer would find out.
     */
    private function reportPostgresTimeouts(): void
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            $rows = $connection->select("SELECT name, setting FROM pg_settings WHERE name IN ('lock_timeout', 'statement_timeout', 'idle_in_transaction_session_timeout')");
        } catch (Throwable) {
            return;
        }

        $unset = [];

        foreach ($rows as $row) {
            $name = is_object($row) ? ($row->name ?? null) : null;
            $setting = is_object($row) ? ($row->setting ?? null) : null;

            if (is_string($name) && ($setting === '0' || $setting === 0)) {
                $unset[] = $name;
            }
        }

        if ($unset === []) {
            return;
        }

        $this->warn(sprintf(
            'PostgreSQL has %s unset, so a lock on the buffer table blocks the write that runs after each response for as long as the lock lasts. Set them with ALTER ROLE.',
            implode(' and ', $unset),
        ));
    }

    private function reportBacklog(): void
    {
        // EACH COUNT IS ITS OWN try, AND NEITHER MAY FAIL THE COMMAND. This is a
        // diagnostic: an unreachable Redis, a spool that is not there yet, a database with no
        // migration run — every one of those is a normal state for somebody typing
        // `matomo:test`, and turning any of them into an exception removes the connectivity
        // answer they actually came for. The same reasoning as the eviction probe below.
        //
        // Separate blocks rather than one, because the two stores fail independently: a Redis
        // buffer being unreachable says nothing about whether the dead-letter table can be
        // read, and one shared catch would hide the second number behind the first.
        if (Config::string('matomo-analytics.mode', 'queue') === 'batch') {
            try {
                $waiting = App::make(HitBuffer::class)->size();
            } catch (Throwable) {
                $waiting = 0;
            }

            if ($waiting > 0) {
                $this->warn(sprintf('Buffer: %d hit(s) waiting to be flushed.', $waiting));
            }
        }

        if (! Config::bool('matomo-analytics.batch.dead_letter.enabled', true)) {
            return;
        }

        try {
            $dead = App::make(DeadLetterStore::class)->count();
        } catch (Throwable) {
            return;
        }

        if ($dead > 0) {
            $this->warn(sprintf(
                'Dead letters: %d batch(es) gave up and are parked — inspect them, then `matomo:replay`.',
                $dead,
            ));
        }
    }

    private function reportPlaintextHost(): void
    {
        $host = Config::nullableString('matomo-analytics.host');

        if ($host === null || str_starts_with(strtolower($host), 'https://')) {
            return;
        }

        $this->warn(sprintf(
            'MATOMO_HOST is "%s", which is not https — token_auth travels in the request body on every hit, so an admin-capable credential crosses the network in clear text. That is supportable on a private network and nowhere else.',
            $host,
        ));
    }

    private function reportRedisEvictionPolicy(): void
    {
        if (Config::string('matomo-analytics.mode', 'queue') !== 'batch'
            || Config::string('matomo-analytics.batch.driver', 'database') !== 'redis') {
            return;
        }

        try {
            $policy = Redis::connection(Config::nullableString('matomo-analytics.batch.redis_connection') ?? 'default')
                ->command('config', ['GET', 'maxmemory-policy']);
        } catch (Throwable) {
            return;
        }

        // phpredis answers with a map, predis with a flat list. Take the last string either
        // way rather than indexing into a shape that depends on the extension.
        $values = is_array($policy) ? array_values(array_filter($policy, is_string(...))) : [];
        $value = $values === [] ? null : $values[count($values) - 1];

        if ($value === null || ! str_starts_with($value, 'allkeys')) {
            return;
        }

        $this->warn(sprintf(
            'Redis maxmemory-policy is "%s", so the buffer can be evicted — buffered hits would disappear with no error and no failed flush. Use noeviction or a volatile-* policy; the setting is instance-wide, so a separate logical database does not help.',
            $value,
        ));
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
