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
                return self::normalize($forwarded) ?? $forwarded;
            }
        }

        return $request->ip();
    }

    /**
     * The client address a forwarding header names, or null when it names none.
     *
     * THIS USED TO RETURN THE HEADER VERBATIM, AND `X-Forwarded-For` IS A CHAIN BY
     * DEFINITION. One hop looks like an address and two do not, so every consumer downstream
     * silently stopped working the moment a CDN or a load balancer was put in front — the
     * ordinary deployment, and the one `ip_header` exists for. Both consumers failed in the
     * direction that keeps data rather than the one that drops it:
     *
     *   `anonymize_ip`  `maybeAnonymize()` branches on a colon, a two-hop v4 chain has none,
     *                   and `anonymizeIpv4()` hands back anything that is not four octets.
     *                   So the FULL visitor address went to Matomo while the setting was on
     *                   and the docs promised it never leaves the application.
     *   `except_ips`    `IpUtils::checkIp('10.1.2.3, 198.51.100.7', ['10.0.0.0/8'])` is
     *                   false, so a team's own exclusion list quietly covered nobody.
     *
     * A port, a bracket pair and a zone id get the same treatment for the same reason: each
     * is a well-formed way to write an address next to something that is not part of it, and
     * `filter_var` refuses all three — which is what put them on the leaking path.
     *
     * The result is validated, and a value that yields no address leaves the caller with the
     * header untouched. So this can only ever replace a non-address with an address: the
     * "whatever a proxy put there is handed back rather than sliced into something that
     * RESEMBLES an address" contract that `anonymizeIpv6()` documents is kept exactly.
     */
    private static function normalize(string $header): ?string
    {
        $first = trim(explode(',', $header, 2)[0]);

        if (str_starts_with($first, '[')) {
            // `[2001:db8::1]:443` — the brackets exist precisely to tell the port from an
            // address that is itself full of colons.
            $close = strpos($first, ']');
            $first = $close === false ? substr($first, 1) : substr($first, 1, $close - 1);
        } elseif (substr_count($first, ':') === 1) {
            // Exactly one colon is `host:port`. Two or more is an unbracketed IPv6, where no
            // port can be told from the address — and none is written without brackets.
            $first = substr($first, 0, (int) strpos($first, ':'));
        }

        // A zone id (`fe80::1%eth0`) names an interface on the machine that wrote it, which
        // is meaningless anywhere else and is not part of the address.
        $percent = strpos($first, '%');
        if ($percent !== false) {
            $first = substr($first, 0, $percent);
        }

        return filter_var($first, FILTER_VALIDATE_IP) === false ? null : $first;
    }
}
