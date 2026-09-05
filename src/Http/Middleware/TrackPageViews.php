<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Tracking\PageView;
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
     * `TrackAiChatbots` was moved here for exactly this reason and carries the same note.
     * `site-search.md` even justified a limitation with "because it runs after the response"
     * while the code ran before it.
     *
     * Laravel terminates middleware BEFORE it runs the application's own terminating
     * callbacks, so a hit queued here is still picked up by the flush the service provider
     * registers there. The ordering `queue` and `batch` mode depend on is unchanged.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->skips($request, $response)) {
            return;
        }

        $serverTime = $request->attributes->get(self::SERVER_TIME);

        $this->tracker->track(new PageView(
            $this->title($request, $response),
            $this->url($request),
            is_int($serverTime) ? $serverTime : null,
        ));
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

        return Config::bool('matomo-analytics.middleware.only_successful', true) && ! $response->isSuccessful();
    }

    private function title(Request $request, Response $response): string
    {
        $title = $this->fromHtml($response);
        if ($title !== null) {
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

        if (! is_string($content) || ! str_contains($contentType, 'text/html')) {
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

        $title = trim(html_entity_decode($matches[1]));

        return $title !== '' ? $title : null;
    }

    private function url(Request $request): string
    {
        return Config::bool('matomo-analytics.middleware.strip_query', false)
            ? $request->url()
            : $request->fullUrl();
    }
}
