<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use MatomoAnalytics\Contracts\GdprClient;
use PHPUnit\Framework\Assert;

/**
 * In-memory GdprClient for tests: records every operation and returns stubbed
 * results (no real deletion). Swap it in with MatomoGdpr::fake().
 */
final class GdprFake implements GdprClient
{
    /** @var list<array{op: string, segment: string|null, site: int|string|null, visits: int}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $found = [];

    /** @var array<string, int> */
    private array $deleted = [];

    private ?string $lastError = null;

    private bool $mutationsFail = false;

    private string $mutationError = 'GDPR operation failed';

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function stubFound(array $rows): self
    {
        $this->found = $rows;

        return $this;
    }

    /**
     * @param  array<string, int>  $counts
     */
    public function stubDeleted(array $counts): self
    {
        $this->deleted = $counts;

        return $this;
    }

    public function setLastError(?string $message): self
    {
        $this->lastError = $message;

        return $this;
    }

    /**
     * Make every mutation (forget/export/deleteVisits/exportVisits) fail with null
     * while findDataSubjects still succeeds — to exercise post-lookup error paths.
     */
    public function failMutations(string $error = 'GDPR operation failed'): self
    {
        $this->mutationsFail = true;
        $this->mutationError = $error;

        return $this;
    }

    public function findDataSubjects(string $segment, int|string|null $site = null): ?array
    {
        $this->calls[] = ['op' => 'find', 'segment' => $segment, 'site' => $site, 'visits' => count($this->found)];

        return $this->lastError !== null ? null : $this->found;
    }

    public function forget(string $segment, int|string|null $site = null): ?array
    {
        $this->calls[] = ['op' => 'forget', 'segment' => $segment, 'site' => $site, 'visits' => count($this->found)];

        if ($this->mutationsFail) {
            $this->lastError = $this->mutationError;
        }

        return $this->lastError !== null ? null : $this->deleted;
    }

    public function export(string $segment, int|string|null $site = null): ?array
    {
        $this->calls[] = ['op' => 'export', 'segment' => $segment, 'site' => $site, 'visits' => count($this->found)];

        if ($this->mutationsFail) {
            $this->lastError = $this->mutationError;
        }

        return $this->lastError !== null ? null : ['exported' => $this->found];
    }

    public function deleteVisits(array $visits): ?array
    {
        $this->calls[] = ['op' => 'deleteVisits', 'segment' => null, 'site' => null, 'visits' => count($visits)];

        if ($this->mutationsFail) {
            $this->lastError = $this->mutationError;
        }

        return $this->lastError !== null ? null : $this->deleted;
    }

    public function exportVisits(array $visits): ?array
    {
        $this->calls[] = ['op' => 'exportVisits', 'segment' => null, 'site' => null, 'visits' => count($visits)];

        if ($this->mutationsFail) {
            $this->lastError = $this->mutationError;
        }

        return $this->lastError !== null ? null : ['exported' => $visits];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  (Closure(array{op: string, segment: string|null, site: int|string|null, visits: int}): bool)|null  $callback
     */
    public function assertForgotten(?string $segment = null, ?Closure $callback = null): void
    {
        $matches = array_filter(
            $this->calls,
            static fn (array $call): bool => $call['op'] === 'forget'
                && ($segment === null || $call['segment'] === $segment)
                && (! $callback instanceof Closure || $callback($call)),
        );

        Assert::assertNotEmpty($matches, 'Expected a GDPR forget() that was not made.');
    }

    public function assertNothingForgotten(): void
    {
        $forgets = array_filter($this->calls, static fn (array $call): bool => $call['op'] === 'forget' || $call['op'] === 'deleteVisits');

        Assert::assertSame([], array_values($forgets), 'Expected no GDPR deletions, but some were made.');
    }
}
