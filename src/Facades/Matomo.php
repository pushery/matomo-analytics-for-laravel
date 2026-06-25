<?php

declare(strict_types=1);

namespace MatomoAnalytics\Facades;

use Illuminate\Support\Facades\Facade;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Testing\MatomoFake;
use MatomoAnalytics\Tracking\Hit;

/**
 * @method static Tracker track(Hit $hit)
 * @method static Tracker pageView(string $title, ?string $url = null)
 * @method static Tracker event(string $category, string $action, ?string $name = null, int|float|null $value = null)
 * @method static Tracker siteSearch(string $keyword, ?string $category = null, ?int $count = null)
 * @method static Tracker goal(int $id, ?float $revenue = null)
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
