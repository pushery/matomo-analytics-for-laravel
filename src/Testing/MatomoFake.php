<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Tracking\Concerns\TracksCommonHits;
use MatomoAnalytics\Tracking\CustomParameters;
use MatomoAnalytics\Tracking\Hit;
use MatomoAnalytics\Tracking\RequiredFields;
use MatomoAnalytics\Tracking\SiteSearch;
use PHPUnit\Framework\Assert;

/**
 * Test double recording every hit instead of sending it. Swapped in via
 * Matomo::fake().
 */
final class MatomoFake implements Fake, Tracker
{
    use TracksCommonHits;

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

    /**
     * Records the hit. A hit the tracker would refuse, because Matomo would record it as
     * another action, throws here whatever `resilience.never_throw` says, so a test fails at
     * the call that production reports and drops. A site search without a keyword is skipped
     * without a word, as the tracker skips it.
     */
    public function track(Hit $hit): static
    {
        if (RequiredFields::emptySearch($hit)) {
            return $this;
        }

        $missing = RequiredFields::missing($hit);
        if ($missing !== null) {
            throw new InvalidArgumentException($missing);
        }

        $this->hits[] = $hit;

        SiteSearch::mark(RequestFacade::instance(), $hit);

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

    /**
     * Asserts a hit of the given type was tracked. A hit decorated with
     * CustomParameters matches its inner type too, so wrapping a hit never breaks
     * an assertion; the callback still receives the recorded (outer) hit.
     *
     * The callback must accept `Hit`, not the type you asked for. That is the direct
     * consequence of the sentence above and it is easy to miss, because the narrow form reads
     * naturally:
     *
     *     $fake->assertTracked(SiteSearch::class, fn (SiteSearch $s) => $s->keyword === 'hats');
     *
     * Ask by an inner type for a hit somebody decorated with `CustomParameters::for()` and the
     * callback receives the decorator — so that closure raises a TypeError. Narrow inside the
     * body instead, where it costs one `instanceof` and cannot surprise anybody:
     *
     *     fn (Hit $hit) => $hit instanceof SiteSearch && $hit->keyword === 'hats'
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
