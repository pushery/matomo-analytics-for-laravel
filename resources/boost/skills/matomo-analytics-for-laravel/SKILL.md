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

An application that serves each tenant on a subdomain of its own, such as
`<slug>.example.test`, names the base domain, so no tenant's name reaches Matomo
with the host of a page or a referrer. Its own hosts under that domain, such as
`app.example.test`, keep their name where the labels in front of the domain are
listed:

```dotenv
MATOMO_REDACT_BASE_DOMAINS=example.test
MATOMO_REDACT_KEPT_LABELS=app,api
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="matomo-analytics-config"
```

The other publish tags:

| Tag | Publishes |
|---|---|
| `matomo-analytics-views` | the privacy-policy view, to override it in the application |
| `matomo-analytics-lang` | the translations, to override them in the application |
| `matomo-analytics-migrations` | the migrations of the batch buffer and the dead-letter queue, in step 4 |
| `matomo-analytics` | every group at once, the migrations included |

Every option in `config/matomo-analytics.php` is documented inline.

The cookieless visitor id is salted with a random secret per day, or per week under
`rotate => 'weekly'`, which the package keeps in the cache store
`MATOMO_VISITOR_SECRET_STORE` names, or the default store.
Name a store every server shares and no `cache:clear` reaches: a Redis connection and
database no other store uses, with `maxmemory-policy noeviction` and
`appendonly yes`, or a database store with a table of its own. A database or a file
store keeps the secret of every past period until something deletes it, so on
either store schedule the pruning every hour, in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
use MatomoAnalytics\Identity\PruneExpiredVisitorSecrets;

Schedule::call(PruneExpiredVisitorSecrets::class)->hourly();
```

### 3. Apply the package

**Server-side, explicit.** One facade covers every hit type:

```php
use MatomoAnalytics\Facades\Matomo;
use MatomoAnalytics\Tracking\EcommerceItem;

Matomo::pageView('Checkout');
Matomo::event('Checkout', 'completed', name: 'standard', value: 49.90);
Matomo::goal(3, revenue: 49.90);
Matomo::ecommerceOrder('ORD-1', grandTotal: 49.90, items: [new EcommerceItem('TEE-01', 'T-Shirt', 'Apparel', 49.90)]);
```

**Server-side, automatic.** Attach the middleware instead of calling per route:

```php
// bootstrap/app.php
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->appendToGroup('web', \MatomoAnalytics\Http\Middleware\TrackPageViews::class);
})
```

or set `middleware.auto` in the config so the package registers it itself.

**Client-side.** One Blade directive renders the cookieless `_paq` snippet. It pushes
`disableBrowserFeatureDetection` as well, so matomo.js reads nothing from the device
and Matomo shows no screen resolution or plugins; `privacy.browser_feature_detection`
turns the reading back on where the application may:

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
  `php artisan migrate` creates the tables; publish them only to own them, as
  below.
- `sync` — inline, for tests and low-volume apps.

None of them let a Matomo outage surface in the application: delivery failures
are swallowed and reported, never rethrown into the request.

To keep the migrations in the application, call
`\MatomoAnalytics\MatomoAnalyticsServiceProvider::ignoreMigrations()` in the `register`
method of one of its service providers before its first `php artisan migrate`, then
publish and run them:

```bash
php artisan vendor:publish --tag="matomo-analytics-migrations"
php artisan migrate
```

Without the call the package goes on registering its own migrations beside the published
copies, which carry the date they were published, so `php artisan migrate` creates
`matomo_tracking_buffer` or `matomo_dead_letters` a second time and fails on the table
that already exists. The `matomo-analytics` tag publishes the migrations with every other
group, so the same holds for it. Publish them once: a migration a later release adds,
which its release notes name, is copied from
`vendor/pushery/matomo-analytics-for-laravel/database/migrations` into
`database/migrations`, renamed to start with today's date, never by publishing the tag
again. Where `php artisan migrate` has already run the migrations from the package, copy the
files from that directory under their own names instead of publishing them, so the
`migrations` table recognizes them as run.

### 5. Read data back

```php
use MatomoAnalytics\Facades\MatomoReports;

