<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use MatomoAnalytics\Privacy\DataSubject;

/**
 * A hit buffer that can remove one data subject's hits, for GDPR erasure.
 *
 * Every driver this package ships implements it. A driver that does not is reported by the
 * erasure as not searched, never as holding nothing.
 */
interface ErasableHitBuffer extends HitBuffer
{
    /**
     * Remove every buffered hit the subject owns and return how many were removed.
     *
     * Hits in a claimed batch are removed as well. A batch already in flight reaches Matomo
     * regardless; what is removed from it is what a release or a stale reclaim would otherwise
     * put back into the buffer.
     */
    public function erase(DataSubject $subject): int;
}
