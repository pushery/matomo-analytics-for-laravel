<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * A Matomo Content Tracking interaction: a visitor interacted (e.g. "click")
 * with a content block. Carries the interaction verb plus the same
 * name/piece/target identifying the block that received it.
 */
final readonly class ContentInteraction implements Hit
{
    public function __construct(
        public string $interaction,
        public string $name,
        public ?string $piece = null,
        public ?string $target = null,
    ) {}

    public function toParams(): array
    {
        $params = [
            'c_i' => $this->interaction,
            'c_n' => $this->name,
        ];

        if ($this->piece !== null) {
            $params['c_p'] = $this->piece;
        }

        if ($this->target !== null) {
            $params['c_t'] = $this->target;
        }

        return $params;
    }
}
