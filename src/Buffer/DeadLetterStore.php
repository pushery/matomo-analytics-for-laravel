<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

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
            'failed_at' => now(),
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
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0,
                'hits' => is_numeric($row->hits ?? null) ? (int) $row->hits : 0,
                'attempts' => is_numeric($row->attempts ?? null) ? (int) $row->attempts : 0,
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
            $raw = is_string($row->payloads ?? null) ? $row->payloads : '';
            $entries[] = [
                'id' => is_numeric($row->id ?? null) ? (int) $row->id : 0,
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
