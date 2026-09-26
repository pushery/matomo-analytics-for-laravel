<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Whether a beacon request was sent by a page of this application.
 *
 * The beacon routes take a form-encoded POST without a CSRF token, and such a POST is
 * CORS-simple: any page anywhere can make its own visitors send one, with their real address and
 * user agent. The payload cannot tell that apart, because the page that sends it writes the
 * payload, the `url` field included. What the sending page cannot write is the origin the browser
 * puts on the request.
 *
 * `Origin` decides when it is there: it has to name this application's own origin, compared on
 * scheme, host and port. Browsers send it on every cross-origin POST, `sendBeacon` included, and
 * an opaque origin arrives as the text `null`, which names no origin and is refused. Without
 * `Origin`, `Sec-Fetch-Site` decides, and only `same-origin` passes. A request carrying neither
 * came from no browser that could be made to send it on someone else's behalf, and passes: the
 * rate limit is what stands in front of a client that calls the route directly.
 */
final class BeaconOrigin
{
    public static function isOwn(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if (is_string($origin) && $origin !== '') {
            return self::sameOrigin($origin, URL::to('/'));
        }

        $site = $request->headers->get('Sec-Fetch-Site');

        return ! is_string($site) || $site === '' || $site === 'same-origin';
    }

    private static function sameOrigin(string $origin, string $own): bool
    {
        $theirs = parse_url($origin);
        $ours = parse_url($own);

        if (! is_array($theirs) || ! is_array($ours) || ! isset($theirs['scheme'], $theirs['host'], $ours['scheme'], $ours['host'])) {
            return false;
        }

        return strtolower($theirs['scheme']) === strtolower($ours['scheme'])
            && strtolower($theirs['host']) === strtolower($ours['host'])
            && self::port($theirs) === self::port($ours);
    }

    /**
     * The port a URL addresses, with the scheme's default filled in, so `https://a` and
     * `https://a:443` are one origin.
     *
     * @param  array{scheme: string, port?: int}  $parts
     */
    private static function port(array $parts): int
    {
        return $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
    }
}
