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
 *
 * The detector is asked first on purpose. The tracking gate asks the same detector, so a request this
 * calls a link preview is never tracked as a visit, and one it calls a person always is. The default
 * detector counts every token of LinkPreviews as a bot while `bots.detect_generic` is on, as it is by
 * default, so with it every fetcher on the list is a link preview. A fetcher in `bots.allow`, or one a
 * detector of the application's own does not count as a bot, is a person here as in the visits.
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
