<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class Outlink implements Hit
{
    public function __construct(
        public string $url,
    ) {}

    public function toParams(): array
    {
        return ['link' => $this->url];
    }
}
