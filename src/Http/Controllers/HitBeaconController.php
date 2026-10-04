<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Support\BeaconOrigin;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Tracking\CustomParameters;
use MatomoAnalytics\Tracking\Download;
use MatomoAnalytics\Tracking\Event;
use MatomoAnalytics\Tracking\Hit;
use MatomoAnalytics\Tracking\Outlink;
use MatomoAnalytics\Tracking\Ping;
use MatomoAnalytics\Tracking\SiteSearch;
use MatomoAnalytics\View\Snippet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records what only the browser sees on a page that loads no `matomo.js`: an event, an outlink,
 * a download, a site search and a heartbeat.
 *
 * The page sends them through {@see Snippet::hitBeacon()} as `{type, url, …}`, and each becomes
 * the hit the facade would build for it. Tracking goes through the normal TrackManager, so the
 * gate and fail-safe delivery apply, and the hit is filed under the page it names. Returns 204,
 * or 422 for a payload this route will not record.
 *
 * Every field is unauthenticated client input. Text is bounded, a number has to be finite, a
 * link has to be an absolute `http` or `https` URL, and `hit_beacon.event_categories`, when set,
 * names the only event categories a page may send.
 */
final class HitBeaconController
{
    /** The longest text field accepted: an event's category, action and name, a search keyword and its category. */
    private const int MAX_TEXT = 255;

    /** The longest link accepted for an outlink or a download, in bytes. */
    private const int MAX_LINK = 2_048;

    public function __invoke(Request $request, Tracker $tracker): Response
    {
        if (! Config::bool('matomo-analytics.hit_beacon.enabled', false)) {
            // Not abort(): that helper ships with laravel/framework's Foundation, and this package
            // requires illuminate components instead. It throws exactly this.
            throw new NotFoundHttpException;
        }

        // The origin of the REQUEST, which the sending page cannot write, before the payload it can.
        if (! BeaconOrigin::isOwn($request)) {
            return new Response(status: Response::HTTP_FORBIDDEN);
        }

        $hit = $this->hit($request);

        if (! $hit instanceof Hit) {
            return new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A page URL from anywhere else is dropped rather than refused: the hit came from one of
        // our pages, and without a URL it is filed under the request it arrived on.
        $page = BeaconOrigin::pageUrl($request->input('url'));

        $tracker->track($page === null ? $hit : CustomParameters::for($hit)->param('url', $page));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function hit(Request $request): ?Hit
    {
        return match ($request->input('type')) {
            'event' => $this->event($request),
            'outlink' => ($link = $this->link($request->input('link'))) === null ? null : new Outlink($link),
            'download' => ($link = $this->link($request->input('link'))) === null ? null : new Download($link),
            'search' => $this->search($request),
            'ping' => new Ping,
            default => null,
        };
    }

    private function event(Request $request): ?Event
    {
        $category = $this->text($request->input('category'));
        $action = $this->text($request->input('action'));
        $name = $request->input('name');
        $value = $request->input('value');

        if ($category === null || $action === null || ! $this->allowedCategory($category)) {
            return null;
        }

        // An optional field that is sent has to be valid; a blank one is the field left out.
        if (! $this->blank($name) && $this->text($name) === null) {
            return null;
        }

        if (! $this->blank($value) && $this->number($value) === null) {
            return null;
        }

        return new Event($category, $action, $this->text($name), $this->number($value));
    }

    private function search(Request $request): ?SiteSearch
    {
        $keyword = $this->text($request->input('keyword'));
        $category = $request->input('category');
        $count = $request->input('count');
        // A boolean is no result count, and filter_var() reads `true` as 1.
        $results = is_bool($count) ? null : filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0], 'flags' => FILTER_NULL_ON_FAILURE]);

        // Matomo tests the keyword with `empty()`, so a search for "0" is one it cannot record.
        if ($keyword === null || $keyword === '0') {
            return null;
        }

        if (! $this->blank($category) && $this->text($category) === null) {
            return null;
        }

        if (! $this->blank($count) && $results === null) {
            return null;
        }

        return new SiteSearch($keyword, $this->text($category), $results);
    }

    /** Whether an optional field was left out: absent, empty or only whitespace, Unicode whitespace included. */
    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && Str::trim($value) === '');
    }

    /**
     * Whether a page may send events in this category.
     *
     * An empty list allows every category, the way `matomo.js` does. A list names the only ones.
     */
    private function allowedCategory(string $category): bool
    {
        $allowed = Config::stringList('matomo-analytics.hit_beacon.event_categories');

        return $allowed === [] || in_array($category, $allowed, true);
    }

    /** Text trimmed of whitespace, Unicode whitespace included, of at most MAX_TEXT characters, or null. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = Str::trim($value);

        return $text === '' || mb_strlen($text) > self::MAX_TEXT ? null : $text;
    }

    /**
     * A finite number, or null.
     *
     * `is_numeric()` accepts any magnitude, and `"1e400"` casts to `INF`, which poisons every
     * sum and average it lands in.
     */
    private function number(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /** An absolute `http` or `https` URL of at most MAX_LINK bytes, or null. */
    private function link(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LINK) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        return in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) && is_string($host) && $host !== ''
            ? $value
            : null;
    }
}
