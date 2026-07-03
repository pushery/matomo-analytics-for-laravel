<p align="center">
  <a href="https://github.com/pushery/matomo-analytics-for-laravel">
    <img src="art/header.png" alt="Matomo Analytics for Laravel" width="100%">
  </a>
</p>

# Matomo Analytics for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/matomo-analytics-for-laravel.svg)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/matomo-analytics-for-laravel/php.svg)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Privacy-first Matomo tracking for Laravel — **client and server**, **single or
batched**, with **AI-bot detection**, **env-driven tracking gates**, and
**fail-safe** delivery that never blocks your app. Works the same for self-hosted
Matomo and Matomo Cloud.

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require pushery/matomo-analytics-for-laravel
php artisan matomo:install
```

The service provider is registered automatically. `matomo:install` publishes
`config/matomo-analytics.php` (every option is documented inline).

Then set your instance in `.env`:

```dotenv
MATOMO_HOST=https://your-instance.matomo.cloud   # or https://analytics.example.com
MATOMO_SITE_ID=1
MATOMO_TOKEN=                                     # optional; see "Server-side identity"
# MATOMO_MODE=batch                              # switch single -> batch transmission
```

Tracking is a no-op until `MATOMO_HOST` and `MATOMO_SITE_ID` are set, so it stays
inert in local and CI environments. Verify connectivity any time:

```bash
php artisan matomo:test
```

## Matomo Cloud

Cloud and self-hosted use the **same code path** — only the host differs. Point
`MATOMO_HOST` at your Cloud subdomain and everything (tracking `…/matomo.php`,
Reporting API `…/index.php`, the JS tracker `…/matomo.js`, opt-out, no-script pixel)
is derived from it:

```dotenv
MATOMO_HOST=https://your-instance.matomo.cloud
MATOMO_SITE_ID=1
MATOMO_TOKEN=your-cloud-auth-token   # Cloud UI → Personal → Security → Auth tokens
```

- **Set a token.** Matomo only honours the real visitor IP (`cip`), exact hit time
  (`cdt`), and geolocation when a `token_auth` is sent — so for correct server-side
  attribution on Cloud, `MATOMO_TOKEN` is effectively required (use a dedicated
  tracking token). Without it, hits are attributed to your app server's IP.
- **Plan limits.** Cloud bills by hits and is more likely to throttle the *Reporting*
  API than tracking. This package already batches/bulk-sends and caches reports with
  date-aware TTLs, so you stay well within limits.
- **Optional CDN for the JS.** Cloud can also serve `matomo.js` from its CDN. Set
  `MATOMO_JS_HOST` to load the asset from there while tracking stays on your subdomain:
  ```dotenv
  MATOMO_JS_HOST=https://cdn.matomo.cloud/your-instance.matomo.cloud
  ```

**Verify Cloud end-to-end** (the definitive check) with the built-in commands:

```bash
php artisan matomo:test                       # sends a real hit, reports the HTTP status
php artisan matomo:report VisitsSummary.get   # confirms the Reporting API against Cloud
```

## Server-side tracking

Track from anywhere via the `Matomo` facade. Hits are gathered during the request
and delivered out of band, so they never block the response.

```php
use MatomoAnalytics\Facades\Matomo;

Matomo::pageView('Pricing');
Matomo::event('Subscription', 'created', 'pro', 49.00);
Matomo::siteSearch('invoices', category: 'docs', count: 7);
Matomo::goal(3, revenue: 49.00);
Matomo::download('https://example.com/whitepaper.pdf');
Matomo::outlink('https://partner.example.com');
Matomo::ping();
```

### Automatic page views

Attach the middleware to track page views without any per-route code:

```php
// routes/web.php
Route::middleware('matomo.track')->group(function () {
    // ...
});
```

Or register it on the whole `web` group by setting `middleware.auto` to `true`.
It records only successful full-page `GET` responses (Livewire updates and
non-2xx responses are skipped) and resolves the page title from the response.

### Server-side identity

When a `MATOMO_TOKEN` (a tracking-scoped `token_auth`) is configured, the package
forwards the real client IP (`cip`) and the exact hit time (`cdt`) — Matomo only
honours those with a token. Visitors are identified cookielessly by default via a
daily-rotating, salted hash; authenticated users are attached as the Matomo
User ID.

## Client-side tracking

Render the JavaScript tracker in your layout:

```blade
<head>
    {{-- ... --}}
    @matomoScript
