<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

/**
 * A claimed set of buffered hits. `ref` is an opaque, driver-specific handle used
 * to ack (delete) or release (return) the batch.
 */
final readonly class BufferBatch
{
    /**
     * @param  list<array<string, scalar>>  $payloads
     * @param  int  $skipped  Claimed entries whose payload would not decode and were dropped.
     *
     * `skipped` EXISTS BECAUSE DROPPING THEM WAS SILENT, AND ACKING DELETED THEM ANYWAY.
     * A driver that decodes stored bytes skips what it cannot read, and `ack()` then removes
     * every entry of the claim — the readable ones because they were delivered, the skipped
     * one because it shares the ref. Measured with three rows and one corrupt payload:
     * delivered 2, dead-lettered 0, `isStuck()` false, no event, no log, buffer empty,
     * dead-letter table empty. The third hit was gone and nothing said so.
     *
     * There is no recovery to offer — a payload that will not decode cannot be sent to Matomo
     * by anyone — so this carries the count out to `BufferFlusher`, which reports it. The
     * difference bought is between a hit that was lost and a hit that was lost unnoticed.
     */
    public function __construct(
        public string $ref,
        public array $payloads,
        public int $skipped = 0,
    ) {}

    public static function empty(): self
    {
        return new self('', []);
    }

    public function isEmpty(): bool
    {
        return $this->payloads === [];
    }

    /**
     * Nothing was claimed at all — the buffer is drained.
     *
     * NOT THE SAME AS `isEmpty()`, and conflating the two wedges the buffer. A batch that
     * claimed rows whose payloads no longer decode is empty of payloads and NOT empty of
     * work: it holds a ref, those rows are marked claimed, and a caller that reads
     * `isEmpty()` as "drained" stops there — leaving the rows to go stale, be reclaimed, fail
     * to decode again, and stop the next run at the same place. One unreadable row would have
     * held up every hit behind it, forever, with no error anywhere.
     */
    public function claimedNothing(): bool
    {
        return $this->ref === '';
    }
}
