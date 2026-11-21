<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/**
 * The fetchers that read a page because a person shared its link, to show its title and image in a
 * card: the token each one's User-Agent carries, matched case-insensitively as a substring, and where
 * its owner documents it, or the catalog that records it where the owner documents nothing: the bot
 * catalog of matomo/device-detector, or Cloudflare's directory of verified bots, category Page Preview.
 *
 * A link preview is a bot for every count of visits. UserAgentClassifier tells it apart from the other
 * bots, for an application that counts the previews of its links on their own.
 */
final class LinkPreviews
{
    /** The bot catalog of matomo/device-detector, for a fetcher its owner documents no User-Agent for. */
    private const string DEVICE_DETECTOR = 'https://github.com/matomo-org/device-detector/blob/master/regexes/bots.yml';

    /** Cloudflare's directory of verified bots: a fetcher's own page, under the slug its entry has there. */
    private const string CLOUDFLARE = 'https://radar.cloudflare.com/bots/directory/';

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
        // Microsoft Teams and Outlook.
        'MicrosoftPreview' => 'https://aka.ms/MicrosoftPreview',
        // The fetchers Cloudflare files under Page Preview, each spelled as the sample User-Agent of its entry
        // spells it. Read on 20 November 2026 through the copy at commit 1220fc04eb of
        // microlinkhq/cloudflare-bot-directory.
        'facebookcatalog' => self::CLOUDFLARE.'facebook',
        'meta-webindexer' => self::CLOUDFLARE.'meta-web-indexer',
        'BingPreview' => self::CLOUDFLARE.'bingpreview',
        'Bluesky/' => self::CLOUDFLARE.'bluesky-app',
        'kakaotalk-scrap' => self::CLOUDFLARE.'kakaotalk-scrap-2',
        // Odnoklassniki, whose fetcher the directory files under the entry `ozon-web-grabber`.
        'OdklBot/' => self::CLOUDFLARE.'ozon-web-grabber',
        'Mail.RU_Bot' => self::CLOUDFLARE.'mail-ru-bot',
        'Daum-Preview' => self::CLOUDFLARE.'daum-preview',
        'Tumblr/' => self::CLOUDFLARE.'automattic-tumblr',
        'FlipboardProxy' => self::CLOUDFLARE.'flipboard',
        'BufferLinkPreviewBot' => self::CLOUDFLARE.'bufferlinkpreviewbot',
        'LoomlyBot' => self::CLOUDFLARE.'loomlybot',
        'Orlo-LinkPreview/' => self::CLOUDFLARE.'orlo-link-preview',
        'HubSpot Page Fetcher' => self::CLOUDFLARE.'hubspot-page-fetcher',
        'Embedly' => self::CLOUDFLARE.'embedly',
        'Iframely' => self::CLOUDFLARE.'iframely',
        'OpenGraph.io/' => self::CLOUDFLARE.'opengraphbot',
        'Google-PageRenderer' => self::CLOUDFLARE.'google-pagerenderer-3',
        // Gmail, fetching the image of a mail for the person reading it.
        'GoogleImageProxy' => self::CLOUDFLARE.'googleimageproxy',
        'FastmailUA' => self::CLOUDFLARE.'fastmail-bot',
        'hey.com/imageproxy' => self::CLOUDFLARE.'hey-email-privacy-proxy-2',
        'ArenaBot' => self::CLOUDFLARE.'arena-bot',
        'PressEngineBot' => self::CLOUDFLARE.'press-engine-bot',
        'MyVidsterPreviewBot' => self::CLOUDFLARE.'myvidsterpreviewbot',
        'LinksIndexerBot' => self::CLOUDFLARE.'linksindexerbot',
        'Creobot' => self::CLOUDFLARE.'creobot',
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
