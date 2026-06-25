<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

use MatomoAnalytics\Contracts\BotDetector;
use MatomoAnalytics\Support\CallableResolver;
use MatomoAnalytics\Support\Config;

/**
 * Cheap substring bot detection: an explicit allow/deny list, the maintained
 * AI-crawler tokens, generic crawler signals, and an optional custom detector
 * (e.g. a matomo/device-detector wrapper) wired through config.
 */
final class DefaultBotDetector implements BotDetector
{
    /**
     * @var list<string>
     */
    private const array GENERIC = [
        'bot', 'crawler', 'spider', 'slurp', 'crawl', '+http', 'python-requests',
        'curl/', 'wget', 'go-http-client', 'java/', 'headlesschrome', 'facebookexternalhit',
        'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'baiduspider', 'semrushbot',
        'ahrefsbot', 'mj12bot', 'dotbot',
    ];

    public function isBot(string $userAgent): bool
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return true;
        }

        if ($this->matchesAny($userAgent, Config::stringList('matomo-analytics.bots.allow'))) {
            return false;
        }

        if ($this->matchesAny($userAgent, Config::stringList('matomo-analytics.bots.deny'))) {
            return true;
        }

        if ($this->isAiCrawler($userAgent)) {
            return true;
        }

        if (Config::bool('matomo-analytics.bots.detect_generic', true) && $this->matchesAny($userAgent, self::GENERIC)) {
            return true;
        }

        return $this->viaCustomDetector($userAgent);
    }

    public function isAiCrawler(string $userAgent): bool
    {
        return Config::bool('matomo-analytics.bots.detect_ai_crawlers', true)
            && $this->matchesAny($userAgent, AiCrawlers::TOKENS);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function matchesAny(string $userAgent, array $tokens): bool
    {
        $haystack = strtolower($userAgent);

        return array_any($tokens, fn (string $token): bool => $token !== '' && str_contains($haystack, strtolower($token)));
    }

    private function viaCustomDetector(string $userAgent): bool
    {
        $callable = CallableResolver::resolve(config('matomo-analytics.bots.detector'));

        return $callable !== null && $callable($userAgent) === true;
    }
}
