<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Exceptions\BufferEvictableException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Redis as PhpRedis;
use Throwable;

/**
 * Redis-backed buffer using the reliable-queue pattern: a claim atomically moves
 * items to a per-claim processing list, ack deletes it, and release moves the
 * items back to the head of the queue. Every processing list is registered in a
 * sorted set keyed by claim time, so a crashed flush (which never acks/releases)
 * is reclaimed on a later claim instead of being orphaned — nothing is lost.
 */
final class RedisHitBuffer implements HitBuffer
{
    /** One CONFIG GET per process, not one per hit — see reportEvictionPolicyOnce(). */
    private static bool $evictionChecked = false;

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

        $processing = $this->key().':processing:'.Str::uuid();

        // Register the processing list BEFORE moving items into it, so a crash at any
        // point during the claim still leaves a reclaimable entry — never an orphan.
        $connection->command('zadd', [$this->processingSet(), Date::now()->getTimestamp(), $processing]);

        $taken = $this->move($connection, $this->key(), $processing, 'LEFT', 'RIGHT', $limit);

        if ($taken === []) {
            $connection->command('del', [$processing]);
            $connection->command('zrem', [$this->processingSet(), $processing]);

            return BufferBatch::empty();
        }

        $payloads = Json::decodeAll($taken);

        return new BufferBatch($processing, $payloads, count($taken) - count($payloads));
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
        // A FLOOR OF ONE MINUTE, because zero inverts the guarantee. `stale_after_minutes`
        // was the only batch value with no lower bound, and at 0 every claim is already
        // expired the moment it is made: the next flush reclaims a batch that the current
        // one is still sending, so at-least-once delivery becomes guaranteed double delivery
        // and Matomo counts every hit twice. Every other bound in this class uses max(1, …);
        // this one was simply missed.
        $stale = max(1, Config::int('matomo-analytics.batch.stale_after_minutes', 15));

        $cutoff = Date::now()->subMinutes($stale)->getTimestamp();

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
        // ASK HOW MANY FIRST. The loop used to walk one LMOVE at a time until the server said
        // "empty", which cannot be pipelined because the stop condition is the previous reply.
        // `LLEN` turns an unknown count into a known one, and a known count is one round trip.
        //
        // A concurrent writer cannot make this wrong: nothing else ever writes to a processing
        // list — it is named after a claim nobody else holds — so its length only shrinks, by
        // this call. Asking for more than is there moves what is there and answers null for the
        // rest, which `move()` already treats as the end.
        $length = $connection->command('llen', [$processing]);

        if (! is_int($length) || $length < 1) {
            return;
        }

