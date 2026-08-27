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
        $outcome = $flusher->drain();

        $this->info(sprintf('Flushed %d Matomo hit(s).', $outcome->delivered));

        // A RUN THAT DELIVERED NOTHING AND LOST SOMETHING. The check below it counts only
        // TRANSIENT failures, because `ConsecutiveFailures` has exactly one increment site
        // and it sits in the transient branch — so the most common misconfiguration in this
        // package, a wrong site id or host, made Matomo answer 4xx to every batch, dead-
        // lettered each one as poison, and ended the command green with "Flushed 0 Matomo
        // hit(s)." A quiet minute prints the same line.
        //
        // The scheduled registration runs this in the background, where a non-zero exit no
        // longer throws — so this code is for the reader who runs it by hand, for
        // `matomo:work`, and for whatever watches exit codes. The events remain the channel
        // for the scheduled path.
        if ($outcome->isStuck()) {
            $this->error(sprintf(
                'Nothing was delivered and %d batch(es) were dead-lettered — check host, site id and token.',
                $outcome->deadLettered,
            ));

            return self::FAILURE;
        }

        // Surface a persistently failing drain to the scheduler's failure hooks and
        // exit-code monitors: once consecutive failures reach the alerting threshold the
        // drain is stuck, so report FAILURE instead of masking it as a green run.
        if ($failures->current() >= max(1, Config::int('matomo-analytics.resilience.reporting.report_after_attempts', 3))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
