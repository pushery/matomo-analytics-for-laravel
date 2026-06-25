<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * A single trackable Matomo action (page view, event, site search, …). A hit
 * carries only its action-specific parameters; request context and visitor
 * identity are layered on by the PayloadBuilder.
 */
interface Hit
{
    /**
     * @return array<string, scalar>
     */
    public function toParams(): array;
}
