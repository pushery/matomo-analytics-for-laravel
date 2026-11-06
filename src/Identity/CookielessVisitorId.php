<?php

declare(strict_types=1);

namespace MatomoAnalytics\Identity;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use MatomoAnalytics\Contracts\VisitorIdResolver;
use MatomoAnalytics\Privacy\IpAnonymizer;
use MatomoAnalytics\Support\ClientIp;
use MatomoAnalytics\Support\Config;
use Throwable;

/**
 * Cookieless, privacy-preserving visitor id: a salted, periodically rotating
 * SHA-256 of IP + User-Agent, truncated to Matomo's 16-hex format. The IP is the
 * address as it leaves for Matomo, truncated with `anonymize_ip` on. The salt is a
 * random secret per period, which the cache forgets when the period ends. No cookie
 * and no consent banner required; the rotation period trades returning-visitor
 * accuracy for privacy.
 */
final class CookielessVisitorId implements VisitorIdResolver
{
    public function resolve(Request $request): string
    {
        $ip = ClientIp::resolve($request);
        $period = $this->rotationKey();

        $raw = implode('|', [
            // The address cip carries, from the same source (ClientIp honors the configured
            // forwarding header, so proxied cookieless visitors get distinct ids instead of
            // collapsing to the shared edge IP) and cut the same way: with `anonymize_ip` on,
            // the id is derived from the truncated address and carries nothing of the full one.
            ($ip !== null ? IpAnonymizer::outgoing($ip) : null) ?? '',
            $request->userAgent() ?? '',
            $this->secret($period),
            $period,
        ]);

        return substr(hash('sha256', $raw), 0, 16);
    }

    /**
     * The secret the id is salted with: a random one per period, which the cache forgets a minute
     * after the period ends, or the application key where there is none.
     *
     * Salted with the application key and the date alone, the id of a past day could be worked out
     * again by anyone holding the key, for any address and user agent they know. Once the cache has
     * dropped a period's secret, nobody can tell which visitor an id of that period belonged to, the
     * operator included. The minute past the end covers a request that started in the old period.
     *
     * `never` has no period after which a secret could go, so it keeps the application key, and its
     * id stays one the key holder can work out. A cache that cannot be reached or keeps nothing gets
     * the same: the hit is still tracked, with the id an earlier version would have sent.
     */
    private function secret(string $period): string
    {
        $key = Config::string('app.key');

        if ($period === 'static') {
            return $key;
        }

        $name = 'matomo-analytics:visitor-secret:'.$period;

        try {
            $store = Cache::store(Config::nullableString('matomo-analytics.visitor.secret_store'));
            $secret = $store->get($name);

            if (! is_string($secret) || $secret === '') {
                // `add` writes only where nothing is stored yet, so two requests that open a period
                // at the same moment both go on with the secret the first of them wrote.
                $store->add($name, bin2hex(random_bytes(32)), $this->periodEnd()->addMinute());
                $secret = $store->get($name);
            }
        } catch (Throwable) {
            return $key;
        }

        return is_string($secret) && $secret !== '' ? $secret : $key;
    }

    /** When the current period ends: the next midnight, or with `weekly` the next Monday's. */
    private function periodEnd(): CarbonInterface
    {
        $today = Date::now()->startOfDay();

        return Config::string('matomo-analytics.visitor.rotate', 'daily') === 'weekly'
            ? $today->addDays(8 - (int) $today->format('N'))
            : $today->addDay();
    }

    private function rotationKey(): string
    {
        return match (Config::string('matomo-analytics.visitor.rotate', 'daily')) {
            'never' => 'static',
            'weekly' => Date::now()->format('o-\WW'),
            default => Date::now()->format('Y-m-d'),
        };
    }
}
