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
    ) {}

    /**
     * A run that delivered nothing and lost at least one batch.
     *
     * Deliberately narrower than "any batch was dead-lettered". A single poison batch among
     * thousands of delivered hits is the dead-letter queue doing its job, and reporting
     * failure for it would train the reader to ignore the signal. Zero delivered AND
     * something lost is the shape a misconfiguration makes, and nothing else does.
     */
    public function isStuck(): bool
    {
        return $this->delivered === 0 && $this->deadLettered > 0;
    }
}
