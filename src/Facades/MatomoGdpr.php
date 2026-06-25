<?php

declare(strict_types=1);

namespace MatomoAnalytics\Facades;

use Illuminate\Support\Facades\Facade;
use MatomoAnalytics\Contracts\GdprClient;
use MatomoAnalytics\Testing\GdprFake;

/**
 * @method static list<array<array-key, mixed>>|null findDataSubjects(string $segment, int|string|null $site = null)
 * @method static array<string, int>|null forget(string $segment, int|string|null $site = null)
 * @method static array<array-key, mixed>|null export(string $segment, int|string|null $site = null)
 * @method static array<string, int>|null deleteVisits(list<array{idsite: int, idvisit: int}> $visits)
 * @method static array<array-key, mixed>|null exportVisits(list<array{idsite: int, idvisit: int}> $visits)
 * @method static string|null lastError()
 *
 * @see GdprClient
 */
final class MatomoGdpr extends Facade
{
    public static function fake(): GdprFake
    {
        $fake = new GdprFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return GdprClient::class;
    }
}
