<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\ErasableHitBuffer;
use MatomoAnalytics\Exceptions\BufferEvictableException;
use MatomoAnalytics\Exceptions\BufferUnavailableException;
use MatomoAnalytics\Privacy\DataSubject;
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
final class RedisHitBuffer implements ErasableHitBuffer
{
    /** How many entries one LRANGE reads while an erasure searches a list. */
    private const int ERASE_PAGE = 1000;

    /** One CONFIG GET per process, and none on the push path — see reportEvictionPolicyOnce(). */
    private static bool $evictionChecked = false;

    /**
     * @param  string  $key  the list the hits wait in, which also names the processing lists and
     *                       their set; `matomo:load-sim` runs on a key of its own
     */
    public function __construct(private readonly string $key = 'matomo-analytics:buffer') {}

    public function push(array $payload): void
    {
        // The request path, so the eviction check stays out of it: see reportEvictionPolicyOnce().
        $this->redis()->command('rpush', [$this->key(), Json::encode($payload)]);
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

            $this->refuseWithoutLmove($connection);

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

    public function erase(DataSubject $subject): int
    {
        $connection = $this->connection();
        $removed = $this->eraseFrom($connection, $this->key(), $subject);

        // The processing lists are read after the queue, so a batch claimed while the queue
        // was being searched is searched here instead of being missed in both places.
        $claimed = $connection->command('zrange', [$this->processingSet(), 0, -1]);

        foreach (array_filter(is_array($claimed) ? $claimed : [], is_string(...)) as $processing) {
            $removed += $this->eraseFrom($connection, $processing, $subject);
        }

        return $removed;
    }

    /**
     * Remove every entry of one list that the subject owns, and return how many went.
     *
     * The list is read a page at a time, so memory stays bounded whatever its length, and the
     * matching entries are removed by value afterwards. LREM with a count of 0 removes every
     * copy of an entry, and every copy of an entry the subject owns belongs to the subject.
     */
    private function eraseFrom(Connection $connection, string $list, DataSubject $subject): int
    {
        $owned = [];

        for ($start = 0; ; $start += self::ERASE_PAGE) {
            $page = $connection->command('lrange', [$list, $start, $start + self::ERASE_PAGE - 1]);
            $entries = array_values(array_filter(is_array($page) ? $page : [], is_string(...)));

            foreach ($entries as $entry) {
                if (! in_array($entry, $owned, true) && $subject->owns(Json::decode($entry))) {
                    $owned[] = $entry;
                }
            }

            if (count($entries) < self::ERASE_PAGE) {
                break;
            }
        }

        $removed = 0;

        foreach ($owned as $entry) {
            // phpredis takes LREM's value before its count, the reverse of the Redis protocol
            // order Predis follows; `PhpRedisConnection::lrem()` accepts the protocol order and
            // swaps the two.
            $count = $connection instanceof PhpRedisConnection
                ? $connection->lrem($list, 0, $entry)
                : $connection->command('lrem', [$list, 0, $entry]);
            $removed += is_int($count) ? $count : 0;
        }

        return $removed;
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

    /**
     * Throws when a claim found nothing because the server cannot move a hit at all.
     *
     * `LMOVE` arrived in Redis 6.2. An older server answers it with an error, and the client hands
     * that back as `false`, its answer for an empty list as well, so the claim alone cannot tell
     * the two apart. A queue that still holds hits after an empty claim is the one case worth a
     * question, and the question goes to the server, because the ordinary reason for it is a hit
     * pushed between the claim and the count, which is no outage.
     */
    private function refuseWithoutLmove(Connection $connection): void
    {
        $length = $connection->command('llen', [$this->key()]);

        if (! is_int($length) || $length < 1) {
            return;
        }

        // One entry per name asked about: the command's details, or `false` (phpredis) and `null`
        // (Predis) for a command the server does not know.
        $known = $connection->command('command', ['info', 'lmove']);

        if (is_array($known) && ! is_array($known[0] ?? null)) {
            throw new BufferUnavailableException(sprintf(
                'The Matomo Redis buffer holds %d hit(s) it cannot claim: the server does not know LMOVE, which the redis batch driver needs (Redis 6.2 or later).',
                $length,
            ));
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

    /**
     * The configured connection, without the eviction check: the one `push()` uses.
     *
     * A cluster is refused here, on every path. A claim moves hits from the buffer's list into a
     * processing list with LMOVE, and Redis Cluster refuses a command whose keys sit in different
     * hash slots; the phpredis cluster client has no pipeline either. The driver cannot drain a
     * cluster, so it says why rather than buffering hits that no claim can reach.
     */
    private function redis(): Connection
    {
        $connection = RedisFacade::connection(Config::nullableString('matomo-analytics.batch.redis_connection') ?? 'default');

        if ($connection instanceof PhpRedisClusterConnection || $connection instanceof PredisClusterConnection) {
            throw new BufferUnavailableException('The redis buffer driver does not run on Redis Cluster: a claim moves hits between two keys, which a cluster refuses across hash slots. Point matomo-analytics.batch.redis_connection at a single Redis instance, or use the database driver.');
        }

        return $connection;
    }

    /** The configured connection, after the eviction check: the one every other operation uses. */
    private function connection(): Connection
    {
        $connection = $this->redis();

        $this->reportEvictionPolicyOnce($connection);

        return $connection;
    }

    /**
     * Report an eviction policy that can delete this buffer, once per process.
     *
     * Under an `allkeys-*` policy Redis may evict the whole list at once, and `push()` raises
     * nothing: measured against a real Redis under `allkeys-lru`, 200,000 hits pushed, 5,358
     * left, `evicted_keys=1`. The recommended policies turn memory pressure into a
     * `RedisException: OOM` on `push()` instead.
     *
     * Every operation asks except `push()`, which runs on each tracked request: under PHP-FPM a
     * request starts with fresh static state, so a check there would cost a `CONFIG GET` per
     * request. A flush, a worker and an erasure ask once each. A provider that renames `CONFIG`
     * away answers with an error, which phpredis returns as false, and one that denies it by ACL
     * makes phpredis throw. Neither says anything about the policy, so both are silence, the
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
        return $this->key;
    }

    private function processingSet(): string
    {
        return $this->key().':processing';
    }
}
