<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use Illuminate\Http\Request;
use MatomoAnalytics\Contracts\VisitorIdResolver;
use MatomoAnalytics\Privacy\UrlRedactor;
use MatomoAnalytics\Support\ClientIp;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Tracking\Hit;

/**
 * Turns a Hit plus the originating request into a flat Matomo Tracking API
 * parameter array: site id, visitor id, real request context (url/referrer/ua/
 * lang) and — only when a token is configured — the real client IP (cip) and the
 * exact hit time (cdt), which Matomo only honors with token_auth.
 */
final readonly class PayloadBuilder
{
    public function __construct(
        private Connection $connection,
        private VisitorIdResolver $visitorId,
        private UrlRedactor $redactor,
    ) {}

    /**
     * @return array<string, scalar>
     */
    public function build(Hit $hit, Request $request): array
    {
        $base = [
            'idsite' => $this->connection->siteId,
            'rec' => 1,
            'apiv' => 1,
            'send_image' => 0,
            '_id' => $this->visitorId->resolve($request),
            'url' => $request->fullUrl(),
        ];

        $referrer = $request->headers->get('referer');
        if (is_string($referrer) && $referrer !== '') {
            $base['urlref'] = $referrer;
        }

        $userAgent = $request->userAgent();
        if ($userAgent !== null && $userAgent !== '') {
            $base['ua'] = $userAgent;
        }

        $language = $request->headers->get('accept-language');
        if (is_string($language) && $language !== '') {
            $base['lang'] = $language;
        }

        $userId = $this->userId($request);
        if ($userId !== null) {
            $base['uid'] = $userId;
        }

        if ($this->connection->token !== null) {
            $ip = $this->clientIp($request);
            if ($ip !== null) {
                $base['cip'] = $ip;
            }
            $base['cdt'] = gmdate('Y-m-d H:i:s');
        }

        return $this->redactUrls(array_merge($base, $hit->toParams()));
    }

    /**
     * Builds an AI-chatbot telemetry hit (recMode) — a bot-only Tracking API request
     * that Matomo records as telemetry WITHOUT creating a visitor or session. It
     * deliberately omits the visit parameters (_id / urlref / uid / cip); no
     * token_auth is required. Returns an empty array unless AI-chatbot tracking is
     * enabled and the instance is configured.
     *
     * @return array<string, scalar>
     */
    public function buildAiChatbot(Request $request): array
    {
        if (
            ! Config::bool('matomo-analytics.enabled', false)
            || ! Config::bool('matomo-analytics.ai_chatbots.track', false)
            || ! $this->connection->isConfigured()
        ) {
            return [];
        }

        $payload = [
            'idsite' => $this->connection->siteId,
            'rec' => 1,
            'recMode' => Config::int('matomo-analytics.ai_chatbots.rec_mode', 1),
            'send_image' => 0,
            'url' => $this->redactor->redact($request->fullUrl()),
            'cdt' => gmdate('Y-m-d H:i:s'),
            'source' => Config::string('matomo-analytics.ai_chatbots.source', 'Laravel'),
        ];

        $userAgent = $request->userAgent();
        if ($userAgent !== null && $userAgent !== '') {
            $payload['ua'] = $userAgent;
        }

        return $payload;
    }

    /**
     * @param  array<string, scalar>  $payload
     * @return array<string, scalar>
     */
    private function redactUrls(array $payload): array
    {
        foreach (Config::stringList('matomo-analytics.privacy.redact.keys') as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = $this->redactor->redact($payload[$key]);
            }
        }

        return $payload;
    }

    private function userId(Request $request): ?string
    {
        if (Config::nullableString('matomo-analytics.visitor.user_id') !== 'auth') {
            return null;
        }

        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier) ? (string) $identifier : null;
    }

    private function clientIp(Request $request): ?string
    {
        $ip = ClientIp::resolve($request);

        return $ip !== null ? $this->maybeAnonymize($ip) : null;
    }

    private function maybeAnonymize(string $ip): string
    {
        if (! Config::bool('matomo-analytics.anonymize_ip', true)) {
            return $ip;
        }

        if (str_contains($ip, ':')) {
            return $this->anonymizeIpv6($ip);
        }

        return $this->anonymizeIpv4($ip);
    }

    /**
     * Keep the first 48 bits and zero the rest, the way Matomo's own two-byte mask does.
     *
     * NORMALIZED THROUGH inet_pton RATHER THAN SPLIT ON COLONS. Splitting is correct only
     * for the fully written-out form: any address carrying a `::` run explodes into empty
     * elements, so `2001:db8::1` came out as `2001:db8:::` — not an address at all. That is
     * the ordinary way IPv6 is written, so most anonymized addresses left here malformed,
     * and neither side complains: Matomo stores what it is sent and the geolocation simply
     * misses.
     *
     * The two `is_string` arms are the declared `string|false` of the two calls, not a
     * second opinion about the input — a value this method returns unchanged is one
     * `filter_var` already refused.
     */
    private function anonymizeIpv6(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            // The forwarding header this can come from (`ip_header`) is whatever a proxy
            // put there, so anything that is not an address is handed back untouched
            // rather than sliced into something that resembles one.
            return $ip;
        }

        if (str_contains($ip, '.')) {
            // An IPv4 address wearing an IPv6 coat (`::ffff:192.0.2.1`) is anonymized as
            // the IPv4 address it is. Masking it to 48 bits would be correct arithmetic and
            // useless data: every mapped address has 48 zero bits in front, so all of them
            // would collapse to the same `::`.
            $parts = explode(':', $ip);
            $parts[count($parts) - 1] = $this->anonymizeIpv4($parts[count($parts) - 1]);

            return implode(':', $parts);
        }

        $packed = inet_pton($ip);
        $masked = (is_string($packed) ? substr($packed, 0, 6) : '').str_repeat("\0", 10);
        $anonymized = inet_ntop($masked);

        return is_string($anonymized) ? $anonymized : $ip;
    }

    private function anonymizeIpv4(string $ip): string
    {
        $octets = explode('.', $ip);
        if (count($octets) === 4) {
            $octets[3] = '0';

            return implode('.', $octets);
        }

        return $ip;
    }
}
