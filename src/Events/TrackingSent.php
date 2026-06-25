<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

final readonly class TrackingSent
{
    public function __construct(
        public int $count,
        public int $status,
    ) {}
}
