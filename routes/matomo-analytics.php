<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MatomoAnalytics\Http\Controllers\WebVitalsController;
use MatomoAnalytics\MatomoAnalyticsServiceProvider;
use MatomoAnalytics\Support\Config;

// Core Web Vitals ingest endpoint. The route is always registered (so toggling the
// feature is a pure config change, no route-cache rebuild); the controller 404s
// unless web_vitals.enabled is true. Throttled per config to bound beacon abuse.
$webVitals = Route::post(
    Config::string('matomo-analytics.web_vitals.path', 'matomo-analytics/web-vitals'),
    WebVitalsController::class,
)->name('matomo-analytics.web-vitals');

// THE THROTTLE HAS A FLOOR UNDER IT. This read used to be `nullableString()`, which
// cannot tell "the operator switched it off" from "the key is not there" — and those mean
// opposite things here. A consumer whose published config predates the key, or who trimmed
// it, got `null` and therefore an unauthenticated POST endpoint with no rate limit at all.
// An explicit `'throttle' => null` still switches it off; an absent key now gets what the
// package ships.
//
// AND IT IS KEYED ON THE PACKAGE'S OWN CLIENT IP, NOT ON `$request->ip()`. Laravel's throttle
// resolves its key from the request's IP, which is the proxy's address behind a CDN unless the
// application has configured TrustProxies — so every visitor of such an installation shares
// one bucket, and the limit meant to bound one abuser bounds everybody instead. This package
// already resolves the real address through `ip_header` for `cip` and `except_ips`; the
// throttle now uses the same answer.
//
// Registered as a NAMED limiter rather than `throttle:<max>,<minutes>`, because the key is
// only reachable that way. The configured "requests,minutes" shape is unchanged.
//
// ONLY THE ATTACHMENT IS HERE. THE REGISTRATION IS IN THE PROVIDER, AND THAT IS NOT A STYLE
// CHOICE. This file is loaded by `loadRoutesFrom()`, a bare `require` that Laravel skips
// outright once `php artisan route:cache` has compiled the route table — so a
// `RateLimiter::for()` written here runs on a developer machine and never in a production
// deploy, while the compiled table goes on carrying the `throttle:` name below. From v0.27.0
// to v0.28.2 that is exactly what shipped, and every beacon into a route-cached installation
// answered 500. What stays here is the line that has to: the middleware name is compiled INTO
// the cached table, which is precisely why it survives and the registration did not.
$throttle = Config::nullableStringOrShipped('matomo-analytics.web_vitals.throttle');
if ($throttle !== null) {
    $webVitals->middleware('throttle:'.MatomoAnalyticsServiceProvider::WEB_VITALS_LIMITER);
}

// THIS ROUTE IS IN NO MIDDLEWARE GROUP, and that has a consequence worth stating rather
// than discovering. `loadRoutesFrom()` is a bare require, so nothing here starts a session:
// the gate's `track_authenticated` and `except_abilities` rules see a guest on this path
// no matter who is logged in. That is deliberate — the browser beacons this with
// `sendBeacon`, which carries no CSRF token — but a consumer who needs those rules to apply
// can name the middleware that makes them work, `['web']` included, and take the CSRF
// exemption on their own side.
$middleware = Config::stringList('matomo-analytics.web_vitals.middleware');
if ($middleware !== []) {
    $webVitals->middleware($middleware);
}
