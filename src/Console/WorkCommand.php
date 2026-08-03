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

    /**
     * Set by the shutdown signal, read at the end of each loop iteration.
     *
     * The flag stays private; the door is `stopAfterCurrentRun()`, which is what both the
     * signal handler and a test go through. With it already set, the loop must perform
     * exactly ONE flush and return — that is the whole contract a delivered SIGTERM buys.
     */
    private bool $shouldStop = false;

    public function handle(BufferFlusher $flusher): int
    {
        $interval = max(1, Config::int('matomo-analytics.batch.flush_interval', 60));
        $maxRuns = $this->intOption('max-runs');
        $maxTime = $this->intOption('max-time');
        $maxMemory = $this->intOption('memory');
        $start = microtime(true);
        $runs = 0;

        // Shut down between runs, not in the middle of one. A supervisor restart or a
        // deploy sends SIGTERM, and without this the signal lands wherever the process
        // happens to be — most often inside sleep(), but possibly mid-flush. The atomic
        // claim makes even that case safe (a killed drainer's batch is reclaimed as
        // stale), so this is not a correctness fix; it is the difference between a clean
        // stop and one that leaves a batch to time out first. Same shape as queue:work.
        //
        // The signal list is a CLOSURE, not an array, and that is not a style choice.
        // `trap()` guards REGISTRATION behind `Signals::whenAvailable()`, but an array
        // argument dereferences SIGTERM and SIGINT before the call is even made — and
        // those constants come from ext-pcntl, which this package does not require
        // (composer.json asks for php and ext-json). On a host without it, the array
        // form is an "Undefined constant" fatal in a command that would otherwise run
        // perfectly well without signal handling. `trap()` passes the argument through
        // `value()`, so a closure is evaluated only where the constants exist.
        //
        // The HANDLER is a first-class callable rather than a closure for a second,
        // unrelated reason: a closure body here is only ever entered by a delivered
        // signal, which no test can trigger without risking the runner itself. Pointing
        // at a named method gives the stop path a seam a test can drive directly. The
        // coverage gate proved that is not hypothetical — the closure form left two
        // unreachable lines and dropped this class to 95.7%.
        $this->trap(fn (): array => [SIGTERM, SIGINT], $this->stopAfterCurrentRun(...));

        while (true) {
            $flusher->flush();
            $runs++;

            if ($this->shouldStop
                || $this->option('once') === true
                || ($maxRuns > 0 && $runs >= $maxRuns)
                || ($maxTime > 0 && (microtime(true) - $start) >= $maxTime)
                || ($maxMemory > 0 && memory_get_usage(true) >= $maxMemory * 1048576)) {
                break;
            }

            sleep($interval);
        }

        return self::SUCCESS;
    }

    /**
     * Stop after the flush that is currently in progress.
     *
     * The signal handler, and the only thing it does. Naming it rather than inlining a
     * closure is what makes the shutdown path reachable from a test — see handle().
     */
    public function stopAfterCurrentRun(): void
    {
        $this->shouldStop = true;
    }

    private function intOption(string $key): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
