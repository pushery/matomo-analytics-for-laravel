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
}
