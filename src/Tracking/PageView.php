<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class PageView implements Hit
{
    public function __construct(
        public string $title,
        public ?string $url = null,
        public ?int $serverTimeMs = null,
    ) {}

    public function toParams(): array
    {
        $params = ['action_name' => $this->title];

        if ($this->url !== null) {
            $params['url'] = $this->url;
        }

        // pf_srv — the server generation time ("Serverzeit") of Matomo's page-performance report.
        if ($this->serverTimeMs !== null) {
            $params['pf_srv'] = $this->serverTimeMs;
        }

        return $params;
    }
}
