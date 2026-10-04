<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class SiteSearch implements Hit
{
    /** The request attribute a tracked site search leaves on its request. */
    private const string TRACKED = 'matomo-analytics.site_search_tracked';

    public function __construct(
        public string $keyword,
        public ?string $category = null,
        public ?int $count = null,
    ) {}

    /**
     * Build a site search from a request's query parameters, or null when the
     * keyword is absent/blank (so callers can skip tracking a non-search request).
     *
     * Blank is judged after `Str::trim()`, which knows Unicode whitespace. Matomo trims the
     * keyword with PHP's `trim()` and records a hit whose keyword is then empty as a page view,
     * so a search box submitted with a space would count the page a second time. A keyword of
     * "0" is left out as well: Matomo tests the keyword with `empty()`, so it cannot record one.
     */
    public static function fromRequest(Request $request, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null): ?self
    {
        $keyword = self::text($request->query($keywordKey));
        if ($keyword === null || $keyword === '0') {
            return null;
        }

        return new self($keyword, $categoryKey !== null ? self::text($request->query($categoryKey)) : null, $count);
    }

    /**
     * Marks the request when the hit is a site search, so that the page view of the same request
     * leaves the visit to the search. Matomo counts a search as the action of its page.
     *
     * @internal
     */
    public static function mark(Request $request, Hit $hit): void
    {
        if ($hit instanceof self || ($hit instanceof CustomParameters && $hit->hit instanceof self)) {
            $request->attributes->set(self::TRACKED, true);
        }
    }

    /**
     * Whether a site search was tracked for the request.
     *
     * @internal
     */
    public static function trackedFor(Request $request): bool
    {
        return $request->attributes->get(self::TRACKED) === true;
    }

    /** The value trimmed of whitespace, Unicode whitespace included, or null when nothing is left. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = Str::trim($value);

        return $text === '' ? null : $text;
    }

    public function toParams(): array
    {
        $params = ['search' => $this->keyword];

        if ($this->category !== null) {
            $params['search_cat'] = $this->category;
        }

        if ($this->count !== null) {
            $params['search_count'] = $this->count;
        }

        return $params;
    }
}
