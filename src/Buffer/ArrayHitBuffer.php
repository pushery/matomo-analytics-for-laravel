<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use MatomoAnalytics\Contracts\ErasableHitBuffer;
use MatomoAnalytics\Privacy\DataSubject;

/**
 * In-memory buffer for tests and single-process use. Bound scoped, so pushes and claims share
 * state within a request and start empty in the next one under Octane.
 */
final class ArrayHitBuffer implements ErasableHitBuffer
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

    public function erase(DataSubject $subject): int
    {
        $kept = array_values(array_filter($this->pending, static fn (array $payload): bool => ! $subject->owns($payload)));
        $removed = count($this->pending) - count($kept);
        $this->pending = $kept;

        foreach ($this->claimed as $ref => $payloads) {
            $kept = array_values(array_filter($payloads, static fn (array $payload): bool => ! $subject->owns($payload)));
            $removed += count($payloads) - count($kept);
            $this->claimed[$ref] = $kept;
        }

        return $removed;
    }
}
