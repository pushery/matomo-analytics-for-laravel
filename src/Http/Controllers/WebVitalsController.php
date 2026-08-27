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
    /**
     * The widest a Core Web Vital can plausibly be, and the reason there is a ceiling here
     * at all.
     *
     * `is_numeric()` accepts any magnitude, so `"1e400"` passed the check and cast to `INF`
     * — an unauthenticated browser POST could put a non-finite value into the consumer's
     * reports, where it poisons every average it lands in. The metric name and the rating
     * were both held against allowlists; the one number in the payload was not held against
     * anything.
     *
     * The bound is generous on purpose: the four duration metrics are milliseconds and an
     * hour is far past any real measurement, while CLS is unitless and in practice below
     * ten. Nothing legitimate is refused by it, and nothing is negative — no Web Vital can be.
     */
    private const float MAX_VALUE = 3_600_000.0;

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
        // Parsed rather than merely checked, so the value that reaches the tracker is the
        // one this method vouched for. A separate `is_numeric()` guard and a later `(float)`
        // cast are two statements that can drift apart; one function returning the float or
        // nothing cannot.
        $measurement = $this->plausibleMeasurement($request->input('value'));

        if (! is_string($metric) || ! in_array($metric, Config::stringList('matomo-analytics.web_vitals.metrics'), true) || $measurement === null) {
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
            $measurement,
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function plausibleMeasurement(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $measurement = (float) $value;

        return is_finite($measurement) && $measurement >= 0.0 && $measurement <= self::MAX_VALUE
            ? $measurement
            : null;
    }
}
