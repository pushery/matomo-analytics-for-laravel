<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Generator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use MatomoAnalytics\Contracts\ErasableHitBuffer;
use MatomoAnalytics\Exceptions\BufferUnavailableException;
use MatomoAnalytics\Privacy\DataSubject;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\SpoolDirectory;
use SplFileObject;

/**
 * Framework-agnostic file spool. Writers append one JSON
 * line under an exclusive lock on the queue file. A claim takes the same lock,
 * copies up to the limit into a claim file of its own and shifts the remainder to
 * the start of the queue. The queue file is never renamed or replaced, so a writer
 * that opened it before a claim still appends to the live queue. Orphaned claim
 * files (from a crashed flush) are reclaimed by age. The path must be absolute and
 * shared between the app and the flusher, outside any per-release directory.
 *
 * Reads stream line by line, so counting or claiming never loads the whole spool
 * into memory — only the claimed batch (bounded by the flush limit) is held. A claim
 * still rewrites the remaining queue, and writers wait for it, so draining a very
 * large spool is O(n) per claim; the file driver targets modest volume, and the
 * database or redis driver is the right choice at scale.
 */
final class FileHitBuffer implements ErasableHitBuffer
{
    public function push(array $payload): void
    {
        $dir = $this->dir();

        if (! SpoolDirectory::ensure($dir, 0o775)) {
            throw new BufferUnavailableException(
                'The Matomo file buffer could not create its spool directory — check that '.$dir.' is writable.',
            );
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

        $queue = $this->openQueue();

        if ($queue === null) {
            return BufferBatch::empty();
        }

        try {
            return $this->takeFrom($queue, $limit);
        } finally {
            flock($queue, LOCK_UN);
            fclose($queue);
        }
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

    public function erase(DataSubject $subject): int
    {
        $removed = 0;
        $queue = $this->openQueue();

        if ($queue !== null) {
            try {
                $removed += $this->eraseFrom($queue, $subject);
            } finally {
                flock($queue, LOCK_UN);
                fclose($queue);
            }
        }

        foreach (glob($this->dir().'/processing.*.jsonl') ?: [] as $claimed) {
            $removed += $this->eraseClaimed($claimed, $subject);
        }

        return $removed;
    }

    /**
     * Remove the subject's lines from an open, locked file and return how many went.
     *
     * The file is compacted in place, like `keepFrom()` compacts the queue, so a writer waiting
     * for the lock appends after the kept lines instead of to a file that was replaced. Kept
     * lines only ever move towards the start, so a line is always read before its bytes can be
     * overwritten.
     *
     * @param  resource  $file
     */
    private function eraseFrom(mixed $file, DataSubject $subject): int
    {
        $read = 0;
        $write = 0;
        $removed = 0;

        while (fseek($file, $read) === 0 && ($line = fgets($file)) !== false) {
            $read += strlen($line);

            if ($subject->owns(Json::decode(rtrim($line, "\r\n")))) {
                $removed++;

                continue;
            }

            if ($write !== $read - strlen($line)) {
                fseek($file, $write);
                fwrite($file, $line);
            }

            $write += strlen($line);
        }

        if ($removed > 0) {
            ftruncate($file, $write);
            fflush($file);
        }

        return $removed;
    }

    /**
     * Remove the subject's lines from a claimed batch, so that a release or a stale reclaim
     * cannot put them back. A batch acked in the meantime is simply gone.
     */
    private function eraseClaimed(string $claimed, DataSubject $subject): int
    {
        $file = @fopen($claimed, 'r+b');

        if ($file === false) {
            return 0;
        }

        try {
            flock($file, LOCK_EX);

            return $this->eraseFrom($file, $subject);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    /**
     * The queue file, open for reading and writing under the lock writers take, or null when
     * nothing was ever buffered.
     *
     * THE QUEUE STAYS WHERE IT IS. A claim used to rename it aside, and push() opens the file
     * before it waits for the lock: a writer caught between the two held the renamed file, its
     * line landed after the claim had read it, and ack() deleted the line with the batch. Locking
     * the same file a writer appends to, and never moving it, leaves no file a writer could still
     * be holding when a batch is deleted.
     *
     * A queue that exists but cannot be opened is refused rather than reported empty. An empty
     * batch is how the flusher learns the buffer is drained, so treating it as empty would leave
     * the hits in the file with every signal green.
     *
     * @return resource|null
     */
    private function openQueue(): mixed
    {
        $queue = $this->queue();
        $handle = @fopen($queue, 'r+b');

        if ($handle === false) {
            clearstatcache(true, $queue);

            if (file_exists($queue)) {
                throw $this->unavailable();
            }

            return null;
        }

        flock($handle, LOCK_EX);

        return $handle;
    }

    /**
     * Take up to $limit lines from the locked queue into a claim file of their own.
     *
     * The claim file is written before the queue is shortened. If the process dies between the
     * two, the lines are in both places and the stale claim is reclaimed later: a hit sent twice
     * rather than one lost.
     *
     * @param  resource  $queue
     */
    private function takeFrom(mixed $queue, int $limit): BufferBatch
    {
        $taken = [];
        $offset = 0;
        $rest = null;

        while (($line = fgets($queue)) !== false) {
            if (trim($line) !== '') {
                if (count($taken) === $limit) {
                    $rest = $offset;

                    break;
                }

                $taken[] = rtrim($line, "\r\n");
            }

            $offset += strlen($line);
        }

        if ($taken === []) {
            return BufferBatch::empty();
        }

        $claim = $this->dir().'/processing.'.Str::uuid().'.jsonl';

        if (@file_put_contents($claim, implode("\n", $taken)."\n") === false) {
            throw $this->unavailable();
        }

        $this->keepFrom($queue, $rest);

        $payloads = Json::decodeAll($taken);

        return new BufferBatch($claim, $payloads, count($taken) - count($payloads));
    }

    /**
     * Shift the queue's bytes from $from onwards to its start and cut the file after them, or
     * empty it when $from is null.
     *
     * Reading always runs ahead of writing, because the claim took at least one line from the
     * front, so no byte is overwritten before it has been copied.
     *
     * @param  resource  $queue
     */
    private function keepFrom(mixed $queue, ?int $from): void
    {
        $kept = 0;

        while ($from !== null) {
            fseek($queue, $from + $kept);
            $chunk = fread($queue, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            fseek($queue, $kept);
            fwrite($queue, $chunk);
            $kept += strlen($chunk);
        }

        ftruncate($queue, $kept);
        fflush($queue);
    }

    private function unavailable(): BufferUnavailableException
    {
        return new BufferUnavailableException(
            'The Matomo file buffer could not claim its queue — check that '.$this->dir().' is writable.',
        );
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

    private function queue(): string
    {
        return $this->dir().'/queue.jsonl';
    }

    private function dir(): string
    {
        return rtrim(Config::string('matomo-analytics.batch.path', App::storagePath('app/matomo-analytics')), '/');
    }
}
