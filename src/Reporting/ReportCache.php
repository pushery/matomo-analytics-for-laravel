<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use MatomoAnalytics\Support\Config;

/**
 * Date-aware caching for the Reporting client. Failed calls (null) are never
 * cached so they self-heal on the next request, and a fresh period (today/live)
 * gets a short TTL while archived history is held longer. Invalidation bumps a
 * version segment in the cache key, which is store-agnostic — no SCAN or LIKE.
 */
final class ReportCache
{
    /** @see version() — read once per instance, moved by flush(). */
    private ?int $memoizedVersion = null;

    /**
     * @param  Closure(): (array<array-key, mixed>|null)  $resolver
     * @return array<array-key, mixed>|null
     */
    public function remember(string $key, int $ttl, Closure $resolver): ?array
    {
        if (! Config::bool('matomo-analytics.reporting.cache.enabled', true)) {
            return $resolver();
        }

        $repo = $this->repo();
        $cached = $repo->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $resolver();
        if (is_array($result)) {
            $repo->put($key, $result, $ttl);
        }

        return $result;
    }

    /**
     * @param  array<string, scalar>  $params
     */
    public function key(string $method, array $params): string
    {
        ksort($params);

        return Config::string('matomo-analytics.reporting.cache.prefix', 'matomo-analytics:report')
            .':v'.$this->version()
            .':'.md5($method.'|'.http_build_query($params));
    }

    /**
     * @param  array<string, scalar>  $params
     */
    public function ttlFor(string $method, array $params): int
    {
        if (str_starts_with($method, 'Live.')) {
            return Config::int('matomo-analytics.reporting.cache.ttl.live', 60);
        }

        $date = strtolower(trim(isset($params['date'])
            ? (string) $params['date']
            : Config::string('matomo-analytics.reporting.default_date', 'today')));
        $period = strtolower(trim(isset($params['period'])
            ? (string) $params['period']
            : Config::string('matomo-analytics.reporting.default_period', 'day')));

        // `previousN` ends with the period before the current one, so it never covers today.
        if (str_starts_with($date, 'previous')) {
            return Config::int('matomo-analytics.reporting.cache.ttl.recent', 900);
        }

        $end = $this->lastDayOf($date, $period);
        $today = Date::today();

        if (! $end instanceof CarbonInterface) {
            return Config::int('matomo-analytics.reporting.cache.ttl.historical', 3600);
        }

        if ($end->greaterThanOrEqualTo($today)) {
            return Config::int('matomo-analytics.reporting.cache.ttl.today', 300);
        }

        return $end->equalTo($today->copy()->subDay())
            ? Config::int('matomo-analytics.reporting.cache.ttl.recent', 900)
            : Config::int('matomo-analytics.reporting.cache.ttl.historical', 3600);
    }

    /**
     * The last day a report covers, from Matomo's `date` and `period`, or null when the date is
     * not one Matomo reads.
     *
     * The tier follows the end of the span, not the date string alone: a report on the current
     * month asked for by its first day, a range that ends today and `lastN` all cover today,
     * which Matomo has not archived yet. A week runs Monday to Sunday, as Matomo counts it.
     */
    private function lastDayOf(string $date, string $period): ?CarbonInterface
    {
        if ($date === '' || str_starts_with($date, 'last')) {
            return Date::today();
        }

        // A range names its end after the comma, and that end is a day whatever the period.
        if (str_contains($date, ',')) {
            return $this->day(substr($date, (int) strrpos($date, ',') + 1));
        }

        $day = $this->day($date);

        if (! $day instanceof CarbonInterface) {
            return null;
        }

        return match ($period) {
            'week' => $day->startOfWeek(CarbonInterface::MONDAY)->addDays(6),
            'month' => $day->endOfMonth()->startOfDay(),
            'year' => $day->endOfYear()->startOfDay(),
            default => $day,
        };
    }

    private function day(string $date): ?CarbonInterface
    {
        $date = trim($date);

        return match (true) {
            $date === 'today', $date === 'now' => Date::today(),
            $date === 'yesterday' => Date::today()->subDay(),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 => Date::createFromFormat('!Y-m-d', $date) ?: null,
            default => null,
        };
    }

    public function flush(): void
    {
        $repo = $this->repo();

        // Moved in the store, never written from the memo: another process may have flushed
        // since this instance read the version, and writing the memo plus one over that would
        // move it back and make entries cached under an older version current again. The
        // database store and Memcached will not increment a key they do not hold yet and answer
        // false, so the first flush on them writes the version itself.
        $next = $repo->increment($this->versionKey());

        if (! is_int($next)) {
            $next = $this->storedVersion() + 1;

            $repo->forever($this->versionKey(), $next);
        }

        // The memo is this instance's, so the flush that just moved the version has to move
        // it here too — otherwise the very request that cleared the cache keeps building keys
        // against the version it just retired and reads its own stale entries back.
        $this->memoizedVersion = $next;
    }

    /**
     * The cache-key version segment, read once per instance.
     *
     * A key is built per report, so reading the counter on every build would cost a round trip
     * per report for a value that cannot change within a request unless this instance changes
     * it, and `flush()` updates the memo when it does. One instance is one request because the
     * binding is `scoped`: a singleton survives every request under Octane and would keep
     * serving a version another process had already retired.
     */
    private function version(): int
    {
        return $this->memoizedVersion ??= $this->storedVersion();
    }

    /** The version the store holds now, or 0 when it holds none. */
    private function storedVersion(): int
    {
        $value = $this->repo()->get($this->versionKey());

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    private function versionKey(): string
    {
        return Config::string('matomo-analytics.reporting.cache.prefix', 'matomo-analytics:report').':version';
    }

    private function repo(): Repository
    {
        return Cache::store(Config::nullableString('matomo-analytics.reporting.cache.store'));
    }
}
