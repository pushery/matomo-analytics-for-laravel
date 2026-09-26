<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config as ConfigFacade;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Buffer\ArrayHitBuffer;
use MatomoAnalytics\Buffer\BufferFlusher;
use MatomoAnalytics\Buffer\BufferManager;
use MatomoAnalytics\Buffer\ConsecutiveFailures;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Buffer\RedisHitBuffer;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\PayloadBuilder;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use MatomoAnalytics\Tracking\PageView;
use MatomoAnalytics\Transport\NullSender;

/**
 * Fires N synthetic hits through the real build -> buffer -> flush pipeline and
 * reports throughput, Bulk POST count and peak memory — an operator tool for
 * sizing a deployment. By default it discards the sends (NullSender), so nothing
 * reaches Matomo; --against=real exercises the configured instance end to end.
 *
 * Every store the run writes to is its own: the buffer (in memory by default, or a spool
 * directory, a Redis key or tables of the run's own), the dead letters and the count of failed
 * flushes. The application's buffer holds its visitors' hits, and a run that drained it would
 * hand them to the fake sender and delete them, while a flush of the application claimed the
 * synthetic ones and sent them to Matomo. What the run leaves is removed when it ends, and the
 * configuration it changed is put back.
 */
final class LoadSimCommand extends Command
{
    /** The configuration a run changes, put back when it ends. */
    private const array OVERRIDDEN = [
        'matomo-analytics.events',
        'matomo-analytics.batch.size',
        'matomo-analytics.batch.path',
        'matomo-analytics.batch.table',
        'matomo-analytics.batch.dead_letter.table',
    ];

    /** Names the run's own spool directory, Redis key, tables and failure count, and only those are removed. */
    private const string OWN = 'load_sim';

    /** The cache key of the run's own count of failed flushes. */
    private const string FAILURES = 'matomo-analytics:'.self::OWN.':consecutive-failures';

    protected $signature = 'matomo:load-sim
        {--hits=1000 : Number of synthetic hits to enqueue and drain}
        {--driver=array : Buffer driver to exercise (array|database|redis|file), each in a store of the run\'s own}
        {--batch= : Bulk batch size (default: the configured batch.size)}
        {--against=fake : "fake" discards the sends (measures the client), "real" sends to the configured Matomo}';

    protected $description = 'Simulate load through the real buffer + flush pipeline and report throughput.';

    public function handle(BufferManager $buffers, PayloadBuilder $builder, Reporter $reporter): int
    {
        $saved = [];

        foreach (self::OVERRIDDEN as $key) {
            $saved[$key] = ConfigFacade::get($key);
        }

        try {
            return $this->simulate($buffers, $builder, $reporter);
        } finally {
            foreach ($saved as $key => $value) {
                ConfigFacade::set($key, $value);
            }
        }
    }

    private function simulate(BufferManager $buffers, PayloadBuilder $builder, Reporter $reporter): int
    {
        $hits = max(1, $this->intOption('hits', 1000));

        $batch = $this->intOption('batch', 0);
        if ($batch > 0) {
            ConfigFacade::set('matomo-analytics.batch.size', $batch);
        }

        // A sim can enqueue a lot; don't flood the event bus while measuring.
        ConfigFacade::set('matomo-analytics.events', false);

        $driver = $this->stringOption('driver') ?? 'array';
        $buffer = $this->ownBuffer($buffers, $driver);
        $failures = new ConsecutiveFailures(self::FAILURES);

        try {
            $sender = $this->stringOption('against') === 'real' ? App::make(Sender::class) : new NullSender;

            $this->info(sprintf('Enqueuing %s synthetic hits…', number_format($hits)));

            $template = $builder->build(
                new PageView('Load sim'),
                Request::create('/matomo-load-sim', 'GET', server: ['HTTP_USER_AGENT' => 'matomo-load-sim']),
            );

            $enqueueStart = microtime(true);
            for ($i = 0; $i < $hits; $i++) {
                $buffer->push($template);
            }
            $enqueueSeconds = microtime(true) - $enqueueStart;

            $flusher = new BufferFlusher($buffer, $sender, $reporter, new DeadLetterStore, $failures);

            $flushStart = microtime(true);
            $delivered = 0;
            while ($buffer->size() > 0) {
                $before = $buffer->size();
                $delivered += $flusher->flush();

                if ($buffer->size() >= $before) {
                    $this->warn('Flush made no progress — stopping (a real endpoint may be unreachable).');

                    break;
                }
            }
            $flushSeconds = microtime(true) - $flushStart;

            $posts = $sender instanceof NullSender
                ? $sender->posts
                : (int) ceil($delivered / max(1, Config::int('matomo-analytics.batch.size', 200)));

            $this->info(sprintf('Delivered %s hit(s) in %s bulk POST(s).', number_format($delivered), number_format($posts)));
            $this->render($hits, $delivered, $posts, $enqueueSeconds, $flushSeconds);
        } finally {
            $this->removeOwnStores($driver, $buffer);
            // A fresh instance, because the flusher's may already count as cleared.
            new ConsecutiveFailures(self::FAILURES)->reset();
        }

        return self::SUCCESS;
    }

    /**
     * A buffer of the run's own in the driver asked for, with a dead-letter table of its own.
     *
     * An unknown driver name is the database, as it is for `BufferManager`, so that no name
     * reaches the application's table past this method.
     */
    private function ownBuffer(BufferManager $buffers, string $driver): HitBuffer
    {
        ConfigFacade::set(
            'matomo-analytics.batch.dead_letter.table',
            Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters').'_'.self::OWN,
        );

        return match ($driver) {
            'array' => new ArrayHitBuffer,
            'file' => $this->ownSpool($buffers),
            'redis' => $this->ownRedisList(),
            default => $this->ownTables($buffers),
        };
    }

    private function ownSpool(BufferManager $buffers): HitBuffer
    {
        $dir = rtrim(Config::string('matomo-analytics.batch.path', App::storagePath('app/matomo-analytics')), '/').'/'.self::OWN;

        $this->removeDirectory($dir);
        ConfigFacade::set('matomo-analytics.batch.path', $dir);

        return $buffers->driver('file');
    }

    private function ownRedisList(): HitBuffer
    {
        $buffer = new RedisHitBuffer('matomo-analytics:'.self::OWN);
        $this->empty($buffer);

        return $buffer;
    }

    /**
     * The run's buffer and dead-letter tables, made by the package's own migrations so that they
     * have the shape of the application's tables on every engine the package supports.
     */
    private function ownTables(BufferManager $buffers): HitBuffer
    {
        ConfigFacade::set(
            'matomo-analytics.batch.table',
            Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer').'_'.self::OWN,
        );

        $this->dropOwnTables();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $file) {
            $migration = require $file;

            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }

        return $buffers->driver('database');
    }

