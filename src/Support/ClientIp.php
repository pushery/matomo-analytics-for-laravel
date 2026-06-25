<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Http\Request;

/**
 * Resolves the real client IP, preferring a configured forwarding header (e.g.
 * CF-Connecting-IP behind Cloudflare) and otherwise the framework's resolved IP.
 */
final class ClientIp
{
    public static function resolve(Request $request): ?string
    {
        $header = Config::nullableString('matomo-analytics.ip_header');
        if ($header !== null) {
            $forwarded = $request->headers->get($header);
            if (is_string($forwarded) && $forwarded !== '') {
                return $forwarded;
            }
        }

        return $request->ip();
    }
}