</head>
```

It emits the cookieless `_paq` snippet (no consent banner required), auto-enables
link tracking and a heart-beat timer, and adds a `<noscript>` fallback pixel. Pass
a Content-Security-Policy nonce when you use one:

```blade
@matomoScript($cspNonce)
```

Set `js.tag_manager` to a container URL to load Matomo Tag Manager instead. Offer
a one-click opt-out anywhere:

```blade
@matomoOptOut
```

### SPA / soft navigation

Single-page navigations never reload the document, so they're invisible to the normal
page view. Enable `spa.enabled` and the tracker records a **virtual page view** on each
client-side navigation. Choose the adapters your app uses:

```php
'spa' => [
    'enabled' => env('MATOMO_SPA', true),
    'adapters' => ['livewire', 'inertia'], // livewire | inertia | generic
],
```

- **`livewire`** — Livewire and [WireKit](https://docs.wirekit.app) `wire:navigate` (listens for `livewire:navigated`).
- **`inertia`** — Inertia.js, covering both Vue and React (listens for `inertia:navigate`).
- **`generic`** — any client-side router, via History `pushState` + `popstate`.

A `window.matomoTrackPageView()` helper is always exposed for manual or custom triggers.
(Matomo Tag Manager handles SPA navigation itself, so this only applies to the direct tracker.)

## Page performance

Matomo's **Page Performance** report (network, server, transfer, DOM-processing, DOM-completion
and on-load times — free, built into Matomo) is filled **automatically** by the tracker on real
page loads; you don't need to do anything. Three options give you control:

```php
'js' => [
    'performance' => true,   // false => stop collecting page performance (disablePerformanceTracking)
],
'spa' => [
    'performance' => true,   // forward app-measured timings on soft navigations (see below)
],
'middleware' => [
    'performance' => false,  // stamp the server generation time (pf_srv) from the request duration
],
```

Soft (single-page) navigations report no browser timings, so those rows stay empty. If your app
measures them, expose the object below **before** the navigation and the SPA tracker forwards it
once (requires Matomo 4.5+):

```js
window.__matomoPerf = { net: 12, srv: 40, tfr: 8, dm1: 60, dm2: 30, onl: 15 }; // milliseconds
```

> This is Matomo's native page-timing report — distinct from **Web Vitals** below, which captures
> Google's Core Web Vitals (LCP/CLS/INP) as Matomo events.

## Web Vitals

Opt in (`web_vitals.enabled`) to capture Core Web Vitals. Drop the directive into your
layout — it beacons LCP/CLS/INP to a server-side route that records each as a Matomo
event through the normal gate:

```blade
@matomoWebVitals
```

It expects Google's [`web-vitals`](https://github.com/GoogleChrome/web-vitals) library on
`window.webVitals` — bundle it with your assets, or point `web_vitals.library` at a
self-hosted copy. No third-party CDN is loaded by default, and the snippet is a clean
no-op if the library isn't present.

## Ecommerce

Track product views, the cart, and completed orders server-side — all fail-safe and
gated like every other hit:

```php
use MatomoAnalytics\Facades\Matomo;
use MatomoAnalytics\Tracking\EcommerceItem;

// A product (or category-only) view
Matomo::ecommerceView(sku: 'TSHIRT-01', name: 'T-Shirt', category: 'Apparel', price: 29.90);

// The cart changed — send its current contents and grand total
Matomo::ecommerceCartUpdate(grandTotal: 59.80, items: [
    new EcommerceItem('TSHIRT-01', 'T-Shirt', 'Apparel', 29.90, quantity: 2),
]);

// A completed order
Matomo::ecommerceOrder(
    orderId: 'ORDER-1001',
    grandTotal: 59.80,
    items: [new EcommerceItem('TSHIRT-01', 'T-Shirt', 'Apparel', 29.90, quantity: 2)],
    subTotal: 50.00, tax: 9.80, shipping: 0.00, discount: 0.00,
);
```

## Site search

Beyond `Matomo::siteSearch($keyword, $category, $count)`, track straight from the request:

```php
Matomo::searchFromRequest(keywordKey: 'q', categoryKey: 'category', count: $results->total());

