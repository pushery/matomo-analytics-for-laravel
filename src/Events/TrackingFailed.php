<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

use Throwable;

/**
 * A delivery that will not be attempted again: a send in `sync` mode that failed, a queued batch
 * Matomo rejected or that ran out of attempts, or a batch the flusher dead-lettered.
 */
final readonly class TrackingFailed
{
    public function __construct(
        public Throwable $exception,
    ) {}
}
