<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use MatomoAnalytics\Support\Config;

/**
 * Immutable connection details for a Matomo instance. The same shape serves a
 * self-hosted base URL and a Matomo Cloud subdomain — only the host string differs.
 */
final readonly class Connection
{
    public function __construct(
        public string $host,
        public int $siteId,
        public ?string $token,
        public string $trackerPath,
        public int $timeout,
        public int $connectTimeout,
        public string $reportingPath = 'index.php',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            host: rtrim(Config::string('matomo-analytics.host'), '/'),
            siteId: Config::int('matomo-analytics.site_id'),
            token: Config::nullableString('matomo-analytics.token'),
            trackerPath: Config::string('matomo-analytics.tracker_path', 'matomo.php'),
            timeout: Config::int('matomo-analytics.timeout', 5),
            connectTimeout: Config::int('matomo-analytics.resilience.connect_timeout', 2),
            reportingPath: Config::string('matomo-analytics.reporting.path', 'index.php'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->siteId > 0;
    }

    public function trackingUrl(): string
    {
        return $this->host.'/'.ltrim($this->trackerPath, '/');
    }

    public function reportingUrl(): string
    {
        return $this->host.'/'.ltrim($this->reportingPath, '/');
    }
}
