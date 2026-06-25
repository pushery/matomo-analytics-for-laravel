<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

/**
 * Fired when a batch is moved to the dead-letter queue after exhausting delivery.
 */
final readonly class HitsDeadLettered
{
    public function __construct(
        public int $count,
        public int $attempts,
    ) {}
}
