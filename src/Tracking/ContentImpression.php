<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * A Matomo Content Tracking impression: a content block (name) was displayed,
 * optionally with the specific piece shown (image/text/…) and its target (the
 * link/URL it points to). The client-side tracker can collect these
 * automatically (js.content_tracking); this hit records one server-side.
 */
final readonly class ContentImpression implements Hit
{
    public function __construct(
        public string $name,
        public ?string $piece = null,
        public ?string $target = null,
    ) {}

    public function toParams(): array
    {
        $params = ['c_n' => $this->name];

        if ($this->piece !== null) {
            $params['c_p'] = $this->piece;
        }

        if ($this->target !== null) {
            $params['c_t'] = $this->target;
        }

        return $params;
    }
}
