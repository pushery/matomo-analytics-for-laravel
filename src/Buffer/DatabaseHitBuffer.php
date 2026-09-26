<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use DateTimeInterface;
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

        // A LOST RACE USED TO END THE WHOLE FLUSH RUN, not the batch it lost. The conditional
        // UPDATE below is right and stays: it is what stops two drainers sending one hit. What
        // was wrong was the loser's ANSWER — an empty batch, which `BufferFlusher::drain()` reads
        // as `claimedNothing()` and therefore as "the buffer is drained". So it stopped, and
        // everything still queued waited for the next tick.
        //
        // Measured on PostgreSQL 18 with real parallel processes and a 200k-500k backlog: four
        // workers started together delivered 2000 hits between them instead of 8000; eight
        // delivered 2000 instead of 16000. Zero deadlocks and zero lock waits in 120 samples —
        // a logical abort, not contention. And it is the DOCUMENTED setup: `matomo:flush` is
        // registered every minute whenever `mode === 'batch'`, while `scaling.md` recommends
        // running `matomo:work` as a daemon.
        //
        // Rows found but none claimed is unambiguous — a rival took them, and there may well be
        // more behind. Retrying is therefore correct AND bounded: each attempt either wins rows
        // or proves the contention is sustained, and an exhausted budget still returns an empty
        // batch, so `drain()`'s contract ("nothing claimed means nothing left") stays true.
        $attempts = 0;

        while (true) {
            $attempts++;
            $batch = $this->attemptClaim($limit, $ref, $stale);

            if ($batch instanceof BufferBatch) {
                return $batch;
            }

            // Three, not more: the loser of a race is competing with a worker that is now busy
            // sending, so the next attempt almost always wins. A higher number would trade a
            // vanishing gain for a longer stall in the one case that IS just contention.
            if ($attempts >= 3) {
                return BufferBatch::empty();
            }
        }
    }

    /**
     * One claim attempt. `null` means "rows were there and a rival took them" — the only case
     * worth retrying; an empty batch means the buffer really had nothing.
     */
    private function attemptClaim(int $limit, string $ref, DateTimeInterface $stale): ?BufferBatch
    {
        // BOTH READS GO TO THE WRITE CONNECTION. Laravel sends a query builder SELECT to the read
        // connection unless a transaction is open or `sticky` is set and this request has
        // written. On a connection with a `read` replica and no `sticky`, the read-back below
        // asked a replica that had not seen the UPDATE yet, came back with nothing, and the
        // empty batch was acked: the claimed rows were deleted unsent.
        $ids = DB::table($this->table())
            ->useWritePdo()
            ->where(function (Builder $query) use ($stale): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $stale);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return BufferBatch::empty();
        }

        // Re-assert the claim predicate in the UPDATE, not just the SELECT: two flushers
        // can select the same ids before either writes (the SELECT takes no row lock).
        // The conditional UPDATE lets only the first stamp them — a concurrent flusher's
        // UPDATE matches 0 rows and its follow-up read comes back empty, so a hit is never
        // claimed (and sent) by two drainers at once. Portable across engines (SQLite
        // serializes writes anyway; no FOR UPDATE / SKIP LOCKED needed).
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
