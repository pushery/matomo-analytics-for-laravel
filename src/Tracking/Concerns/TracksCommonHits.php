<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Tracking\ContentImpression;
use MatomoAnalytics\Tracking\ContentInteraction;
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
use MatomoAnalytics\Tracking\TrackerForUser;

/**
 * The typed hit methods of {@see Tracker}, each one call to track(), and forUser().
 *
 * Shared by the tracker, the fake and the tracker forUser() returns, so a hit is built the same
 * way whichever of them sends it. An own Tracker implementation uses it too and writes track(),
 * aiChatbot() and flush() itself.
 */
trait TracksCommonHits
{
    abstract public function track(Hit $hit): static;

    public function pageView(string $title, ?string $url = null): static
    {
        return $this->track(new PageView($title, $url));
    }

    public function event(string $category, string $action, ?string $name = null, int|float|null $value = null): static
    {
        return $this->track(new Event($category, $action, $name, $value));
    }

    public function contentImpression(string $name, ?string $piece = null, ?string $target = null): static
    {
        return $this->track(new ContentImpression($name, $piece, $target));
    }

    public function contentInteraction(string $interaction, string $name, ?string $piece = null, ?string $target = null): static
    {
        return $this->track(new ContentInteraction($interaction, $name, $piece, $target));
    }

    public function siteSearch(string $keyword, ?string $category = null, ?int $count = null): static
    {
        return $this->track(new SiteSearch($keyword, $category, $count));
    }

    public function searchFromRequest(?Request $request = null, string $keywordKey = 'q', ?string $categoryKey = null, ?int $count = null): static
    {
        $search = SiteSearch::fromRequest($request ?? RequestFacade::instance(), $keywordKey, $categoryKey, $count);

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
     * A tracker that names `$user`, or the id given, as the Matomo User ID on every hit sent
     * through it. See {@see TrackerForUser}.
     */
    public function forUser(Authenticatable|int|string $user): Tracker
    {
        return new TrackerForUser($this, $user);
    }
}
