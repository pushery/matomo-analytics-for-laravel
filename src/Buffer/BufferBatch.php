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
     */
    public function __construct(
        public string $ref,
        public array $payloads,
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
