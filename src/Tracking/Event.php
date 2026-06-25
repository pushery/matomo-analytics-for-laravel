<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

final readonly class Event implements Hit
{
    public function __construct(
        public string $category,
        public string $action,
        public ?string $name = null,
        public int|float|null $value = null,
    ) {}

    public function toParams(): array
    {
        $params = [
            'e_c' => $this->category,
            'e_a' => $this->action,
        ];

        if ($this->name !== null) {
            $params['e_n'] = $this->name;
        }

        if ($this->value !== null) {
            $params['e_v'] = $this->value;
        }

        return $params;
    }
}
