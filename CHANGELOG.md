# Changelog

All notable changes to `pushery/matomo-analytics-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.8.0] - 2026-06-25

### Added

- Optional `matomo/device-detector` backstop: `DeviceDetectorBotDetector` wired through the
  `bots.detector` hook gives exhaustive, upstream-maintained bot detection across every category
  (search, social, SEO/marketing, monitoring, AI, …). Opt in with `composer require matomo/device-detector`.

### Changed

- Expanded the bundled AI-crawler list to 130+ tokens, regenerated from the canonical
  [ai.robots.txt](https://github.com/ai-robots-txt/ai.robots.txt) catalogue (substring-unsafe
  entries filtered out) and kept current by a scheduled sync workflow that opens a review PR.
- Generic bot detection now also catches social link-preview agents (WhatsApp, SkypeUriPreview, vkShare).

## [0.7.0] - 2026-06-25

### Added

- Ecommerce tracking: `Matomo::ecommerceOrder()`, `ecommerceCartUpdate()` and `ecommerceView()`
  (with `EcommerceItem`/`EcommerceOrder`/`EcommerceCartUpdate`/`EcommerceView` value objects) map
  to Matomo's ecommerce parameters — `idgoal=0`, `ec_id`, `revenue`, `ec_st`/`ec_tx`/`ec_sh`/`ec_dt`,
  the `ec_items` JSON array, and the `_pks`/`_pkn`/`_pkc`/`_pkp` product-view params.
- Site-search build-out: `Matomo::searchFromRequest()` and `SiteSearch::fromRequest()` build a
  search straight from request query parameters; a `matomo.search` middleware auto-tracks searches
  on successful GET responses; no-result tracking via `siteSearch(..., count: 0)`.

## [0.6.0] - 2026-06-25

### Added

- Documentation: a "Scaling self-hosted Matomo (QueuedTracking)" guide — how Matomo's
  server-side QueuedTracking plugin composes with the package's batch/queue delivery so a
  busy self-hosted instance answers tracking requests in milliseconds and absorbs spikes.

## [0.5.0] - 2026-06-25

### Fixed

- Laravel Octane: the in-memory `array` batch buffer and the resolved `HitBuffer` are now
  request-scoped, so a long-lived Octane worker no longer carries buffered hits from one
  request into the next. All request-stateful services (tracker, reporting, GDPR, buffer)
  reset between requests; stateless ones stay shared. No change for classic FPM requests.

## [0.4.0] - 2026-06-25

### Added

- SPA / soft-navigation tracking (opt-in `spa.enabled`): the tracker snippet records a
  virtual page view on every client-side navigation that would otherwise be missed.
  Adapters — `livewire` (Livewire/WireKit `wire:navigate`), `inertia` (Inertia.js, covering
  Vue & React), and `generic` (History `pushState` + `popstate`). A `window.matomoTrackPageView()`
  helper is always exposed for manual/custom triggers. Tag Manager is left to handle SPA itself.

## [0.3.0] - 2026-06-25

### Added

- GDPR data-subject tools over Matomo's PrivacyManager API: `MatomoGdpr::forget()`
  erases (and `export()` exports) every visit matching a segment such as
  `userId==alice@example.com`, plus lower-level `findDataSubjects()`/`deleteVisits()`/
  `exportVisits()`. Calls are never cached and require an admin-access token.
- `matomo:forget {segment}` console command — finds the data subject, confirms, then
  erases (`--force` to skip the prompt, `--export` to export instead, `--site` to scope).
- A `DataSubjectForgotten` event (visit count + deletion counts) for audit trails, and a
  `MatomoGdpr::fake()` test double.

### Changed

- composer.json description and keywords now match the package's positioning
  (privacy-first, cookieless, Web Vitals, reporting API, bot detection) for Packagist
  discoverability.

## [0.2.0] - 2026-06-25

### Added

- Optional `js.host` (`MATOMO_JS_HOST`) to load `matomo.js` from a separate asset host
  — e.g. a Matomo Cloud CDN (`https://cdn.matomo.cloud/your-instance.matomo.cloud`) —
  while tracking stays on the main host; the host is also dns-prefetched.
