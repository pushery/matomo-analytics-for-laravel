<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class PageView implements Hit
{
    public function __construct(
        public string $title,
        public ?string $url = null,
    ) {}

    public function toParams(): array
    {
        $params = ['action_name' => $this->title];

        if ($this->url !== null) {
            $params['url'] = $this->url;
        }

        return $params;
    }
}
