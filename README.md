<p align="center">
  <a href="https://github.com/pushery/matomo-analytics-for-laravel">
    <img src="https://raw.githubusercontent.com/pushery/matomo-analytics-for-laravel/main/art/header.png" alt="Matomo Analytics for Laravel" width="100%">
  </a>
</p>

# Matomo Analytics for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/matomo-analytics-for-laravel.svg)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/matomo-analytics-for-laravel/php.svg)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![Laravel Versions](https://badge.laravel.cloud/badge/pushery/matomo-analytics-for-laravel?style=flat)](https://packagist.org/packages/pushery/matomo-analytics-for-laravel)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Privacy-first Matomo tracking for Laravel — **client and server**, **single or
batched**, with **AI-bot detection**, **env-driven tracking gates**, and
**fail-safe** delivery that never blocks your app. Works the same for self-hosted
Matomo and Matomo Cloud.

```bash
composer require pushery/matomo-analytics-for-laravel
php artisan matomo:install
```

```dotenv
MATOMO_ENABLED=true
MATOMO_HOST=https://your-instance.matomo.cloud   # or https://analytics.example.com
MATOMO_SITE_ID=1
```

Nothing is tracked until you set `MATOMO_ENABLED=true` — installing the package
starts no tracking at all. It also stays a no-op while either variable above is
missing, so it is inert in local and CI environments. Verify with
`php artisan matomo:test`.

Requires PHP 8.4+ and Laravel 12 or 13.

## Documentation

**Full documentation at [docs.pushery.com/matomo-analytics-for-laravel](https://docs.pushery.com/matomo-analytics-for-laravel/).**

- [Installation](https://docs.pushery.com/matomo-analytics-for-laravel/installation) — setup, the connectivity check, and whether you need a token
- [Configuration reference](https://docs.pushery.com/matomo-analytics-for-laravel/reference/configuration) — every key, its environment variable and its default
- [Server-side tracking](https://docs.pushery.com/matomo-analytics-for-laravel/tracking/server-side) — the facade, automatic page views, visitor identity
- [Client-side tracking](https://docs.pushery.com/matomo-analytics-for-laravel/tracking/client-side) — the Blade snippet, Tag Manager, SPA navigation
- [Reporting](https://docs.pushery.com/matomo-analytics-for-laravel/reporting/overview) — read statistics back out of Matomo

## What it does

**Tracking, server-side.** Page views, events, goals, downloads, outlinks, site
search, ecommerce (views, cart updates, orders) and content
impressions/interactions, all through one `Matomo` facade. Or attach a middleware
and track page views with no per-route code. Custom dimensions, plus a raw
Tracking-API escape hatch for anything the typed hits do not model.

**Tracking, client-side.** One Blade directive renders the cookieless `_paq`
snippet — link tracking, heartbeat timer, no-script pixel, CSP nonce support — or
a Matomo Tag Manager container instead. Virtual page views on soft navigation for
Livewire, Inertia and any History-based router. Matomo's native page-performance
report, plus Core Web Vitals (LCP/CLS/INP) as Matomo events.

**Delivery that never blocks a response.** `queue` sends a request's hits as one
Bulk request after the response; `batch` buffers across requests and flushes in
large batches through a `database`, `redis` or `file` buffer; `sync` sends inline.
A poison batch is dead-lettered rather than blocking the queue, and
`matomo:replay` puts it back. A load simulator measures your own throughput.

**Privacy by default.** Cookieless, rotating visitor identifiers. `Do-Not-Track`
and `Sec-GPC` honored server-side. URL redaction on out of the box, so secrets and
PII never reach your analytics. Consent postures for a cookie-based setup, a
server-side opt-out cookie, a publishable privacy-policy partial, and GDPR
erase/export through Matomo's PrivacyManager API.

**Bots and AI traffic.** Bots and AI crawlers are excluded by default, from a
curated list that updates itself, with an optional exhaustive backstop. AI
assistant referrals need no configuration; on-demand assistant page fetches can be
recorded as server-side bot telemetry — no edge worker required.

**Reading data back.** A cached Reporting API client with curated helpers, a
fluent query builder, a segment builder, a named-segment registry,
one-round-trip bulk requests, and optional adapters for Matomo's premium plugins.

**Built for production.** Artisan commands for install, connectivity, flushing,
draining, load simulation, replay, reporting, GDPR and annotations. Laravel events
for every stage of a hit. Deploy markers on your reports timeline. Octane-safe
scoped bindings, and a fake for every facade so tracking is trivial to assert in
tests.

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
