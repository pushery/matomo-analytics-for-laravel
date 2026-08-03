<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Tracking\ContentImpression;
use MatomoAnalytics\Tracking\ContentInteraction;
use MatomoAnalytics\Tracking\CustomParameters;
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

    /**
     * User agents recorded via aiChatbot() (bot telemetry is not a Hit).
     *
     * @var list<string>
     */
    public array $chatbots = [];

    public int $flushed = 0;

    public function track(Hit $hit): static
    {
        $this->hits[] = $hit;

        return $this;
    }

    public function aiChatbot(?Request $request = null): static
    {
        $this->chatbots[] = ($request ?? RequestFacade::instance())->userAgent() ?? '';

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
     * Asserts a hit of the given type was tracked. A hit decorated with
     * CustomParameters matches its inner type too, so wrapping a hit never breaks
     * an assertion; the callback still receives the recorded (outer) hit.
     *
     * @param  class-string<Hit>  $type
     * @param  (Closure(Hit): bool)|null  $callback
     */
    public function assertTracked(string $type, ?Closure $callback = null): void
    {
        $matches = array_filter(
            $this->hits,
            fn (Hit $hit): bool => $this->isType($hit, $type) && (! $callback instanceof Closure || $callback($hit)),
        );

        Assert::assertNotEmpty($matches, "Expected a tracked [{$type}], none recorded.");
    }

    /**
     * @param  class-string<Hit>  $type
     */
    private function isType(Hit $hit, string $type): bool
    {
        $current = $hit;

        while ($current instanceof Hit) {
            if ($current instanceof $type) {
                return true;
            }

            $current = $current instanceof CustomParameters ? $current->hit : null;
        }

        return false;
    }

    public function assertNothingTracked(): void
    {
        Assert::assertSame([], $this->hits);
    }

    /**
     * @param  (Closure(string): bool)|null  $callback
     */
    public function assertAiChatbotTracked(?Closure $callback = null): void
    {
        $matches = array_filter(
            $this->chatbots,
            static fn (string $userAgent): bool => ! $callback instanceof Closure || $callback($userAgent),
        );

        Assert::assertNotEmpty($matches, 'Expected an AI-chatbot telemetry hit, none recorded.');
    }

    public function assertNoAiChatbotTracked(): void
    {
        Assert::assertSame([], $this->chatbots);
    }

    public function assertTrackedCount(int $count): void
    {
        Assert::assertCount($count, $this->hits);
    }
}
