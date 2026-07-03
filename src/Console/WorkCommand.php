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
    protected $signature = 'matomo:work
        {--once : Drain the buffer once and exit}
        {--max-runs=0 : Stop after this many runs (0 = run continuously)}
        {--max-time=0 : Stop after roughly this many seconds (0 = no limit)}
        {--memory=0 : Stop when memory use exceeds this many MB so a supervisor can recycle it (0 = no limit)}';

    protected $description = 'Continuously flush the buffered Matomo hits.';

    public function handle(BufferFlusher $flusher): int
    {
        $interval = max(1, Config::int('matomo-analytics.batch.flush_interval', 60));
        $maxRuns = $this->intOption('max-runs');
        $maxTime = $this->intOption('max-time');
        $maxMemory = $this->intOption('memory');
        $start = microtime(true);
        $runs = 0;

        while (true) {
            $flusher->flush();
            $runs++;

            if ($this->option('once') === true
                || ($maxRuns > 0 && $runs >= $maxRuns)
                || ($maxTime > 0 && (microtime(true) - $start) >= $maxTime)
                || ($maxMemory > 0 && memory_get_usage(true) >= $maxMemory * 1048576)) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
