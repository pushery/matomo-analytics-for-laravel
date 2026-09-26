<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\BeaconOrigin;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Tracking\PageView;
use MatomoAnalytics\View\Snippet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records the page view of a page the browser delivered out of a speculation-rules prefetch.
 *
 * The middleware cannot see that page at all. The prefetch itself is skipped on purpose — the
 * pointer resting on a link is not a visit — and the navigation that follows never reaches the
 * server, because the bytes are already in the browser. So the page reports itself, once, from
 * {@see Snippet::prefetchPageView()}, and this is where it lands.
 *
 * Tracking goes through the normal TrackManager, so the gate (bots, opt-out, DNT, excluded
 * routes, …) and fail-safe delivery all apply. Returns 204.
 */
final class PrefetchPageViewController
{
    /**
     * A page title is a `<title>` element, and this is far past any real one.
     *
     * The value is unauthenticated client input that becomes the Matomo `action_name`, so it is
     * bounded here rather than wherever it is first displayed. Matomo itself truncates, but a
     * report is not the only reader of that column.
     */
    private const int MAX_TITLE = 1_000;

    public function __invoke(Request $request, Tracker $tracker): Response
    {
        if (! Config::bool('matomo-analytics.prefetch_beacon.enabled', false)) {
            // NOT abort(): that helper ships only with laravel/framework's Foundation, and
            // this package requires illuminate components instead. It throws exactly this.
            throw new NotFoundHttpException;
        }

        // The origin of the REQUEST, which the sending page cannot write, before the `url` it can.
        if (! BeaconOrigin::isOwn($request)) {
            return new Response(status: Response::HTTP_FORBIDDEN);
        }

        $url = $this->ownUrl($request->input('url'));

        // A URL IS REQUIRED HERE, WHILE THE WEB VITALS BEACON TREATS ONE IT CANNOT VOUCH FOR
        // AS ABSENT. The shapes differ because the fallbacks do: a web-vitals sample without a
        // page is still a real measurement and is filed with the beacon's own URL. A page view
        // without a page is not a page view at all — filed against `/matomo-analytics/page-view`
        // it would put a row in the reports for a path no reader ever opened, and it would do
        // that for exactly the requests this endpoint must not trust.
        if ($url === null) {
            return new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tracker->track(new PageView($this->title($request->input('title'), $url), $url));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The beaconed URL, but only when it names a page of this application.
     *
     * This is the page the view is filed under, not a check on who sent it: the sending page
     * writes this field, so it proves nothing about the sender. {@see BeaconOrigin} answers that,
     * from the request's own origin, before this is read.
     *
     * Compared on scheme, host and port, the same three parts {@see WebVitalsController} holds
     * its own payload to.
     */
    private function ownUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        $own = parse_url(URL::to('/'));

        if (! is_array($parts) || ! is_array($own)) {
            return null;
        }

        $same = ($parts['scheme'] ?? null) === ($own['scheme'] ?? null)
            && ($parts['host'] ?? null) === ($own['host'] ?? null)
            && ($parts['port'] ?? null) === ($own['port'] ?? null);

        return $same ? $url : null;
    }

    /**
     * The reported title, falling back to the URL's own path.
     *
     * The fallback matches what the middleware does when a response carries no `<title>`: the
     * page is named by where it is rather than left blank, because an empty `action_name` shows
     * up in Matomo as a page nobody can identify.
     */
    private function title(mixed $title, string $url): string
    {
        if (is_string($title) && trim($title) !== '') {
            return mb_substr(trim($title), 0, self::MAX_TITLE);
        }

        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

        return $path === '' ? '/' : $path;
    }
}
