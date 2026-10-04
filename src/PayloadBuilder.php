<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config as ConfigFacade;
use MatomoAnalytics\Contracts\VisitorIdResolver;
use MatomoAnalytics\Privacy\UrlRedactor;
use MatomoAnalytics\Support\CallableResolver;
use MatomoAnalytics\Support\ClientIp;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\ConsoleRequest;
use MatomoAnalytics\Tracking\Hit;
use Throwable;

/**
 * Turns a Hit plus the originating request into a flat Matomo Tracking API
 * parameter array: site id, visitor id, real request context (url/referrer/ua/
 * lang), the hit time (cdt) and — only when a token is configured — the real
 * client IP (cip), which Matomo honors only with token_auth. The hit time needs a
 * token only once it is more than a day old; HttpSender drops it from such a hit
 * when there is none.
 */
final readonly class PayloadBuilder
{
    /**
     * The request attribute under which `TrackAiChatbots` leaves the response it saw: the HTTP
     * status, the body size in bytes and the milliseconds the application took, as
     * `http_status`, `bw_bytes` and `pf_srv`. Matomo's bot tracking stores the three and counts
     * its not-found, server-error, size and timing figures for AI assistants from them.
     */
    public const string AI_CHATBOT_RESPONSE = 'matomo-analytics.ai_chatbot_response';

    public function __construct(
        private Connection $connection,
        private VisitorIdResolver $visitorId,
        private UrlRedactor $redactor,
    ) {}

    /**
     * The site this hit belongs to: whatever the configured resolver answers, else the
     * connection's own id.
     *
     * THIS RUNS ON THE REQUEST PATH AND MUST NOT THROW. A resolver is application code the
     * package cannot see, so anything it does other than returning a positive int -- throwing,
     * returning null, a string, a negative -- falls back rather than propagating. An
     * extension point that can break tracking is worse than no extension point.
     */
    private function siteId(): int
    {
        $resolver = CallableResolver::resolve(ConfigFacade::get('matomo-analytics.site_id_resolver'));

        if ($resolver === null) {
            return $this->connection->siteId;
        }

        try {
            $resolved = $resolver();
        } catch (Throwable) {
            return $this->connection->siteId;
        }

        return is_int($resolved) && $resolved > 0 ? $resolved : $this->connection->siteId;
    }

    /**
     * @return array<string, scalar>
     */
    public function build(Hit $hit, Request $request): array
    {
        $base = [
            'idsite' => $this->siteId(),
            'rec' => 1,
            'apiv' => 1,
            'send_image' => 0,
            'url' => $request->fullUrl(),
        ];

        // A console process has no visitor, only the request Laravel invents for it, and every
        // job and command would share that one: Symfony's user agent, 127.0.0.1, and a visitor
        // id derived from both. Such a hit carries only what its caller put on it.
        if (! ConsoleRequest::isSynthetic($request)) {
            $base = [...$base, ...$this->visitor($request)];
        }

        $userId = $this->userId($request);
        if ($userId !== null) {
            $base['uid'] = $userId;
        }

        $base['cdt'] = gmdate('Y-m-d H:i:s');

        return $this->redactor->redactPayload($this->wellFormed(array_merge($base, $hit->toParams())));
    }

    /**
     * What a request says about the visitor behind it: the visitor id, the referrer, the user
     * agent, the language and, with a token, the address.
     *
     * @return array<string, scalar>
     */
    private function visitor(Request $request): array
    {
        $visitor = ['_id' => $this->visitorId->resolve($request)];

        $referrer = $request->headers->get('referer');
        if (is_string($referrer) && $referrer !== '') {
            $visitor['urlref'] = $referrer;
        }

        $userAgent = $request->userAgent();
        if ($userAgent !== null && $userAgent !== '') {
            $visitor['ua'] = $userAgent;
        }

        $language = $request->headers->get('accept-language');
        if (is_string($language) && $language !== '') {
            $visitor['lang'] = $language;
        }

        if ($this->connection->token !== null) {
            $ip = $this->clientIp($request);
            if ($ip !== null) {
                $visitor['cip'] = $ip;
            }
        }

        return $visitor;
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
            'idsite' => $this->siteId(),
            'rec' => 1,
            'recMode' => Config::int('matomo-analytics.ai_chatbots.rec_mode', 1),
            'send_image' => 0,
            'url' => $this->redactor->redact(mb_scrub($request->fullUrl(), 'UTF-8')),
            'cdt' => gmdate('Y-m-d H:i:s'),
            'source' => Config::string('matomo-analytics.ai_chatbots.source', 'Laravel'),
        ];

        $userAgent = $request->userAgent();
        if ($userAgent !== null && $userAgent !== '') {
            $payload['ua'] = $userAgent;
        }

        $response = $request->attributes->get(self::AI_CHATBOT_RESPONSE);
        if (is_array($response)) {
            foreach (['http_status', 'bw_bytes', 'pf_srv'] as $key) {
                $value = $response[$key] ?? null;

                if (is_int($value) && $value >= 0) {
                    $payload[$key] = $value;
                }
            }
        }

        return $this->wellFormed($payload);
    }

    /**
     * Every string in the payload as valid UTF-8, with a broken byte sequence replaced.
     *
     * A client writes the referrer, the user agent and the language, and nothing makes them
     * UTF-8. The queue payload and the buffered line are both JSON, which refuses a broken byte,
     * so one such header lost every hit of its request in `queue` mode, and in `batch` mode every
     * hit from that one on. Done before the URLs are redacted: a redaction pattern with the `u`
     * modifier fails on a broken byte and leaves the URL as it was.
     *
     * @param  array<string, scalar>  $payload
     * @return array<string, scalar>
     */
    private function wellFormed(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $payload[$key] = mb_scrub($value, 'UTF-8');
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

    /**
     * The address to send as `cip`, or null to send none.
     *
     * With `anonymize_ip` on, a value that is not an address is not sent: the setting promises
     * that the full address never leaves this application, and a value that cannot be masked
     * cannot be held to that promise.
     */
    private function maybeAnonymize(string $ip): ?string
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
     * REJECTION IS `inet_pton` RATHER THAN `filter_var`, and the difference is one class:
     * a zone id. `filter_var` refuses `fe80::1%eth0`, so it used to leave here VERBATIM — a
     * whole link-local address surviving the setting that exists to cut it. It is an address
     * with an interface qualifier, not a non-address, and it is now masked like one. What is
     * not an address is not sent at all.
     *
     * The `is_string` arms are the declared `string|false` of the calls, not a second opinion
     * about the input.
     */
    private function anonymizeIpv6(string $ip): ?string
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
            // the IPv4 address it is. Masking it to 48 bits would be correct arithmetic and
            // useless data: every mapped address has 48 zero bits in front, so all of them
            // would collapse to the same `::`.
            //
            // THE TEST USED TO BE `str_contains($ip, '.')`, AND RFC 4291 LETS **ANY** IPv6
            // ADDRESS END IN DOTTED-QUAD NOTATION. `2001:db8::192.0.2.1` is an ordinary
            // global address that merely writes its last 32 bits the familiar way — it took
            // this branch and left with 112 of its 128 bits intact, on the one setting whose
            // whole job is to remove them.
            //
            // Reading the packed prefix asks what the branch is actually about, and it makes
            // the two spellings of one mapped address agree as a side effect: `::ffff:c000:201`
            // carries no dot, so it used to fall through to the 48-bit mask and collapse to
            // `::` while `::ffff:192.0.2.1` kept its network.
            $mapped = inet_ntop(substr($packed, 12));
            $masked = is_string($mapped) ? $this->anonymizeIpv4($mapped) : null;

            return $masked === null ? null : '::ffff:'.$masked;
        }

        $anonymized = inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));

        return is_string($anonymized) ? $anonymized : null;
    }

    private function anonymizeIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $octets = explode('.', $ip);
        $octets[3] = '0';

        return implode('.', $octets);
    }
}
