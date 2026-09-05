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
`pushery/matomo-analytics-for-laravel` package. Laravel Boost surfaces it inside
consuming applications, so keep it focused on adoption — never on package
internals.

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

Publish everything at once, or only the configuration file:

```bash
php artisan vendor:publish --tag="matomo-analytics"
php artisan vendor:publish --tag="matomo-analytics-config"
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

Reading requires `MATOMO_TOKEN`; tracking does not.

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
  already covers environments, bots, Do-Not-Track, consent, and opt-out — adding
  a second gate in application code hides why a hit was dropped.
- Do not call the Matomo HTTP API directly alongside this package. Wrap the hit in
  `CustomParameters::for($hit)->param('_rcn', 'newsletter')` and pass it to
  `Matomo::track()`, so the gate, the URL redaction and the delivery mode still
  apply to it.
- Do not switch to `sync` to "make tracking reliable". It moves a third-party
  HTTP call into the request path; `queue` and `batch` exist precisely so a slow
  Matomo cannot slow the application.
- Do not document package internals here; keep this skill focused on adoption.
- Do not duplicate the full documentation — link
  <https://docs.pushery.com/matomo-analytics-for-laravel/> for the deep reference
  and keep this skill small enough to load and apply quickly.