        $this->move($connection, $processing, $this->key(), 'RIGHT', 'LEFT', $length);
    }

    /**
     * Move up to $limit items between two lists, in as few round trips as the client allows.
     *
     * ONE ROUND TRIP INSTEAD OF $limit OF THEM, where the client can do it. The claim path used
     * to issue a separate `LMOVE` per hit — fifty sequential requests for a default batch, and
     * the same again on every release and every reclaim. On a local server that is microseconds;
     * against a managed Redis with a millisecond of latency it is fifty milliseconds per batch
     * and, at forty batches to a flush, two seconds of a one-minute schedule spent waiting.
     *
     * The pipelined answers are the items, in order, and a non-string is one `LMOVE` that found
     * nothing — so asking for more than exists is safe rather than merely tolerable.
     *
     * IT IS NOT A TERMINATOR, AND READING IT AS ONE LOST HITS. That sentence used to end "a
     * non-string ends the take", and the loop below broke on it. Under concurrency a later
     * `LMOVE` in the same batch can still succeed, because a pipeline is not atomic — see the
     * comment at the loop for what that cost.
     *
     * `pipeline()` IS DECLARED ONLY ON THE PHPREDIS CONNECTION. Predis reaches it through
     * `Connection::__call`, so it would work there too — but not in a way the analyzer can see,
     * and a package that narrows a data path on an unchecked assumption has learned nothing from
     * the rest of this class. The sequential path below is therefore kept, not as dead code but
     * as the correctness path for every other client; `RedisHitBufferTest` drives it and the
     * real-server suite drives the pipeline.
     *
     * @return list<string>
     */
    private function move(Connection $connection, string $from, string $to, string $take, string $put, int $limit): array
    {
        if ($connection instanceof PhpRedisConnection) {
            // Typed as the phpredis client because that is what `PhpRedisConnection::pipeline()`
            // hands the callback — `$this->client()->pipeline()`, the same object in queued mode.
            // Aliased, because `Illuminate\Support\Facades\Redis` already owns the short name
            // in this file and importing both is a fatal rather than a warning.
            $replies = $connection->pipeline(static function (PhpRedis $pipe) use ($from, $to, $take, $put, $limit): void {
                for ($i = 0; $i < $limit; $i++) {
                    $pipe->lmove($from, $to, $take, $put);
                }
            });

            $moved = [];

            // EVERY STRING REPLY, NOT EVERY REPLY UNTIL THE FIRST GAP — and the difference was
            // silent data loss. This loop used to `break` on the first non-string, which is only
            // sound if the batch is atomic. It is not: Redis executes a pipeline command by
            // command and lets other clients interleave once it spans more than one server-side
            // read. So an `LMOVE` against a momentarily empty source answers false while LATER
            // ones — already sent, and beyond recall — still move items a concurrent `push()` has
            // just appended. Breaking left those items in the processing list, absent from the
            // batch, and `ack()` then deleted the list.
            //
            // Measured before the fix with three writers against three claimers and closed-book
            // accounting: 12.6% of hits lost at `batch.size=250`, 30.1% at 1000. The threshold is
            // a BYTE boundary rather than a command count — a longer key prefix loses 4.8% at a
            // limit of 100 — so there was no batch size anyone could have called safe.
            //
            // A gap is one command that found nothing, not a terminator. Collecting every string
            // makes the batch match the processing list exactly, which is what `ack()` assumes.
            foreach (is_array($replies) ? $replies : [] as $reply) {
                if (is_string($reply)) {
                    $moved[] = $reply;
                }
            }

            return $moved;
        }

        $moved = [];

        for ($i = 0; $i < $limit; $i++) {
            $item = $connection->command('lmove', [$from, $to, $take, $put]);

            if (! is_string($item)) {
                break;
            }

            $moved[] = $item;
        }

        return $moved;
    }

    private function connection(): Connection
    {
        $connection = RedisFacade::connection(Config::nullableString('matomo-analytics.batch.redis_connection') ?? 'default');

        $this->reportEvictionPolicyOnce($connection);

        return $connection;
    }

    /**
     * Report an eviction policy that can delete this buffer — once per process.
     *
     * THE FIX FOR THIS USED TO BE A DOCUMENTATION SECTION AND A LINE IN `matomo:test`, A
     * COMMAND NOBODY RUNS ON A SCHEDULE. At runtime there was no guard at all, and the loss is
     * total and silent: measured against a real Redis under `allkeys-lru`, 200,000 hits pushed,
     * 5,358 left, 194,642 gone, `evicted_keys=1` — the whole list at once — and `push()` threw
     * nothing. The recommended policies do prevent it and turn memory pressure into a
     * `RedisException: OOM` on `push()` instead, which is the half the documentation left out.
     *
     * Once per process, and off the hot path in the sense that matters: this is one `CONFIG
     * GET` for the lifetime of a worker, not one per hit. A managed provider that disables
     * `CONFIG` throws here, and that is not a finding about the policy — it is silence, the
     * same answer `matomo:test` gives.
     */
    private function reportEvictionPolicyOnce(Connection $connection): void
    {
        if (self::$evictionChecked) {
            return;
        }

        self::$evictionChecked = true;

        try {
            $reply = $connection->command('config', ['GET', 'maxmemory-policy']);
        } catch (Throwable) {
            return;
        }

        $policy = is_array($reply) ? ($reply['maxmemory-policy'] ?? $reply[1] ?? null) : null;

        if (! is_string($policy) || ! str_starts_with($policy, 'allkeys-')) {
            return;
        }

        App::make(Reporter::class)->report(
            new BufferEvictableException(sprintf(
                'The Redis instance backing the Matomo buffer runs maxmemory-policy "%s", so its keys are evictable and buffered hits can disappear without an error.',
                $policy,
            )),
            ['stage' => 'buffer'],
        );
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
