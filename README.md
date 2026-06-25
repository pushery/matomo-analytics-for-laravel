<p align="center">
  <a href="https://github.com/pushery/matomo-analytics-for-laravel">
    <img src="art/header.png" alt="Matomo Analytics for Laravel" width="100%">
  </a>
</p>

# Matomo Analytics for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/matomo-analytics-for-laravel.svg)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/pushery/matomo-analytics-for-laravel.svg)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/packagist/l/pushery/matomo-analytics-for-laravel.svg)](LICENSE)

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

## Events

Listen for `TrackingQueued`, `TrackingSent`, `TrackingFailed`, and
`VisitorExcluded` to hook tracking into your own pipelines.

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

## Console commands

| Command | Purpose |
|---|---|
| `matomo:install` | Publish the config and print setup hints. |
| `matomo:test` | Send a test hit and report connectivity. |
| `matomo:flush` | Drain the batch buffer (scheduled automatically in batch mode). |
| `matomo:work` | Long-running daemon that continuously drains the batch buffer. |
| `matomo:replay` | Re-queue dead-lettered hits into the buffer (`--list`, `--limit`, `--prune`). |
| `matomo:report` | Fetch a Reporting API method and print the JSON result. |

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
