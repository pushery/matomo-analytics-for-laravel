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

        // An empty name is no name, as matomo-php-tracker reads it: Matomo would otherwise show
        // an event with a blank name beside the same event without one.
        if ($this->name !== null && $this->name !== '') {
            $params['e_n'] = $this->name;
        }

        if ($this->value !== null) {
            $params['e_v'] = $this->value;
        }

        return $params;
    }
}
