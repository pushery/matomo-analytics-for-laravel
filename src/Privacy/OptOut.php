<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use Illuminate\Support\Facades\Cookie;
use MatomoAnalytics\Support\Config;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Server-side opt-out. Matomo's own opt-out widget sets a cookie on the Matomo
 * domain, which the server-side tracker cannot see — so this offers a first-party
 * cookie the tracking gate honours. Attach the returned cookie to a response
 * (e.g. return back()->withCookie(OptOut::enable())) from your own opt-out control.
 */
final class OptOut
{
    /** Opt the current browser out of server-side tracking. */
    public static function enable(): SymfonyCookie
    {
        return Cookie::forever(self::cookieName(), '1');
    }

    /** Opt the current browser back in by clearing the cookie. */
    public static function disable(): SymfonyCookie
    {
        return Cookie::forget(self::cookieName());
    }

    public static function cookieName(): string
    {
        return Config::string('matomo-analytics.privacy.opt_out.cookie', 'matomo_opt_out');
    }
}
