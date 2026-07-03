<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Generator;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Support\Config;
use SplFileObject;

/**
 * Framework-agnostic file spool (the pushery pattern). Writers append one JSON
 * line under a lock; a claim atomically renames the queue aside so writers never
 * block, takes up to the limit, and streams the remainder back. Orphaned claim
 * files (from a crashed flush) are reclaimed by age. The path must be absolute
 * and shared between the app and the flusher, outside any per-release directory.
 *
 * Reads stream line by line, so counting or claiming never loads the whole spool
 * into memory — only the claimed batch (bounded by the flush limit) is held. Note
 * that a claim still rewrites the remaining queue, so draining a very large spool
 * is O(n) per claim; the file driver targets modest volume, and the database or
 * redis driver is the right choice at scale.
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
        return iterator_count($this->readLines($this->queue()));
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

        // Atomically rename the queue aside. If a concurrent claim already took it,
        // the rename is a no-op and the claim file is absent — readLines() then
        // streams nothing and the empty-batch path below applies.
        $claim = $this->dir().'/processing.'.Str::uuid()->toString().'.jsonl';
        @rename($queue, $claim);

        // Stream the claim file: hold only the taken batch in memory and append the
        // untouched remainder straight back onto the queue.
        $taken = [];
        $remainder = null;

        foreach ($this->readLines($claim) as $line) {
            if (count($taken) < $limit) {
                $taken[] = $line;

                continue;
            }

            $remainder ??= $this->appendHandle($queue);
            $remainder->fwrite($line."\n");
        }

        $remainder?->flock(LOCK_UN);

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
     * Stream the non-empty lines of a spool file, one at a time. Yields nothing
     * for a missing file, so a lost rename race degrades to an empty claim.
     *
     * @return Generator<int, string>
     */
    private function readLines(string $file): Generator
    {
        if (! is_file($file)) {
            return;
        }

        $reader = new SplFileObject($file, 'rb');
        $reader->setFlags(SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        foreach ($reader as $line) {
            if (is_string($line) && trim($line) !== '') {
                yield $line;
            }
        }
    }

    private function appendHandle(string $file): SplFileObject
    {
        $handle = new SplFileObject($file, 'ab');
        $handle->flock(LOCK_EX);

        return $handle;
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
