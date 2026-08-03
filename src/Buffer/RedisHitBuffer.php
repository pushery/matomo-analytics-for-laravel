<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;

/**
 * Redis-backed buffer using the reliable-queue pattern: a claim atomically moves
 * items to a per-claim processing list, ack deletes it, and release moves the
 * items back to the head of the queue. Every processing list is registered in a
 * sorted set keyed by claim time, so a crashed flush (which never acks/releases)
 * is reclaimed on a later claim instead of being orphaned — nothing is lost.
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
        $this->reclaimStale($connection);

        $processing = $this->key().':processing:'.Str::uuid()->toString();

        // Register the processing list BEFORE moving items into it, so a crash at any
        // point during the claim still leaves a reclaimable entry — never an orphan.
        $connection->command('zadd', [$this->processingSet(), Date::now()->getTimestamp(), $processing]);

        $taken = [];
        for ($i = 0; $i < $limit; $i++) {
            $item = $connection->command('lmove', [$this->key(), $processing, 'LEFT', 'RIGHT']);
            if (! is_string($item)) {
                break;
            }

            $taken[] = $item;
        }

        if ($taken === []) {
            $connection->command('del', [$processing]);
            $connection->command('zrem', [$this->processingSet(), $processing]);

            return BufferBatch::empty();
        }

        return new BufferBatch($processing, Json::decodeAll($taken));
    }

    public function ack(BufferBatch $batch): void
    {
        if ($batch->ref !== '') {
            $connection = $this->connection();
            $connection->command('del', [$batch->ref]);
            $connection->command('zrem', [$this->processingSet(), $batch->ref]);
        }
    }

    public function release(BufferBatch $batch): void
    {
        if ($batch->ref === '') {
            return;
        }

        $connection = $this->connection();
        $this->drainBackToQueue($connection, $batch->ref);
        $connection->command('del', [$batch->ref]);
        $connection->command('zrem', [$this->processingSet(), $batch->ref]);
    }

    /**
     * Move any processing list whose claim is older than stale_after back to the
     * queue and forget it — recovering the in-flight items of a crashed flush.
     */
    private function reclaimStale(Connection $connection): void
    {
        $cutoff = Date::now()->subMinutes(Config::int('matomo-analytics.batch.stale_after_minutes', 15))->getTimestamp();

        $stale = $connection->command('zrangebyscore', [$this->processingSet(), '-inf', $cutoff]);
        if (! is_array($stale)) {
            return;
        }

        foreach (array_filter($stale, is_string(...)) as $processing) {
            $this->drainBackToQueue($connection, $processing);
            $connection->command('del', [$processing]);
            $connection->command('zrem', [$this->processingSet(), $processing]);
        }
    }

    private function drainBackToQueue(Connection $connection, string $processing): void
    {
        while (true) {
            $moved = $connection->command('lmove', [$processing, $this->key(), 'RIGHT', 'LEFT']);
            if (! is_string($moved)) {
                break;
            }
        }
    }

    private function connection(): Connection
    {
        return Redis::connection(Config::nullableString('matomo-analytics.batch.redis_connection') ?? 'default');
    }

    private function key(): string
    {
        return 'matomo-analytics:buffer';
    }

    private function processingSet(): string
    {
        return $this->key().':processing';
    }
}
