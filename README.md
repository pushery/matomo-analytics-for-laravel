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
- Laravel 13+

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
`deviceTypes`, `browsers`, `goals`, `eventCategories`. A failed call returns `null`
(never cached, so it retries next time) and is reported through the same throttled
alerting as tracking. Invalidate everything with `MatomoReports::flushCache()`.

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

Bots and AI/LLM crawlers (GPTBot, ClaudeBot, CCBot, PerplexityBot, Bytespider, …)
are detected and **excluded by default**. Configure under `bots` — add an `allow`
or `deny` list, plug in a custom `detector` (e.g. a `matomo/device-detector`
wrapper), or set `track` to `true` to record them.

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
| `matomo:work` | Long-running daemon that continuously drains the batch buffer. |
| `matomo:replay` | Re-queue dead-lettered hits into the buffer (`--list`, `--limit`, `--prune`). |
| `matomo:report` | Fetch a Reporting API method and print the JSON result. |
| `matomo:forget` | Erase or export a data subject's data for GDPR requests (`--force`, `--export`, `--site`). |

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
