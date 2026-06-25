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
}
