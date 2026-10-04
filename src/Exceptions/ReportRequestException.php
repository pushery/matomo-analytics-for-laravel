<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

/**
 * Read-side failure: a Matomo Reporting API call could not be completed or
 * returned an error envelope. Carried to the resilience reporter so read-side
 * outages follow the same throttled alerting policy as the tracking side.
 */
final class ReportRequestException extends RuntimeException
{
    /**
     * An error answer from a Matomo API, reported without its text.
     *
     * Matomo quotes the request's segment in an error, and a segment can name a person, as
     * `userId==…` in a GDPR request does. The text stays with the caller, in `lastError()`, and
     * the log and the exception tracker learn which API answered which method.
     */
    public static function errorAnswer(string $api, mixed $method): self
    {
        return new self(sprintf(
            "Matomo %s API answered %s with an error. Its text stays with the caller in lastError(), because Matomo quotes the request's segment in it.",
            $api,
            is_string($method) ? $method : 'a request',
        ));
    }
}
