<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class Download implements Hit
{
    public function __construct(
        public string $url,
    ) {}

    public function toParams(): array
    {
        return ['download' => $this->url];
    }
}
