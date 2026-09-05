<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

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
        $base = 'matomo-analytics.reporting.cache.ttl.';

        if (str_starts_with($method, 'Live.')) {
            return Config::int($base.'live', 60);
        }

        $date = isset($params['date'])
            ? (string) $params['date']
            : Config::string('matomo-analytics.reporting.default_date', 'today');

        if ($date === '' || str_contains($date, 'today') || $date === Date::now()->toDateString()) {
            return Config::int($base.'today', 300);
        }

        if (str_contains($date, 'yesterday') || str_starts_with($date, 'last') || str_contains($date, 'previous')) {
            return Config::int($base.'recent', 900);
        }

        return Config::int($base.'historical', 3600);
    }

    public function flush(): void
    {
        $next = $this->version() + 1;

        $this->repo()->forever($this->versionKey(), $next);

        // The memo is this instance's, so the flush that just moved the version has to move
        // it here too — otherwise the very request that cleared the cache keeps building keys
        // against the version it just retired and reads its own stale entries back.
        $this->memoizedVersion = $next;
    }

    /**
     * The cache-key version segment, read once per instance.
     *
     * IT WAS READ ON EVERY KEY BUILD, AND A KEY IS BUILT PER REPORT. Twelve warm dashboard
     * widgets therefore cost 24 round trips, half of them fetching the same counter — which
     * cannot change within a request unless this instance changes it, and `flush()` updates
     * the memo when it does.
     *
     * THE BINDING WAS CHANGED FROM `singleton` TO `scoped` FOR THIS. "Per instance" is only
     * "per request" if the instance is, and a singleton survives every request under Octane —
     * it would keep serving a version another process had already retired. A static property
     * would have the same defect and no binding to fix it.
     */
    private function version(): int
    {
        if ($this->memoizedVersion !== null) {
            return $this->memoizedVersion;
        }

        $value = $this->repo()->get($this->versionKey());

        if (is_int($value)) {
            return $this->memoizedVersion = $value;
        }

        return $this->memoizedVersion = is_numeric($value) ? (int) $value : 0;
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
