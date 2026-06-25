<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class Goal implements Hit
{
    public function __construct(
        public int $id,
        public ?float $revenue = null,
    ) {}

    public function toParams(): array
    {
        $params = ['idgoal' => $this->id];

        if ($this->revenue !== null) {
            $params['revenue'] = $this->revenue;
        }

        return $params;
    }
}
