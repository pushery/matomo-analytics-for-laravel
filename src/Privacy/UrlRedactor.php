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

    /**
     * ONE PASS OVER THE URL, NOT ONE PER PARAMETER. This ran a separate
     * `preg_replace_callback` for every configured name — two dozen ship by default, so an
     * ordinary hit paid one full scan of its URL per name, and the audit counted 36 passes
     * per hit across the two URLs a payload carries.
     *
     * The names go into one alternation instead. Each match is independent of the others, so
     * the combined pattern finds exactly what the sequence of patterns found: `[?&]` anchors
     * every branch to a parameter boundary, and `[^&#]*` stops at the next one, so no branch
     * can consume the separator another branch needs.
     *
     * The early return is the bigger win in practice: a URL with no `?` cannot match `[?&]`
     * at all, and most page views have no query string.
     */
    private function redactQueryParams(string $url, string $replacement): string
    {
        if (! str_contains($url, '?')) {
            return $url;
        }

        $names = [];

        foreach (Config::stringList('matomo-analytics.privacy.redact.query_params') as $param) {
            if ($param !== '') {
                $names[] = preg_quote($param, '/');
            }
        }

        if ($names === []) {
            return $url;
        }

        // Match `name=` and the array forms `name[]=` / `name[0]=`, so a bracketed
        // key does not let the value slip through unredacted.
        $pattern = '/([?&](?:'.implode('|', $names).')(?:\[[^\]&#]*\])?=)[^&#]*/i';

        $result = preg_replace_callback(
            $pattern,
            /** @param array<int, string> $matches */
            static fn (array $matches): string => $matches[1].rawurlencode($replacement),
            $url,
        );

        return is_string($result) ? $result : $url;
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
