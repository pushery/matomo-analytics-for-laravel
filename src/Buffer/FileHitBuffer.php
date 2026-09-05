<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Generator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Exceptions\BufferUnavailableException;
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

        // Atomically rename the queue aside, and READ THE SYSCALL'S OWN ANSWER. There used to
        // be an `is_file($queue)` guard above this and no check at all below it, so three very
        // different states came out as one empty batch: nothing buffered, a concurrent claim
        // that got there first, and a rename that FAILED.
        //
        // THE THIRD ONE WEDGED THE SPOOL SILENTLY AND FOREVER. An empty batch is how the
        // flusher learns the buffer is drained. Measured with the spool directory at `0555`:
        // delivered 0, dead-lettered 0, `isStuck()` false, no log, no event, and
        // `matomo:flush` printing `Flushed 0 Matomo hit(s).` every minute over hits that were
        // still sitting in the file.
        //
        // The queue file separates the last two. A rival renamed it away, so it is gone; a
        // rename that failed on permissions, a full disk or a read-only mount left it exactly
        // where it was. `clearstatcache()` because the answer must come from the filesystem
        // rather than from a stat taken before the rename.
        $claim = $this->dir().'/processing.'.Str::uuid().'.jsonl';

        if (! @rename($queue, $claim)) {
            clearstatcache(true, $queue);

            if (is_file($queue)) {
                throw new BufferUnavailableException(
                    'The Matomo file buffer could not claim its queue — check that '.$this->dir().' is writable.',
                );
            }

            return BufferBatch::empty();
        }

        // rename(2) preserves the queue's mtime, so on an idle spool the fresh claim file
        // inherits an already-stale timestamp. Stamp it with the claim time up front so a
        // concurrent reclaimStale() cannot treat this in-flight claim as abandoned and
        // re-queue it (double-send) while we are still streaming it below.
        @touch($claim);

        // Stream the claim file: hold only the taken batch in memory and append the
        // untouched remainder straight back onto the queue.
        $taken = [];
        $remainder = null;

        foreach ($this->readLines($claim) as $line) {
            if (count($taken) < $limit) {
                $taken[] = $line;

                continue;
            }

            // `??=`, and it is LOAD-BEARING -- not a micro-optimization. appendHandle()
            // takes LOCK_EX, and a plain `=` evaluates the right-hand side (blocking on that
            // lock) BEFORE the previous handle is released. The second overflow line would
            // then wait on a lock this same process is still holding: a self-deadlock, with
            // the flusher hung and nothing in the log. Measured -- the mutation survey hangs
            // indefinitely on exactly this substitution.
            $remainder ??= $this->appendHandle($queue);
            $remainder->fwrite($line."\n");
        }

        $remainder?->flock(LOCK_UN);

        if ($taken === []) {
            @unlink($claim);

            return BufferBatch::empty();
        }

        file_put_contents($claim, implode("\n", $taken)."\n");

        $payloads = Json::decodeAll($taken);

        return new BufferBatch($claim, $payloads, count($taken) - count($payloads));
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
        // Floored at one minute for the reason RedisHitBuffer spells out: at 0 every claim
        // is already expired when it is made, so at-least-once becomes guaranteed twice.
        $cutoff = Date::now()->subMinutes(max(1, Config::int('matomo-analytics.batch.stale_after_minutes', 15)))->getTimestamp();

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
        return rtrim(Config::string('matomo-analytics.batch.path', App::storagePath('app/matomo-analytics')), '/');
    }
}
