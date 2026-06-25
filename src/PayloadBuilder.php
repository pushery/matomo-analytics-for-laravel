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
 * exact hit time (cdt), which Matomo only honours with token_auth.
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
        if (! Config::bool('matomo-analytics.anonymize_ip', false)) {
            return $ip;
        }

        if (str_contains($ip, ':')) {
            $blocks = explode(':', $ip);
            $kept = array_slice($blocks, 0, 3);

            return implode(':', [...$kept, ':']);
        }

        $octets = explode('.', $ip);
        if (count($octets) === 4) {
            $octets[3] = '0';

            return implode('.', $octets);
        }

        return $ip;
    }
}
