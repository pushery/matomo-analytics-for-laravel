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
    /** Whether this instance has already cleared the counter in the current run — see reset(). */
    private bool $cleared = false;

    /**
     * @param  string  $key  the cache key of the count; `matomo:load-sim` keeps its own apart
     *                       from the application's, whose count a simulated flush would reset
     */
    public function __construct(private readonly string $key = 'matomo-analytics:flush:consecutive-failures') {}

    public function current(): int
    {
        $value = Cache::get($this->key);

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
        Cache::add($this->key, 0, Date::now()->addDay());

        $this->cleared = false;

        $next = Cache::increment($this->key);

        return is_int($next) ? $next : $this->current();
    }

    /**
     * Start a run: the next `reset()` reaches the cache again.
     *
     * `BufferFlusher::drain()` calls this first, so the memo in `reset()` lasts one drain. A
     * drainer that lives for many drains, as `matomo:work` does, then still clears a counter
     * another process raised in between.
     */
    public function beginRun(): void
    {
        $this->cleared = false;
    }

    /**
     * Forget the counter, at most once per run until it is incremented again.
     *
     * `deliver()` calls this after every successful batch, and the key is almost always absent,
     * so a delete per batch would cost a round trip each for nothing: forty for a healthy
     * 2000-hit flush at a batch size of 50. Only the first reset of a run reaches the cache, and
     * that first one always does. The counter is shared across processes and carries a failure
     * from one scheduled `matomo:flush` to the next, so the delete that matters is the one
     * clearing what another run left. `beginRun()` marks where a run starts.
     */
    public function reset(): void
    {
        if ($this->cleared) {
            return;
        }

        $this->cleared = true;

        Cache::forget($this->key);
    }
}
