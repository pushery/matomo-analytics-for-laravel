<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\Config;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            // NOT abort(): that helper ships only with laravel/framework's Foundation,
            // and this package requires illuminate components instead. abort() does
            // exactly this — it throws the Symfony exception Laravel's handler renders
            // as a 404, which arrives here through illuminate/http either way.
            throw new NotFoundHttpException;
        }

        $metric = $request->input('metric');
        $value = $request->input('value');

        if (! is_string($metric) || ! in_array($metric, Config::stringList('matomo-analytics.web_vitals.metrics'), true) || ! is_numeric($value)) {
            // Constructed rather than via response(), for the same reason. The factory
            // behind that helper only adds the header defaults an empty body has none of.
            return new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The rating is an unauthenticated, client-supplied string used as the Matomo
        // event name — accept only the three ratings the Web Vitals spec defines, so a
        // client cannot push an arbitrary unbounded name.
        $rating = $request->input('rating');

        $tracker->event(
            Config::string('matomo-analytics.web_vitals.category', 'Web Vitals'),
            $metric,
            is_string($rating) && in_array($rating, ['good', 'needs-improvement', 'poor'], true) ? $rating : null,
            (float) $value,
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
