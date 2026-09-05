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
        public bool $requireTls = false,
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
            requireTls: Config::bool('matomo-analytics.require_tls', false),
        );
    }

    public function isConfigured(): bool
    {
        if ($this->host === '' || $this->siteId <= 0) {
            return false;
        }

        return ! $this->requireTls || $this->hostIsEncrypted();
    }

    /**
     * Whether the configured host would carry `token_auth` over TLS.
     *
     * Kept separate from isConfigured() so a caller can tell the two refusals apart. They
     * need opposite reactions -- a missing host is something you have not done yet, a
     * plaintext host under require_tls is something you asked the package to refuse -- and
     * a single boolean would send an operator hunting for a host that is already set.
     */
    public function hostIsEncrypted(): bool
    {
        return str_starts_with(strtolower($this->host), 'https://');
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