    private function removeOwnStores(string $driver, HitBuffer $buffer): void
    {
        match ($driver) {
            'array' => null,
            'file' => $this->removeOwnSpool(),
            'redis' => $this->empty($buffer),
            default => $this->dropOwnTables(),
        };
    }

    private function removeOwnSpool(): void
    {
        $dir = Config::string('matomo-analytics.batch.path', '');

        if (basename($dir) === self::OWN) {
            $this->removeDirectory($dir);
        }
    }

    /**
     * Removes the run's spool directory with the files in it.
     *
     * The spool is flat, one queue file and one file per claimed batch, so its files are
     * removed one by one. Plain PHP rather than the `File` facade, because that facade resolves
     * to illuminate/filesystem, a component this package does not require.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            @unlink($dir.'/'.$name);
        }

        @rmdir($dir);
    }

    private function dropOwnTables(): void
    {
        foreach (['matomo-analytics.batch.table', 'matomo-analytics.batch.dead_letter.table'] as $key) {
            $table = Config::string($key, '');

            if (str_ends_with($table, '_'.self::OWN)) {
                Schema::dropIfExists($table);
            }
        }
    }

    /** Claims and deletes whatever the buffer holds, sending nothing. */
    private function empty(HitBuffer $buffer): void
    {
        do {
            $claimed = $buffer->claim(1000);
            $buffer->ack($claimed);
        } while (! $claimed->claimedNothing());
    }

    private function render(int $hits, int $delivered, int $posts, float $enqueueSeconds, float $flushSeconds): void
    {
        $this->table(['Metric', 'Value'], [
            ['Hits enqueued', number_format($hits)],
            ['Hits delivered', number_format($delivered)],
            ['Bulk POSTs', number_format($posts)],
            ['Enqueue throughput', $this->rate($hits, $enqueueSeconds)],
            ['Flush throughput', $this->rate($delivered, $flushSeconds)],
            ['Peak memory', sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576)],
        ]);
    }

    private function rate(int $count, float $seconds): string
    {
        $perSecond = $seconds > 0.0 ? $count / $seconds : 0.0;

        return sprintf('%s hits/s', number_format($perSecond));
    }

    private function intOption(string $key, int $default): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
