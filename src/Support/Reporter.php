<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
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
            // NOT the report() helper: that one is Foundation-only, and this package
            // requires illuminate components rather than laravel/framework. The helper
            // does exactly this — resolve the handler and call report on it.
            App::make(ExceptionHandler::class)->report($e);
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

        $key = 'matomo-analytics:report:'.md5($e::class.'|'.$this->signature($e->getMessage()));

        return Cache::add($key, true, Date::now()->addMinutes($minutes));
    }

    /**
     * A message reduced to what stays the same across repetitions of one failure.
     *
     * THE THROTTLE PROMISES A SIGNATURE AND A CONNECTION FAILURE HAS NO STABLE MESSAGE.
     * Guzzle writes the elapsed time into it — `Operation timed out after 2002 milliseconds` —
     * so hashing the raw message gives a sustained outage a fresh key on almost every attempt,
     * which is exactly the flood `throttle_minutes` exists to prevent. Measured: three
     * identical failures produced two keys and four log records.
     *
     * AND THE OBVIOUS FIX IS THE WRONG ONE. Stripping every digit run would fold
     * `HTTP 400` and `HTTP 500` into one signature, so the second, different failure would be
     * silenced by the first — a worse defect than the one being repaired, and a silent one.
     * Only a number attached to a time unit is normalized, because only that varies while the
     * failure stays the same.
     */
    private function signature(string $message): string
    {
        return (string) preg_replace(
            '/\b\d+(?:[.,]\d+)?\s*(?:milliseconds?|microseconds?|seconds?|minutes?|ms|us|µs|secs?)\b/i',
            '<duration>',
            $message,
        );
    }
}
