<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

/**
 * GDPR data-subject tools over Matomo's PrivacyManager API: find the visits a
 * person produced (by segment, e.g. userId or visitIp), then erase or export
 * them. These are admin, destructive operations — the configured token must have
 * admin access — and are never cached.
 */
interface GdprClient
{
    /**
     * Find the visits matching a segment (the data subject).
     *
     * @param  int|string|null  $site  idSite; null = the configured site, "all" = every site
     * @return list<array<array-key, mixed>>|null matching visit rows (each with idsite/idvisit), or null on failure
     */
    public function findDataSubjects(string $segment, int|string|null $site = null): ?array;

    /**
     * Find the data subject by segment and erase every matching visit.
     *
     * @return array<string, int>|null deletion counts keyed by storage area, [] if nothing matched, null on failure
     */
    public function forget(string $segment, int|string|null $site = null): ?array;

    /**
     * Find the data subject by segment and export every matching visit's data.
     *
     * @return array<array-key, mixed>|null export payload, [] if nothing matched, null on failure
     */
    public function export(string $segment, int|string|null $site = null): ?array;

    /**
     * Erase specific visits.
     *
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @return array<string, int>|null deletion counts, or null on failure
     */
    public function deleteVisits(array $visits): ?array;

    /**
     * Export specific visits.
     *
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @return array<array-key, mixed>|null export payload, or null on failure
     */
    public function exportVisits(array $visits): ?array;

    /** The last error surfaced by a failed call; null when healthy. */
    public function lastError(): ?string;
}
