<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\Cache;

/**
 * A small cross-run counter of consecutive failed flushes, kept in the cache so it
 * survives between scheduled runs. It distinguishes a brief Matomo outage (retry)
 * from a sustained one (eventually dead-letter the stuck batch). Reset on success.
 */
final class ConsecutiveFailures
{
    private const string KEY = 'matomo-analytics:flush:consecutive-failures';

    public function current(): int
    {
        $value = Cache::get(self::KEY);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    public function increment(): int
    {
        $next = $this->current() + 1;
        Cache::forever(self::KEY, $next);

        return $next;
    }

    public function reset(): void
    {
        Cache::forget(self::KEY);
    }
}
