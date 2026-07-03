<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

/**
 * Write side for Matomo's free Annotations plugin: add notes to a site's reports
 * timeline (e.g. a deployment marker). Uses the same token-safe POST transport as
 * the reporting client but is never cached. The configured token must belong to a
 * non-anonymous user with at least view access to the site.
 */
interface AnnotationsClient
{
    /**
     * Add an annotation (Annotations.add) to a site's reports timeline.
     *
     * @param  string|null  $date  the annotation date (YYYY-MM-DD); null = today
     * @param  int|string|null  $site  idSite; null = the configured site
     * @return array<array-key, mixed>|null the created annotation, or null on failure
     */
    public function add(string $note, ?string $date = null, bool $starred = false, int|string|null $site = null): ?array;

    /**
     * Annotate a deployment, e.g. "Deployed v1.2.3". The version defaults to
     * config('app.version'); the note is prefixed by annotations.release_prefix and
     * starred per annotations.starred.
     *
     * @return array<array-key, mixed>|null
     */
    public function annotateRelease(?string $version = null, ?string $date = null): ?array;

    /** The last error surfaced by a failed call; null when healthy. */
    public function lastError(): ?string;
}
