<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Tracking\SiteSearch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in middleware that records a Matomo site search from a request query
 * parameter on successful GET responses — e.g. `->middleware('matomo.search:q,category')`.
 * It only fires when the keyword is present; the standard tracking gate still
 * applies downstream. Result counts aren't known here, so use
 * Matomo::siteSearch()/searchFromRequest() directly when you want a count
 * (including no-result tracking with count 0).
 */
final readonly class TrackSiteSearch
{
    /** Where `handle()` leaves its middleware parameters for `terminate()` to read. */
    private const string KEYS = 'matomo-analytics.search_keys';

    public function __construct(
        private Tracker $tracker,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $keywordKey = 'q', ?string $categoryKey = null): Response
    {
        // The parameters arrive HERE and are needed THERE: Laravel resolves a terminable
        // middleware by class and calls `terminate($request, $response)` with no middleware
        // arguments, so `matomo.search:q,category` would otherwise silently fall back to its
        // defaults. Carried on the request rather than on `$this`, which is `readonly` and
        // shared across requests under Octane.
        $request->attributes->set(self::KEYS, [$keywordKey, $categoryKey]);

        return $next($request);
    }

    /**
     * Tracked after the response is sent, not merely after it is built.
     *
     * Behind `$next()` in `handle()` a visitor searching would wait through the gate, the
     * payload build and the buffer write, and in `sync` mode through the whole HTTP call to
     * Matomo.
     *
     * Laravel terminates middleware before the application's own terminating callbacks, so a
     * hit queued here is still picked up by the flush the service provider registers there.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! self::answers($request, $response)) {
            return;
        }

        [$keywordKey, $categoryKey] = self::keys($request);

        $this->tracker->searchFromRequest($request, $keywordKey, $categoryKey);
    }

    /**
     * Whether this middleware records a search for the request, which the page view of the same
     * request then leaves to it. Read before the search is tracked: the route's middleware
     * terminates in the order it was listed, so the page view can come first.
     *
     * @internal
     */
    public static function searches(Request $request, Response $response): bool
    {
        if (! $request->attributes->has(self::KEYS) || ! self::answers($request, $response)) {
            return false;
        }

        [$keywordKey, $categoryKey] = self::keys($request);

        return SiteSearch::fromRequest($request, $keywordKey, $categoryKey) instanceof SiteSearch;
    }

    private static function answers(Request $request, Response $response): bool
    {
        return $request->isMethod('GET') && $response->getStatusCode() < 400;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function keys(Request $request): array
    {
        $keys = $request->attributes->get(self::KEYS);

        return [
            is_array($keys) && is_string($keys[0] ?? null) ? $keys[0] : 'q',
            is_array($keys) && is_string($keys[1] ?? null) ? $keys[1] : null,
        ];
    }
}
