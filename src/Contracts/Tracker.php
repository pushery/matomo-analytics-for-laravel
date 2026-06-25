<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use Illuminate\Http\Request;
use MatomoAnalytics\Tracking\EcommerceItem;
use MatomoAnalytics\Tracking\Hit;

interface Tracker
{
    public function track(Hit $hit): static;

    public function pageView(string $title, ?string $url = null): static;

    public function event(string $category, string $action, ?string $name = null, int|float|null $value = null): static;

    public function siteSearch(string $keyword, ?string $category = null, ?int $count = null): static;

    public function searchFromRequest(?Request $request = null, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null): static;

    public function goal(int $id, ?float $revenue = null): static;

    public function ecommerceView(?string $sku = null, ?string $name = null, ?string $category = null, ?float $price = null, ?string $title = null, ?string $url = null): static;

    /**
     * @param  list<EcommerceItem>  $items
     */
    public function ecommerceCartUpdate(float $grandTotal, array $items = []): static;

    /**
     * @param  list<EcommerceItem>  $items
     */
    public function ecommerceOrder(string $orderId, float $grandTotal, array $items = [], ?float $subTotal = null, ?float $tax = null, ?float $shipping = null, ?float $discount = null): static;

    public function download(string $url): static;

    public function outlink(string $url): static;

    public function ping(): static;

    public function flush(): void;
}
