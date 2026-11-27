<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Http\Request;

/**
 * Whether a beacon request carries more than a beacon can.
 *
 * `navigator.sendBeacon()` and a `fetch()` with `keepalive`, the two ways the snippet sends a
 * beacon, may carry 64 KiB together for every such request still in flight, as the Fetch standard
 * sets it. A larger body comes from no page of this application, so the beacon routes refuse it
 * before they read anything of it: a JSON body is decoded in full on the first read of a field,
 * long before the bounds of each field apply.
 *
 * The declared `Content-Length` decides where there is one, so a request is refused without its
 * body being read at all. Without a declared length, the body as it arrived is measured.
 */
final class BeaconBody
{
    /** The most a beacon carries, in bytes. A value of the Fetch standard, not a setting. */
    public const int MAX_BYTES = 65_536;

    /** Whether the request declares or carries a body larger than any beacon. */
    public static function tooLarge(Request $request): bool
    {
        $declared = $request->headers->get('Content-Length');

        if (is_string($declared) && ctype_digit($declared)) {
            return (int) $declared > self::MAX_BYTES;
        }

        return strlen($request->getContent()) > self::MAX_BYTES;
    }
}
