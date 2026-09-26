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
    /** The levels a PSR-3 logger accepts; `Log::log()` throws on any other. */
    private const array LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function shouldReport(int $attempt): bool
    {
        return $attempt >= Config::int('matomo-analytics.resilience.reporting.report_after_attempts', 3);
    }

    /**
     * Report a failure, and never throw while doing it.
     *
     * Every caller reaches this from a catch block whose promise is that tracking cannot break
     * the host, and a report that throws breaks that promise one frame later. The likeliest
     * way is the ordinary setup of one Redis, or one database, behind both the queue and the
     * cache: the dispatch fails, and the throttle's `Cache::add()` fails the same way. So the
     * throttle lets the report through when it cannot be asked, an unknown log level falls
     * back to `warning`, and anything still failing ends here: there is no channel left to
     * report it on.
     *
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

        try {
            Log::log(
                $this->level(Config::string('matomo-analytics.resilience.reporting.level', 'warning'), 'warning'),
                'Matomo tracking failed: '.$e->getMessage(),
                $context,
            );

            if ($channel === 'report') {
                // NOT the report() helper: that one is Foundation-only, and this package
                // requires illuminate components rather than laravel/framework. The helper
                // does exactly this — resolve the handler and call report on it.
                App::make(ExceptionHandler::class)->report($e);
            }
        } catch (Throwable) {
            // The log or the application's handler failed, and neither has anywhere to go.
        }
    }

    public function recordTransient(Throwable $e): void
    {
        $level = Config::nullableString('matomo-analytics.resilience.reporting.transient_level');
        if ($level === null) {
            return;
        }

        try {
            Log::log($this->level($level, 'info'), 'Matomo tracking retrying: '.$e->getMessage());
        } catch (Throwable) {
            // Same as report(): a retry note that cannot be written is not worth an exception.
        }
    }

    /** The configured level when a logger accepts it, the fallback when it would throw. */
    private function level(string $configured, string $fallback): string
    {
        return in_array($configured, self::LEVELS, true) ? $configured : $fallback;
    }

    private function passesThrottle(Throwable $e): bool
    {
        $minutes = Config::int('matomo-analytics.resilience.reporting.throttle_minutes', 15);
        if ($minutes <= 0) {
            return true;
        }

        $key = 'matomo-analytics:report:'.md5($e::class.'|'.$this->signature($e->getMessage()));

        // A throttle that cannot be asked lets the report through: reporting once too often
        // is recoverable, and throwing from here is not.
        try {
            return Cache::add($key, true, Date::now()->addMinutes($minutes));
        } catch (Throwable) {
            return true;
        }
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
