<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

/**
 * What one drain of the buffer actually did.
 *
 * `flush()` returns the delivered hit count and nothing else, which cannot tell a quiet run
 * apart from a losing one: a wrong `site_id` makes Matomo answer 4xx to every batch, each
 * batch is dead-lettered as poison, and the run ends with zero delivered — exactly the number
 * an idle minute produces. The consecutive-failure counter does not close that gap either,
 * because it only counts TRANSIENT failures; the permanent branch never touches it. So the
 * single most common misconfiguration in this package produced a green scheduled command
 * every minute while nothing arrived.
 *
 * Kept as a separate return path rather than a wider `flush()` signature: the delivered count
 * is what nearly every caller wants, and changing that shape for the one caller that needs
 * more would be a worse trade.
 */
final readonly class FlushOutcome
{
    public function __construct(
        public int $delivered,
        public int $deadLettered,
        public bool $unavailable = false,
    ) {}

    /**
     * A run that is not moving hits and should say so.
     *
     * Two shapes, and deliberately narrower than "any batch was dead-lettered". A single
     * poison batch among thousands of delivered hits is the dead-letter queue doing its job,
     * and reporting failure for it would train the reader to ignore the signal.
     *
     * - Zero delivered AND something lost is what a wrong host, site id or token makes.
     * - The buffer could not be claimed from at all — a spool directory nobody can write to,
     *   a full disk, a read-only mount. That one used to be INVISIBLE: the driver had nothing
     *   to return but an empty batch, an empty batch is how the flusher learns the buffer is
     *   drained, and so the command printed `Flushed 0 Matomo hit(s).` and exited zero every
     *   minute over hits that were still sitting in the file.
     */
    public function isStuck(): bool
    {
        return $this->unavailable || ($this->delivered === 0 && $this->deadLettered > 0);
    }

    /**
     * What to tell someone about a stuck run, or null when it is not stuck.
     *
     * It lives here rather than in each command because both `matomo:flush` and `matomo:work`
     * need it and the two must not drift — and because the two causes need different words.
     * "Nothing was delivered and 0 batches were dead-lettered" is what the shared message said
     * about an unreachable buffer, which points the reader at the host, the site id and the
     * token: three things that are fine.
     */
    public function stuckReason(): ?string
    {
        if ($this->unavailable) {
            return 'The buffer could not be read — check the batch driver and that its store is reachable and writable.';
        }

        if ($this->delivered === 0 && $this->deadLettered > 0) {
            return sprintf(
                'Nothing was delivered and %d batch(es) were dead-lettered — check host, site id and token.',
                $this->deadLettered,
            );
        }

        return null;
    }
}
