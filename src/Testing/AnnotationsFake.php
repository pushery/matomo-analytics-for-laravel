<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use MatomoAnalytics\Annotations\Concerns\AnnotatesReleases;
use MatomoAnalytics\Contracts\AnnotationsClient;
use PHPUnit\Framework\Assert;

/**
 * In-memory AnnotationsClient for tests: records every annotation instead of
 * sending it. Swap it in with MatomoAnnotations::fake().
 */
final class AnnotationsFake implements AnnotationsClient
{
    use AnnotatesReleases;

    /** @var list<array{note: string, date: string|null, starred: bool, site: int|string|null}> */
    public array $annotations = [];

    private ?string $lastError = null;

    private bool $fails = false;

    private string $failError = 'Annotation failed';

    /**
     * Make every subsequent add() fail with null (to exercise error paths).
     */
    public function fail(string $error = 'Annotation failed'): self
    {
        $this->fails = true;
        $this->failError = $error;

        return $this;
    }

    public function setLastError(?string $message): self
    {
        $this->lastError = $message;

        return $this;
    }

    public function add(string $note, ?string $date = null, bool $starred = false, int|string|null $site = null): ?array
    {
        $this->annotations[] = ['note' => $note, 'date' => $date, 'starred' => $starred, 'site' => $site];

        if ($this->fails) {
            $this->lastError = $this->failError;

            return null;
        }

        $this->lastError = null;

        return ['note' => $note, 'date' => $date, 'starred' => $starred ? 1 : 0];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  (Closure(array{note: string, date: string|null, starred: bool, site: int|string|null}): bool)|null  $callback
     */
    public function assertAnnotated(?string $note = null, ?Closure $callback = null): void
    {
        $matches = array_filter(
            $this->annotations,
            static fn (array $annotation): bool => ($note === null || $annotation['note'] === $note)
                && (! $callback instanceof Closure || $callback($annotation)),
        );

        Assert::assertNotEmpty($matches, 'Expected an annotation that was not made.');
    }

    public function assertNothingAnnotated(): void
    {
        Assert::assertSame([], $this->annotations, 'Expected no annotations, but some were made.');
    }
}
