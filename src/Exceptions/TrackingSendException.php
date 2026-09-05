<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

final class TrackingSendException extends RuntimeException
{
    public static function status(int $status): self
    {
        return new self('Matomo tracking request failed with HTTP '.$status);
    }

    /**
     * Matomo answered 200 and said it threw some of the batch away.
     *
     * Its own constructor rather than `status(200)`, because the two are different events and
     * an error dashboard should not merge them: one is a request that failed, this is a
     * request that succeeded while some of its contents did not. It is also the only failure
     * shape here that does NOT mean the batch should be retried — the hits that landed landed,
     * and re-sending would double-count them.
     */
    public static function rejected(int $count): self
    {
        return new self(sprintf('Matomo accepted the request and rejected %d of its hit(s) as invalid.', $count));
    }
}
