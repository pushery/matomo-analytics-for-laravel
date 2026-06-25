<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting\Concerns;

/**
 * Curated shortcuts for the most common Reporting API methods, expressed in terms
 * of get(). Shared by the live client and the test fake so faked code can call
 * the same helpers. Anything not covered here is reachable via get('Module.method').
 */
trait ResolvesCommonReports
{
    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    abstract public function get(string $method, array $params = []): ?array;

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function visitsSummary(array $params = []): ?array
    {
        return $this->get('VisitsSummary.get', $params);
    }

    /**
     * Realtime visitor counters; Matomo wraps the result in a single-element list,
     * which is unwrapped here for convenience.
     *
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function liveCounters(int $lastMinutes = 30, array $params = []): ?array
    {
        $result = $this->get('Live.getCounters', array_merge(['lastMinutes' => $lastMinutes], $params));

        $first = $result[0] ?? null;

        return is_array($first) ? $first : $result;
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function lastVisits(int $count = 10, array $params = []): ?array
    {
        return $this->get('Live.getLastVisitsDetails', array_merge(['filter_limit' => $count], $params));
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function topPageUrls(array $params = []): ?array
    {
        return $this->get('Actions.getPageUrls', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function topPageTitles(array $params = []): ?array
    {
        return $this->get('Actions.getPageTitles', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function siteSearchKeywords(array $params = []): ?array
    {
        return $this->get('Actions.getSiteSearchKeywords', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function topReferrers(array $params = []): ?array
    {
        return $this->get('Referrers.getWebsites', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function referrerTypes(array $params = []): ?array
    {
        return $this->get('Referrers.getReferrerType', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function countries(array $params = []): ?array
    {
        return $this->get('UserCountry.getCountry', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function deviceTypes(array $params = []): ?array
    {
        return $this->get('DevicesDetection.getType', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function browsers(array $params = []): ?array
    {
        return $this->get('DevicesDetection.getBrowsers', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function goals(array $params = []): ?array
    {
        return $this->get('Goals.get', $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    public function eventCategories(array $params = []): ?array
    {
        return $this->get('Events.getCategory', $params);
    }
}
