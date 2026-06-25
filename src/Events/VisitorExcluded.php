<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

final readonly class VisitorExcluded
{
    public function __construct(
        public string $reason,
    ) {}
}
