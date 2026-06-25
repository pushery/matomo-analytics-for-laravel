<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;

/**
 * Redis-backed buffer using the reliable-queue pattern: a claim atomically moves
 * items to a per-claim processing list, ack deletes it, and release moves the
 * items back to the head of the queue — so a crashed flush loses nothing.
 */
final class RedisHitBuffer implements HitBuffer
{
    public function push(array $payload): void
    {
        $this->connection()->command('rpush', [$this->key(), Json::encode($payload)]);
    }

    public function size(): int
    {
        $length = $this->connection()->command('llen', [$this->key()]);

        return is_int($length) ? $length : 0;
    }

    public function claim(int $limit): BufferBatch
    {
        if ($limit < 1) {
            return BufferBatch::empty();
        }

        $connection = $this->connection();
        $processing = $this->key().':processing:'.Str::uuid()->toString();
        $taken = [];

        for ($i = 0; $i < $limit; $i++) {
            $item = $connection->command('lmove', [$this->key(), $processing, 'LEFT', 'RIGHT']);
            if (! is_string($item)) {
                break;
            }

            $taken[] = $item;
        }

        if ($taken === []) {
            return BufferBatch::empty();
        }

        return new BufferBatch($processing, Json::decodeAll($taken));
    }

    public function ack(BufferBatch $batch): void
    {
        if ($batch->ref !== '') {
            $this->connection()->command('del', [$batch->ref]);
        }
    }

    public function release(BufferBatch $batch): void
    {
        if ($batch->ref === '') {
            return;
        }

        $connection = $this->connection();
        while (true) {
            $moved = $connection->command('lmove', [$batch->ref, $this->key(), 'RIGHT', 'LEFT']);
            if (! is_string($moved)) {
                break;
            }
        }

        $connection->command('del', [$batch->ref]);
    }

    private function connection(): Connection
    {
        return Redis::connection(Config::nullableString('matomo-analytics.batch.redis_connection') ?? 'default');
    }

    private function key(): string
    {
        return 'matomo-analytics:buffer';
    }
}
