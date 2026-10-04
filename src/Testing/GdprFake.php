<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use Illuminate\Support\Testing\Fakes\Fake;
use MatomoAnalytics\Contracts\GdprClient;
use PHPUnit\Framework\Assert;

/**
 * In-memory GdprClient for tests: records every operation and returns stubbed
 * results (no real deletion). Swap it in with MatomoGdpr::fake().
 */
final class GdprFake implements Fake, GdprClient
{
    /** @var list<array{op: string, segment: string|null, site: int|string|null, visits: int}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $found = [];

    /** @var array<string, int> */
    private array $deleted = [];

    /**
     * What forget() reports for the local half: by default, that it ran and found nothing.
     *
     * @var array{local_buffer: int, local_dead_letters: int, local_segment_understood: bool, local_buffer_searched: bool, local_queue_searched: bool}
     */
    private array $local = [
        'local_buffer' => 0,
        'local_dead_letters' => 0,
        'local_segment_understood' => true,
        'local_buffer_searched' => true,
        'local_queue_searched' => true,
    ];

    private ?string $lastError = null;

    /** The message every call fails with while set; see setLastError(). */
    private ?string $failure = null;

    /** @var array<array-key, mixed>|null what an export answers; null answers with the found rows under `log_visit` */
    private ?array $exported = null;

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

    /**
     * Stub the local half of forget(): rows removed from the buffer and the dead letters, whether
     * the segment could be evaluated here, and whether the buffer and the queue were searched.
     */
    public function stubLocal(
        int $buffer = 0,
        int $deadLetters = 0,
        bool $segmentUnderstood = true,
        bool $bufferSearched = true,
        bool $queueSearched = true,
    ): self {
        $this->local = [
            'local_buffer' => $buffer,
            'local_dead_letters' => $deadLetters,
            'local_segment_understood' => $segmentUnderstood,
            'local_buffer_searched' => $bufferSearched,
            'local_queue_searched' => $queueSearched,
        ];

        return $this;
    }

    /**
     * Stub what an export answers. Matomo answers with the data subject's rows keyed by log
     * table (`log_visit`, `log_link_visit_action` and so on); without a stub the fake answers
     * with the visits it exports under `log_visit`.
     *
     * @param  array<array-key, mixed>  $tables
     */
    public function stubExported(array $tables): self
    {
        $this->exported = $tables;

        return $this;
    }

    /**
     * Make every call fail with this message, the way the real client fails while Matomo
     * refuses; null makes the calls succeed again.
     */
    public function setLastError(?string $message): self
    {
        $this->failure = $message;
        $this->lastError = $message;

        return $this;
    }

    /**
     * Make every mutation (forget/export/deleteVisits/exportVisits) fail with null
     * while findDataSubjects still succeeds — to exercise post-lookup error paths. A
     * successful lookup clears lastError(), as the real client's does.
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

        return $this->answer(false, $this->found);
    }

    public function forget(string $segment, int|string|null $site = null): ?array
    {
        $this->calls[] = ['op' => 'forget', 'segment' => $segment, 'site' => $site, 'visits' => count($this->found)];

        // The same shape the real client returns: Matomo's counts, then the local half.
        return $this->answer(true, array_merge($this->deleted, $this->local));
    }

    public function export(string $segment, int|string|null $site = null): ?array
    {
        $this->calls[] = ['op' => 'export', 'segment' => $segment, 'site' => $site, 'visits' => count($this->found)];

        // The real client looks the subject up first, and when nothing matched it exports
        // nothing, without a second call that could fail.
        return $this->answer($this->found !== [], $this->found === [] ? [] : ($this->exported ?? ['log_visit' => $this->found]));
    }

    public function deleteVisits(array $visits): ?array
    {
        $this->calls[] = ['op' => 'deleteVisits', 'segment' => null, 'site' => null, 'visits' => count($visits)];

        return $this->answer($visits !== [], $visits === [] ? [] : $this->deleted);
    }

    public function exportVisits(array $visits): ?array
    {
        $this->calls[] = ['op' => 'exportVisits', 'segment' => null, 'site' => null, 'visits' => count($visits)];

        return $this->answer($visits !== [], $visits === [] ? [] : ($this->exported ?? ['log_visit' => $visits]));
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * A call's result the way the real client reports it: null with lastError() set when the
     * call fails, the result with lastError() cleared when it succeeds.
     *
     * @template TResult of array
     *
     * @param  TResult  $result
     * @return TResult|null
     */
    private function answer(bool $mutation, array $result): ?array
    {
        $this->lastError = $this->failure ?? ($mutation && $this->mutationsFail ? $this->mutationError : null);

        return $this->lastError === null ? $result : null;
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
