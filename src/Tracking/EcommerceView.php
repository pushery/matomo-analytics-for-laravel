<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

use Illuminate\Http\Request;

/**
 * An ecommerce product or category view — a page view carrying the viewed
 * product's details (_pks/_pkn/_pkc/_pkp). For a category view, pass only the
 * category.
 */
final readonly class EcommerceView implements Hit
{
    /** Set by the page view middleware before the handler runs: a page view of this request may follow. */
    private const string PAGE_VIEW_FOLLOWS = 'matomo-analytics.page_view_follows';

    /** The product view held for that page view. */
    private const string HELD = 'matomo-analytics.product_view';

    public function __construct(
        public ?string $sku = null,
        public ?string $name = null,
        public ?string $category = null,
        public ?float $price = null,
        public ?string $title = null,
        public ?string $url = null,
    ) {}

    public function toParams(): array
    {
        $params = [];

        if ($this->title !== null) {
            $params['action_name'] = $this->title;
        }

        if ($this->url !== null) {
            $params['url'] = $this->url;
        }

        if ($this->sku !== null) {
            $params['_pks'] = $this->sku;
        }

        if ($this->name !== null) {
            $params['_pkn'] = $this->name;
        }

        if ($this->category !== null) {
            $params['_pkc'] = $this->category;
        }

        if ($this->price !== null) {
            $params['_pkp'] = $this->price;
        }

        return $params;
    }

    /**
     * Marks the request as one whose page view the page view middleware may send.
     *
     * @internal
     */
    public static function expectPageView(Request $request): void
    {
        $request->attributes->set(self::PAGE_VIEW_FOLLOWS, true);
    }

    /**
     * Holds a product view of this request's page for the page view that follows, and says
     * whether it did.
     *
     * Matomo records a product view as a page view that carries the product. A handler records it
     * before the page is rendered, so it knows no title, and the page view middleware sends its
     * own page view with the title afterwards: two page views of one product page, one nameless
     * and one without the product. Held, the product goes out on the one page view. A view that
     * names its own `url` or `title` is about another page, or complete, and is not held; nor is
     * a second one, which has no page view left to ride on.
     *
     * @internal
     */
    public static function hold(Request $request, Hit $hit): bool
    {
        $view = $hit instanceof CustomParameters ? $hit->hit : $hit;

        if (! $view instanceof self
            || $view->url !== null
            || $view->title !== null
            || $request->attributes->get(self::PAGE_VIEW_FOLLOWS) !== true
            || $request->attributes->has(self::HELD)) {
            return false;
        }

        $request->attributes->set(self::HELD, $hit);

        return true;
    }

    /**
     * The product view held for the request, if any, taken off it together with the mark, so a
     * product view sent after this goes out on its own.
     *
     * @internal
     */
    public static function release(Request $request): ?Hit
    {
        $held = $request->attributes->get(self::HELD);

        $request->attributes->remove(self::HELD);
        $request->attributes->remove(self::PAGE_VIEW_FOLLOWS);

        return $held instanceof Hit ? $held : null;
    }
}
