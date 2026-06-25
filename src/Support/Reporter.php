<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Failure alerting policy. A single transient timeout never pages Flare/
 * Nightwatch/Sentry: failures are reported only after a configurable number of
 * attempts, via a configurable channel, and throttled per error signature so a
 * sustained Matomo outage cannot flood monitoring.
 */
final class Reporter
{
    public function shouldReport(int $attempt): bool
    {
        return $attempt >= Config::int('matomo-analytics.resilience.reporting.report_after_attempts', 3);
    }

    /**
     * @param  array<string, scalar>  $context
     */
    public function report(Throwable $e, array $context = []): void
    {
        $channel = Config::string('matomo-analytics.resilience.reporting.channel', 'report');
        if ($channel === 'silent') {
            return;
        }

        if (! $this->passesThrottle($e)) {
            return;
        }

        Log::log(
            Config::string('matomo-analytics.resilience.reporting.level', 'warning'),
            'Matomo tracking failed: '.$e->getMessage(),
            $context,
        );

        if ($channel === 'report') {
            report($e);
        }
    }

    public function recordTransient(Throwable $e): void
    {
        $level = Config::nullableString('matomo-analytics.resilience.reporting.transient_level');
        if ($level !== null) {
            Log::log($level, 'Matomo tracking retrying: '.$e->getMessage());
        }
    }

    private function passesThrottle(Throwable $e): bool
    {
        $minutes = Config::int('matomo-analytics.resilience.reporting.throttle_minutes', 15);
        if ($minutes <= 0) {
            return true;
        }

        $key = 'matomo-analytics:report:'.md5($e::class.'|'.$e->getMessage());

        return Cache::add($key, true, now()->addMinutes($minutes));
    }
}
