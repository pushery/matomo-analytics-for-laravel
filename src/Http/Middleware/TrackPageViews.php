<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in middleware that records a page view for successful full-page GET
 * responses. The standard tracking gate (bots, DNT, audience, …) still applies
 * downstream; this layer only adds the method/status/Livewire checks and resolves
 * a human-readable title from the response.
 */
final readonly class TrackPageViews
{
    public function __construct(
        private Tracker $tracker,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->skips($request, $response)) {
            $this->tracker->pageView($this->title($request, $response), $this->url($request));
        }

        return $response;
    }

    private function skips(Request $request, Response $response): bool
    {
        if (Config::bool('matomo-analytics.middleware.only_get', true) && ! $request->isMethod('GET')) {
            return true;
        }

        if (Config::bool('matomo-analytics.middleware.skip_livewire', true) && $request->hasHeader('X-Livewire')) {
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

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $matches) !== 1) {
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
