<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class Ping implements Hit
{
    public function toParams(): array
    {
        return ['ping' => 1];
    }
}
