<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use Illuminate\Support\Facades\Config as ConfigFacade;

/**
 * The consent requirement of the JS tracker, read from `privacy.consent`.
 *
 * Three values mean something: `none`, `cookie` and `full`. Every other value is read as
 * `full`, so a typo, a different spelling or a boolean makes the tracker wait for consent
 * instead of tracking without asking. `matomo:test` names such a value, because the tracker
 * then records nothing until the application's consent layer grants it.
 *
 * An absent key is the shipped default, `none`: the package's config merge fills a missing key
 * with that value in the first place, so reading it as `full` would change the behavior of a
 * published config that simply predates the key.
 */
final class ConsentMode
{
    public const string NONE = 'none';

    public const string COOKIE = 'cookie';

    public const string FULL = 'full';

    private const string KEY = 'matomo-analytics.privacy.consent';

    public static function resolve(): string
    {
        return match (self::configured()) {
            self::NONE => self::NONE,
            self::COOKIE => self::COOKIE,
            default => self::FULL,
        };
    }

    /**
     * The configured value, written out for a message, when it is none of the three; null otherwise.
     *
     * A string is quoted, so `'required'` and a non-string such as `true` stay apart in the message.
     */
    public static function unrecognized(): ?string
    {
        $value = self::configured();

        if (in_array($value, [self::NONE, self::COOKIE, self::FULL], true)) {
            return null;
        }

        return is_string($value) ? "'".$value."'" : (json_encode($value) ?: get_debug_type($value));
    }

    private static function configured(): mixed
    {
        return ConfigFacade::has(self::KEY) ? ConfigFacade::get(self::KEY) : self::NONE;
    }
}