// No-result searches are valuable — track them with a count of zero
Matomo::siteSearch($keyword, count: 0);
```

Or auto-track every search on a route with the middleware (result count isn't known there):

```php
Route::get('/search', SearchController::class)->middleware('matomo.search:q,category');
```

## Custom dimensions & content tracking

**Custom Dimensions** (free, built into Matomo) attach extra fields to a hit. Set them
per request client-side — the map mirrors the server-side `dimension{N}`:

```php
'js' => [
    'custom_dimensions' => [1 => 'member', 3 => env('APP_ENV')], // setCustomDimension on every page view
],
```

Server-side, decorate any hit with the `CustomParameters` helper. It adds Custom
Dimensions and is also the escape hatch for any raw Tracking-API parameter the typed
hits don't model (campaign `_rcn`/`_rck`, …):

```php
use MatomoAnalytics\Facades\Matomo;
use MatomoAnalytics\Tracking\CustomParameters;
use MatomoAnalytics\Tracking\PageView;

Matomo::track(
    CustomParameters::for(new PageView('Dashboard'))
        ->dimension(1, 'plan:pro')    // dimension1=plan:pro
        ->param('_rcn', 'newsletter') // any raw Tracking-API parameter
);
```

**Content Tracking** (impressions and interactions on content blocks) works both ways.
Turn on automatic client-side impression tracking (blocks marked up with
`data-track-content`):

```php
'js' => [
    'content_tracking' => 'visible', // false | 'all' | 'visible' (only blocks in the viewport)
],
```

…or record impressions and interactions server-side:

```php
Matomo::contentImpression('Promo banner', piece: 'summer.jpg', target: 'https://example.com/sale');
Matomo::contentInteraction('click', 'Promo banner', piece: 'summer.jpg');
```

## Reporting (read side)

Pull statistics back out of Matomo with the `MatomoReports` facade. It reuses your
`host`/`site_id`/`token` (a token with at least view access is required), POSTs the
`token_auth` in the request body (never the query string), caches results with
date-aware TTLs, and surfaces Matomo's error envelope through `lastError()`:

```php
use MatomoAnalytics\Facades\MatomoReports;

$summary = MatomoReports::visitsSummary(['period' => 'day', 'date' => 'today']);
$pages   = MatomoReports::topPageUrls(['period' => 'month', 'date' => '2026-01']);

// Anything not covered by a helper:
$goals = MatomoReports::get('Goals.get', ['period' => 'week', 'date' => 'today']);

// One round-trip for several methods (API.getBulkRequest):
[$visits, $actions] = MatomoReports::bulk([
    'VisitsSummary.get',
    ['method' => 'Actions.get', 'period' => 'week'],
]);

if ($summary === null) {
    report_to_user(MatomoReports::lastError()); // e.g. show a dashboard banner
}
```

Curated helpers: `visitsSummary`, `liveCounters`, `lastVisits`, `topPageUrls`,
`topPageTitles`, `siteSearchKeywords`, `topReferrers`, `referrerTypes`, `countries`,
`deviceTypes`, `browsers`, `goals`, `eventCategories`, `customDimension`, `contentNames`,
`contentPieces`. A failed call returns `null`
(never cached, so it retries next time) and is reported through the same throttled
alerting as tracking. Invalidate everything with `MatomoReports::flushCache()`.

### Fluent queries, segments & filters

Build a filtered, segmented query fluently — it runs through the same cache and
resilience path as `get()`:

```php
use MatomoAnalytics\Facades\MatomoReports;
use MatomoAnalytics\Reporting\Segment;

$pages = MatomoReports::query('Actions.getPageUrls')
    ->period('month')->date('2026-01')
    ->segment('deviceType==smartphone')   // a raw definition, a named segment, or a Segment builder
    ->sortBy('nb_visits')->limit(10)
    ->flat()
    ->get();
```

Register named segments in config and reference them by key, or compose one inline
with the `Segment` builder:

```php
// config/matomo-analytics.php
'reporting' => [
    'segments' => ['engaged' => 'visitCount>1;actions>=3'],
],

// reference the named segment …
MatomoReports::query('VisitsSummary.get')->segment('engaged')->get();

