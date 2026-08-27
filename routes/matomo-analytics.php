<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MatomoAnalytics\Http\Controllers\WebVitalsController;
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
$throttle = Config::nullableStringOrShipped('matomo-analytics.web_vitals.throttle');
if ($throttle !== null) {
    $webVitals->middleware('throttle:'.$throttle);
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
