<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

use Illuminate\Http\Request;

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
     */
    public static function fromRequest(Request $request, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null): ?self
    {
        $keyword = $request->query($keywordKey);
        if (! is_string($keyword) || $keyword === '') {
            return null;
        }

        $category = $categoryKey !== null ? $request->query($categoryKey) : null;

        return new self($keyword, is_string($category) && $category !== '' ? $category : null, $count);
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
