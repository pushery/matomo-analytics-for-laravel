<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use Illuminate\Http\Request;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Tracking\Download;
use MatomoAnalytics\Tracking\EcommerceCartUpdate;
use MatomoAnalytics\Tracking\EcommerceItem;
use MatomoAnalytics\Tracking\EcommerceOrder;
use MatomoAnalytics\Tracking\EcommerceView;
use MatomoAnalytics\Tracking\Event;
use MatomoAnalytics\Tracking\Goal;
use MatomoAnalytics\Tracking\Hit;
use MatomoAnalytics\Tracking\Outlink;
use MatomoAnalytics\Tracking\PageView;
use MatomoAnalytics\Tracking\Ping;
use MatomoAnalytics\Tracking\SiteSearch;
use PHPUnit\Framework\Assert;

/**
 * Test double recording every hit instead of sending it. Swapped in via
 * Matomo::fake().
 */
final class MatomoFake implements Tracker
{
    /**
     * @var list<Hit>
     */
    public array $hits = [];

    public int $flushed = 0;

    public function track(Hit $hit): static
    {
        $this->hits[] = $hit;

        return $this;
    }

    public function flush(): void
    {
        $this->flushed++;
    }

    public function pageView(string $title, ?string $url = null): static
    {
        return $this->track(new PageView($title, $url));
    }

    public function event(string $category, string $action, ?string $name = null, int|float|null $value = null): static
    {
        return $this->track(new Event($category, $action, $name, $value));
    }

    public function siteSearch(string $keyword, ?string $category = null, ?int $count = null): static
    {
        return $this->track(new SiteSearch($keyword, $category, $count));
    }

    public function searchFromRequest(?Request $request = null, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null): static
    {
        $search = SiteSearch::fromRequest($request ?? request(), $keywordKey, $categoryKey, $count);

        return $search instanceof SiteSearch ? $this->track($search) : $this;
    }

    public function goal(int $id, ?float $revenue = null): static
    {
        return $this->track(new Goal($id, $revenue));
    }

    public function ecommerceView(?string $sku = null, ?string $name = null, ?string $category = null, ?float $price = null, ?string $title = null, ?string $url = null): static
    {
        return $this->track(new EcommerceView($sku, $name, $category, $price, $title, $url));
    }

    /**
     * @param  list<EcommerceItem>  $items
     */
    public function ecommerceCartUpdate(float $grandTotal, array $items = []): static
    {
        return $this->track(new EcommerceCartUpdate($grandTotal, $items));
    }

    /**
     * @param  list<EcommerceItem>  $items
     */
    public function ecommerceOrder(string $orderId, float $grandTotal, array $items = [], ?float $subTotal = null, ?float $tax = null, ?float $shipping = null, ?float $discount = null): static
    {
        return $this->track(new EcommerceOrder($orderId, $grandTotal, $items, $subTotal, $tax, $shipping, $discount));
    }

    public function download(string $url): static
    {
        return $this->track(new Download($url));
    }

    public function outlink(string $url): static
    {
        return $this->track(new Outlink($url));
    }

    public function ping(): static
    {
        return $this->track(new Ping);
    }

    /**
     * @param  class-string<Hit>  $type
     * @param  (Closure(Hit): bool)|null  $callback
     */
    public function assertTracked(string $type, ?Closure $callback = null): void
    {
        $matches = array_filter(
            $this->hits,
            static fn (Hit $hit): bool => $hit instanceof $type && (! $callback instanceof Closure || $callback($hit)),
        );

        Assert::assertNotEmpty($matches, "Expected a tracked [{$type}], none recorded.");
    }

    public function assertNothingTracked(): void
    {
        Assert::assertSame([], $this->hits);
    }

    public function assertTrackedCount(int $count): void
    {
        Assert::assertCount($count, $this->hits);
    }
}