// … or build one fluently
$segment = Segment::where('deviceType', '==', 'smartphone')->andWhere('visitCount', '>', 1);
MatomoReports::query('VisitsSummary.get')->segment($segment)->get();
```

Filters map to Matomo's report parameters: `limit`, `offset`, `sortBy`, `search`,
`truncate`, `flat`, `expanded`, `showColumns`, `hideColumns`, plus `params()` for
anything else.

### Premium plugin reports

If you license Matomo's premium plugins, thin read adapters are provided and degrade
gracefully — they return `null` (surfaced via `lastError()`) when the plugin isn't
installed: `abTests`, `funnelFlow`, `forms`, `media`, `cohorts`, `usersFlow`.

## Transmission modes

Switch with `MATOMO_MODE` — no code changes:

| Mode | Behaviour |
|---|---|
| `queue` (default) | A request's hits are sent as one queued Bulk request on terminate. |
| `sync` | Sent immediately (handy for the CLI, tests, or very low volume). |
| `batch` | Hits are buffered across requests and flushed in large Bulk batches — the most resource-efficient option. |

In `batch` mode hits are stored in a buffer (`database` driver by default) and
drained by a scheduled `matomo:flush` (registered automatically; ensure your
scheduler runs). For the database driver, publish and run the migration:

```bash
php artisan vendor:publish --tag=matomo-analytics-migrations
php artisan migrate
```

The queued worker must serve the `matomo` queue, e.g. `php artisan queue:work --queue=matomo,default`.

### Scaling self-hosted Matomo (QueuedTracking)

The modes above control delivery on **your app's** side. On a busy **self-hosted** Matomo,
also install Matomo's [QueuedTracking](https://github.com/matomo-org/plugin-QueuedTracking)
plugin, which queues incoming hits on the Matomo server (Redis or MySQL) and processes them
with a background worker — so the tracking endpoint answers in milliseconds instead of writing
to the database on the request path. (Matomo Cloud already does this for you.)

The two layers compose and need no extra package configuration:

- `batch` mode here sends fewer, larger **Bulk** requests; QueuedTracking accepts each instantly
  and writes asynchronously — the most efficient combination at high volume.
- Because hits leave your app fast and QueuedTracking absorbs spikes, you get end-to-end
  backpressure without ever blocking a user response.

On the Matomo host, enable it and run its processor (e.g. a `core:archive`-style worker or
`./console queuedtracking:process` on a schedule) per the plugin's docs.

### Where the ceiling really is

At high volume the bottleneck is the **Matomo server** (QueuedTracking does roughly a few
hundred hits/second per worker), not this client. The package's job is only to be
**non-blocking, loss-free, bulk-coalesced and bounded-memory** — and to let you prove it. Buffer
reads stream line by line, so draining a large spool never loads it all into memory; and the
Bulk API coalesces a whole batch into one request. Size a deployment with the built-in simulator:

```bash
php artisan matomo:load-sim --hits=100000 --driver=redis   # discards the sends, measures the client
php artisan matomo:load-sim --hits=10000 --against=real     # end-to-end against your Matomo
```

It reports enqueue/flush throughput, the exact Bulk POST count and peak memory. For a long-running
drainer under a supervisor, recycle the worker on time or memory so a process never grows unbounded:

```bash
php artisan matomo:work --max-time=3600 --memory=256
```

## Who gets tracked

Tracking is governed by a single gate, configured under `tracking`:

```php
'tracking' => [
    'environments' => ['production'],     // restrict to environments (null = all)
    'track_authenticated' => true,        // include logged-in users
    'except_abilities' => ['admin'],      // skip users passing a Gate ability
    'except_ips' => ['10.0.0.0/8'],       // skip IPs / CIDR ranges
    'except_routes' => ['horizon*', 'up'],// skip route/path patterns
    'gate' => null,                       // invokable class-string for full control
],
```

## Bots & AI crawlers

Bots and AI/LLM crawlers (GPTBot, ClaudeBot, PerplexityBot, Bytespider, …) are
detected and **excluded by default**. Detection is layered: an explicit `allow`/`deny`
list, a curated **AI-crawler list** (130+ tokens), generic crawler signals
(`bot`/`crawler`/`spider`/`+http`/… plus social link-preview agents), and an optional
custom `detector`. Set `bots.track` to `true` to record them instead.

The AI-crawler list moves fast, so it is **kept current automatically**: a scheduled
workflow regenerates it from the canonical [`ai.robots.txt`](https://github.com/ai-robots-txt/ai.robots.txt)
catalogue (and Cloudflare Radar when a token is configured) and opens a PR for review.

For exhaustive, always-current coverage of *every* category (search, social,
SEO/marketing, monitoring, …) without bloating the package, opt into the
[`matomo/device-detector`](https://github.com/matomo-org/device-detector) backstop —
the same catalogue Matomo uses server-side:

```bash
composer require matomo/device-detector
```

```php
// config/matomo-analytics.php
'bots' => [
    'detector' => \MatomoAnalytics\Bots\DeviceDetectorBotDetector::class,
],
```

## AI assistants

Matomo splits AI traffic three ways — this package covers all three:

- **AI Assistants (acquisition)** — human visitors *referred from* an AI assistant (Matomo 5.5+). Matomo
  derives this acquisition channel from the visit referrer, which the package already forwards, so the
  report populates **with no configuration**.
- **AI crawlers** — training/indexing bots hitting your site (see above); excluded by default.
- **AI chatbots** — the on-demand fetchers an assistant fires to read a page for a user. They run no
  JavaScript, so Matomo can only see them server-side. Matomo's own collector for this is a Cloudflare
  Worker; this package sends the same telemetry itself, at no edge cost:

```php
'ai_chatbots' => [
    'track' => true,   // off by default
    'auto' => true,    // auto-register the matomo.chatbots middleware on the 'web' group
],
```

Or attach the middleware to specific routes with `->middleware('matomo.chatbots')`, or record a fetch
yourself with `Matomo::aiChatbot($request)`. Each recognised fetch is recorded as Matomo bot telemetry
(`recMode`) — kept separate from your human analytics and never creating a visit. The recognised User-Agents default to the narrow on-demand set Matomo surfaces
(override them, or `rec_mode`/`source`, via config). Requires Matomo 5.8+ for the AI Chatbots report.

## Privacy

Cookieless by default, with `Do-Not-Track`/`Sec-GPC` honoured server-side. Choose
a consent posture under `privacy.consent` (`none`, `cookie`, or `full`) — the
client snippet emits the matching Matomo consent calls. A publishable
privacy-policy partial is included:

```bash
php artisan vendor:publish --tag=matomo-analytics-views
```

**URL redaction** is on by default: secrets and PII are stripped from tracked URLs
before they reach Matomo. Sensitive query parameters keep their key but lose their
value, and regex patterns can scrub anything else — configure under `privacy.redact`:

```
?token=abc123&page=2   ->   ?token=REDACTED&page=2
```

**Server-side opt-out**: the tracking gate honours a first-party opt-out cookie
(Matomo's own opt-out widget sets a cookie on the Matomo domain that server-side
tracking can't see). Wire it to your own control:

```php
use MatomoAnalytics\Privacy\OptOut;

