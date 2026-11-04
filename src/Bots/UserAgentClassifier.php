<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

use MatomoAnalytics\Contracts\BotDetector;

/**
 * Tells a person from a link preview and from every other bot.
 *
 * The bot detector the container binds decides what is a bot, `bots.allow` and `bots.deny` included,
 * so this never calls a person what the tracking gate counts as a bot, or the other way round. A bot
 * that carries the token of a fetcher in LinkPreviews is a link preview.
 */
final readonly class UserAgentClassifier
{
    public function __construct(private BotDetector $bots) {}

    public function classify(string $userAgent): UserAgentKind
    {
        if (! $this->bots->isBot($userAgent)) {
            return UserAgentKind::Human;
        }

        return LinkPreviews::matches($userAgent) ? UserAgentKind::LinkPreview : UserAgentKind::Bot;
    }
}
