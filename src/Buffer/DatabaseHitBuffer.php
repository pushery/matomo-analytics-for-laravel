<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\ErasableHitBuffer;
use MatomoAnalytics\Privacy\DataSubject;
use MatomoAnalytics\Support\Config;

/**
 * Portable, durable buffer backed by a database table. Claims are token-stamped
 * and auto-reclaim stale rows (e.g. from a crashed flush), so nothing is lost.
 */
final class DatabaseHitBuffer implements ErasableHitBuffer
{
    public function push(array $payload): void
    {
        DB::table($this->table())->insert([
            'payload' => Json::encode($payload),
            'created_at' => Date::now(),
        ]);
    }

    public function size(): int
    {
        return DB::table($this->table())->whereNull('claimed_at')->count();
    }

    public function claim(int $limit): BufferBatch
    {
        if ($limit < 1) {
            return BufferBatch::empty();
        }

        $ref = (string) Str::uuid();
        // Floored at one minute for the reason RedisHitBuffer spells out: at 0 every claim
        // is already expired when it is made, so at-least-once becomes guaranteed twice.
        $stale = Date::now()->subMinutes(max(1, Config::int('matomo-analytics.batch.stale_after_minutes', 15)));

        // Several drainers can claim at once: `matomo:flush` runs every minute in `batch` mode,
        // and `matomo:work` runs beside it as a daemon. Where the engine can skip a row another
        // claim holds, each claim locks the rows it takes and passes over the rest, so drainers
        // take different batches and none of them loses a race (rowLock()).
        //
        // Without that lock, every claimer selects the same first rows, and the conditional
        // UPDATE lets one of them win. Rows found but none won is not an empty buffer, and
        // `BufferFlusher::drain()` reads an empty batch as one, so the loser tries again. The
        // budget bounds that when a rival keeps winning: after three lost races the answer is an
        // empty batch, and the rows go to the drainers still running.
        $connection = DB::connection();
        $lock = $this->rowLock($connection);
        $attempts = 0;

        while (true) {
            $attempts++;

            if ($lock !== null) {
                $this->readCommittedOnMySql($connection);
            }

            // A row lock lasts until the transaction that took it ends, so the SELECT that locks
            // the rows and the UPDATE that stamps them run in one.
            $batch = $lock === null
                ? $this->attemptClaim($limit, $ref, $stale, null)
                : $connection->transaction(fn (): ?BufferBatch => $this->attemptClaim($limit, $ref, $stale, $lock));

            if ($batch instanceof BufferBatch) {
                return $batch;
            }

            if ($attempts >= 3) {
                return BufferBatch::empty();
            }
        }
    }

    /**
     * The row lock a claim takes, or null where the engine cannot skip a row another claim holds.
     *
     * `FOR UPDATE SKIP LOCKED` exists from PostgreSQL 9.5 and MySQL 8.0.1, below the versions this
     * package supports. MariaDB answers through the `mysql` driver as well and is not one of them,
     * so it keeps the claim without a lock, as SQLite does, which has no row locks.
     */
    private function rowLock(Connection $connection): ?string
    {
        if ($connection->getDriverName() === 'pgsql') {
            return 'for update skip locked';
        }

        if ($connection instanceof MySqlConnection && ! $connection->isMaria()) {
            return 'for update skip locked';
        }

        return null;
    }

    /**
     * Run the next transaction on a MySQL connection at READ COMMITTED.
     *
     * At MySQL's default REPEATABLE READ a locking SELECT keeps every row it passes locked until
     * the transaction ends, the batches other drainers are sending included, and the gaps between
     * them. An ack that deletes such a batch then waits for the claim while the claim's UPDATE
     * waits for the ack: a deadlock. At READ COMMITTED the SELECT keeps only the rows it returns.
     * `SET TRANSACTION` applies to the next transaction alone and cannot be issued inside one, so
     * a claim that runs in an open transaction keeps that transaction's level.
     */
    private function readCommittedOnMySql(Connection $connection): void
    {
        if ($connection instanceof MySqlConnection && $connection->transactionLevel() === 0) {
            $connection->statement('set transaction isolation level read committed');
        }
    }

    /**
     * One claim attempt. `null` means "rows were there and a rival took them" — the only case
     * worth retrying; an empty batch means the buffer really had nothing.
     *
     * With a lock, the SELECT passes over the rows another claim holds and locks the ones it
     * returns, so it has to run inside a transaction.
     */
    private function attemptClaim(int $limit, string $ref, DateTimeInterface $stale, ?string $lock): ?BufferBatch
    {
        // BOTH READS GO TO THE WRITE CONNECTION. Laravel sends a query builder SELECT to the read
        // connection unless a transaction is open or `sticky` is set and this request has
        // written. On a connection with a `read` replica and no `sticky`, the read-back below
        // asked a replica that had not seen the UPDATE yet, came back with nothing, and the
        // empty batch was acked: the claimed rows were deleted unsent.
        $select = DB::table($this->table())
            ->useWritePdo()
            ->where(function (Builder $query) use ($stale): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $stale);
            })
            ->orderBy('id')
            ->limit($limit);

        if ($lock !== null) {
            $select->lock($lock);
        }

        $ids = $select->pluck('id');

        if ($ids->isEmpty()) {
            return BufferBatch::empty();
        }

        // Re-assert the claim predicate in the UPDATE, not just the SELECT. Without a row lock,
        // two flushers can select the same ids before either writes, and the conditional UPDATE
        // lets only the first stamp them: the other's UPDATE matches 0 rows, so a hit is never
        // claimed (and sent) by two drainers at once. With the lock, the rows are this claim's
        // until its transaction ends, and the predicate holds for all of them.
        $claimed = DB::table($this->table())
            ->whereIn('id', $ids)
            ->where(function (Builder $query) use ($stale): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $stale);
            })
            ->update([
                'claimed_at' => Date::now(),
                'claimed_by' => $ref,
            ]);

        if ($claimed === 0) {
            // Rows were there and a rival stamped them first. NOT an empty buffer.
            return null;
        }

        $payloads = [];
        $rows = 0;
        foreach (DB::table($this->table())->useWritePdo()->where('claimed_by', $ref)->orderBy('id')->pluck('payload') as $row) {
            $rows++;
            $decoded = is_string($row) ? Json::decode($row) : null;
            if ($decoded !== null) {
                $payloads[] = $decoded;
            }
        }

        return new BufferBatch($ref, $payloads, $rows - count($payloads));
    }

    public function ack(BufferBatch $batch): void
    {
        DB::table($this->table())->where('claimed_by', $batch->ref)->delete();
    }

    public function release(BufferBatch $batch): void
    {
        DB::table($this->table())->where('claimed_by', $batch->ref)->update([
            'claimed_at' => null,
            'claimed_by' => null,
        ]);
    }

    public function erase(DataSubject $subject): int
    {
        $table = $this->table();

        // An installation that skipped the migrations has no buffer here, and nothing to erase.
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $removed = 0;

        // The decoded payload is compared rather than the stored JSON matched as text: a
        // substring match would delete a row whose URL merely mentions the value, and a JSON
        // path is not portable across the engines this package supports. Pages keyed by id keep
        // memory bounded however large the table is, and deleting a row behind the page never
        // moves one the next page has yet to read. Claimed rows are included.
        foreach (DB::table($table)->select(['id', 'payload'])->lazyById() as $row) {
            if (is_string($row->payload ?? null) && $subject->owns(Json::decode($row->payload))) {
                $removed += DB::table($table)->where('id', $row->id)->delete();
            }
        }

        return $removed;
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
    }
}
