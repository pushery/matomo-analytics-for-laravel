<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use Illuminate\Support\Facades\Config as ConfigFacade;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use MatomoAnalytics\Support\Config;
use Throwable;

/**
 * Strips secrets and PII out of URLs before they are sent to Matomo. Sensitive
 * query parameters (api tokens, passwords, signatures, …) keep their key but have
 * their value replaced, and arbitrary regex patterns can scrub anything else. The
 * key segment is preserved so reports still show "a token was present" without
 * leaking its value into analytics, logs, or shared dashboards. Under a base domain
 * the host loses the labels that name a tenant.
 *
 * A pattern that fails on a URL closes rather than opens: `preg_replace()` answers null without a
 * warning when the subject makes it fail, with bytes that are no UTF-8 under `/u` or past a
 * backtracking limit, and the URL then goes out as the replacement alone, because what the pattern
 * had to hide is somewhere in it.
 */
final class UrlRedactor
{
    /**
     * What may stand between a listed name and its `=`: any number of bracket pairs, written out
     * (`[0]`) or percent-encoded (`%5B0%5D`). The snippet hands the same expression to the
     * browser, where JavaScript reads it with the same meaning.
     *
     * @internal
     */
    public const string ARRAY_FORMS = '(?:\[[^\]&#]*\]|%5B(?:(?!%5D)[^&#])*%5D)*';

    /** The paths Laravel's starter kits give the route `password.reset`. */
    private const array RESET_PASSWORD_URIS = ['reset-password/{token}', 'password/reset/{token}'];

    /** A domain name as `privacy.redact.base_domains` holds one: labels of letters, digits and inner hyphens. */
    private const string DOMAIN_NAME = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/';

    public function redact(string $url): string
    {
        if (! Config::bool('matomo-analytics.privacy.redact.enabled', true)) {
            return $url;
        }

        $replacement = Config::string('matomo-analytics.privacy.redact.replacement', 'REDACTED');

        $url = $this->redactHosts($url, $replacement);
        $url = $this->redactQueryParams($url, $replacement);
        $url = $this->redactPaths($url, $replacement);

        return $this->redactPatterns($url, $replacement);
    }

    /**
     * Patterns for the host of a URL under one of the domains in `privacy.redact.base_domains`,
     * where a tenant's name stands in a subdomain. Each captures what comes before the host, the
     * scheme and any credentials, and the base domain itself, and matches every label between
     * them: `northwind.example.test` becomes `REDACTED.example.test`, and the base domain on its
     * own stays. In a syntax PHP and JavaScript read alike, without regard to case, so the
     * browser applies the same list.
     *
     * @return list<string>
     */
    public function hostPatterns(): array
    {
        $patterns = [];

        foreach ($this->baseDomains() as $domain) {
            $patterns[] = '^((?:[a-z][a-z0-9+.-]*:)?//(?:[^/?#@]*@)?)[^/?#@:]+(\.'.str_replace('.', '\.', $domain).')(?=[:/?#]|$)';
        }

        return $patterns;
    }

