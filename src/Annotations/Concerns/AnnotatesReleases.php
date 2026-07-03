<?php

declare(strict_types=1);

namespace MatomoAnalytics\Annotations\Concerns;

use MatomoAnalytics\Support\Config;

/**
 * Builds a deployment annotation ("<prefix> <version>") and delegates to add().
 * Shared by the live manager and the test fake so both resolve the version, prefix
 * and starred flag identically.
 */
trait AnnotatesReleases
{
    /**
     * @return array<array-key, mixed>|null
     */
    abstract public function add(string $note, ?string $date = null, bool $starred = false, int|string|null $site = null): ?array;

    /**
     * @return array<array-key, mixed>|null
     */
    public function annotateRelease(?string $version = null, ?string $date = null): ?array
    {
        $version ??= Config::nullableString('app.version');
        $prefix = Config::string('matomo-analytics.annotations.release_prefix', 'Deployed');
        $note = $version !== null ? $prefix.' '.$version : $prefix;

        return $this->add($note, $date, Config::bool('matomo-analytics.annotations.starred', false));
    }
}
