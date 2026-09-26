<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MatomoAnalytics\Http\Controllers\PrefetchPageViewController;
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

// The throttle is switched off only on purpose, by an explicit `null` or `'off'`. An absent key,
// a blank `.env` line or a value that is not a throttle gets the one the package ships
// (`Config::throttle()`), because this is an unauthenticated POST endpoint.
//
// It is keyed on the client address this package resolves, not on `$request->ip()`. Laravel's
// throttle keys on the request's IP, which is the proxy's address behind a CDN unless the
// application trusts its proxies, so every visitor would share one bucket. The package resolves
// the real address through `ip_header` for `cip` and `except_ips`, and the throttle uses the same
// answer. That key is only reachable through a named limiter rather than
// `throttle:<max>,<minutes>`; the configured "requests,minutes" shape is unchanged.
//
// Only the attachment is here, and the limiter is registered in the provider. This file is loaded
// by `loadRoutesFrom()`, which Laravel skips once `php artisan route:cache` has compiled the route
// table, so a `RateLimiter::for()` written here would never run in a cached deploy while the
// compiled table carries the `throttle:` name below. The middleware name is compiled into the
// cached table, which is why it lives here.
if (Config::throttle('matomo-analytics.web_vitals.throttle') !== null) {
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

// The page-view beacon for a page the browser served out of a speculation-rules prefetch.
// Same shape as the route above and for the same reasons — always registered so toggling the
// feature needs no route-cache rebuild, the controller 404s while it is off, throttled through
// a NAMED limiter registered in the provider (a `RateLimiter::for()` written here would not
// survive `route:cache`), and in no middleware group, because sendBeacon carries no CSRF token.
$prefetchPageView = Route::post(
    Config::string('matomo-analytics.prefetch_beacon.path', 'matomo-analytics/page-view'),
    PrefetchPageViewController::class,
)->name('matomo-analytics.prefetch-page-view');

if (Config::throttle('matomo-analytics.prefetch_beacon.throttle') !== null) {
    $prefetchPageView->middleware('throttle:'.MatomoAnalyticsServiceProvider::PREFETCH_BEACON_LIMITER);
}

$beaconMiddleware = Config::stringList('matomo-analytics.prefetch_beacon.middleware');
if ($beaconMiddleware !== []) {
    $prefetchPageView->middleware($beaconMiddleware);
}
