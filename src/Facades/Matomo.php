<?php

declare(strict_types=1);

namespace MatomoAnalytics\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Testing\MatomoFake;
use MatomoAnalytics\Tracking\EcommerceItem;
use MatomoAnalytics\Tracking\Hit;

/**
 * @method static Tracker track(Hit $hit)
 * @method static Tracker pageView(string $title, ?string $url = null)
 * @method static Tracker event(string $category, string $action, ?string $name = null, int|float|null $value = null)
 * @method static Tracker siteSearch(string $keyword, ?string $category = null, ?int $count = null)
 * @method static Tracker searchFromRequest(?Request $request = null, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null)
 * @method static Tracker goal(int $id, ?float $revenue = null)
 * @method static Tracker ecommerceView(?string $sku = null, ?string $name = null, ?string $category = null, ?float $price = null, ?string $title = null, ?string $url = null)
 * @method static Tracker ecommerceCartUpdate(float $grandTotal, list<EcommerceItem> $items = [])
 * @method static Tracker ecommerceOrder(string $orderId, float $grandTotal, list<EcommerceItem> $items = [], ?float $subTotal = null, ?float $tax = null, ?float $shipping = null, ?float $discount = null)
 * @method static Tracker download(string $url)
 * @method static Tracker outlink(string $url)
 * @method static Tracker ping()
 * @method static void flush()
 *
 * @see Tracker
 */
final class Matomo extends Facade
{
    public static function fake(): MatomoFake
    {
        $fake = new MatomoFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Tracker::class;
    }
}
