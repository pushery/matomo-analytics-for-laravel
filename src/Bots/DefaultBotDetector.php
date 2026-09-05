<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

use Illuminate\Support\Facades\Config as ConfigFacade;
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
    /**
     * The compile-time token lists, lowercased once. Keyed by name — see lowered().
     *
     * @var array<string, list<string>>
     */
    private static array $lowered = [];

    private const array GENERIC = [
        'bot', 'crawler', 'spider', 'slurp', 'crawl', '+http', 'python-requests',
        'curl/', 'wget', 'go-http-client', 'java/', 'headlesschrome', 'facebookexternalhit',
        'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'baiduspider', 'semrushbot',
        'ahrefsbot', 'mj12bot', 'dotbot',
        // Social/link-preview fetchers that carry none of the generic signals above.
        'whatsapp', 'skypeuripreview', 'vkshare',
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

        if (Config::bool('matomo-analytics.bots.detect_generic', true) && $this->matchesAny($userAgent, $this->lowered('generic', self::GENERIC))) {
            return true;
        }

        return $this->viaCustomDetector($userAgent);
    }

    public function isAiCrawler(string $userAgent): bool
    {
        return Config::bool('matomo-analytics.bots.detect_ai_crawlers', true)
            && $this->matchesAny($userAgent, $this->lowered('crawlers', AiCrawlers::TOKENS));
    }

    public function isAiChatbot(string $userAgent): bool
    {
        $tokens = Config::stringList('matomo-analytics.ai_chatbots.user_agents');

        return $this->matchesAny($userAgent, $tokens === [] ? $this->lowered('chatbots', AiChatbots::USER_AGENTS) : $tokens);
    }

    /**
     * @param  list<string>  $tokens
     */
    /**
     * `mb_strtolower`, NOT `strtolower`, BECAUSE THE FRAMEWORK USES IT AND THIS DIVERGED.
     * `strtolower` is byte-wise: it lowercases ASCII and leaves every other codepoint alone.
     * So a configured token spelled with non-ASCII capitals matched under `Str::contains` and
     * not here — `ЯНДЕКС-РОБОТ` against `Яндекс`, to name the case that was measured. Ten real
     * user agents were compared against the framework's own helper: nine identical, six of
     * them positive, and that one divergence.
     *
     * The shipped lists are ASCII, so this only ever affected `bots.allow` and `bots.deny`
     * entries a consumer wrote — which is the half where a silent mismatch is least likely to
     * be noticed, because it fails by tracking a bot rather than by throwing.
     *
     * @param  list<string>  $tokens
     */
    private function matchesAny(string $userAgent, array $tokens): bool
    {
        $haystack = mb_strtolower($userAgent);

        return array_any($tokens, fn (string $token): bool => $token !== '' && str_contains($haystack, mb_strtolower($token)));
    }

    /**
     * A COMPILE-TIME token list, lowercased once for the whole process.
     *
     * 164 `mb_strtolower()` CALLS PER HIT, ON VALUES THAT CANNOT CHANGE. The lowering sits
     * inside the match loop, and `AiCrawlers::TOKENS` has 164 entries — measured at 0.0035ms
     * per hit, about 4% of `track()`, spent turning the same constants into the same strings
     * over and over.
     *
     * Only the CONSTANTS go through here. A configured list (`bots.allow`, `bots.deny`, a
     * custom chatbot list) is read from config on every call and is short, and memoizing it
     * would serve a stale list to the next test — or, under Octane, to the next request — the
     * moment somebody changed it.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function lowered(string $name, array $tokens): array
    {
        return self::$lowered[$name] ??= array_map(mb_strtolower(...), $tokens);
    }

    private function viaCustomDetector(string $userAgent): bool
    {
        $callable = CallableResolver::resolve(ConfigFacade::get('matomo-analytics.bots.detector'));

        return $callable !== null && $callable($userAgent) === true;
    }
}
