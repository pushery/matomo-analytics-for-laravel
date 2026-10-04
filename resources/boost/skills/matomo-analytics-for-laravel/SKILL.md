---
name: matomo-analytics-for-laravel
description: >
  Install, configure, and apply the Matomo Analytics for Laravel package in a
  Laravel application — client and server tracking, batching, tracking gates,
  and reading statistics back.
license: MIT
metadata:
  author: pushery
---

# Matomo Analytics for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/matomo-analytics-for-laravel` package. The full reference is at
<https://docs.pushery.com/matomo-analytics-for-laravel/>.

## Primary Goal

Apply the package's public API in the smallest correct way for the consuming
application.

## Workflow

### 1. Install

```bash
composer require pushery/matomo-analytics-for-laravel
php artisan matomo:install
```

The service provider is registered automatically through package discovery.

### 2. Configure

Three environment variables are the whole minimum:

```dotenv
MATOMO_ENABLED=true
MATOMO_HOST=https://your-instance.matomo.cloud
MATOMO_SITE_ID=1
```

`MATOMO_ENABLED` is the master switch and it ships **off**: nothing is tracked
until it is true, whatever else is configured. Host and site id are a second
belt — with the switch on but either of them missing, tracking is still a no-op.
So the package stays inert in local and CI environments on its own, and you
should not add conditionals around it for that. Verify a real connection with
`php artisan matomo:test`, which names whichever of the three is holding it back.

Publish everything at once, or one part of it:

```bash
php artisan vendor:publish --tag="matomo-analytics"             # all of the below
php artisan vendor:publish --tag="matomo-analytics-config"      # config/matomo-analytics.php
php artisan vendor:publish --tag="matomo-analytics-migrations"  # the batch buffer and dead-letter tables
php artisan vendor:publish --tag="matomo-analytics-views"       # the privacy-policy view
php artisan vendor:publish --tag="matomo-analytics-lang"        # the translations
```

Every option in `config/matomo-analytics.php` is documented inline.

### 3. Apply the package

**Server-side, explicit.** One facade covers every hit type:

```php
use MatomoAnalytics\Facades\Matomo;

Matomo::pageView('Checkout');
Matomo::event('Checkout', 'completed', name: 'standard', value: 49.90);
Matomo::goal(3, revenue: 49.90);
Matomo::ecommerceOrder('ORD-1', grandTotal: 49.90, items: $items);
```

**Server-side, automatic.** Attach the middleware instead of calling per route:

```php
// bootstrap/app.php
$middleware->appendToGroup('web', \MatomoAnalytics\Http\Middleware\TrackPageViews::class);
```

or set `middleware.auto` in the config so the package registers it itself.

**Client-side.** One Blade directive renders the cookieless `_paq` snippet:

```blade
<head>
    @matomoScript
</head>
```

Set `spa.enabled` when the app uses Livewire, Inertia, or any History-based
router, so soft navigations become virtual page views.

**Browser hits without `matomo.js`.** A page that tracks server-side only can still
send what the browser sees: set `hit_beacon.enabled`, place `@matomoHitBeacon`, and
call `matomoHit('event', { category: 'Docs', action: 'copy' })`, or the types
`outlink`, `download`, `search` and `ping`. Do not build a route of your own for this;
the package's route checks the origin, bounds the fields and applies the gate.

### 4. Choose a transmission mode

`mode` (env `MATOMO_MODE`) decides when hits leave the process, and it is the one
setting worth thinking about:

- `queue` (default) — the request's hits go out as one Bulk request after the
  response. Needs a queue worker.
- `batch` — hits buffer across requests in a `database`, `redis`, or `file`
  buffer and flush in large batches via `matomo:flush` or `matomo:work`.
  The `database` driver's migrations are registered automatically, so
  `php artisan migrate` creates the tables — publish them only if you want to own
  them, and then call `MatomoAnalyticsServiceProvider::ignoreMigrations()` from a
  provider's `register()` so they are not registered twice.
- `sync` — inline, for tests and low-volume apps.

None of them let a Matomo outage surface in the application: delivery failures
are swallowed and reported, never rethrown into the request.

### 5. Read data back

```php
use MatomoAnalytics\Facades\MatomoReports;

$visits = MatomoReports::visitsSummary(['period' => 'day', 'date' => 'today']);
```

Reading requires `MATOMO_TOKEN`. Tracking works without it, but only a token lets
the package send the visitor's IP address (`cip`); without one, Matomo records the
address of your server for every server-side hit.

## Testing in the consuming application

Every facade has a fake, so tracking is asserted rather than mocked:

```php
use MatomoAnalytics\Tracking\Hit;
use MatomoAnalytics\Tracking\PageView;

$fake = Matomo::fake();

// exercise the code under test

$fake->assertTracked(PageView::class, fn (Hit $hit): bool => $hit instanceof PageView && $hit->title === 'Checkout');
```

Type the callback's parameter as `Hit`, not as the class in the first argument.
The callback only ever sees hits of the type you asked for, so a narrower type
looks safe — until a hit is wrapped in `CustomParameters`. A decorated hit matches
by its inner type, which is what makes the assertion still find it, but the callback
is handed the wrapper. `fn (PageView $hit)` is a `TypeError` there. Narrow with
`instanceof` inside the closure instead.

`MatomoReports::fake()`, `MatomoGdpr::fake()`, and `MatomoAnnotations::fake()`
follow the same shape.

## Anti-Patterns

- Do not wrap tracking calls in `if (app()->isProduction())`. The tracking gate
  already covers environments, bots, Do-Not-Track and the opt-out cookie — adding
  a second gate in application code hides why a hit was dropped.
- Do not assume the gate asks for consent. `privacy.consent` only drives the
  JavaScript tracker; for server-side hits, plug your consent check into
  `tracking.gate`, the hook that lets the application refuse a hit.
- Do not call the Matomo HTTP API directly alongside this package. Wrap the hit in
  `CustomParameters::for($hit)->param('_rcn', 'newsletter')` and pass it to
  `Matomo::track()`, so the gate, the URL redaction and the delivery mode still
  apply to it.
- Do not switch to `sync` to "make tracking reliable". It moves a third-party
  HTTP call into the request path; `queue` and `batch` exist precisely so a slow
  Matomo cannot slow the application.
