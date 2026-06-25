<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use MatomoAnalytics\Tracking\Hit;

interface Tracker
{
    public function track(Hit $hit): static;

    public function pageView(string $title, ?string $url = null): static;

    public function event(string $category, string $action, ?string $name = null, int|float|null $value = null): static;

    public function siteSearch(string $keyword, ?string $category = null, ?int $count = null): static;

    public function goal(int $id, ?float $revenue = null): static;

    public function download(string $url): static;

    public function outlink(string $url): static;

    public function ping(): static;

    public function flush(): void;
}
