<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use MatomoAnalytics\Reporting\ReportQuery;

/**
 * Read side of the package: a thin, cached client over the Matomo Reporting API.
 * Calls POST form-encoded with token_auth in the body (never the query string),
 * decode JSON, surface Matomo error envelopes, and never cache a failed result.
 *
 * The contract has two halves. get(), query(), bulk(), flushCache() and lastError()
 * are the protocol; everything below them is a curated shortcut expressed in terms
 * of get(), and the two are separated by a comment rather than by two interfaces.
 *
 * IMPLEMENTING THIS IS STILL A FIVE-METHOD JOB. `use ResolvesCommonReports` supplies
 * every shortcut from get() alone -- the trait declares get() abstract for exactly
 * that reason -- so an implementer writes the protocol and one use statement. Both
 * shipped implementations do precisely that.
 *
 * The shortcuts are declared here because the alternative was measured against the
 * defect and does not fix it. A second interface carrying them would leave anyone
 * who injects ReportClient -- the bound name, the documented one, the obvious one --
 * looking at the same five methods while the facade advertises twenty-seven. That is
 * a discoverability defect, and it is not repaired by adding a second name a reader
 * has to discover first.
 */
interface ReportClient
{
    /**
     * Fetch a single Reporting API method, cached with a date-aware TTL.
     *
     * @param  array<string, scalar>  $params  e.g. ['period' => 'day', 'date' => 'today', 'segment' => '…']
     * @return array<array-key, mixed>|null the decoded report, or null on a failed/unconfigured call
     */
    public function get(string $method, array $params = []): ?array;

    /**
     * Start a fluent query for a method — layer on a segment and report filters,
     * then call get() to run it through the same cache + resilience path.
     */
    public function query(string $method): ReportQuery;

    /**
     * Fetch several methods in one HTTP round-trip via API.getBulkRequest.
     *
     * @param  list<string|array<string, scalar>>  $requests  method strings, or ['method' => '…', …params]
     * @return list<array<array-key, mixed>|null> one result per request, aligned by index
     */
    public function bulk(array $requests): array;

    /**
     * Invalidate every cached report (a versioned-prefix bump; store-agnostic).
     */
    public function flushCache(): void;

    /**
     * The last error surfaced by a failed call, for a dashboard banner; null when healthy.
     */
    public function lastError(): ?string;

    // ---------------------------------------------------------------------------------
    // Curated shortcuts. Each is one call to get() with a fixed Matomo method name, and
    // ResolvesCommonReports implements all of them. FacadeContractLockstepTest holds this
    // list, the trait and the facade's @method block against each other: the drift this
    // section repairs was invisible for as long as nothing compared the three.
    // ---------------------------------------------------------------------------------

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function visitsSummary(array $params = []): ?array;

    /**
     * Realtime visitor counters; Matomo wraps the result in a single-element list,
     * which is unwrapped here for convenience.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function liveCounters(int $lastMinutes = 30, array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function lastVisits(int $count = 10, array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function topPageUrls(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function topPageTitles(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function siteSearchKeywords(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function topReferrers(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function referrerTypes(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function countries(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function deviceTypes(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function browsers(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function goals(array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function eventCategories(array $params = []): ?array;

    /**
     * A single Custom Dimension report by its Matomo dimension id.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function customDimension(int $idDimension, array $params = []): ?array;

    /**
     * Content Tracking impression/interaction counts grouped by content name.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function contentNames(array $params = []): ?array;

    /**
     * Content Tracking impression/interaction counts grouped by content piece.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function contentPieces(array $params = []): ?array;

    /**
     * A/B Testing — requires the licensed AbTesting plugin.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function abTests(array $params = []): ?array;

    /**
     * Funnel flow for a funnel id — requires the licensed Funnels plugin.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function funnelFlow(int $idFunnel, array $params = []): ?array;

    /**
     * Form Analytics overview — requires the licensed FormAnalytics plugin.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function forms(array $params = []): ?array;

    /**
     * Media Analytics overview — requires the licensed MediaAnalytics plugin.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function media(array $params = []): ?array;

    /**
     * Cohort retention — requires the licensed Cohorts plugin.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function cohorts(array $params = []): ?array;

    /**
     * Users Flow — requires the licensed UsersFlow plugin.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function usersFlow(array $params = []): ?array;
}
