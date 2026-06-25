<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use MatomoAnalytics\Buffer\BufferBatch;

/**
 * A durable, cross-request buffer of hit payloads for batch mode. Delivery is
 * at-least-once: claim a batch, send it, then ack on a confirmed 200 or release
 * it back on failure.
 */
interface HitBuffer
{
    /**
     * @param  array<string, scalar>  $payload
     */
    public function push(array $payload): void;

    public function size(): int;

    public function claim(int $limit): BufferBatch;

    public function ack(BufferBatch $batch): void;

    public function release(BufferBatch $batch): void;
}