    /**
     * Patterns for a secret that stands in a path, where no parameter name reaches it: the token
     * of a password-reset link or of a magic link. Each captures the path up to the secret and
     * matches the secret, in a syntax PHP and JavaScript read alike, so the browser applies the
     * same list.
     *
     * Every registered route whose URI names one of the parameters in `privacy.redact.path_params`
     * gives one pattern, `token` by default, so a magic-link route such as
     * `magic-link/verify/{token}` is covered without a pattern of its own. The reset link is built
     * in beside them: the path the application registers for `password.reset` and the two the
     * starter kits use, whatever the list says.
     *
     * @return list<string>
     */
    public function pathPatterns(): array
    {
        $uris = self::RESET_PASSWORD_URIS;

        try {
            $route = Route::getRoutes()->getByName('password.reset');
        } catch (Throwable) {
            $route = null;
        }

        if ($route !== null) {
            array_unshift($uris, $route->uri());
        }

        $candidates = [];

        foreach ($uris as $uri) {
            $candidates[] = $this->tokenPattern($uri, ['token']);
        }

        $params = array_values(array_filter(
            Config::stringList('matomo-analytics.privacy.redact.path_params'),
            static fn (string $param): bool => $param !== '',
        ));

        if ($params !== []) {
            try {
                $routes = Route::getRoutes()->getRoutes();
            } catch (Throwable) {
                $routes = [];
            }

            foreach ($routes as $registered) {
                $candidates[] = $this->tokenPattern($registered->uri(), $params);
            }
        }

        $patterns = [];

        foreach ($candidates as $pattern) {
            if ($pattern !== null && ! in_array($pattern, $patterns, true)) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * The payload with every field `privacy.redact.keys` names redacted.
     *
     * A payload is redacted when it is built. One that waited, in the dead-letter table for days,
     * carries the redaction of the day it was built, and passing it through here again applies
     * today's. A value that is already redacted comes back unchanged.
     *
     * @param  array<string, scalar>  $payload
     * @return array<string, scalar>
     */
    public function redactPayload(array $payload): array
    {
        foreach ($this->keys() as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = $this->redact($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * The payload fields to redact: those `privacy.redact.keys` names, and `_ref` wherever
     * `urlref` is one of them.
     *
     * matomo.js sends the referrer twice. `urlref` is the referrer of the page, and `_ref` the
     * one it keeps in its `_pk_ref` cookie for conversion attribution and adds to every request
     * while the cookie lives. A list that names the referrer means both, so a published config
     * written before `_ref` was known covers it too. The server and the browser read this list.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_values(array_filter(
            Config::stringList('matomo-analytics.privacy.redact.keys'),
            static fn (string $key): bool => $key !== '',
        ));

        if (in_array('urlref', $keys, true) && ! in_array('_ref', $keys, true)) {
            $keys[] = '_ref';
        }

        return $keys;
    }

    /**
     * One pass over the URL, not one per parameter. Close to thirty names ship by default, and a
     * separate `preg_replace_callback` per name would scan each URL a payload carries once
     * for every name.
     *
     * The names go into one alternation instead. Each match is independent of the others, so
     * the combined pattern finds exactly what the sequence of patterns found: `[?&#]` anchors
     * every branch to a parameter boundary, and `[^&#]*` stops at the next one, so no branch
     * can consume the separator another branch needs.
     *
     * The parameters start at the first `?` or `#`, and the fragment is read like the query:
     * OAuth, OpenID Connect and magic-link flows carry their token there
     * (`#access_token=…&token_type=bearer`), and the beacons send the page's whole address.
     * Scheme, host and path are left out of the pattern, so an `&` in a path is never read as a
     * parameter, and most page views have neither character and return at once.
     */
    private function redactQueryParams(string $url, string $replacement): string
    {
        $start = strcspn($url, '?#');

        if ($start === strlen($url)) {
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

        // Match `name=` and the array forms `name[]=`, `name[0]=` and `name[a][b]=`, so a
        // bracketed key does not let the value slip through unredacted. Each bracket may also be
        // percent-encoded: `fullUrl()` rebuilds the query with `http_build_query()`, which writes
        // `name%5B0%5D=`, and a link generated the same way keeps that spelling. The `i` flag
        // covers a lowercase `%5b` as it covers the name.
        $pattern = '/([?&#](?:'.implode('|', $names).')'.self::ARRAY_FORMS.'=)[^&#]*/i';

        $result = preg_replace_callback(
            $pattern,
            /** @param array<int, string> $matches */
            static fn (array $matches): string => $matches[1].rawurlencode($replacement),
            substr($url, $start),
        );

        return is_string($result) ? substr($url, 0, $start).$result : $replacement;
    }

    private function redactPaths(string $url, string $replacement): string
    {
        foreach ($this->pathPatterns() as $pattern) {
            $url = preg_replace('~'.$pattern.'~', '${1}'.rawurlencode($replacement), $url) ?? $replacement;
        }

        return $url;
    }

    /**
     * `(/reset-password/)[^/?#&]+` for `reset-password/{token}`: the path up to the first segment
     * that is one of the parameters given, then that segment. Another parameter before it matches
     * any one segment; null when the URI names none of them.
     *
     * @param  list<string>  $params
     */
    private function tokenPattern(string $uri, array $params): ?string
    {
        $prefix = '/';

        foreach (explode('/', trim($uri, '/')) as $segment) {
            if (preg_match('/^\{([^}?]+)\??\}$/', $segment, $name) === 1 && in_array($name[1], $params, true)) {
                return '('.$prefix.')[^/?#&]+';
            }

            $prefix .= (preg_match('/^\{[^}]*\}$/', $segment) === 1
                ? '[^/?#&]+'
                : (string) preg_replace('#[.*+?^$|(){}\[\]\\\\/~]#', '\\\\$0', $segment)).'/';
        }

        return null;
    }

    private function redactPatterns(string $url, string $replacement): string
    {
        foreach ($this->patterns() as $pattern) {
            $url = preg_replace($pattern, $replacement, $url) ?? $replacement;
        }

        return $url;
    }

    /**
     * The host's patterns, each refused with its own wording when PCRE cannot compile it, so a
     * typo in `privacy.redact.patterns` names itself instead of failing somewhere in a request.
     *
     * @return list<string>
     */
    private function patterns(): array
    {
        $patterns = Config::stringList('matomo-analytics.privacy.redact.patterns');

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new InvalidArgumentException(sprintf(
                    'matomo-analytics.privacy.redact.patterns holds [%s], which is no valid regular expression: %s',
                    $pattern,
                    error_get_last()['message'] ?? preg_last_error_msg(),
                ));
            }
        }

        return $patterns;
    }

    private function redactHosts(string $url, string $replacement): string
    {
        foreach ($this->hostPatterns() as $pattern) {
            $url = preg_replace('~'.$pattern.'~i', '${1}'.rawurlencode($replacement).'${2}', $url) ?? $replacement;
        }

        return $url;
    }

    /**
     * The domains in `privacy.redact.base_domains`, lowercased: a list in a published config, or
     * the comma-separated value of `MATOMO_REDACT_BASE_DOMAINS`. A leading `*.` or `.` is
     * dropped, so `*.example.test` names `example.test`. An entry that is still no domain name is
     * refused with its own wording, as a pattern PCRE cannot compile is.
     *
     * @return list<string>
     */
    private function baseDomains(): array
    {
        $value = ConfigFacade::get('matomo-analytics.privacy.redact.base_domains');
        $entries = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $domains = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $domain = strtolower(trim($entry));
            $domain = str_starts_with($domain, '*.') ? substr($domain, 2) : ltrim($domain, '.');

            if ($domain === '') {
                continue;
            }

            if (preg_match(self::DOMAIN_NAME, $domain) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'matomo-analytics.privacy.redact.base_domains holds [%s], which is no domain name: name the domain alone, as example.test, without a scheme, a port or a path',
                    trim($entry),
                ));
            }

            if (! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }
}
