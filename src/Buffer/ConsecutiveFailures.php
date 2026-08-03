<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

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
        // ATOMIC, in two steps, and both are load-bearing.
        //
        // The read-modify-write this replaced (`current() + 1`, then `forever`) loses
        // increments when two drainers fail at the same moment — a scheduled
        // `matomo:flush` beside a `matomo:work` daemon is exactly that shape. Nothing is
        // lost from the buffer, but the stuck batch reaches `max_attempts` later than
        // configured, which is the one thing this counter exists to time.
        //
        // `add()` first, because `increment()` on a MISSING key is where cache stores
        // disagree: some initialize it, some answer `false` and write nothing. `add()`
        // writes 0 only if the key is absent, so `increment()` always has an integer to
        // work on and every store behaves the same way.
        //
        // THE TTL IS WHAT MAKES THAT ATOMIC, and it is not a tuning knob. Read
        // Illuminate\Cache\Repository::add(): the store's own atomic `add()` is reached
        // ONLY inside `if ($ttl !== null)`. Called without one, it falls through to
        // `is_null($this->get($key))` and then `put()` — a read-then-write, which is the
        // very race this method exists to close, reintroduced in the one window that
        // matters: the first failure after every reset. The version before this comment
        // passed no TTL and claimed atomicity in prose.
        //
        // A day is arbitrary and safe to be arbitrary: `reset()` deletes the key on every
        // success, and a counter that survives a full day of uninterrupted failure has
        // long since tripped `batch.max_attempts`.
        Cache::add(self::KEY, 0, Date::now()->addDay());

        $next = Cache::increment(self::KEY);

        return is_int($next) ? $next : $this->current();
    }

    public function reset(): void
    {
        Cache::forget(self::KEY);
    }
}
