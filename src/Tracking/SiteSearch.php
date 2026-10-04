<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class SiteSearch implements Hit
{
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
     * so a search box submitted with a space would count the page a second time.
     */
    public static function fromRequest(Request $request, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null): ?self
    {
        $keyword = self::text($request->query($keywordKey));
        if ($keyword === null) {
            return null;
        }

        return new self($keyword, $categoryKey !== null ? self::text($request->query($categoryKey)) : null, $count);
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
