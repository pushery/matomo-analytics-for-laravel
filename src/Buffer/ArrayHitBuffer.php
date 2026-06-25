<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use MatomoAnalytics\Contracts\HitBuffer;

/**
 * In-memory buffer for tests and single-process use. Bound as a singleton so
 * pushes and claims share state within a request.
 */
final class ArrayHitBuffer implements HitBuffer
{
    /**
     * @var list<array<string, scalar>>
     */
    private array $pending = [];

    /**
     * @var array<string, list<array<string, scalar>>>
     */
    private array $claimed = [];

    private int $sequence = 0;

    public function push(array $payload): void
    {
        $this->pending[] = $payload;
    }

    public function size(): int
    {
        return count($this->pending);
    }

    public function claim(int $limit): BufferBatch
    {
        if ($limit < 1 || $this->pending === []) {
            return BufferBatch::empty();
        }

        $taken = array_slice($this->pending, 0, $limit);
        $this->pending = array_slice($this->pending, count($taken));

        $ref = 'array-'.$this->sequence++;
        $this->claimed[$ref] = $taken;

        return new BufferBatch($ref, $taken);
    }

    public function ack(BufferBatch $batch): void
    {
        unset($this->claimed[$batch->ref]);
    }

    public function release(BufferBatch $batch): void
    {
        $restored = $this->claimed[$batch->ref] ?? [];
        unset($this->claimed[$batch->ref]);

        $this->pending = [...$restored, ...$this->pending];
    }
}