return back()->withCookie(OptOut::enable());   // stop tracking this browser
return back()->withCookie(OptOut::disable());  // opt back in
```

## GDPR data-subject requests

Handle "right to be forgotten" and access requests through Matomo's PrivacyManager
API. Identify a person with a segment (e.g. `userId`, `visitIp`) and erase or export
every matching visit. These operations are **never cached** and need an **admin-access
`token`** (the read/tracking token may not be enough).

```php
use MatomoAnalytics\Facades\MatomoGdpr;

MatomoGdpr::forget('userId==alice@example.com');   // erase; returns deletion counts
MatomoGdpr::export('userId==alice@example.com');   // export the subject's data
MatomoGdpr::findDataSubjects('visitIp==203.0.113.7'); // preview the matching visits
```

Or from the CLI — it previews the match count and asks before deleting:

```bash
php artisan matomo:forget "userId==alice@example.com"          # confirm, then erase
php artisan matomo:forget "userId==alice@example.com" --force  # no prompt
php artisan matomo:forget "userId==alice@example.com" --export # export instead
php artisan matomo:forget "userId==alice@example.com" --site=all
```

A `DataSubjectForgotten` event (visit count + per-area deletion counts) fires on every
erasure, so you can keep an audit trail.

## Release annotations

Mark deployments (or anything else) on your Matomo reports timeline with the free
Annotations plugin — it needs a `token_auth` for a non-anonymous user, and every call
is routed through the same resilience layer as the rest of the package, so a failed
annotation never breaks a deploy:

```php
use MatomoAnalytics\Facades\MatomoAnnotations;

