<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use MatomoAnalytics\Support\Config;

/**
 * Date-aware caching for the Reporting client. Failed calls (null) are never
 * cached so they self-heal on the next request, and a fresh period (today/live)
 * gets a short TTL while archived history is held longer. Invalidation bumps a
 * version segment in the cache key, which is store-agnostic — no SCAN or LIKE.
 */
final class ReportCache
{
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

        if ($date === '' || str_contains($date, 'today') || $date === now()->toDateString()) {
            return Config::int($base.'today', 300);
        }

        if (str_contains($date, 'yesterday') || str_starts_with($date, 'last') || str_contains($date, 'previous')) {
            return Config::int($base.'recent', 900);
        }

        return Config::int($base.'historical', 3600);
    }

    public function flush(): void
    {
        $this->repo()->forever($this->versionKey(), $this->version() + 1);
    }

    private function version(): int
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
