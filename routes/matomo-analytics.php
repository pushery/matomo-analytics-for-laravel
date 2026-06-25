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

$throttle = Config::nullableString('matomo-analytics.web_vitals.throttle');
if ($throttle !== null) {
    $webVitals->middleware('throttle:'.$throttle);
}
