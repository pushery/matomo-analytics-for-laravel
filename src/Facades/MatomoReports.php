<?php

declare(strict_types=1);

namespace MatomoAnalytics\Facades;

use Illuminate\Support\Facades\Facade;
use MatomoAnalytics\Contracts\ReportClient;
use MatomoAnalytics\Testing\MatomoReportsFake;

/**
 * @method static array<array-key, mixed>|null get(string $method, array<string, scalar> $params = [])
 * @method static list<array<array-key, mixed>|null> bulk(list<string|array<string, scalar>> $requests)
 * @method static void flushCache()
 * @method static string|null lastError()
 * @method static array<array-key, mixed>|null visitsSummary(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null liveCounters(int $lastMinutes = 30, array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null lastVisits(int $count = 10, array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null topPageUrls(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null topPageTitles(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null siteSearchKeywords(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null topReferrers(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null referrerTypes(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null countries(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null deviceTypes(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null browsers(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null goals(array<string, scalar> $params = [])
 * @method static array<array-key, mixed>|null eventCategories(array<string, scalar> $params = [])
 *
 * @see ReportClient
 */
final class MatomoReports extends Facade
{
    public static function fake(): MatomoReportsFake
    {
        $fake = new MatomoReportsFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ReportClient::class;
    }
}
