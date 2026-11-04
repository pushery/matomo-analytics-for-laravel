<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/**
 * The fetchers that read a page because a person shared its link, to show its title and image in a
 * card: the token each one's User-Agent carries, matched case-insensitively as a substring, and where
 * its owner documents it, or the catalog that records it where the owner documents nothing.
 *
 * A link preview is a bot for every count of visits. UserAgentClassifier tells it apart from the other
 * bots, for an application that counts the previews of its links on their own.
 */
final class LinkPreviews
{
    /** The bot catalog of matomo/device-detector, for a fetcher its owner documents no User-Agent for. */
    private const string DEVICE_DETECTOR = 'https://github.com/matomo-org/device-detector/blob/master/regexes/bots.yml';

    /**
     * @var array<string, string>
     */
    public const array USER_AGENTS = [
        // WhatsApp, and Signal, whose desktop client sends `WhatsApp/2` for its previews
        // (ts/linkPreviews/linkPreviewFetch.preload.ts in signalapp/Signal-Desktop).
        'WhatsApp/' => 'https://developers.facebook.com/documentation/business-messaging/whatsapp/link-previews/',
        'Slackbot-LinkExpanding' => 'https://api.slack.com/robots',
        'Slack-ImgProxy' => 'https://api.slack.com/robots',
        // Facebook, Instagram and Messenger.
        'facebookexternalhit' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers',
        'Twitterbot' => self::DEVICE_DETECTOR,
        'LinkedInBot' => self::DEVICE_DETECTOR,
        'TelegramBot' => self::DEVICE_DETECTOR,
        'Discordbot' => self::DEVICE_DETECTOR,
        'SkypeUriPreview' => self::DEVICE_DETECTOR,
        'vkShare' => self::DEVICE_DETECTOR,
        'ViberUrlDownloader' => self::DEVICE_DETECTOR,
    ];

    /** Whether a User-Agent carries the token of one of the fetchers, compared without regard to case. */
    public static function matches(string $userAgent): bool
    {
        $haystack = mb_strtolower($userAgent);

        return array_any(
            array_keys(self::USER_AGENTS),
            static fn (string $token): bool => str_contains($haystack, mb_strtolower($token)),
        );
    }
}
