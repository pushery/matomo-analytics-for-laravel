<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MatomoAnalytics\Contracts\BotDetector;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in middleware that records an incoming AI-assistant page fetch as Matomo bot
 * telemetry (recMode) — the self-hosted alternative to Matomo's Cloudflare Worker.
 * Only GET requests whose User-Agent is a known on-demand AI fetcher are captured,
 * and only while `ai_chatbots.track` is enabled. The telemetry never creates a
 * visit, so it stays out of the human analytics reports.
 */
final readonly class TrackAiChatbots
{
    public function __construct(
        private Tracker $tracker,
        private BotDetector $botDetector,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->captures($request)) {
            $this->tracker->aiChatbot($request);
        }

        return $response;
    }

    private function captures(Request $request): bool
    {
        if (! Config::bool('matomo-analytics.ai_chatbots.track', false) || ! $request->isMethod('GET')) {
            return false;
        }

        $userAgent = $request->userAgent();

        return $userAgent !== null && $userAgent !== '' && $this->botDetector->isAiChatbot($userAgent);
    }
}
