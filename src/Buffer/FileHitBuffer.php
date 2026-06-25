<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;

/**
 * Framework-agnostic file spool (the pushery pattern). Writers append one JSON
 * line under a lock; a claim atomically renames the queue aside so writers never
 * block, takes up to the limit, and writes the remainder back. Orphaned claim
 * files (from a crashed flush) are reclaimed by age. The path must be absolute
 * and shared between the app and the flusher, outside any per-release directory.
 */
final class FileHitBuffer implements HitBuffer
{
    public function push(array $payload): void
    {
        $dir = $this->dir();
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents($this->queue(), Json::encode($payload)."\n", FILE_APPEND | LOCK_EX);
    }

    public function size(): int
    {
        return count($this->readLines($this->queue()));
    }

    public function claim(int $limit): BufferBatch
    {
        if ($limit < 1) {
            return BufferBatch::empty();
        }

        $this->reclaimStale();

        $queue = $this->queue();
        if (! is_file($queue)) {
            return BufferBatch::empty();
        }

        // If a concurrent claim already renamed the queue, this rename is a no-op
        // and the claim file will be absent, so the empty-batch path below applies.
        $claim = $this->dir().'/processing.'.Str::uuid()->toString().'.jsonl';
        @rename($queue, $claim);

        $lines = $this->readLines($claim);
        $taken = array_slice($lines, 0, $limit);
        $remainder = array_slice($lines, count($taken));

        if ($remainder !== []) {
            file_put_contents($queue, implode("\n", $remainder)."\n", FILE_APPEND | LOCK_EX);
        }

        if ($taken === []) {
            @unlink($claim);

            return BufferBatch::empty();
        }

        file_put_contents($claim, implode("\n", $taken)."\n");

        return new BufferBatch($claim, Json::decodeAll($taken));
    }

    public function ack(BufferBatch $batch): void
    {
        if ($batch->ref !== '') {
            @unlink($batch->ref);
        }
    }

    public function release(BufferBatch $batch): void
    {
        if ($batch->ref === '') {
            return;
        }

        $contents = @file_get_contents($batch->ref);
        if (is_string($contents)) {
            file_put_contents($this->queue(), $contents, FILE_APPEND | LOCK_EX);
        }

        @unlink($batch->ref);
    }

    private function reclaimStale(): void
    {
        $cutoff = now()->subMinutes(Config::int('matomo-analytics.batch.stale_after_minutes', 15))->getTimestamp();

        foreach (glob($this->dir().'/processing.*.jsonl') ?: [] as $file) {
            $modified = filemtime($file);
            $contents = @file_get_contents($file);
            if ($modified !== false && $modified < $cutoff && is_string($contents)) {
                file_put_contents($this->queue(), $contents, FILE_APPEND | LOCK_EX);
                @unlink($file);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readLines(string $file): array
    {
        $contents = is_file($file) ? @file_get_contents($file) : false;
        if (! is_string($contents)) {
            return [];
        }

        return array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => trim($line) !== ''));
    }

    private function queue(): string
    {
        return $this->dir().'/queue.jsonl';
    }

    private function dir(): string
    {
        return rtrim(Config::string('matomo-analytics.batch.path', storage_path('app/matomo-analytics')), '/');
    }
}
