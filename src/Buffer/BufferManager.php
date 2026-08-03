<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\App;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;

/**
 * Resolves the configured batch buffer driver. (file and redis drivers arrive in
 * a later phase; until then any non-array driver uses the database buffer.)
 */
final class BufferManager
{
    public function driver(?string $name = null): HitBuffer
    {
        return match ($name ?? Config::string('matomo-analytics.batch.driver', 'database')) {
            'array' => App::make(ArrayHitBuffer::class),
            'file' => App::make(FileHitBuffer::class),
            'redis' => App::make(RedisHitBuffer::class),
            default => App::make(DatabaseHitBuffer::class),
        };
    }
}
