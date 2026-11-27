<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

final class TrackingSendException extends RuntimeException
{
    /**
     * @param  string  $failure  the failure without what Matomo answered, which stays the same while the failure does
     */
    private function __construct(string $message, public readonly string $failure)
    {
        parent::__construct($message);
    }

    /**
     * Matomo refused the request with this status.
     *
     * The message carries what Matomo answered, where it answered anything, because the body is the only place it
     * names its reason. `failure` leaves the answer out: Matomo counts the hits of the batch in it, so a throttle
     * keyed on the whole message would take every batch for a new failure.
     */
    public static function status(int $status, string $answer = ''): self
    {
        $failure = 'Matomo tracking request failed with HTTP '.$status;

        return new self($answer === '' ? $failure : $failure.'. Matomo answered: '.$answer, $failure);
    }

    /**
     * Matomo answered 200 and said it threw some of the batch away.
     *
     * Its own constructor rather than `status(200)`, because the two are different events and
     * an error dashboard should not merge them: one is a request that failed, this is a
     * request that succeeded while some of its contents did not. It is also the only failure
     * shape here that does not mean the batch should be retried — the hits that landed landed,
     * and re-sending would double-count them.
     */
    public static function rejected(int $count): self
    {
        $message = sprintf('Matomo accepted the request and rejected %d of its hit(s) as invalid.', $count);

        return new self($message, $message);
    }
}
