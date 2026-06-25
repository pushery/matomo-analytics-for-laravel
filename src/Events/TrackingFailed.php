<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

use Throwable;

final readonly class TrackingFailed
{
    public function __construct(
        public Throwable $exception,
    ) {}
}
