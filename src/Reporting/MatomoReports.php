<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\ReportClient;
use MatomoAnalytics\Exceptions\ReportRequestException;
use MatomoAnalytics\Reporting\Concerns\ResolvesCommonReports;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * Reporting API client. Every call POSTs form-encoded with token_auth in the
 * body (never the query string, which would leak the token into logs), forces
 * HTTP/1.1, decodes JSON, and detects Matomo's {result: error} envelope. Results
 * are cached date-aware; failures set lastError() and route through the resilience
 * reporter. Bound scoped because lastError() is per-request state (Octane-safe).
 */
final class MatomoReports implements ReportClient
{
    use ResolvesCommonReports;

    private ?string $lastError = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Reporter $reporter,
        private readonly ReportCache $cache,
    ) {}

    public function get(string $method, array $params = []): ?array
    {
        if (! $this->isReady()) {
            return null;
        }

        return $this->cache->remember(
            $this->cache->key($method, $params),
            $this->cache->ttlFor($method, $params),
            fn (): ?array => $this->fetch($method, $params),
        );
    }

    public function bulk(array $requests): array
    {
        if (! $this->isReady()) {
            return array_fill(0, count($requests), null);
        }

        $urls = array_map($this->bulkUrl(...), $requests);

        $decoded = $this->call([
            'module' => 'API',
            'method' => 'API.getBulkRequest',
            'format' => 'json',
            'token_auth' => $this->token(),
            'urls' => $urls,
        ]);

        if ($decoded === null) {
            return array_fill(0, count($requests), null);
        }

        return array_map(static fn (mixed $entry): ?array => is_array($entry) ? $entry : null, array_values($decoded));
    }

    public function flushCache(): void
    {
        $this->cache->flush();
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    private function fetch(string $method, array $params): ?array
    {
        return $this->call($this->body($method, $params));
    }

    /**
     * @param  array<string, scalar|array<int, string>>  $body
     * @return array<array-key, mixed>|null
     */
    private function call(array $body): ?array
    {
        try {
            $response = $this->request()->post($this->connection->reportingUrl(), $body);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), $e);
        }

        if (! $response->successful()) {
            return $this->fail('Matomo reporting API returned HTTP '.$response->status());
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->fail('Matomo reporting API returned a non-JSON response.');
        }

        if (($decoded['result'] ?? null) === 'error') {
            $message = $decoded['message'] ?? null;

            return $this->fail('Matomo reporting API error: '.(is_string($message) ? $message : 'unknown error'));
        }

        $this->lastError = null;

        return $decoded;
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, scalar>
     */
    private function body(string $method, array $params): array
    {
        return array_merge(
            [
                'period' => Config::string('matomo-analytics.reporting.default_period', 'day'),
                'date' => Config::string('matomo-analytics.reporting.default_date', 'today'),
            ],
            $params,
            [
                'module' => 'API',
                'method' => $method,
                'format' => 'json',
                'idSite' => $this->connection->siteId,
                'token_auth' => $this->token(),
            ],
        );
    }

    /**
     * @param  string|array<string, scalar>  $request
     */
    private function bulkUrl(string|array $request): string
    {
        $method = is_string($request) ? $request : (string) ($request['method'] ?? '');
        $params = is_string($request) ? [] : array_diff_key($request, ['method' => true]);

        return http_build_query(array_merge(
            [
                'method' => $method,
                'period' => Config::string('matomo-analytics.reporting.default_period', 'day'),
                'date' => Config::string('matomo-analytics.reporting.default_date', 'today'),
                'idSite' => $this->connection->siteId,
            ],
            $params,
        ));
    }

    private function request(): PendingRequest
    {
        return Http::asForm()
            ->timeout(Config::int('matomo-analytics.reporting.timeout', 10))
            ->withOptions(['version' => 1.1]);
    }

    private function isReady(): bool
    {
        if ($this->connection->isConfigured() && $this->connection->token !== null) {
            return true;
        }

        $this->lastError = 'Matomo reporting is not configured (host, site_id and a token are required).';

        return false;
    }

    private function token(): string
    {
        return $this->connection->token ?? '';
    }

    private function fail(string $message, ?Throwable $previous = null): null
    {
        $this->lastError = $message;
        $this->reporter->report($previous ?? new ReportRequestException($message), ['stage' => 'reporting']);

        return null;
    }
}
