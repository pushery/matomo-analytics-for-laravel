<?php

declare(strict_types=1);

namespace MatomoAnalytics\Events;

/**
 * Fired when a GDPR erasure deletes one or more data-subject visits — useful as an
 * audit signal for "right to be forgotten" requests.
 */
final readonly class DataSubjectForgotten
{
    /**
     * @param  array<string, int>  $deleted  deletion counts keyed by storage area
     */
    public function __construct(
        public int $visits,
        public array $deleted = [],
    ) {}
}
