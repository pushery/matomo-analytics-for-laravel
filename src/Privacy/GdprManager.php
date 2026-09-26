<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\GdprClient;
use MatomoAnalytics\Events\DataSubjectForgotten;
use MatomoAnalytics\Exceptions\ReportRequestException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * GDPR data-subject tools over Matomo's PrivacyManager API. Same token-safe POST
 * transport as the reporting client (token_auth in the body, forced HTTP/1.1,
 * {result: error} detection) but never cached — these are live, destructive admin
 * operations. The configured token must have admin access. Bound scoped because
 * lastError() is per-request state (Octane-safe).
 */
final class GdprManager implements GdprClient
{
    private ?string $lastError = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Reporter $reporter,
    ) {}

    public function findDataSubjects(string $segment, int|string|null $site = null): ?array
    {
        $result = $this->call([
            'method' => 'PrivacyManager.findDataSubjects',
            'idSite' => $site ?? $this->connection->siteId,
            'segment' => $segment,
        ]);

        if ($result === null) {
            return null;
        }

        return array_values(array_filter($result, is_array(...)));
    }

    /**
     * @return array<string, bool|int>|null Matomo's counts, plus `local_buffer`,
     *                                      `local_dead_letters`, `local_segment_understood`,
     *                                      `local_buffer_searched` and `local_queue_searched`
     */
    public function forget(string $segment, int|string|null $site = null): ?array
    {
        // Matomo is not the only place this person's hits are: the buffer holds built payloads
        // and the dead letters hold whole batches for up to thirty days, `cip`, `ua`, `url`,
        // `urlref` and `uid` included, in the application's own stores. They are reported under
        // their own keys rather than folded into Matomo's counts, because the two are different
        // systems, and `local_segment_understood` says whether this half could run at all.
        //
        // The local half runs first. A flush that ran between the two halves would otherwise
        // deliver the person's buffered hits to Matomo after Matomo had erased them. Erased here
        // first, they cannot be delivered, and whatever a flush had already claimed reaches
        // Matomo before the lookup below, which then finds and erases it with the rest.
        $local = (new LocalHitPurge)->forget($segment);

        $visits = $this->descriptorsFor($segment, $site);

        if (! is_array($visits)) {
            return null;
        }

        $counts = $this->deleteVisits($visits);

        if ($counts === null) {
            return null;
        }

        return array_merge($counts, [
            'local_buffer' => $local['buffer'],
            'local_dead_letters' => $local['dead_letters'],
            'local_segment_understood' => $local['matched'],
            'local_buffer_searched' => $local['buffer_searched'],
            'local_queue_searched' => $local['queue_searched'],
        ]);
    }

    public function export(string $segment, int|string|null $site = null): ?array
    {
        $visits = $this->descriptorsFor($segment, $site);

        return is_array($visits) ? $this->exportVisits($visits) : null;
    }

    public function deleteVisits(array $visits): ?array
    {
        if ($visits === []) {
            return [];
        }

        $result = $this->call(['method' => 'PrivacyManager.deleteDataSubjects', 'visits' => $visits]);
        if ($result === null) {
            return null;
        }

        $counts = $this->intCounts($result);
        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new DataSubjectForgotten(count($visits), $counts));
        }

        return $counts;
    }

    public function exportVisits(array $visits): ?array
    {
        if ($visits === []) {
            return [];
        }

        return $this->call(['method' => 'PrivacyManager.exportDataSubjects', 'visits' => $visits]);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Find the data subject and reduce the matching rows to {idsite, idvisit}
     * descriptors. Returns null on a failed lookup, [] when nothing matched.
     *
     * The two ends of the API spell the keys differently: `findDataSubjects` answers with
     * Matomo's visit columns, `idSite` and `idVisit`, and `deleteDataSubjects` and
     * `exportDataSubjects` take descriptors keyed `idsite` and `idvisit`. Both spellings are
     * read here, so a row is never dropped for its casing.
     *
     * @return list<array{idsite: int, idvisit: int}>|null
     */
    private function descriptorsFor(string $segment, int|string|null $site): ?array
    {
        $found = $this->findDataSubjects($segment, $site);
        if ($found === null) {
            return null;
        }

        $visits = [];
        foreach ($found as $row) {
            $idSite = $row['idSite'] ?? $row['idsite'] ?? null;
            $idVisit = $row['idVisit'] ?? $row['idvisit'] ?? null;

            if (is_numeric($idSite) && is_numeric($idVisit)) {
                $visits[] = ['idsite' => (int) $idSite, 'idvisit' => (int) $idVisit];
            }
        }

        return $visits;
    }

    /**
     * @param  array<array-key, mixed>  $result
     * @return array<string, int>
     */
    private function intCounts(array $result): array
    {
        $counts = [];
        foreach ($result as $key => $value) {
            if (is_numeric($value)) {
                $counts[(string) $key] = (int) $value;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<array-key, mixed>|null
     */
    private function call(array $params): ?array
    {
        if (! $this->connection->isConfigured() || $this->connection->token === null) {
            $this->lastError = 'Matomo GDPR tools are not configured (host, site_id and an admin token are required).';

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
            return $this->fail('Matomo GDPR API returned HTTP '.$response->status());
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->fail('Matomo GDPR API returned a non-JSON response.');
        }

        if (($decoded['result'] ?? null) === 'error') {
            $message = $decoded['message'] ?? null;

            return $this->fail('Matomo GDPR API error: '.(is_string($message) ? $message : 'unknown error'));
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
        $this->reporter->report($previous ?? new ReportRequestException($message), ['stage' => 'gdpr']);

        return null;
    }
}
