<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The page behind a Livewire component update.
 *
 * A component posts its actions to Livewire's own endpoint, `/livewire/update`, or
 * `/livewire-<hash>/update` from Livewire 4, while it lives on a page of the application. A
 * hit an action sends belongs to that page: it is filed under it, and the tracking gate
 * judges it by it.
 *
 * The browser names the page in the `Referer`, query string included. Where it sends none of
 * this origin, under a `no-referrer` policy for one, the page is the path Livewire recorded when
 * it mounted the component, `memo.path` in the snapshot the update carries, which is also what
 * Livewire's own `originalUrl()` reads.
 *
 * @internal
 */
final class LivewireUpdate
{
    /**
     * The page a Livewire component update was sent from, or null for any other request and
     * for an update that names no page of this application.
     */
    public static function pageUrl(Request $request): ?string
    {
        if (! $request->isMethod('POST') || ! $request->hasHeader('X-Livewire')) {
            return null;
        }

        return BeaconOrigin::pageUrl($request->headers->get('referer')) ?? self::mountedPage($request);
    }

    /** The page Livewire mounted the component on, from the snapshot of the first component. */
    private static function mountedPage(Request $request): ?string
    {
        $snapshot = $request->input('components.0.snapshot');
        $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
        $memo = is_array($decoded) ? ($decoded['memo'] ?? null) : null;
        $path = is_array($memo) ? ($memo['path'] ?? null) : null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        // Livewire records `Request::path()`, which is `/` for the home page and has no leading
        // slash otherwise; the URL keeps a path for both, so the gate reads it as one.
        return rtrim(URL::to('/'), '/').'/'.ltrim($path, '/');
    }
}
