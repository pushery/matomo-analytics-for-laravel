<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Buffer\BufferFlusher;
use MatomoAnalytics\Support\Config;

/**
 * Long-running buffer drainer for a Supervisor / Forge daemon. The scheduled
 * matomo:flush is the simpler default; use this for high-volume file/redis spools.
 * Run a single instance (the atomic claims keep concurrent runs from double-sending).
 */
final class WorkCommand extends Command
{
    protected $signature = 'matomo:work {--once : Drain the buffer once and exit} {--max-runs=0 : Stop after this many runs (0 = run continuously)}';

    protected $description = 'Continuously flush the buffered Matomo hits.';

    public function handle(BufferFlusher $flusher): int
    {
        $interval = max(1, Config::int('matomo-analytics.batch.flush_interval', 60));
        $maxRunsOption = $this->option('max-runs');
        $maxRuns = is_numeric($maxRunsOption) ? max(0, (int) $maxRunsOption) : 0;
        $runs = 0;

        while (true) {
            $flusher->flush();
            $runs++;

            if ($this->option('once') === true || ($maxRuns > 0 && $runs >= $maxRuns)) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }
}
