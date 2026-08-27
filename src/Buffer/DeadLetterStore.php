<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Generator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

/**
 * The dead-letter queue: batches that exhausted delivery are parked here (as JSONL)
 * with their attempt count and last error, rather than being lost or retried
 * forever. `matomo:replay` reads them back into the live buffer.
 */
final class DeadLetterStore
{
    /**
     * Rows deleted per step by the retention prune. Large enough that an ordinary prune is
     * one or two statements, small enough that no single one holds the table for long.
     */
    private const int PRUNE_CHUNK = 500;

    /**
     * Park a batch, and say whether it landed.
     *
     * THE RETURN VALUE IS THE POINT. `pruneOlderThan()` has always tolerated a missing
     * table, and for a long time it was the only method here that did — so an installation
     * that suppresses the package migrations got a clean nightly prune and an "Undefined
     * table" out of the delivery path, which is the half that matters. Both callers park a
     * batch they are about to stop retrying, so a write that cannot happen must be
     * answerable rather than fatal: the caller then chooses the honest fallback (the queue
     * path fails the job into `failed_jobs`, the flusher leaves the batch claimed) instead
     * of losing the hits to an exception nobody expected.
     *
     * @param  list<array<string, scalar>>  $payloads
     * @return bool true when a row was written; false when there is no table to write to
     */
    public function record(array $payloads, int $attempts, string $error): bool
    {
        if ($payloads === []) {
            return false;
        }

        if (! $this->tableExists()) {
            return false;
        }

        DB::table($this->table())->insert([
            'payloads' => implode("\n", array_map(Json::encode(...), $payloads)),
            'hits' => count($payloads),
            'attempts' => $attempts,
            'error' => $error,
            'failed_at' => Date::now(),
        ]);

        return true;
    }

    public function count(): int
    {
        if (! $this->tableExists()) {
            return 0;
        }

        return DB::table($this->table())->count();
    }

    /**
     * Metadata for the most recently dead-lettered batches (for `matomo:replay --list`).
     *
     * @return list<array{id: int, hits: int, attempts: int, error: string, failed_at: string}>
     */
    public function recent(int $limit): array
    {
        if (! $this->tableExists()) {
            return [];
        }

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
     * Walk up to $limit parked batches (null = all), decoded back into payloads.
     *
     * A GENERATOR, NOT AN ARRAY, and the difference is measured rather than stylistic.
     * `->get()` over the whole table materialized every row AND its decoded payload tree
     * at once: with a realistic Matomo payload (493 bytes of JSON, 50 payloads to a row,
     * so 24 KB on disk) a decoded row costs about 86 KB in PHP, and the raw result set
     * stays alive alongside it. Two thousand rows — an ordinary backlog after one outage
     * — came to roughly 168 MB before the first hit was replayed.
     *
     * `matomo:replay` already deletes each entry the moment its payloads are buffered, so
     * streaming changes nothing about the crash semantics: it only stops the command from
     * holding rows it has already finished with.
     *
     * @return Generator<int, array{id: int, payloads: list<array<string, scalar>>}>
     */
    public function take(?int $limit = null): Generator
    {
        if (! $this->tableExists()) {
            return;
        }

        $remaining = $limit === null ? null : max(0, $limit);
        if ($remaining === 0) {
            return;
        }

        foreach (DB::table($this->table())->orderBy('id')->lazyById() as $row) {
            $raw = is_string($row->payloads ?? null) ? $row->payloads : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty

            yield [
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0, // @pest-mutate-ignore: DecrementInteger,IncrementInteger,RemoveIntegerCast
                'payloads' => Json::decodeAll(explode("\n", $raw)),
            ];

            if ($remaining !== null && --$remaining === 0) {
                return;
            }
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public function delete(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        if (! $this->tableExists()) {
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
        // A MISSING TABLE IS NOTHING TO CLEAN UP, not an error. An installation can suppress
        // the package migrations (`ignoreMigrations()`) or switch the store off entirely, and
        // the daily prune then ran against a table that was never created — throwing
        // "Undefined table" every night, in a release whose whole point was to REMOVE noise
        // from the error dashboard. Reported from a consumer within hours of 0.21.0.
        //
        // A cleanup command that fails on the absence of the thing it cleans up has no state
        // to report.
        if (! Schema::hasTable($this->table())) {
            return 0;
        }

        // DELETED IN STEPS, and over the primary key rather than with `DELETE ... LIMIT`,
        // which PostgreSQL does not have. One unbounded statement held a lock on the whole
        // table for as long as it took — and each row here carries a `longText` holding an
        // entire batch, so "as long as it took" scales with the outage that filled it. On
        // Postgres it also leaves the dead tuples behind until autovacuum catches up.
        //
        // The cutoff is computed ONCE, before the loop. Recomputing `now()` per step would
        // widen the window slightly on every pass, so a long prune would delete rows that
        // were not old enough when it started.
        $cutoff = Date::now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table($this->table())
                ->where('failed_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::PRUNE_CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table($this->table())->whereIn('id', $ids)->delete();
        } while (count($ids) === self::PRUNE_CHUNK);

        return $deleted;
    }

    public function purge(): int
    {
        if (! $this->tableExists()) {
            return 0;
        }

        $count = $this->count();
        DB::table($this->table())->delete();

        return $count;
    }

    /**
     * Whether the dead-letter table is actually there.
     *
     * An installation can suppress the package migrations with `ignoreMigrations()`, or
     * publish them and never run them. Every read and write here goes through this, so the
     * store behaves like an empty one instead of throwing out of whichever code path
     * happens to touch it first.
     */
    private function tableExists(): bool
    {
        return Schema::hasTable($this->table());
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');
    }
}
