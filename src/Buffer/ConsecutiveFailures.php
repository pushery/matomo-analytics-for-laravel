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

    /** Whether this instance has already cleared the counter — see reset(). */
    private bool $cleared = false;

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

        $this->cleared = false;

        $next = Cache::increment(self::KEY);

        return is_int($next) ? $next : $this->current();
    }

    /**
     * Forget the counter, at most once per instance until it is incremented again.
     *
     * THIS WAS A ROUND TRIP PER DELIVERED BATCH, ALMOST ALWAYS ON A KEY THAT DOES NOT
     * EXIST. `deliver()` calls it on every success, so a fully healthy 2000-hit flush issued
     * 40 `DEL` commands — counted at a TCP relay. At 1ms of round-trip time that is 40ms per
     * flush and, on a per-minute schedule, 57,600 consequence-free round trips per day per
     * application. Thirty-nine of the forty are repeats of a delete that already happened
     * inside the same run.
     *
     * THE FLAG SAYS "ALREADY CLEARED", NOT "NEVER INCREMENTED", AND THE DIFFERENCE IS THE
     * WHOLE CORRECTNESS OF THIS. The counter is CROSS-PROCESS by design — it is what carries a
     * failure from one scheduled `matomo:flush` to the next, and each of those is a new
     * process. A flag meaning "this instance never incremented" would therefore skip the very
     * delete that matters, the one clearing what the PREVIOUS run left, and nothing but the
     * TTL would ever clear the counter again. The first version of this was written that way
     * and an existing arm caught it.
     *
     * So the first `reset()` after construction always reaches the cache, and only the repeats
     * within one drain are dropped. Every cross-process guarantee is unchanged.
     */
    public function reset(): void
    {
        if ($this->cleared) {
            return;
        }

        $this->cleared = true;

        Cache::forget(self::KEY);
    }
}