- A dedicated "Matomo Cloud" guide in the README: host setup, the token requirement
  for the real visitor IP / hit time / geolocation, the CDN option, and end-to-end
  verification with `matomo:test` / `matomo:report`.

## [0.1.1] - 2026-06-25

### Fixed

- README: use a resolvable Packagist PHP-version badge
  (`packagist/dependency-v/.../php`); the previous `packagist/php-v` badge rendered
  "not found" on shields.io.

## [0.1.0] - 2026-06-25

First public release.

### Added

#### Tracking (server- and client-side)

- Server-side tracking via the `Matomo` facade: page views, events, site search,
  goals, downloads, outlinks, and pings.
- Cookieless visitor identification (a daily-rotating salted hash), with the real
  client IP and exact hit time forwarded when a token is configured.
- Three transmission modes — `sync`, `queue` (one bulk request per request), and
  `batch` (a cross-request buffer flushed in bulk) — switchable via `MATOMO_MODE`.
- Batch buffer drivers: `database`, `redis`, `file`, and `array`, drained by the
  scheduled `matomo:flush` or the `matomo:work` daemon.
- Automatic page-view middleware (`matomo.track`), with optional registration on the
  `web` group.
- Client-side `@matomoScript` and `@matomoOptOut` Blade directives: cookieless,
  consent modes, Do-Not-Track, heartbeat, a `<noscript>` pixel, a CSP nonce, and an
  optional Matomo Tag Manager container.
- Core Web Vitals (opt-in): a `@matomoWebVitals` directive beacons LCP/CLS/INP (and
  FCP/TTFB) to a server-side ingest route that records each as a Matomo event through
  the normal gate. Uses Google's `web-vitals` library (app-bundled or a configurable
  self-hosted URL); no third-party CDN is loaded by default.

#### Reporting (read side)

- Read-side Reporting API client via the `MatomoReports` facade: `get()` for a single
  method and `bulk()` for `API.getBulkRequest` batching, plus curated helpers
  (`visitsSummary`, `liveCounters`, `lastVisits`, `topPageUrls`, `topPageTitles`,
  `siteSearchKeywords`, `topReferrers`, `referrerTypes`, `countries`, `deviceTypes`,
  `browsers`, `goals`, `eventCategories`).
- Token-safe transport (form-encoded POST with `token_auth` in the body, forced
  HTTP/1.1, `{result: error}` envelope detection via `lastError()`), date-aware caching
  with a store-agnostic versioned `flushCache()` that never caches failures, the
  `matomo:report` command, and a `MatomoReports::fake()` test double.

#### Privacy & GDPR

- Configurable tracking gates (environment, authenticated state, Gate abilities,
  IP/CIDR ranges, route patterns, and a custom callable).
- Bot and AI-crawler detection (a maintained token list, generic signals, allow/deny
  lists, and a pluggable detector); bots are excluded by default.
- URL redaction: secrets and PII are stripped from tracked URLs before they reach
  Matomo (on by default, configurable query parameters and regex patterns).
- Server-side opt-out: the gate honours a first-party opt-out cookie
  (`MatomoAnalytics\Privacy\OptOut::enable()`/`disable()`).

#### Resilience

- Fail-safe delivery: never blocks the response, never throws into the app, with
  durable retries/backoff and throttled alerting that reports only after a configurable
  number of attempts.
- Dead-letter queue: a poison batch (HTTP 4xx) is parked at once and persistently
  failing batches are dead-lettered after `batch.max_attempts`, so one bad batch never
  blocks the queue; `matomo:replay` (`--list`, `--limit`, `--prune`) re-queues them,
  and a `HitsDeadLettered` event is emitted.
- Laravel events: `TrackingQueued`, `TrackingSent`, `TrackingFailed`, and
  `VisitorExcluded`.

#### Compatibility

- Support for both self-hosted Matomo and Matomo Cloud.
- Console commands: `matomo:install`, `matomo:test`, `matomo:flush`, `matomo:work`,
  `matomo:report`, `matomo:replay`.
