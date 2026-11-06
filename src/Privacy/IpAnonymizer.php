<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use MatomoAnalytics\Support\Config;

/**
 * The client address as it may leave this application: truncated with `anonymize_ip` on, whole
 * with it off.
 *
 * Everything this package sends to Matomo that is derived from the address reads it here, the
 * `cip` of a hit and the cookieless visitor id alike, so no field carries more of the address than
 * `cip` does.
 */
final class IpAnonymizer
{
    /**
     * The address to send, or null to send none.
     *
     * With `anonymize_ip` on, a value that is not an address is not sent: the setting promises
     * that the full address never leaves this application, and a value that cannot be masked
     * cannot be held to that promise.
     */
    public static function outgoing(string $ip): ?string
    {
        if (! Config::bool('matomo-analytics.anonymize_ip', true)) {
            return $ip;
        }

        $bytes = self::bytes();

        if (str_contains($ip, ':')) {
            return self::anonymizeIpv6($ip, $bytes);
        }

        return self::anonymizeIpv4($ip, $bytes);
    }

    /**
     * How many bytes `anonymize_ip_bytes` cuts, held to the 1 to 3 that Matomo's own setting offers.
     *
     * A value below the range reads as 1 rather than as no cut, because `anonymize_ip` promises
     * that the full address never leaves this application. A value above it reads as 3, the
     * strongest cut there is.
     *
     * @return int<1, 3>
     */
    private static function bytes(): int
    {
        return max(1, min(3, Config::int('matomo-analytics.anonymize_ip_bytes', 1)));
    }

    /**
     * Keep the first 48 bits, or the first 24 at a cut of three bytes, and zero the rest.
     *
     * That is never more than Matomo's own mask of the same size keeps: its two-byte mask keeps
     * 48 bits and its three-byte mask 24, and its one-byte mask keeps 64, so a cut of one byte
     * here keeps 48 as two would.
     *
     * Normalized through inet_pton rather than split on colons. Splitting is correct only
     * for the fully written-out form: any address carrying a `::` run explodes into empty
     * elements, so `2001:db8::1` came out as `2001:db8:::` — not an address at all. That is
     * the ordinary way IPv6 is written, so most anonymized addresses left here malformed,
     * and neither side complains: Matomo stores what it is sent and the geolocation simply
     * misses.
     *
     * Rejection is `inet_pton` rather than `filter_var`, and the difference is one class:
     * a zone id. `filter_var` refuses `fe80::1%eth0`, so it used to leave here verbatim — a
     * whole link-local address surviving the setting that exists to cut it. It is an address
     * with an interface qualifier, not a non-address, and it is now masked like one. What is
     * not an address is not sent at all.
     *
     * The `is_string` arms are the declared `string|false` of the calls, not a second opinion
     * about the input.
     *
     * @param  int<1, 3>  $bytes
     */
    private static function anonymizeIpv6(string $ip, int $bytes): ?string
    {
        // The zone id is stripped here rather than left to `inet_pton`, because whether it
        // accepts one depends on the C library: glibc and macOS do, musl does not, so the same
        // address would anonymize on one host and fail on another. `ClientIp` strips it at the
        // source for the same reason it is meaningless here: it names an interface on the
        // machine that wrote it.
        $percent = strpos($ip, '%');
        $ip = $percent === false ? $ip : substr($ip, 0, $percent);

        $packed = inet_pton($ip);

        if (! is_string($packed) || strlen($packed) !== 16) {
            // Not an address, so nothing can be masked, and a value sent whole could carry the
            // address the setting exists to cut.
            return null;
        }

        // Ten zero bytes then `ff ff`: the packed form of `::ffff:0:0/96`, the range RFC 4291
        // reserves for an IPv4 address carried inside an IPv6 one.
        $mappedPrefix = str_repeat("\0", 10)."\xff\xff";

        if (str_starts_with($packed, $mappedPrefix)) {
            // An IPv4 address wearing an IPv6 coat (`::ffff:192.0.2.1`) is anonymized as
            // the IPv4 address it is. Masking it to 48 or 24 bits would be correct arithmetic
            // and useless data: every mapped address has 48 zero bits in front, so all of them
            // would collapse to the same `::`.
            //
            // The test used to be `str_contains($ip, '.')`, and RFC 4291 lets **any** IPv6
            // address end in dotted-quad notation. `2001:db8::192.0.2.1` is an ordinary
            // global address that merely writes its last 32 bits the familiar way — it took
            // this branch and left with 112 of its 128 bits intact, on the one setting whose
            // whole job is to remove them.
            //
            // Reading the packed prefix asks what the branch is actually about, and it makes
            // the two spellings of one mapped address agree as a side effect: `::ffff:c000:201`
            // carries no dot, so it used to fall through to the 48-bit mask and collapse to
            // `::` while `::ffff:192.0.2.1` kept its network.
            $mapped = inet_ntop(substr($packed, 12));
            $masked = is_string($mapped) ? self::anonymizeIpv4($mapped, $bytes) : null;

            return $masked === null ? null : '::ffff:'.$masked;
        }

        $kept = $bytes === 3 ? 3 : 6;
        $anonymized = inet_ntop(substr($packed, 0, $kept).str_repeat("\0", 16 - $kept));

        return is_string($anonymized) ? $anonymized : null;
    }

    /**
     * Zero as many of the last octets as the cut has bytes.
     *
     * @param  int<1, 3>  $bytes
     */
    private static function anonymizeIpv4(string $ip, int $bytes): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $octets = explode('.', $ip);

        for ($octet = 4 - $bytes; $octet < 4; $octet++) {
            $octets[$octet] = '0';
        }

        return implode('.', $octets);
    }
}
