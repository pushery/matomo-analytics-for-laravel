<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use MatomoAnalytics\Support\Config;

/**
 * The dead-letter queue: batches that exhausted delivery are parked here (as JSONL)
 * with their attempt count and last error, rather than being lost or retried
 * forever. `matomo:replay` reads them back into the live buffer.
 */
final class DeadLetterStore
{
    /**
     * @param  list<array<string, scalar>>  $payloads
     */
    public function record(array $payloads, int $attempts, string $error): void
    {
        if ($payloads === []) {
            return;
        }

        DB::table($this->table())->insert([
            'payloads' => implode("\n", array_map(Json::encode(...), $payloads)),
            'hits' => count($payloads),
            'attempts' => $attempts,
            'error' => $error,
            'failed_at' => Date::now(),
        ]);
    }

    public function count(): int
    {
        return DB::table($this->table())->count();
    }

    /**
     * Metadata for the most recently dead-lettered batches (for `matomo:replay --list`).
     *
     * @return list<array{id: int, hits: int, attempts: int, error: string, failed_at: string}>
     */
    public function recent(int $limit): array
    {
        $rows = DB::table($this->table())->orderByDesc('id')->limit(max(1, $limit))->get();

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0, // @pest-mutate-ignore: DecrementInteger,IncrementInteger,RemoveIntegerCast
                'hits' => is_numeric($row->hits ?? null) ? (int) $row->hits : 0, // @pest-mutate-ignore: DecrementInteger,IncrementInteger,RemoveIntegerCast
                'attempts' => is_numeric($row->attempts ?? null) ? (int) $row->attempts : 0, // @pest-mutate-ignore: DecrementInteger,IncrementInteger,RemoveIntegerCast
                'error' => is_string($row->error ?? null) ? $row->error : '',
                'failed_at' => is_string($row->failed_at ?? null) ? $row->failed_at : '',
            ];
        }

        return $entries;
    }

    /**
     * Fetch up to $limit parked batches (null = all), decoded back into payloads.
     *
     * @return list<array{id: int, payloads: list<array<string, scalar>>}>
     */
    public function take(?int $limit = null): array
    {
        $query = DB::table($this->table())->orderBy('id');
        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        $entries = [];
        foreach ($query->get() as $row) {
            $raw = is_string($row->payloads ?? null) ? $row->payloads : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty
            $entries[] = [
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0, // @pest-mutate-ignore: DecrementInteger,IncrementInteger,RemoveIntegerCast
                'payloads' => Json::decodeAll(explode("\n", $raw)),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<int>  $ids
     */
    public function delete(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::table($this->table())->whereIn('id', $ids)->delete();
    }

    /**
     * Delete entries that failed longer ago than $days, and report how many went.
     *
     * Filters on `failed_at`, which is why the column finally carries an index — the
     * original migration left it deliberately unindexed and said so: "it earns one the day
     * something filters on age (a retention window)". This is that day.
     *
     * A row whose `failed_at` is NULL is never pruned, and that comes from SQL rather than
     * from a clause here: `NULL < x` evaluates to UNKNOWN, not TRUE, so such a row simply
     * never matches. An explicit `whereNotNull()` stood here first and was removed after its
     * own red probe passed — with the clause deleted the behavior was identical, which is the
     * definition of a branch no run can enter. It would have survived every mutant forever
     * while reading like a safeguard.
     *
     * The guarantee is worth keeping tested even though it is the database's: a later rewrite
     * that coalesces the column, or filters the other way round, would start deleting rows
     * whose age nobody established. `DeadLetterRetentionTest` holds it.
     */
    public function pruneOlderThan(int $days): int
    {
        return DB::table($this->table())
            ->where('failed_at', '<', Date::now()->subDays($days))
            ->delete();
    }

    public function purge(): int
    {
        $count = $this->count();
        DB::table($this->table())->delete();

        return $count;
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');
    }
}
