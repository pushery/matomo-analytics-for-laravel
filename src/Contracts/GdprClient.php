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
     * The most visits one lookup returns: Matomo's `PrivacyManager.findDataSubjects` asks
     * `Live.getLastVisitsDetails` with `filter_limit` 401.
     */
    public const int LOOKUP_LIMIT = 401;

    /**
     * Find the visits matching a segment (the data subject).
     *
     * @param  int|string|null  $site  idSite; null = the configured site, "all" = every site
     * @return list<array<array-key, mixed>>|null matching visit rows as Matomo returns them (each with idSite/idVisit), or null on failure
     */
    public function findDataSubjects(string $segment, int|string|null $site = null): ?array;

    /**
     * Find the data subject by segment and erase every matching visit.
     *
     * The counts carry Matomo's own storage areas, plus this package's local stores under
     * `local_buffer` and `local_dead_letters`, plus three booleans. `local_segment_understood`
     * says whether the local half could evaluate the segment at all, which is a different
     * answer from "nothing matched". `local_buffer_searched` is false when the application bound
     * a buffer that cannot erase (one implementing `HitBuffer` but not `ErasableHitBuffer`).
     * `local_queue_searched` is false in the `queue` mode, where hits still waiting in the queue,
     * and jobs that ran out of attempts in `failed_jobs`, cannot be searched from here.
     *
     * A lookup returns at most LOOKUP_LIMIT visits, so a person with more is erased in rounds.
     * `erased_visits` counts the visits erased across them, and `erased_completely` is false
     * when the rounds ended while a lookup still came back full: run it again for the rest.
     *
     * @return array<string, bool|int>|null Matomo's deletion counts keyed by storage area (none when Matomo matched nothing), then `erased_visits`, `erased_completely` and the local keys above; null on failure
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
