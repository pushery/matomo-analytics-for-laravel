<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

/**
 * Read side of the package: a thin, cached client over the Matomo Reporting API.
 * Calls POST form-encoded with token_auth in the body (never the query string),
 * decode JSON, surface Matomo error envelopes, and never cache a failed result.
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
}
