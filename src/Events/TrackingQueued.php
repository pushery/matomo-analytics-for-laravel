<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

final readonly class TrackingQueued
{
    /**
     * @param  list<array<string, scalar>>  $payloads
     */
    public function __construct(
        public array $payloads,
    ) {}
}