MatomoAnnotations::add('Migrated to Postgres 16', date: '2026-07-03', starred: true);
MatomoAnnotations::annotateRelease('1.4.0'); // "Deployed 1.4.0"
```

From the CLI — drop this straight into your deploy pipeline:

```bash
php artisan matomo:annotate "Maintenance window"
php artisan matomo:annotate --release   # "<prefix> <version>"
```

`matomo:annotate --release` is a **no-op unless you opt in** with `annotations.release`
(`MATOMO_ANNOTATE_RELEASES=true`), so it's safe to run unconditionally on every deploy.
It also needs a `token_auth` (`MATOMO_TOKEN`) for a non-anonymous user.

The version comes from `--app-version`; without it the command falls back to
`config('app.version')` — which stock Laravel does **not** define. So either pass it:

```bash
php artisan matomo:annotate --release --app-version=1.4.0   # posts "Deployed 1.4.0"
```

or expose an app version once (`'version' => env('APP_VERSION')` in `config/app.php`,
plus `APP_VERSION=1.4.0` in `.env`) and plain `--release` picks it up. The note is
`"<annotations.release_prefix> <version>"`; if no version resolves it is just the
prefix (`Deployed`). Star release annotations with `annotations.starred`.

## Fail-safe by design

Tracking never blocks a response and a tracking error never surfaces in your app.
Delivery is durable: queued jobs retry with escalating backoff and land in
`failed_jobs` if exhausted; the batch buffer keeps hits until a confirmed `200`.
Alerts are throttled and raised only after a configurable number of attempts, so a
single timeout never pages your monitoring (`resilience.reporting`).

Nothing gets stuck or lost: a poison batch Matomo permanently rejects (HTTP 4xx) is
moved to a **dead-letter** table at once, and a batch that keeps failing transiently
is dead-lettered after `batch.max_attempts` — so one bad batch never blocks the queue.
Inspect and re-queue the dead-letter with `matomo:replay --list` and `matomo:replay`.

## Laravel Octane

Octane-safe. Every request-stateful service (tracker, reporting, GDPR, the in-memory
buffer) is bound `scoped`, so a long-lived Octane worker resets them between requests and
never leaks one request's state into the next; only stateless services stay shared. This is
covered by tests that exercise Octane's between-request reset directly — no extra setup
needed on your side.

## Events

Listen for `TrackingQueued`, `TrackingSent`, `TrackingFailed`,
`VisitorExcluded`, and `DataSubjectForgotten` to hook tracking into your own pipelines.

## Testing

Swap in a fake and assert what would be tracked:

```php
use MatomoAnalytics\Facades\Matomo;
use MatomoAnalytics\Tracking\PageView;

$fake = Matomo::fake();

$this->get('/pricing');

$fake->assertTracked(PageView::class);
```

For the read side, swap the reporting client and stub responses:

```php
use MatomoAnalytics\Facades\MatomoReports;

$reports = MatomoReports::fake();
$reports->stub('VisitsSummary.get', ['nb_visits' => 42]);

// ... exercise code that calls MatomoReports ...

$reports->assertRequested('VisitsSummary.get');
```

The GDPR tools fake the same way:

```php
use MatomoAnalytics\Facades\MatomoGdpr;

$gdpr = MatomoGdpr::fake()->stubFound([['idsite' => 1, 'idvisit' => 10]]);

// ... exercise code that calls MatomoGdpr::forget() ...

$gdpr->assertForgotten('userId==alice@example.com');
```

## Console commands

| Command | Purpose |
|---|---|
| `matomo:install` | Publish the config and print setup hints. |
| `matomo:test` | Send a test hit and report connectivity. |
| `matomo:flush` | Drain the batch buffer (scheduled automatically in batch mode). |
| `matomo:work` | Long-running daemon that drains the batch buffer (`--max-time`, `--memory` for supervisor recycling). |
| `matomo:load-sim` | Simulate load through the buffer + flush pipeline and report throughput (`--hits`, `--driver`, `--batch`, `--against`). |
| `matomo:replay` | Re-queue dead-lettered hits into the buffer (`--list`, `--limit`, `--prune`). |
| `matomo:report` | Fetch a Reporting API method and print the JSON result. |
| `matomo:forget` | Erase or export a data subject's data for GDPR requests (`--force`, `--export`, `--site`). |
| `matomo:annotate` | Add an annotation, or a deploy marker with `--release`, to the reports timeline. |

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source
Livewire component kit, gives you a polished component library out of the box.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
