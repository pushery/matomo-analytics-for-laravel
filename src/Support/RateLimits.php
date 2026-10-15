<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use MatomoAnalytics\MatomoAnalyticsServiceProvider;

/**
 * The named rate limiters the package's beacon routes throttle on: Web Vitals, the prefetch page
 * view and the hit beacon.
 *
 * Each one is named rather than inline, so it can key on `ClientIp::resolve()` instead of
 * `$request->ip()`, which behind a CDN is the proxy address and would put every visitor in one
 * bucket. Each is registered whether its throttle is configured or not, and the configuration is
 * read when a request is limited: the route table and the provider boot can be cached at different
 * moments, and a route compiled with `throttle:` always finds its limiter. A throttle set to `null`
 * answers `Limit::none()`.
 */
final readonly class RateLimits
{
    /**
     * Each limiter's name, with the configuration key of its throttle.
     *
     * @var array<string, string>
     */
    private const array LIMITERS = [
        MatomoAnalyticsServiceProvider::WEB_VITALS_LIMITER => 'matomo-analytics.web_vitals.throttle',
        MatomoAnalyticsServiceProvider::PREFETCH_BEACON_LIMITER => 'matomo-analytics.prefetch_beacon.throttle',
        MatomoAnalyticsServiceProvider::HIT_BEACON_LIMITER => 'matomo-analytics.hit_beacon.throttle',
    ];

    public function __construct(private Container $app) {}

    /**
     * Register every limiter the beacon routes name, and again on demand.
     *
     * The provider calls this when it boots. An application that binds a cache per tenant
     * discards the `RateLimiter` singleton on a tenant switch, so the counters land in the tenant's
     * cache, and the named limiters go with it; calling this after the switch puts the package's
     * back. The limiter is resolved from the container at call time, never through the facade,
     * which keeps the instance it resolved first.
     */
    public function define(): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        foreach (self::LIMITERS as $name => $configKey) {
            $limiter->for($name, static function (Request $request) use ($configKey): Limit {
                $throttle = Config::throttle($configKey);

                if ($throttle === null) {
                    return Limit::none();
                }

                [$max, $minutes] = $throttle;
                $ip = ClientIp::resolve($request);

                return Limit::perMinutes($minutes, $max)
                    ->by($ip === null ? 'matomo-analytics:unknown-client' : ClientIp::rateLimitKey($ip));
            });
        }
    }
}
