<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * The fields Matomo needs to record a hit as the action it asks for.
 *
 * Matomo files a tracking request under the action whose required field it carries and
 * records it as a page view when none does, so an event without a category or a download
 * without a URL would count as an extra page view. Goal id 0 is Matomo's ecommerce goal,
 * and an order without an order id is recorded as a cart update.
 *
 * A site search is the exception in kind: its keyword is what a visitor typed, so a search
 * without one is input rather than a mistake in the calling code. It is nothing to track, as
 * for a request without a keyword, and not a reason to report.
 *
 * Each field is read the way Matomo's tracker reads it: line breaks and null bytes are
 * removed first, and where Matomo tests a field with `empty()`, the string "0" counts as
 * missing as well.
 *
 * @internal
 */
final class RequiredFields
{
    /**
     * Whether the hit is a site search without a keyword Matomo can record, which trims it and
     * records a search whose keyword is then empty or "0" as a page view.
     */
    public static function emptySearch(Hit $hit): bool
    {
        return self::kind($hit) instanceof SiteSearch && self::absent(trim(self::read($hit->toParams(), 'search')));
    }

    /**
     * Why Matomo would record the hit as another action, or null when it records it as itself.
     * A hit decorated with custom parameters is judged by the parameters it actually sends.
     */
    public static function missing(Hit $hit): ?string
    {
        $params = $hit->toParams();
        $kind = self::kind($hit);

        if ($kind instanceof Event && (self::read($params, 'e_c') === '' || self::read($params, 'e_a') === '')) {
            return 'Event not sent: Matomo records an event without a category or an action as a page view.';
        }

        if ($kind instanceof ContentImpression && self::absent(self::read($params, 'c_n'))) {
            return 'Content impression not sent: Matomo records one without a name, or named "0", as a page view.';
        }

        if ($kind instanceof ContentInteraction) {
            return self::interaction($params);
        }

        if ($kind instanceof Outlink && self::absent(self::read($params, 'link'))) {
            return 'Outlink not sent: Matomo records an outlink without a URL as a page view.';
        }

        if ($kind instanceof Download && self::absent(self::read($params, 'download'))) {
            return 'Download not sent: Matomo records a download without a URL as a page view.';
        }

        if ($kind instanceof Goal) {
            return self::goal($params);
        }

        if ($kind instanceof EcommerceOrder && self::absent(self::read($params, 'ec_id'))) {
            return 'Ecommerce order not sent: Matomo records an order without an order id, or with "0", as a cart update.';
        }

        return null;
    }

    /** The hit a decorated hit wraps, however deep. */
    private static function kind(Hit $hit): Hit
    {
        while ($hit instanceof CustomParameters) {
            $hit = $hit->hit;
        }

        return $hit;
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private static function interaction(array $params): ?string
    {
        if (self::absent(self::read($params, 'c_n'))) {
            return 'Content interaction not sent: Matomo records one without a content name, or named "0", as a page view.';
        }

        if (trim(self::read($params, 'c_i')) === '') {
            return 'Content interaction not sent: without an interaction such as "click", Matomo records it as an impression.';
        }

        return null;
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private static function goal(array $params): ?string
    {
        $raw = self::read($params, 'idgoal');
        // Matomo takes the id when the value is an integer and reads anything else as -1.
        $id = is_numeric($raw) && (float) $raw === (float) (int) $raw ? (int) $raw : -1;

        if ($id === 0) {
            return 'Goal not sent: Matomo reads goal id 0 as an ecommerce cart update. Track a cart with ecommerceCartUpdate().';
        }

        if ($id < 0) {
            return 'Goal not sent: Matomo records a goal id below 1 as a page view.';
        }

        return null;
    }

    /**
     * A parameter as Matomo's tracker reads it from the query string the hit is sent as.
     *
     * @param  array<string, scalar>  $params
     */
    private static function read(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        return str_replace(["\n", "\r", "\0"], '', is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    /** Whether Matomo's `empty()` check rejects the value: "" and "0". */
    private static function absent(string $value): bool
    {
        return $value === '' || $value === '0';
    }
}
