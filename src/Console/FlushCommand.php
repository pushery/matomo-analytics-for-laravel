<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Buffer\BufferFlusher;
use MatomoAnalytics\Buffer\ConsecutiveFailures;
use MatomoAnalytics\Support\Config;

final class FlushCommand extends Command
{
    protected $signature = 'matomo:flush';

    protected $description = 'Flush buffered Matomo hits to the tracking endpoint.';

    public function handle(BufferFlusher $flusher, ConsecutiveFailures $failures): int
    {
        $this->info(sprintf('Flushed %d Matomo hit(s).', $flusher->flush()));

        // Surface a persistently failing drain to the scheduler's failure hooks and
        // exit-code monitors: once consecutive failures reach the alerting threshold the
        // drain is stuck, so report FAILURE instead of masking it as a green run.
        if ($failures->current() >= max(1, Config::int('matomo-analytics.resilience.reporting.report_after_attempts', 3))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
