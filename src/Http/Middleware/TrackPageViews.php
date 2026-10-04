<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\SpeculativeRequest;
use MatomoAnalytics\Tracking\CustomParameters;
use MatomoAnalytics\Tracking\EcommerceView;
use MatomoAnalytics\Tracking\Hit;
use MatomoAnalytics\Tracking\PageView;
use MatomoAnalytics\Tracking\SiteSearch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in middleware that records a page view for successful full-page GET
 * responses. The standard tracking gate (bots, DNT, audience, …) still applies
 * downstream; this layer only adds the method/status/Livewire checks and resolves
 * a human-readable title from the response.
 */
final readonly class TrackPageViews
{
    /** Where `handle()` leaves the elapsed time for `terminate()` to read. */
    private const string SERVER_TIME = 'matomo-analytics.server_time';

    private const string TITLE = 'matomo-analytics.title';

    /** Where `handle()` leaves whether the response is a page, for `terminate()` to read. */
    private const string PAGE = 'matomo-analytics.page';

    /** How far into an HTML body the title is looked for. The spec puts it in `<head>`. */
    private const int TITLE_SCAN_BYTES = 65536;

    public function __construct(
        private Tracker $tracker,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Before the handler, so a product view it records can wait for this page view.
        EcommerceView::expectPageView($request);

        $response = $next($request);

        // THE ELAPSED TIME IS TAKEN HERE AND THE TRACKING HAPPENS IN `terminate()`, AND THE
        // SPLIT IS THE POINT. `pf_srv` is meant to be generation time; read in `terminate()`
        // it would also include flushing the response to the web server, so the one number
        // that must be measured before the response leaves is measured before it leaves.
        //
        // Carried on the REQUEST rather than on `$this`: this class is `readonly`, and a
        // property on a middleware instance is exactly the shape that leaks between requests
        // under Octane.
        $request->attributes->set(self::SERVER_TIME, $this->serverTime($request));

        $this->requestClientHints($response);

        // The title is read here as well, and whether the response is a page, from the response
        // as the application rendered it. A middleware around this one can still turn it into a
        // 304 before terminate() runs: `cache.headers` renders the page, compares the validator
        // and drops the body and its Content-Type. A tracker listed inside it therefore keeps the
        // title of the page the reader revalidated, and a revalidated JSON response stays no page.
        $request->attributes->set(self::TITLE, $this->fromHtml($response));
        $request->attributes->set(self::PAGE, $this->isPage($request, $response));

        return $response;
    }

    /**
     * TRACKED AFTER THE RESPONSE IS SENT, not merely after it is built.
     *
     * THIS USED TO SIT IN `handle()` BEHIND `$next()`, WHICH READS AS "AFTERWARDS" AND IS
     * NOT. The visitor waits through the gate, the payload build and the buffer write — and in
     * `sync` mode through the whole HTTP call to Matomo. Measured against an instance
     * answering in 20ms: 24.08ms of request time in `sync` against 0.096ms in `queue`, a
     * factor of 250, counted at the far end as 140 POSTs.
     *
     * `TrackAiChatbots` and `TrackSiteSearch` track in `terminate()` for the same reason.
     *
     * Laravel terminates middleware BEFORE it runs the application's own terminating
     * callbacks, so a hit queued here is still picked up by the flush the service provider
     * registers there. The ordering `queue` and `batch` mode depend on is unchanged.
     */
    public function terminate(Request $request, Response $response): void
    {
        $product = EcommerceView::release($request);

        if ($this->skips($request, $response) || $this->leavesItToASearch($request, $response)) {
            // No page view to carry the product, so the product view goes out on its own.
            if ($product instanceof Hit) {
                $this->tracker->track($product);
            }

            return;
        }

        $serverTime = $request->attributes->get(self::SERVER_TIME);

        $pageView = new PageView(
            $this->title($request, $response),
            $this->url($request),
            is_int($serverTime) ? $serverTime : null,
        );

        $this->tracker->track($product instanceof Hit ? new CustomParameters($pageView, $product->toParams()) : $pageView);
    }

    /**
     * Server generation time (pf_srv) in milliseconds derived from the Laravel
     * request duration, or null unless middleware.performance is enabled.
     */
    private function serverTime(Request $request): ?int
    {
        if (! Config::bool('matomo-analytics.middleware.performance', false)) {
            return null;
        }

        $start = $request->server('REQUEST_TIME_FLOAT');

        if (! is_numeric($start)) {
            return null;
        }

        return max(0, (int) round((microtime(true) - (float) $start) * 1000));
    }

    /**
     * A background request for part of a page rather than a page: an htmx swap, a Turbo Frame,
     * an XHR, an Inertia partial reload. htmx's boosted links and history restores, Inertia
     * visits and PJAX are navigations and pass, and so does a Turbo Drive visit, which carries
     * none of these headers.
     */
    private function loadsPart(Request $request): bool
    {
        if ($request->headers->get('HX-Request') === 'true') {
            return $request->headers->get('HX-Boosted') !== 'true'
                && $request->headers->get('HX-History-Restore-Request') !== 'true';
        }

        if ($request->hasHeader('Turbo-Frame')) {
            return true;
        }

        // An Inertia partial reload asks for some props of the page already shown: a poll, a
        // deferred prop loading after the page, a reload with `only` or `except`. It names the
        // component it reloads, which a visit does not.
        if ($request->hasHeader('X-Inertia')) {
            return $request->hasHeader('X-Inertia-Partial-Component');
        }

        return $request->ajax() && ! $request->pjax();
    }

    private function skips(Request $request, Response $response): bool
    {
        if (Config::bool('matomo-analytics.middleware.only_get', true) && ! $request->isMethod('GET')) {
            return true;
        }

        // TWO HEADERS, AND THE SECOND ONE IS NOT A PREFIX OF THE FIRST AS A HEADER KEY.
        // `hasHeader('X-Livewire')` matches an ordinary component update and does NOT match
        // `X-Livewire-Navigate`, which is what a `wire:navigate` PREFETCH sends — a separate
        // key, not a longer value of the same one.
        //
        // A prefetch got past all three gates: it is a GET, it was not "a Livewire request" by
        // that test, and it carries a full 200 HTML body. Livewire prefetches on MOUSEDOWN for
        // every `wire:navigate` link and after 60ms of hover with `.hover`, so an abandoned
        // click — or, on a hover link, the pointer passing over it — counted a page view for a
        // page nobody ever saw.
        if (Config::bool('matomo-analytics.middleware.skip_livewire', true)
            && ($request->hasHeader('X-Livewire') || $request->hasHeader('X-Livewire-Navigate'))) {
            return true;
        }

        if (Config::bool('matomo-analytics.middleware.skip_partials', true) && $this->loadsPart($request)) {
            return true;
        }

        // SPECULATION RULES ARE THE SAME HAZARD WITH A DIFFERENT HEADER, AND THE BROWSER SAYS
        // SO ITSELF. A prefetch from `<script type="speculationrules">` is an ordinary GET with
        // a full 200 HTML body — every other test here passes it — and Chrome marks it with
        // `Sec-Purpose: prefetch`. With `eagerness: moderate` that request is sent when the
        // POINTER RESTS ON A LINK, so a reader who hovers a menu and clicks nothing counted a
        // page view for every link they passed over. Measured in a consumer with Playwright
        // Chromium against its own test server.
        //
        // `Purpose: prefetch` is checked beside it because that is the header Chrome sent
        // before `Sec-Purpose` was specified, and some proxies and older engines still send
        // it. The match is on the token rather than on equality: a PRERENDER announces itself
        // as `Sec-Purpose: prefetch;prerender`, and a prerendered page has exactly the same
        // problem — the bytes are fetched now and may never be looked at.
        //
        // AND SKIPPING IT ALONE WOULD TRADE ONE WRONG NUMBER FOR ANOTHER, which is why this
        // ships with {@see \MatomoAnalytics\View\Snippet::prefetchPageView()}. When the
        // reader does click, the page is served FROM the prefetch and the server hears nothing
        // at all — so without the beacon the view is simply missing. The documentation's page
        // on prefetched pages says so.
        if (Config::bool('matomo-analytics.middleware.skip_prefetch', true) && SpeculativeRequest::is($request)) {
            return true;
        }

        if (Config::bool('matomo-analytics.middleware.only_html', true) && ! $this->deliversPage($request, $response)) {
            return true;
        }

        // A DELIVERED PAGE IS 2xx OR 304 -- and `isSuccessful()` alone is strictly 200-299.
        //
        // A 304 means the reader has the page; the server only declined to resend the bytes, and
        // on a site with cache validators that is the second and every later view of a page.
        // Tracking runs in `terminate()`, after the whole stack, so a consumer cannot order an
        // ETag middleware behind the tracker to keep the 200, and the 304 has to count here.
        // The title is read in handle() for the same reason, where the page is still whole.
        //
        // NOT `>= 400`, though `TrackSiteSearch` uses that and the difference looks like an
        // inconsistency worth flattening. It is not. A redirect delivers no page: the browser
        // follows it and the TARGET is tracked on its own request, so counting the 3xx as well
        // would record two page views for one page. Site search asks a different question --
        // the search HAPPENED, whatever the response rendered -- so counting a redirect there
        // is right and counting one here is not. Both directions are pinned by arms.
        return Config::bool('matomo-analytics.middleware.only_successful', true)
            && ! $response->isSuccessful()
            && $response->getStatusCode() !== Response::HTTP_NOT_MODIFIED;
    }

    /**
     * Asks the browser for the client hints that name the platform version, the device model
     * and the full browser version, when `middleware.client_hints` is on.
     *
     * A browser sends them with the following requests, so they reach the hits of the pages a
     * visitor opens next, never the first. Off by default: they say more about a device, and
     * the package collects nothing of the kind unasked. A token the application already asks
     * for stays in the header once.
     */
    private function requestClientHints(Response $response): void
    {
        if (! Config::bool('matomo-analytics.middleware.client_hints', false)
            || ! $this->isHtml((string) $response->headers->get('Content-Type', ''))) {
            return;
        }

        $asked = array_filter(array_map(trim(...), explode(',', (string) $response->headers->get('Accept-CH', ''))));
        $known = array_map(strtolower(...), $asked);

        foreach (['Sec-CH-UA-Platform-Version', 'Sec-CH-UA-Model', 'Sec-CH-UA-Full-Version-List'] as $hint) {
            if (! in_array(strtolower($hint), $known, true)) {
                $asked[] = $hint;
            }
        }

        $response->headers->set('Accept-CH', implode(', ', $asked));
    }

    /**
     * A request that records a site search sends no page view.
     *
     * Matomo counts a search as the action of its page, so a page view beside it counts the page
     * twice, and with site search on for the website Matomo reads the keyword out of that page
     * view's URL and counts the search twice as well. A search is recorded either by a call
     * during the request, which marks it, or by `matomo.search` in its own `terminate()`.
     */
    private function leavesItToASearch(Request $request, Response $response): bool
    {
        return SiteSearch::trackedFor($request) || TrackSiteSearch::searches($request, $response);
    }

    /**
     * Whether the response is a page, as handle() found it, or for a request an earlier
     * middleware answered, as the final response reads.
     */
    private function deliversPage(Request $request, Response $response): bool
    {
        $page = $request->attributes->get(self::PAGE);

        return is_bool($page) ? $page : $this->isPage($request, $response);
    }

    /**
     * A page view is a document someone looks at. An HTML response is one, and so is an Inertia
     * visit, whose page arrives as JSON. JSON for a `fetch()`, a file, an image or a feed is not,
     * and a response sent as an attachment is a download whatever its type. A response that
     * declares no type, as a 304 does, is not judged.
     */
    private function isPage(Request $request, Response $response): bool
    {
        $disposition = (string) $response->headers->get('Content-Disposition', '');

        if (strtolower(trim(explode(';', $disposition, 2)[0])) === 'attachment') {
            return false;
        }

        if ($request->hasHeader('X-Inertia')) {
            return true;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        return $type === '' || $this->isHtml($type);
    }

    /** Whether a Content-Type names an HTML document, in any case and with any parameters. */
    private function isHtml(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'text/html' || $mediaType === 'application/xhtml+xml';
    }

    private function title(Request $request, Response $response): string
    {
        $title = $request->attributes->get(self::TITLE);

        // handle() does not run for a request an earlier middleware answered, and then the
        // final response is all there is to read.
        if (! $request->attributes->has(self::TITLE)) {
            $title = $this->fromHtml($response);
        }

        if (is_string($title)) {
            return $title;
        }

        $route = $request->route();
        if ($route instanceof Route) {
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $path = trim($request->path(), '/');

        return $path === '' ? '/' : $path;
    }

    private function fromHtml(Response $response): ?string
    {
        $content = $response->getContent();
        $contentType = (string) $response->headers->get('Content-Type', '');

        if (! is_string($content) || ! $this->isHtml($contentType)) {
            return null;
        }

        // BOUNDED, BECAUSE AN UNANCHORED SCAN OVER A BODY WITH NO `<title>` IS LINEAR IN
        // THE WHOLE BODY. Measured: 49 KB → 0.0531ms, 195 KB → 0.2146ms, 977 KB → 1.0654ms,
        // perfectly linear, against a constant 0.0011ms when a title is present near the top.
        // It is the pages LEAST likely to have one that pay: HTML fragments, error pages, and
        // HTMX or Turbo responses.
        //
        // 64 KB rather than a `stripos` precheck, because a precheck is a second full scan on
        // exactly the bodies that are already the expensive case. The spec puts `<title>` in
        // `<head>`, so a document that has one has it long before this bound; a document that
        // does not is answered after 64 KB instead of after a megabyte.
        $head = strlen($content) > self::TITLE_SCAN_BYTES
            ? substr($content, 0, self::TITLE_SCAN_BYTES)
            : $content;

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $matches) !== 1) {
            return null;
        }

        // Squished: Unicode whitespace at the edges goes, so a title of `&nbsp;` is no title, and a
        // run of whitespace inside becomes one space, as document.title reads a title broken over
        // several lines. Scrubbed first, because Str::squish() answers null for a string that is
        // not valid UTF-8.
        $title = Str::squish(mb_scrub(html_entity_decode($matches[1]), 'UTF-8'));

        return $title !== '' ? $title : null;
    }

    private function url(Request $request): string
    {
        return Config::bool('matomo-analytics.middleware.strip_query', false)
            ? $request->url()
            : $request->fullUrl();
    }
}
