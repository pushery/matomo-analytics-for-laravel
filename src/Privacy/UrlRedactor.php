<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use MatomoAnalytics\Support\Config;

/**
 * Strips secrets and PII out of URLs before they are sent to Matomo. Sensitive
 * query parameters (api tokens, passwords, signatures, …) keep their key but have
 * their value replaced, and arbitrary regex patterns can scrub anything else. The
 * key segment is preserved so reports still show "a token was present" without
 * leaking its value into analytics, logs, or shared dashboards.
 */
final class UrlRedactor
{
    public function redact(string $url): string
    {
        if (! Config::bool('matomo-analytics.privacy.redact.enabled', true)) {
            return $url;
        }

        $replacement = Config::string('matomo-analytics.privacy.redact.replacement', 'REDACTED');

        return $this->redactPatterns($this->redactQueryParams($url, $replacement), $replacement);
    }

    private function redactQueryParams(string $url, string $replacement): string
    {
        foreach (Config::stringList('matomo-analytics.privacy.redact.query_params') as $param) {
            $pattern = '/([?&]'.preg_quote($param, '/').'=)[^&#]*/i';

            $result = preg_replace_callback(
                $pattern,
                /** @param array<int, string> $matches */
                static fn (array $matches): string => $matches[1].rawurlencode($replacement),
                $url,
            );

            if (is_string($result)) {
                $url = $result;
            }
        }

        return $url;
    }

    private function redactPatterns(string $url, string $replacement): string
    {
        foreach (Config::stringList('matomo-analytics.privacy.redact.patterns') as $pattern) {
            $result = preg_replace($pattern, $replacement, $url);

            if (is_string($result)) {
                $url = $result;
            }
        }

        return $url;
    }
}
