<?php

declare(strict_types=1);

namespace MatomoAnalytics\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use MatomoAnalytics\Contracts\VisitorIdResolver;
use MatomoAnalytics\Support\ClientIp;
use MatomoAnalytics\Support\Config;

/**
 * Cookieless, privacy-preserving visitor id: a salted, periodically rotating
 * SHA-256 of IP + User-Agent, truncated to Matomo's 16-hex format. No cookie and
 * no consent banner required; the rotation period trades returning-visitor
 * accuracy for privacy.
 */
final class CookielessVisitorId implements VisitorIdResolver
{
    public function resolve(Request $request): string
    {
        $raw = implode('|', [
            // Same IP source as cip (ClientIp honors the configured forwarding header),
            // so proxied cookieless visitors get distinct ids instead of collapsing to
            // the shared edge IP.
            ClientIp::resolve($request) ?? '',
            $request->userAgent() ?? '',
            Config::string('app.key'),
            $this->rotationKey(),
        ]);

        return substr(hash('sha256', $raw), 0, 16);
    }

    private function rotationKey(): string
    {
        return match (Config::string('matomo-analytics.visitor.rotate', 'daily')) {
            'never' => 'static',
            'weekly' => Date::now()->format('o-\WW'),
            default => Date::now()->format('Y-m-d'),
        };
    }
}
