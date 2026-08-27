<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\App;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;

/**
 * Resolves the configured batch buffer driver: array, file, redis, or database — the
 * fallback for any name this does not recognize, so an unreadable driver setting degrades
 * to the durable buffer rather than to none.
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
