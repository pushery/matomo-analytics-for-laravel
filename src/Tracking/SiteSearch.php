<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class SiteSearch implements Hit
{
    public function __construct(
        public string $keyword,
        public ?string $category = null,
        public ?int $count = null,
    ) {}

    public function toParams(): array
    {
        $params = ['search' => $this->keyword];

        if ($this->category !== null) {
            $params['search_cat'] = $this->category;
        }

        if ($this->count !== null) {
            $params['search_count'] = $this->count;
        }

        return $params;
    }
}
