<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

/**
 * A value as it stands in a cache key: hashed with the application key.
 *
 * A rate-limit count is a cache entry, and Sentry and Flare record the key of every cache entry
 * they see. A key that holds a client's address, or a plain hash of one, hands the address to
 * both: the framework's own hash of a named limiter is md5() of the limiter's name and the key, and
 * a plain hash of a version 4 address is undone by hashing all four billion of them. A hash keyed
 * with the application key cannot be tried back without the key, and the same client still counts
 * against the same bucket.
 *
 * Rotating the application key starts every count afresh, once. An application without a key gets
 * a hash with an empty one rather than an error inside a beacon request.
 */
final class KeyedDigest
{
    public static function of(string $value): string
    {
        return hash_hmac('sha256', $value, Config::string('app.key'));
    }
}
