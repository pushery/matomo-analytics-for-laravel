<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MatomoAnalytics\Contracts\Tracker;
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
    public function __construct(
        private Tracker $tracker,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $keywordKey = 'q', ?string $categoryKey = null): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() < 400) {
            $this->tracker->searchFromRequest($request, $keywordKey, $categoryKey);
        }

        return $response;
    }
}
