# Changelog

All notable changes to `pushery/matomo-analytics-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.17.0] - 2026-07-27

**This release changes the page-view numbers a tracked site reports — they go down.** It adds
no feature and removes no option, and it reaches you only if you set `spa.enabled` to `true`
(off by default). What it corrects is a miscount: soft-navigation tracking recorded a second
page view on every *full* page load of a Livewire application. The counts you were seeing were
inflated; the lower ones after this upgrade are the correct ones. Visits, visitors and every
unique-based metric are unaffected. It is a minor rather than a patch for exactly that reason —
the numbers move, so someone should read this before it lands. Details, including the one case
that genuinely loses a page view, are in
<https://docs.pushery.com/matomo-analytics-for-laravel/guides/upgrading>.

### Fixed

- **SPA tracking counted every full page load twice in a Livewire application.** Livewire's
  navigation plugin ends its start-up by firing its `navigated` event once unconditionally,
  and that reaches the page as `livewire:navigated` — on every **hard** load, at the URL the
  snippet has just recorded a page view for, and whether or not the application uses
  `wire:navigate` anywhere. The `livewire` adapter listened for that event and recorded a
  second, virtual page view for it. Adapters now record only when the URL actually changes.
  **Expect the page-view count of an affected site to fall**: the numbers it was reporting
  were inflated, and this is the correction, not a loss of data. `window.matomoTrackPageView()`
  is deliberately unaffected and still records at an unchanged URL, which is what its
  documented uses (a tab switch, a modal route) need. The same rule makes `livewire` and
  `generic` safe to enable together — one navigation is no longer recorded by both.
- **The first virtual page view of a visit reported no referrer.** It was sent empty, so
  Matomo read the first soft navigation as a direct entry and every flow report started one
  step late. It now carries the URL the browser loaded normally.

### Changed

- The shipped configuration no longer describes `wire:navigate` as WireKit's directive. It is
  Livewire's; WireKit is a component library that requires Livewire, which is the actual —
  and checkable — reason the `livewire` adapter covers a WireKit application.

## [0.16.0] - 2026-07-26

**This release changes three shipped defaults.** Each change makes the quiet option the safe
one, and each can break an existing setup in exactly one direction: tracking stops. Nothing
starts collecting more than it did before. If you published `config/matomo-analytics.php`,
your file's values win and none of it reaches you — see
<https://docs.pushery.com/matomo-analytics-for-laravel/guides/upgrading>.

### Changed

- **BREAKING — the package now ships dormant.** `enabled` defaults to `false`, so installing
  it tracks nobody until you set `MATOMO_ENABLED=true`. Previously the default was `true` and
  dormancy depended on `host`/`site_id` happening to be unset, which is not the same promise:
  it made tracking start as a side effect of configuration rather than as a decision. If you
  are upgrading and want tracking to continue, set `MATOMO_ENABLED=true`.
- **BREAKING — `MATOMO_URL` is no longer read.** `host` was `env('MATOMO_HOST', env('MATOMO_URL'))`,
  falling back to a key this package does not own. An application that already had its own
  Matomo integration reading `MATOMO_URL` was therefore activating this package by configuring
  that one — and, together with the default above, could start tracking every visitor
  (including those who had refused consent in the application's own gate) after a routine
  `.env` edit. Only `MATOMO_HOST` is read now. Reported from a real consumer integration.
- **BREAKING — safer privacy defaults.** `anonymize_ip` now defaults to `true`, and
  `visitor.user_id` to `null` instead of `'auth'`. Both are still fully configurable; what
  changed is which one you have to ask for. Attaching an authenticated user id to every hit,
  and storing full IP addresses, are deliberate choices rather than starting points.
- `matomo:install` now names `MATOMO_ENABLED` first — following its old hint configured a
  package that then stayed silent, with nothing explaining why. `matomo:test` warns when
  tracking is disabled and probes the connection anyway, since knowing the credentials work is
  exactly what you want before flipping the switch.

### Added

- **The privacy-policy partial is translated into all seven shipped locales** (de, en, es, fr,
  it, nl, pt). It was hardcoded English, so a non-English site published an English privacy
  paragraph onto a public page — the one piece of user-facing prose this package renders. The
  text now lives in `lang/<locale>/messages.php` and is published with the
  `matomo-analytics-lang` tag; `$heading` still overrides just the heading.

### Notes

- The privacy-policy partial asserts that no consent banner is required. That holds for the
  SHIPPED configuration — cookieless, anonymised IPs, no user id, nothing shared with third
  parties. Once you publish the lang files the text is yours: if you relax one of those
  settings, change the sentence that depends on it.
- **If your application has its own consent layer, wire it into `tracking.gate`** rather than
  gating this package from outside. It takes an invokable class-string or a closure
  `fn(Request, $hit): ?bool`, is consulted before every hit, and overrides the built-in rules.
  It is not new — it is now documented as the seam it is rather than as one setting among
  several.

## [0.15.0] - 2026-07-26

### Added

- **An umbrella publish tag.** `php artisan vendor:publish --tag=matomo-analytics` now
  publishes every resource group at once — config, migrations, views and translations —
  instead of requiring four separate invocations. Each group keeps its specific tag
  (`matomo-analytics-config`, `-migrations`, `-views`, `-lang`), so nothing that worked
  before changes.
- **A bundled Laravel Boost skill** at `resources/boost/skills/matomo-analytics-for-laravel/SKILL.md`.
  Boost surfaces it inside applications that install this package, so an assistant
  working in a consuming app gets the package's real integration guidance — the facade
  entry points, the transmission modes, the testing fakes and the anti-patterns —
  instead of inferring an API.
- Translations are prepared for seven locales (de, en, es, fr, it, nl, pt). The files
  are in place and published under the `matomo-analytics-lang` tag; the package's one
  translatable string (the privacy-policy partial) is not extracted yet.
- A Laravel-versions badge in the README, derived from the package's own constraint
  rather than hand-maintained.
- The release now generates a lean-dist public `.gitattributes` that `export-ignore`s
  the repo meta (`art/`, the CHANGELOG, CONTRIBUTING, and `.github`). The installed
  Composer package is therefore byte-for-byte as lean as before while those files
  remain visible in the public repository. Previously this package generated no public
  `.gitattributes`, so the dist carried them.

### Removed

- The `bots.record_ai_dimension` configuration key. It was published in the config file
  but never read anywhere in the package, so setting it had no effect. Removing it changes
  no behavior; if your published `config/matomo-analytics.php` still carries the line, it
  can be deleted.

### Changed

- **Published migrations now sort after your own.** They are published through
  `publishesMigrations()`, which rewrites the bundled `0001_01_01_00000N` ordering
  prefix to the publish date. Previously the bundled prefix was copied verbatim, so a
  published migration sorted before every migration the application already had. If
  you published the migrations before, the existing files are untouched — this affects
  only migrations published from now on.
- The configuration file no longer describes `batch` mode as unfinished. It said the
  mode "arrives in a later release; currently behaves as 'queue'", which stopped being
  true when the cross-request buffer shipped: `batch` has working `database`, `redis`,
  `file` and `array` drivers, flushed by `matomo:flush` / `matomo:work`.
- `CONTRIBUTING.md` no longer tells readers of the published package to run gate
  commands whose inputs are not part of it.
- **Documentation moved to <https://docs.pushery.com/matomo-analytics-for-laravel/>.**
  The README carried the entire manual; it is now a showcase that links to the
  documentation site, where the same material is structured as a browsable,
  searchable set of pages — installation, configuration, tracking, delivery, privacy
  and gating, reporting, guides and a full reference for every config key, command,
  event, contract and database table. Nothing was dropped: the pages cover more than
  the README did, including several settings and extension points it never mentioned.

## [0.14.3] - 2026-07-07

### Fixed

- `SendHitsJob::retryUntil()` no longer fatals host applications that run
  `Date::use(CarbonImmutable::class)`: the return type is now
  `DateTimeInterface` (the queue contract's shape) instead of the concrete
  `Illuminate\Support\Carbon`, which rejected the `CarbonImmutable` that
  `now()` returns there and failed every dispatched hit with a `TypeError`.

## [0.14.2] - 2026-07-05

### Changed

- The database batch buffer is now verified against real MySQL 8.4 and PostgreSQL — the engines
  Laravel Cloud runs — in addition to SQLite, so buffered hit delivery is proven on the database
  you deploy to.

## [0.14.1] - 2026-07-04

### Documentation

- Clarified the release-annotation docs: `config('app.version')` is not defined by stock
  Laravel, so `matomo:annotate --release` needs an explicit `--app-version` (or an app
  version key) to include the version in the marker.

## [0.14.0] - 2026-07-03

### Added

- **Load simulator.** `matomo:load-sim` fires N synthetic hits through the real build → buffer →
  flush pipeline and reports enqueue/flush throughput, the exact Bulk POST count and peak memory —
  an operator tool for sizing a deployment. Defaults to a fake sink (`NullSender`, nothing reaches
  Matomo); `--against=real` exercises the configured instance. `--hits`, `--driver`, `--batch`.
- **Worker recycling.** `matomo:work` gained `--max-time` and `--memory` (MB) so a supervisor can
  recycle a long-running drainer before it grows unbounded, mirroring `queue:work`.

### Changed

- **Bounded-memory file spool.** The `file` buffer driver now streams reads line by line, so
  counting or claiming never loads the whole spool into memory — only the claimed batch is held.

### Performance

- New gated `tests/Performance` budget suite (run with `composer test:performance`; excluded from
  the default gate) asserts a large spool drains fully, coalesces into exactly `ceil(N/size)` bulk
  POSTs, and stays within a memory budget.

## [0.13.0] - 2026-07-03

### Added

- **Release annotations.** Post notes to Matomo's free Annotations plugin — most usefully a
  deploy marker on your reports timeline. `MatomoAnnotations::add()` / `annotateRelease()`, the
  `matomo:annotate` command (with `--release`, gated by the `annotations.release` config so it's
  safe to run on every deploy), a `MatomoAnnotations::fake()` test double, and an `annotations`
  config block. Routed through the resilience reporter, so a failed annotation never breaks a deploy.

### Config

- New env key `MATOMO_ANNOTATE_RELEASES` (default `false`) enables `matomo:annotate --release`.

## [0.12.0] - 2026-07-03

### Added

- **Fluent report queries.** `MatomoReports::query('Module.method')` returns a builder for
  segments and the standard Matomo report filters — `->segment()`, `->sortBy()`, `->limit()`,
  `->offset()`, `->search()`, `->truncate()`, `->flat()`, `->expanded()`, `->showColumns()`,
  `->hideColumns()`, `->params()` — then `->get()` runs it through the same cache and resilience
  path as `get()`.
- **Segments.** A `Segment` builder composes Matomo segment definitions
  (`Segment::where('deviceType', '==', 'smartphone')->andWhere('visitCount', '>', 1)`), and a
  named-segment registry (`reporting.segments`) lets you reference saved segments by key.
- **Premium-plugin report adapters.** Thin, gracefully-degrading read helpers for licensed
  Matomo plugins: `abTests`, `funnelFlow`, `forms`, `media`, `cohorts`, `usersFlow` (each
  returns `null` when the plugin isn't installed).

## [0.11.0] - 2026-07-03

### Added

- **Custom Dimensions.** Attach Matomo Custom Dimensions to any hit. Client-side, map
  `js.custom_dimensions` (dimension id => value) to emit `setCustomDimension` on every
  page view (re-applied on SPA soft navigations). Server-side, decorate any hit with the
  new `CustomParameters` helper: `CustomParameters::for($hit)->dimension(1, 'plan:pro')`.
- **Raw parameter escape hatch.** `CustomParameters::param($key, $value)` sets any raw
  Tracking-API parameter the typed hits don't model (e.g. campaign `_rcn`/`_rck`), so you
  never have to drop down to a manual request.
- **Content Tracking.** Record content impressions and interactions. Client-side,
  `js.content_tracking` (`'all'` or `'visible'`) turns on automatic impression tracking
  (also re-scanned on SPA navigations). Server-side, `Matomo::contentImpression()` and
  `Matomo::contentInteraction()` record them explicitly.
- **Reporting helpers** for the new reports: `MatomoReports::customDimension($idDimension)`,
  `contentNames()` and `contentPieces()`.

## [0.10.0] - 2026-07-03

### Added

- **Page-performance control (Matomo's "Page Performance" report).** Matomo's page-performance metrics
  (network, server, transfer, DOM-processing, DOM-completion and on-load times) are collected
  automatically by the tracker on real page loads. Three new options give you control over them:
  - `js.performance` (default `true`) — set to `false` to stop the tracker from collecting page
    performance (emits `disablePerformanceTracking`).
  - `spa.performance` (default `true`) — on single-page/soft navigations the browser reports no new
    timings, so those rows stay empty. When your app measures them, expose them as
    `window.__matomoPerf = { net, srv, tfr, dm1, dm2, onl }` (milliseconds) and the SPA tracker forwards
    them for the next virtual page view. Harmless no-op until you populate that object (needs Matomo 4.5+).
  - `middleware.performance` (default `false`) — the page-view middleware stamps the server generation
    time (`pf_srv`) from the Laravel request duration, useful when tracking purely server-side.
- **AI-chatbot telemetry — self-hosted, without a Cloudflare Worker.** When an AI assistant fetches a
  page on a user's behalf it runs no JavaScript, so Matomo can only capture it server-side; Matomo's own
  integration for this is a Cloudflare Worker. This package can now send the same telemetry itself, at no
  edge cost:
  - `ai_chatbots.track` (default `false`) turns it on. Enable the `matomo.chatbots` middleware (or set
    `ai_chatbots.auto`) and incoming AI-assistant fetches are recorded as Matomo bot telemetry (`recMode`)
    — kept out of your human analytics and never creating a visit.
  - The recognised fetchers default to the narrow on-demand set Matomo surfaces (override via
    `ai_chatbots.user_agents`); `rec_mode` and `source` are configurable. Requires Matomo 5.8+.
  - `Matomo::aiChatbot($request)` records a fetch manually.
- **AI Assistants acquisition report.** Matomo's "AI Assistants" acquisition channel (human visits
  referred from AI assistants, Matomo 5.5+) is derived from the visit referrer, which the package already
  forwards — so it populates with no configuration. A guard now locks in that URL redaction never strips
  the referrer host this attribution depends on.

## [0.9.1] - 2026-07-01

### Changed

- The security policy now documents how dependencies are kept current — automated update pull requests
  paired with advisory alerts, each reviewed before it is merged.

## [0.9.0] - 2026-06-25

### Changed

- The package now supports **Laravel 12** alongside Laravel 13 — the minimum was lowered from `^13.0`
  to `^12.0 || ^13.0` (and `orchestra/testbench` to `^10.0 || ^11.0`). PHP stays at `^8.4`. Both
  Laravel lines are verified on PHP 8.4 and 8.5, so the support claim covers the whole matrix
  rather than only the newest combination.

## [0.8.1] - 2026-06-25

### Changed

- Refreshed the AI-crawler list with Cloudflare Radar's AI categories (AI_CRAWLER / AI_ASSISTANT /
  AI_SEARCH) on top of ai.robots.txt — adds Browserbase, KimiBot, Brandwatch, Claude, Element451Bot,
  AwarioSmartBot and more (150 tokens). The sync filters Radar to the AI categories client-side and
  drops substring-unsafe / non-AI entries.

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
