<?php

declare(strict_types=1);

namespace MatomoAnalytics\Facades;

use Illuminate\Support\Facades\Facade;
use MatomoAnalytics\Contracts\AnnotationsClient;
use MatomoAnalytics\Testing\AnnotationsFake;

/**
 * @method static array<array-key, mixed>|null add(string $note, ?string $date = null, bool $starred = false, int|string|null $site = null)
 * @method static array<array-key, mixed>|null annotateRelease(?string $version = null, ?string $date = null)
 * @method static string|null lastError()
 *
 * @see AnnotationsClient
 */
final class MatomoAnnotations extends Facade
{
    public static function fake(): AnnotationsFake
    {
        $fake = new AnnotationsFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return AnnotationsClient::class;
    }
}
