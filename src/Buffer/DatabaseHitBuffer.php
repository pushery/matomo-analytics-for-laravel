<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;

/**
 * Portable, durable buffer backed by a database table. Claims are token-stamped
 * and auto-reclaim stale rows (e.g. from a crashed flush), so nothing is lost.
 */
final class DatabaseHitBuffer implements HitBuffer
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

        $ref = Str::uuid()->toString();
        $stale = Date::now()->subMinutes(Config::int('matomo-analytics.batch.stale_after_minutes', 15));

        $ids = DB::table($this->table())
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
            return BufferBatch::empty();
        }

        $payloads = [];
        foreach (DB::table($this->table())->where('claimed_by', $ref)->orderBy('id')->pluck('payload') as $row) {
            $decoded = is_string($row) ? Json::decode($row) : null;
            if ($decoded !== null) {
                $payloads[] = $decoded;
            }
        }

        return new BufferBatch($ref, $payloads);
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

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
    }
}
