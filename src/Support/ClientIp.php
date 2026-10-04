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
                // A header that names no address is treated like an absent one. Its text is not
                // an identity: passed on, every invented value would get a rate-limit bucket, a
                // `cip` and a visitor id of its own.
                return self::normalize($forwarded, max(1, Config::int('matomo-analytics.ip_header_trusted_hops', 1)))
                    ?? $request->ip();
            }
        }

        return $request->ip();
    }

    /**
     * The key a rate limit counts this address under.
     *
     * An IPv6 connection is given at least a /64, and every device in it picks its own address,
     * so a limit counted per full address would hand a sender a fresh counter for each of 2^64
     * of them. An IPv6 address counts under its /64. An IPv4 address, where a connection has one,
     * counts under itself, and so does an IPv4 address written as IPv6 (`::ffff:192.0.2.1`).
     * A value that is not an address is returned as it is.
     *
     * The `is_string` arms are the declared `string|false` of the calls, not a second opinion
     * about the input.
     */
    public static function rateLimitKey(string $ip): string
    {
        if (! str_contains($ip, ':')) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if (! is_string($packed)) {
            return $ip;
        }

        // Ten zero bytes then `ff ff`: the packed form of `::ffff:0:0/96`, the range RFC 4291
        // reserves for an IPv4 address carried inside an IPv6 one.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $mapped = inet_ntop(substr($packed, 12));

            return is_string($mapped) ? $mapped : $ip;
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return is_string($network) ? $network.'/64' : $ip;
    }

    /**
     * The client address a forwarding header names, or null when it names none.
     *
     * READ FROM THE RIGHT. A proxy APPENDS the address it received the request from to whatever
     * `X-Forwarded-For` the request already carried: nginx's `$proxy_add_x_forwarded_for`, an AWS
     * load balancer in its default mode and Cloudflare all do. So the entries on the left are
     * whatever the client chose to send, and only the ones the trusted proxies appended can be
     * believed. With one trusted proxy that is the last entry; `ip_header_trusted_hops` names how
     * many append, and the client is that many entries from the right. A chain shorter than that
     * is read from its first entry, the nearest thing to a client it holds.
     *
     * A single-value header (CF-Connecting-IP, X-Real-IP) is a chain of one, so its one entry is
     * both ends. `Forwarded` is a chain of the same kind, read by the `for` parameter of a hop.
     *
     * A port, a bracket pair and a zone id are stripped: each is a well-formed way to write an
     * address next to something that is not part of it, and `filter_var` refuses all three.
     */
    private static function normalize(string $header, int $hops): ?string
    {
        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $header)),
            static fn (string $entry): bool => $entry !== '',
        ));

        if ($entries === []) {
            return null;
        }

        $entry = $entries[max(0, count($entries) - $hops)];

        // `Forwarded` (RFC 7239) writes a hop as `for=…;proto=…;by=…` and quotes an address that
        // carries brackets or a port. Its `for` parameter is the client of that hop, and a hop
        // without one names no address.
        if (str_contains($entry, '=')) {
            if (preg_match('/(?:^|;)\s*for\s*=\s*"?([^";]*)/i', $entry, $for) !== 1) {
                return null;
            }

            $entry = trim($for[1]);
        }

        if (str_starts_with($entry, '[')) {
            // `[2001:db8::1]:443` — the brackets exist precisely to tell the port from an
            // address that is itself full of colons.
            $close = strpos($entry, ']');
            $entry = $close === false ? substr($entry, 1) : substr($entry, 1, $close - 1);
        } elseif (substr_count($entry, ':') === 1) {
            // Exactly one colon is `host:port`. Two or more is an unbracketed IPv6, where no
            // port can be told from the address — and none is written without brackets.
            $entry = strstr($entry, ':', true) ?: $entry;
        }

        // A zone id (`fe80::1%eth0`) names an interface on the machine that wrote it, which
        // is meaningless anywhere else and is not part of the address.
        $percent = strpos($entry, '%');
        if ($percent !== false) {
            $entry = substr($entry, 0, $percent);
        }

        return filter_var($entry, FILTER_VALIDATE_IP) === false ? null : $entry;
    }
}
