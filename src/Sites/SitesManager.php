<?php

declare(strict_types=1);

namespace MatomoAnalytics\Sites;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\SitesClient;
use MatomoAnalytics\Exceptions\ReportRequestException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * Creates, changes and deletes sites through Matomo's SitesManager API. Same token-safe POST
 * transport as the reporting, GDPR and annotation clients (token_auth in the body, forced
 * HTTP/1.1, no redirects, {result: error} detection) and never cached. A failure goes through the
 * resilience Reporter and lastError(). Bound scoped because lastError() is per-request state.
 */
final class SitesManager implements SitesClient
{
    private ?string $lastError = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Reporter $reporter,
    ) {}

    public function create(string $name, array $urls): ?int
    {
        $decoded = $this->call(['method' => 'SitesManager.addSite', 'siteName' => $name, 'urls' => $urls]);

        if ($decoded === null) {
            return null;
        }

        // Matomo answers the new id as {"value": 7}, a number or the string of one.
        $value = $decoded['value'] ?? null;
        $id = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);

        return $id > 0 ? $id : $this->fail('Matomo sites API answered SitesManager.addSite without the id of a site.');
    }

    public function update(int $siteId, ?string $name = null, ?array $urls = null): bool
    {
        $params = ['method' => 'SitesManager.updateSite', 'idSite' => $this->site($siteId)];

        if ($name !== null) {
            $params['siteName'] = $name;
        }

        if ($urls !== null) {
            $params['urls'] = $urls;
        }

        return $this->call($params) !== null;
    }

    public function delete(int $siteId): bool
    {
        return $this->call(['method' => 'SitesManager.deleteSite', 'idSite' => $this->site($siteId)]) !== null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function site(int $siteId): int
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException("A Matomo site id is 1 or more, and {$siteId} was given.");
        }

        return $siteId;
    }

    /**
     * @param  array<string, scalar|list<string>>  $params
     * @return array<array-key, mixed>|null
     */
    private function call(array $params): ?array
    {
        if (! $this->connection->isConfigured() || $this->connection->token === null) {
            $this->lastError = 'Matomo sites are not configured (host, site_id and a token are required).';

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
            return $this->fail('Matomo sites API returned HTTP '.$response->status());
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->fail('Matomo sites API returned a non-JSON response.');
        }

        if (($decoded['result'] ?? null) === 'error') {
            $message = $decoded['message'] ?? null;

            return $this->fail(
                'Matomo sites API error: '.(is_string($message) ? $message : 'unknown error'),
                ReportRequestException::errorAnswer('sites', $params['method'] ?? null),
            );
        }

        $this->lastError = null;

        return $decoded;
    }

    private function request(): PendingRequest
    {
        return Http::asForm()
            ->timeout(Config::int('matomo-analytics.reporting.timeout', 10))
            ->withOptions(['version' => 1.1])
            // Same reason as `HttpSender::request()`: a 307/308 replays the body, and the body
            // carries `token_auth`. Refused rather than sanitized.
            ->withoutRedirecting();
    }

    private function fail(string $message, ?Throwable $previous = null): null
    {
        $this->lastError = $message;
        $this->reporter->report($previous ?? new ReportRequestException($message), ['stage' => 'sites']);

        return null;
    }
}
