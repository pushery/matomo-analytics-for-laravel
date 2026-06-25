<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\Config;

/**
 * Ingests a single Core Web Vitals sample beaconed from the browser and records
 * it as a Matomo event (category from config, action = metric, name = rating,
 * value = the measurement). Tracking goes through the normal TrackManager, so the
 * gate (bots, opt-out, DNT, …) and fail-safe delivery all apply. Returns 204.
 */
final class WebVitalsController
{
    public function __invoke(Request $request, Tracker $tracker): Response
    {
        if (! Config::bool('matomo-analytics.web_vitals.enabled', false)) {
            abort(404);
        }

        $metric = $request->input('metric');
        $value = $request->input('value');

        if (! is_string($metric) || ! in_array($metric, Config::stringList('matomo-analytics.web_vitals.metrics'), true) || ! is_numeric($value)) {
            return response()->noContent(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rating = $request->input('rating');

        $tracker->event(
            Config::string('matomo-analytics.web_vitals.category', 'Web Vitals'),
            $metric,
            is_string($rating) && $rating !== '' ? $rating : null,
            (float) $value,
        );

        return response()->noContent();
    }
}
