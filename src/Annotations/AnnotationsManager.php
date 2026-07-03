<?php

declare(strict_types=1);

namespace MatomoAnalytics\Annotations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MatomoAnalytics\Annotations\Concerns\AnnotatesReleases;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\AnnotationsClient;
use MatomoAnalytics\Exceptions\ReportRequestException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * Writes annotations to Matomo's free Annotations plugin. Same token-safe POST
 * transport as the reporting/GDPR clients (token_auth in the body, forced
 * HTTP/1.1, {result: error} detection) but never cached. Routed through the
 * resilience Reporter so a failed annotation never breaks a deploy. Bound scoped
 * because lastError() is per-request state (Octane-safe).
 */
final class AnnotationsManager implements AnnotationsClient
{
    use AnnotatesReleases;

    private ?string $lastError = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Reporter $reporter,
    ) {}

    public function add(string $note, ?string $date = null, bool $starred = false, int|string|null $site = null): ?array
    {
        return $this->call([
            'method' => 'Annotations.add',
            'idSite' => $site ?? $this->connection->siteId,
            'date' => $date ?? gmdate('Y-m-d'),
            'note' => $note,
            'starred' => $starred ? 1 : 0,
        ]);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<array-key, mixed>|null
     */
    private function call(array $params): ?array
    {
        if (! $this->connection->isConfigured() || $this->connection->token === null) {
            $this->lastError = 'Matomo annotations are not configured (host, site_id and a token are required).';

            return null;
        }

        $body = array_merge($params, [
            'module' => 'API',
            'format' => 'json',
            'token_auth' => $this->connection->token,
        ]);

        try {
            $response = $this->request()->post($this->connection->reportingUrl(), $body);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage(), $e);
        }

        if (! $response->successful()) {
            return $this->fail('Matomo annotations API returned HTTP '.$response->status());
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->fail('Matomo annotations API returned a non-JSON response.');
        }

        if (($decoded['result'] ?? null) === 'error') {
            $message = $decoded['message'] ?? null;

            return $this->fail('Matomo annotations API error: '.(is_string($message) ? $message : 'unknown error'));
        }

        $this->lastError = null;

        return $decoded;
    }

    private function request(): PendingRequest
    {
        return Http::asForm()
            ->timeout(Config::int('matomo-analytics.reporting.timeout', 10))
            ->withOptions(['version' => 1.1]);
    }

    private function fail(string $message, ?Throwable $previous = null): null
    {
        $this->lastError = $message;
        $this->reporter->report($previous ?? new ReportRequestException($message), ['stage' => 'annotations']);

        return null;
    }
}
