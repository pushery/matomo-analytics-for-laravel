<?php

declare(strict_types=1);

namespace MatomoAnalytics\Testing;

use Closure;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use MatomoAnalytics\Contracts\ReportClient;
use MatomoAnalytics\Reporting\Concerns\ResolvesCommonReports;
use MatomoAnalytics\Reporting\ReportQuery;
use PHPUnit\Framework\Assert;

/**
 * In-memory ReportClient for tests: records every request and returns stubbed
 * responses. A method without a stub fails the way a call to the real client fails: it
 * returns null and sets lastError(). Swap it in with MatomoReports::fake().
 *
 * A copy from forSite() answers with this fake's stubs and logs into this fake's requests,
 * with the site it asks for as `idSite`, the way the real client sends it. Its lastError() is
 * its own, as on the real client.
 */
final class MatomoReportsFake implements Fake, ReportClient
{
    use ResolvesCommonReports;

    /** @var list<array{method: string, params: array<string, scalar>}> */
    public array $requests = [];

    public int $flushed = 0;

    /** @var array<string, array<array-key, mixed>|null> */
    private array $stubs = [];

    private ?string $lastError = null;

    /** The message a failed call reports; see setLastError(). */
    private ?string $failureMessage = null;

    /** The site a copy from forSite() asks for. */
    private ?int $siteId = null;

    /** The fake a copy from forSite() came from, which logs its requests and holds its stubs. */
    private ?self $origin = null;

    /**
     * Pre-program the response for a method. Null makes the call fail: it returns null and sets
     * lastError(), as the real client does.
     *
     * @param  array<array-key, mixed>|null  $response
     */
    public function stub(string $method, ?array $response): self
    {
        $origin = $this->origin ?? $this;
        $origin->stubs[$method] = $response;

        return $this;
    }

    /**
     * The message failed calls report, and lastError() until the next call answers.
     */
    public function setLastError(?string $message): self
    {
        $origin = $this->origin ?? $this;
        $origin->failureMessage = $message;
        $this->lastError = $message;

        return $this;
    }

    public function get(string $method, array $params = []): ?array
    {
        $origin = $this->origin ?? $this;

        if ($this->siteId !== null) {
            $params = array_merge($params, ['idSite' => $this->siteId]);
        }

        $origin->requests[] = ['method' => $method, 'params' => $params];

        $response = $origin->stubs[$method] ?? null;

        // Like the real client: a failed call returns null with a reason, and an answer clears it.
        $this->lastError = $response === null
            ? ($origin->failureMessage ?? sprintf('Nothing answers [%s] in the fake, so the call fails.', $method))
            : null;

        return $response;
    }

    public function query(string $method): ReportQuery
    {
        return new ReportQuery($this, $method);
    }

    public function bulk(array $requests): array
    {
        return array_map(function (string|array $request): ?array {
            $method = is_string($request) ? $request : (string) ($request['method'] ?? '');
            $params = is_string($request) ? [] : array_diff_key($request, ['method' => true]);

            return $this->get($method, $params);
        }, $requests);
    }

    public function flushCache(): void
    {
        $origin = $this->origin ?? $this;
        $origin->flushed++;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function forSite(int $siteId): self
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException("A Matomo site id is 1 or more, and {$siteId} was given.");
        }

        $copy = new self;
        $copy->siteId = $siteId;
        $copy->origin = $this->origin ?? $this;

        return $copy;
    }

    /**
     * @param  (Closure(array<string, scalar>): bool)|null  $callback
     */
    public function assertRequested(string $method, ?Closure $callback = null): void
    {
        $matches = array_filter(
            ($this->origin ?? $this)->requests,
            static fn (array $request): bool => $request['method'] === $method
                && (! $callback instanceof Closure || $callback($request['params'])),
        );

        Assert::assertNotEmpty($matches, "Expected a Matomo report request for [{$method}] that was not made.");
    }

    public function assertNothingRequested(): void
    {
        Assert::assertSame([], ($this->origin ?? $this)->requests, 'Expected no Matomo report requests, but some were made.');
    }

    public function assertRequestedCount(int $count): void
    {
        Assert::assertCount($count, ($this->origin ?? $this)->requests);
    }
}