$visits = MatomoReports::visitsSummary(['period' => 'day', 'date' => 'today']);
```

Reading requires `MATOMO_TOKEN`. Tracking works without it, but only a token lets
the package send the visitor's IP address (`cip`); without one, Matomo records the
address of your server for every server-side hit.

An application that counts each tenant on a site of its own reads that site through
`MatomoReports::forSite($tenant->matomo_site_id)`, and creates, changes and deletes the
sites through the `SitesClient` contract. Take the site id from the tenant, never from
a request parameter: the token reads every site it was granted.

### 6. Operate

- `php artisan matomo:replay --list` shows the batches that failed for good and sit in the
  dead-letter queue; `matomo:replay` sends them again, and `--prune-older-than=30` deletes the
  older ones.
- `php artisan matomo:report VisitsSummary.get --period=day --date=today` prints what a
  Reporting API method returns, the quickest check that `MATOMO_TOKEN` reads.
- `php artisan matomo:annotate "Pricing page relaunched"` puts a note on the reports timeline;
  `--release` marks a deployment with the application's version where `annotations.release`
  allows it.
- `php artisan matomo:forget "userId==alice@example.com"` erases a data subject's data through
  Matomo's GDPR tools after a confirmation, and `--export` exports it instead.
- `php artisan matomo:load-sim --hits=5000 --driver=database` drives synthetic hits through the
  real buffer and flush and reports the throughput; `--against=fake`, the default, sends nothing
  to Matomo.

## Testing in the consuming application

Every facade has a fake, so tracking is asserted rather than mocked:

```php
use MatomoAnalytics\Facades\Matomo;
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

The fake records what your code tracks before the tracking gate, so a bot, a
Do-Not-Track request or an excluded route is recorded there and refused only in
production. Test the gate on the real path instead: `sync` mode with `Http::fake()`,
then `Http::assertNothingSent()`.

`MatomoReports::fake()`, `MatomoGdpr::fake()`, and `MatomoAnnotations::fake()`
follow the same shape.

## Anti-Patterns

- Do not wrap tracking calls in `if (app()->isProduction())`. The tracking gate
  already covers environments, bots, Do-Not-Track and the opt-out cookie — adding
  a second gate in application code hides why a hit was dropped.
- Do not assume the gate knows your consent layer. Under `privacy.consent => 'full'`
  it refuses a server-side hit until matomo.js has remembered a consent in the
  `mtm_consent` cookie, so call `rememberConsentGiven`; any other consent check
  belongs in `tracking.gate`, the hook that lets the application refuse a hit.
- Do not pass a value that may be empty to a tracking call. An event without a
  category or an action, `goal(0)` or an order without an order id is one Matomo
  would record as another action, so the tracker refuses it and the fake throws;
  skip the call when the value is missing. An empty site search keyword is the one
  exception: it is a visitor's input, and the tracker skips it quietly.
- Do not call the Matomo HTTP API directly alongside this package. Wrap the hit in
  `CustomParameters::for($hit)->param('_rcn', 'newsletter')` and pass it to
  `Matomo::track()`, so the gate, the URL redaction and the delivery mode still
  apply to it.
- Do not keep the visitor secret, or in `batch` mode the default store, on a store
  that loses keys: a Redis under any `maxmemory-policy` but `noeviction`, Memcached
  without `-M`, a Redis without `appendonly yes`, or a store a deploy flushes with
  `cache:clear` or `optimize:clear`. A lost secret counts every visitor of the rest of
  the period a second time, and the default store holds the count of failed flushes, so
  losing it lets a stuck batch retry longer before it is dead-lettered. A full Redis
  under `noeviction` refuses writes instead of dropping keys.
- Do not switch to `sync` to "make tracking reliable". It moves a third-party
  HTTP call into the request path; `queue` and `batch` exist precisely so a slow
  Matomo cannot slow the application.
