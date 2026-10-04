<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch — OFF until you turn it on
    |--------------------------------------------------------------------------
    | Installing this package must never start tracking anyone. The default is
    | therefore false: dormancy is a property of the package, not an accident of
    | an unset host. Set MATOMO_ENABLED=true when you mean to track.
    |
    | (Tracking additionally stays a no-op whenever `host` or `site_id` is
    | missing — but that is a second belt, not the switch.)
    */

    'enabled' => env('MATOMO_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Connection (Matomo Cloud or self-hosted — same code path)
    |--------------------------------------------------------------------------
    | `host` is the base URL, e.g. https://analytics.example.com or
    | https://your-instance.matomo.cloud.
    | `token` (token_auth) is server-side only; it is required for the real
    | client IP (cip) and bulk authorization. The hit time (cdt) goes with every hit,
    | and Matomo takes it without a token while it is less than a day old. A hit
    | delivered later than that — after a long retry or a dead-letter replay — keeps
    | its time only with a token; without one it is sent without the time and
    | recorded when it arrives.
    |
    | Only MATOMO_HOST is read. MATOMO_URL used to be accepted as a fallback and
    | is not any more: it is not this package's key, so applications that already
    | had their own Matomo integration reading MATOMO_URL were activating THIS
    | package by configuring THAT one. Setting MATOMO_HOST means this package.
    */

    'host' => env('MATOMO_HOST'),
    'site_id' => env('MATOMO_SITE_ID'),

    // A SITE ID PER REQUEST, FOR MULTI-TENANT APPLICATIONS. Null means "use site_id above",
    // which is what almost every application wants.
    //
    // The shape is `fn(): ?int` -- an invokable class-string (config-cache-safe) or a closure.
    // It takes no arguments on purpose: the tenant is a property of the application's own
    // request context, which the callback already has, and passing a Hit would tie this seam
    // to a value object it has no business knowing.
    //
    // It is resolved PER PAYLOAD, so a buffered batch may legitimately carry hits for several
    // sites -- each buffered hit already holds its own `idsite`, and Matomo's Bulk endpoint
    // accepts a mixed batch. That is why this is a resolver rather than a `scoped` binding:
    // rebuilding the connection per request would not fix the buffer, and this does.
    //
    // A resolver that throws, or answers with anything but a positive int, falls back to
    // `site_id`. Tracking never breaks the caller, and that includes an extension point.
    'site_id_resolver' => null,

    // REFUSE TO TRACK OVER A PLAINTEXT HOST. Off by default, because Matomo on a private
    // network without TLS is a legitimate deployment and turning this on by default would
    // silently stop tracking for every one of them.
    //
    // With it on, a `host` that is not https makes the connection count as UNCONFIGURED --
    // the same no-op path a missing host takes, never an exception. This package does not
    // throw into application code, and a security setting is the last place to start.
    //
    // What it protects: `token_auth` travels in the request body on every server-side hit,
    // so an admin-capable credential crosses the network in clear text.
    'require_tls' => env('MATOMO_REQUIRE_TLS', false),
    'token' => env('MATOMO_TOKEN'),
    'tracker_path' => env('MATOMO_TRACKER_PATH', 'matomo.php'),
    'js_path' => env('MATOMO_JS_PATH', 'matomo.js'),
    'timeout' => filter_var(env('MATOMO_TIMEOUT', 5), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 5,

    /*
    |--------------------------------------------------------------------------
    | Dispatch mode
    |--------------------------------------------------------------------------
    | 'sync'  — send immediately (CLI/tests/low volume).
    | 'queue' — collect a request's hits and flush them as one Bulk request via
    |           a queued job on terminate (default; never blocks the response).
    | 'batch' — cross-request buffer flushed in large Bulk batches, drained by the
    |           matomo:flush scheduler or the matomo:work daemon. Choose a durable
    |           store via batch.driver (database|redis|file) or 'array' for tests.
    */

    'mode' => env('MATOMO_MODE', 'queue'),

    /*
    |--------------------------------------------------------------------------
    | The scheduled commands, and what running them in the background costs
    |--------------------------------------------------------------------------
    | Both scheduled commands run in the background by default, so `schedule:run`
    | never waits on Matomo — a slow or unreachable instance would otherwise hold
    | up every other task in that minute, inside an application that installed
    | this package to have analytics rather than a queue of its own.
    |
    | The price is your error reporting, and it is why this is a switch rather
    | than a decision made for you. From Laravel 12.11 on, ScheduleRunCommand
    | raises the non-zero exit of a foreground task: it dispatches
    | ScheduledTaskFailed and reaches the exception handler, Sentry, Flare and
    | Nightwatch included. A background task does neither, so a nightly prune
    | that fails is invisible on the surface somebody actually watches. Before
    | 12.11 a foreground failure shows only in the output of `schedule:run`.
    |
    | Set this to false if that report is what you need. On every supported
    | Laravel version, and in either mode, onFailure() attached through
    | MatomoAnalyticsServiceProvider::configureSchedule() sees a failed run too.
    | The commands report plenty on their own besides the exit code: the
    | consecutive-failure counter and the TrackingFailed / HitsDeadLettered
    | events.
    */

    'schedule' => [
        'run_in_background' => env('MATOMO_SCHEDULE_BACKGROUND', true),
    ],

    'queue' => [
        'connection' => env('MATOMO_QUEUE_CONNECTION'),
        'queue' => env('MATOMO_QUEUE', 'matomo'),
        'tries' => 5,
        'backoff' => [30, 120, 300, 900],
        'retry_until_minutes' => 1440,
    ],

    'batch' => [
        'driver' => env('MATOMO_BATCH_DRIVER', 'database'), // database|redis|file|array

        // HOW MANY HITS GO INTO ONE BULK REQUEST, AND THEREFORE HOW MANY REQUESTS A
        // BACKLOG COSTS. It is the round-trip knob, and it was set low enough to matter:
        // draining 2000 hits against a Matomo answering in 20ms took 1021ms at 50, 276ms
        // at 200 and 125ms at 500 — the same hits, the same connection, 8x apart. These
        // are round trips rather than handshakes; the shared cURL handler already reuses
        // one TCP connection for a whole flush (on a PHP with curl; without it, each
        // request opens its own).
        //
        // It is also the memory knob: a claimed batch
        // is held in memory at roughly 2.3 KB per hit, so 200 costs about 460 KB and 500
        // about 1.2 MB per flushing process. 200 is the middle of that trade — four times
        // fewer requests for less than half a megabyte.
        //
        // A blank `.env` line such as `MATOMO_BATCH_SIZE=` reads as unset, so the size and the
        // interval keep their shipped values; so does the dead-letter retention further down.
        'size' => filter_var(env('MATOMO_BATCH_SIZE', 200), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 200,
        'flush_interval' => filter_var(env('MATOMO_BATCH_INTERVAL', 60), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 60,
        'max_per_flush' => filter_var(env('MATOMO_BATCH_MAX_PER_FLUSH', 2000), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 2000,
        'stale_after_minutes' => 15,

        // The `redis` driver moves hits with LMOVE, which needs Redis 6.2 or later. On an older
        // server a flush ends marked unavailable and names the missing command.
        'redis_connection' => env('MATOMO_BATCH_REDIS', 'default'),
        'table' => 'matomo_tracking_buffer',
        'path' => env('MATOMO_BATCH_PATH'),

        // After this many consecutive failed flushes a stuck batch is moved to the
        // dead-letter queue (transient failures keep retrying until then; a poison
        // HTTP 4xx is dead-lettered at once). Nothing is lost — replay re-queues it.
        // The consecutive-failure count lives in the cache, so batch mode needs a
        // persistent (non-array) cache store for this escalation to survive across runs.
        'max_attempts' => filter_var(env('MATOMO_BATCH_MAX_ATTEMPTS', 25), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 25,
        'dead_letter' => [
            'enabled' => true,
            'table' => 'matomo_dead_letters',

            // How long a dead-lettered batch is kept before it is deleted, in days.
            // `0` keeps them forever — the same convention as `--max-runs`, `--max-time`
            // and `--memory`, where 0 means "no limit".
            //
            // ON by default, and that is a deliberate choice rather than a default that
            // happened. Two reasons, and the second is the one specific to this package:
            //
            //  1. Nothing else ever deletes from this table. `matomo:replay` removes an
            //     entry it re-queues and `--prune` empties the queue on demand — both need
            //     a human. An installation where nothing goes wrong never runs either, so
            //     the table only grows, and its rows carry a longText payload each.
            //  2. An old dead letter is not merely stale, it is misleading to replay.
            //     `cdt` is stamped when the payload is BUILT, so a replayed batch carries
            //     its original timestamp — and Matomo refuses a `cdt` older than about a
            //     day unless the request carries `token_auth`. Without a token such a hit
            //     is sent without its time and recorded at today's date instead, which
            //     quietly moves month-old visits into the current report.
            //
            // 30 days is far beyond any realistic diagnosis window and well short of the
            // point where the table becomes a problem. Set it to `0` if you would rather
            // keep everything, or lower it if the queue is large.
            'retention_days' => filter_var(env('MATOMO_DEAD_LETTER_RETENTION_DAYS', 30), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fail-safe / resilience
    |--------------------------------------------------------------------------
    | The app is never blocked and tracking errors never bubble up. A delivery
    | that is retried raises an alert only after `report_after_attempts` failures;
    | one that will not be retried (a send in `sync` mode, a parked batch) raises
    | it at once. Alerts go via `channel` ('report' routes to Flare/Nightwatch/
    | Sentry, 'log', or 'silent') and are throttled per error signature so a
    | sustained outage cannot flood monitoring.
    */

    'resilience' => [
        'never_throw' => true,
        'connect_timeout' => filter_var(env('MATOMO_CONNECT_TIMEOUT', 2), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 2,
        'reporting' => [
            'report_after_attempts' => filter_var(env('MATOMO_REPORT_AFTER_ATTEMPTS', 3), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 3,
            'channel' => env('MATOMO_REPORT_CHANNEL', 'report'),
            'level' => 'warning',             // a PSR-3 level; any other is read as warning
            'transient_level' => null,        // a PSR-3 level for retry notes, or null for none
            'throttle_minutes' => filter_var(env('MATOMO_REPORT_THROTTLE_MINUTES', 15), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting API (read side)
    |--------------------------------------------------------------------------
    |
    | The read-side client (Matomo\Facades\MatomoReports) pulls statistics back
    | from the Reporting API. It reuses host/site_id/token above; a token with
    | at least view access is required. Results are cached with date-aware TTLs
    | and failures are surfaced via lastError() and the resilience reporter.
    */

    'reporting' => [
        'path' => env('MATOMO_REPORTING_PATH', 'index.php'),
        'timeout' => filter_var(env('MATOMO_REPORTING_TIMEOUT', 10), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1], 'flags' => FILTER_NULL_ON_FAILURE]) ?? 10,
        'default_period' => env('MATOMO_REPORTING_PERIOD', 'day'),
        'default_date' => env('MATOMO_REPORTING_DATE', 'today'),

        // Named segment registry: reference a saved segment by key in
        // MatomoReports::query(...)->segment('mobile'), or pass a raw definition /
        // a Segment builder. Values are Matomo segment definitions.
        'segments' => [
            // 'mobile' => 'deviceType==smartphone',
            // 'engaged' => 'visitCount>1;actions>=3',
        ],

        'cache' => [
            'enabled' => true,
            'store' => env('MATOMO_REPORTING_CACHE_STORE'), // null = default cache store
            'prefix' => 'matomo-analytics:report',
            'ttl' => [
                'live' => 60,         // Live.* realtime counters
                'today' => 300,       // spans reaching today: today, lastN, the current week, month or year
                'recent' => 900,      // spans that ended yesterday, and previousN
                'historical' => 3600, // fully archived past periods
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Visitor identity
    |--------------------------------------------------------------------------
    */

    'visitor' => [
        'rotate' => 'daily', // daily|weekly|never (cookieless salt rotation)
        // null by default: attaching the authenticated user id links every hit to a
        // known person, which is a deliberate step, not a starting point. Set 'auth'
        // when you have a lawful basis for it.
        'user_id' => null,   // 'auth' to attach the authenticated user id, or null
    ],

    // On by default, and the truncation happens HERE — in this application, before
    // the hit is sent. That is the answer to the question an EU deployment actually
    // asks: the full address never leaves your server. (Matomo can also anonymize
    // server-side; that is its own setting and independent of this one.) A value that
    // is not an address cannot be truncated, so it is not sent at all. Turn this off
    // deliberately if you have a basis to store full addresses.
    'anonymize_ip' => true,

    // Forwarding header carrying the real client IP (e.g. CF-Connecting-IP behind
    // Cloudflare, or the standard `Forwarded`, read by its `for` parameter). A forwarding
    // header can be a chain, and it is read from the right: each proxy appends the address
    // it received the request from to whatever the request already carried, so the left
    // entries are the client's own invention and only the appended ones can be believed.
    // A port, brackets and a zone id are stripped; a header that names no address counts
    // as absent.
    // Security: the header is trusted without verification, so only set this when the
    // origin is reachable exclusively through the trusted proxy. If the origin is directly
    // reachable, a client can spoof it (poisoning cip / bypassing except_ips): prefer
    // leaving this null and configuring Laravel's TrustProxies + $request->ip().
    'ip_header' => env('MATOMO_IP_HEADER'),

    // How many trusted proxies append to `ip_header`. The client is that many entries
    // from the right: 1 behind a single proxy, 2 behind a CDN in front of a load
    // balancer that both append. A single-value header such as CF-Connecting-IP is
    // unaffected.
    'ip_header_trusted_hops' => filter_var(env('MATOMO_IP_HEADER_TRUSTED_HOPS', 1), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 1,

    /*
    |--------------------------------------------------------------------------
    | Tracking gates — which visitors are tracked
    |--------------------------------------------------------------------------
    */

    'tracking' => [
        'environments' => null,          // null = all; or ['production']
        'track_authenticated' => true,   // include logged-in users
        'skip_prefetch' => true,         // refuse every hit of a speculative request (Sec-Purpose/Purpose: prefetch)
        'except_abilities' => [],        // skip users passing any of these Gate abilities, e.g. ['admin']
        'except_ips' => [],              // skip these client IPs / CIDR ranges
        // `livewire-*/*` is Livewire 4, whose endpoint prefix carries a hash
        // (`/livewire-490cd34f/update`); `livewire/*` alone covers Livewire 3 only.
        // The second segment is required on purpose, so a page at `/livewire-tips`
        // stays tracked.
        'except_routes' => ['horizon*', 'telescope*', 'nova*', 'up', 'health*', 'livewire/*', 'livewire-*/*'],

        // THE CONSENT SEAM, and it can only ever say NO. Consulted LAST, after every
        // rule above it, and server-side only: return false to refuse tracking; true and
        // null both leave the earlier verdict exactly as it was. It cannot re-admit a
        // visitor the bot check, the opt-out cookie or Do-Not-Track already turned away.
        // If your application has its own consent layer, wire it here — that is how this
        // package defers to it rather than tracking around it.
        //
        //     'gate' => \App\Analytics\ConsentGate::class,   // __invoke(Request, $hit): ?bool
        //
        // An invokable class-string survives `config:cache`; a closure does not.
        'gate' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy / consent
    |--------------------------------------------------------------------------
    */

    'privacy' => [
        'honor_dnt' => true,   // skip on DNT:1 / Sec-GPC:1
        'cookieless' => true,  // JS: disableCookies before trackPageView
        'consent' => 'none',   // none|cookie|full; any other value is read as full

        // Server-side opt-out: the gate skips tracking when this first-party cookie
        // is present. Set/clear it with MatomoAnalytics\Privacy\OptOut::enable()/disable()
        // — a server-set, encrypted cookie. A plaintext cookie set from client-side JS is
        // dropped by Laravel's EncryptCookies and will NOT be honored: opt out server-side.
        'opt_out' => [
            'respect' => true,
            'cookie' => 'matomo_opt_out',
        ],

        // Scrub secrets/PII out of tracked URLs before they reach Matomo. Sensitive
        // query parameters keep their key but lose their value; regex patterns can
        // scrub anything else. Applies to the listed payload keys.
        'redact' => [
            'enabled' => true,
            'replacement' => 'REDACTED',
            // The OAuth callback is named in the documentation as a covered case, and its
            // secret-bearing parameter is `code` — which was not on this list, along with
            // `state`, `id_token`, `jwt` and `refresh_token`. A callback URL lands in `urlref`
            // on the very next page view, so the authorization code reached Matomo intact.
            'query_params' => [
                'token', 'api_key', 'apikey', 'api-key', 'access_token', 'refresh_token',
                'id_token', 'jwt', 'code', 'state', 'auth', 'auth_token', 'password',
                'passwd', 'pwd', 'secret', 'client_secret', 'signature', 'sig', '_token',
                'session', 'session_id', 'sessionid',
            ],
            'patterns' => [], // e.g. ['/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/'] to scrub emails
            'keys' => ['url', 'urlref', 'link', 'download'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bots / AI crawlers
    |--------------------------------------------------------------------------
    */

    'bots' => [
        'track' => false,               // track bots/crawlers at all?
        'detect_ai_crawlers' => true,   // built-in AI/LLM crawler token list
        'detect_generic' => true,       // built-in generic crawler signals
        'allow' => [],                  // UA tokens always treated as human
        'deny' => [],                   // UA tokens always treated as bots
        'detector' => null,             // extra invokable class-string/closure: fn(string $ua): bool (e.g. a device-detector wrapper)
    ],

    /*
    |--------------------------------------------------------------------------
    | AI chatbot telemetry (opt-in) — the DIY alternative to a Cloudflare Worker
    |--------------------------------------------------------------------------
    | When an AI assistant fetches a page on a user's behalf (the on-demand
    | fetchers), it never runs JavaScript, so Matomo can only see it server-side.
    | Matomo's own edge collector for this is a Cloudflare Worker; the primitive it
    | sends is a plain Tracking-API hit with recMode set — which this package can
    | emit itself, at zero edge cost. These hits are recorded as bot telemetry only
    | (recMode) and never create a visitor/session in your normal analytics.
    | Requires Matomo 5.8+ for the AI Chatbots report. Enable the `matomo.chatbots`
    | middleware (or set `auto`) so incoming fetches are captured.
    */

    'ai_chatbots' => [
        'track' => false,             // master switch for AI-chatbot telemetry
        'auto' => false,              // auto-register the matomo.chatbots middleware on the 'web' group
        'rec_mode' => 1,              // 1 = bot only (non-bots discarded), 2 = auto (Matomo decides)
        'source' => 'Laravel',        // label sent with each hit to identify the collector
        'user_agents' => null,        // null = the built-in on-demand-fetcher list (Bots\AiChatbots::USER_AGENTS)
    ],

    /*
    |--------------------------------------------------------------------------
    | Page-view middleware (opt-in)
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'auto' => false,            // auto-register on the 'web' group
        'only_get' => true,         // only GET requests
        'only_successful' => true,  // only delivered pages: 2xx and 304
        'skip_livewire' => true,    // skip Livewire update requests
        'skip_prefetch' => true,    // skip a speculative request (Sec-Purpose/Purpose: prefetch)
        'strip_query' => false,     // drop the query string from the tracked URL

        // Stamp the server generation time (pf_srv = "Serverzeit") onto the tracked page view
        // from the Laravel request duration. The one page-performance sub-timing the server
        // legitimately knows — useful when tracking purely server-side (no JS snippet). Off by
        // default; the client tracker already reports full-fidelity timings on real page loads.
        'performance' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Client-side JS snippet
    |--------------------------------------------------------------------------
    */

    'js' => [
        // The client-side tracker as a whole. It governs BOTH doors that put tracking into a
        // page: `@matomoScript` and the `<noscript>` pixel (`@matomoNoscript`) — both go
        // through the same `active()` check. `@matomoWebVitals` has its own switch below and
        // ships off; `@matomoOptOut` is not tracking and keeps working either way.
        //
        // It has an env seam because a consumer who wants the client tracker structurally off
        // may not be able to edit this file: where it is template-managed, the extension point
        // is the MATOMO_* keys, and an edit to the literal is silently reverted by the next
        // sync — the switch reads "off" until one day it does not, and nobody sees it.
        'enabled' => env('MATOMO_JS_ENABLED', true),
        'host' => env('MATOMO_JS_HOST'),  // optional separate host for matomo.js, e.g. a Matomo Cloud CDN: https://cdn.matomo.cloud/your-instance.matomo.cloud (tracking still goes to MATOMO_HOST)
        'tag_manager' => null,        // full MTM container URL; when set, mtm.js renders instead of matomo.js
        'enable_link_tracking' => true,

        // Native Matomo page-performance metrics (the "Leistung" report: network/server/
        // transfer/DOM/on-load times) are collected AUTOMATICALLY by matomo.js on the first
        // full page load — no extra call. This flag only lets you turn them OFF: when false,
        // the snippet pushes disablePerformanceTracking before trackPageView.
        'performance' => true,

        // Custom Dimensions set on every page view, as a map of dimension id => value
        // (the client-side mirror of the server-side dimension{N}). Values are static per
        // request; for dynamic per-hit dimensions use the server-side CustomParameters helper.
        'custom_dimensions' => [], // e.g. [1 => 'member', 3 => env('APP_ENV')]

        // Automatic Content Tracking impressions: false (off), 'all' (track every content
        // block on the page) or 'visible' (only blocks scrolled into the viewport). Requires
        // content blocks marked up with data-track-content. See Matomo's Content Tracking.
        'content_tracking' => false,

        'heartbeat' => 15,            // enableHeartBeatTimer seconds; 0 to disable
        // Render a <noscript> tracking pixel as part of @matomoScript. Note where that
        // lands: inside <head>, the HTML spec allows a <noscript> to hold only link,
        // style and meta, so the <img> is a parse error there (recoverable — browsers
        // push the following head elements back — but a validator will say so). For a
        // validator-clean page, set this to false and place @matomoNoscript in <body>.
        'noscript' => true,
        'dns_prefetch' => true,       // emit a dns-prefetch link for the Matomo origin
    ],

    /*
    |--------------------------------------------------------------------------
    | SPA / soft-navigation tracking (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, the tracker snippet also records a virtual page view on each
    | client-side navigation (which never reloads the page, so the normal page
    | view would be missed). Pick the adapters your app uses:
    |   - "livewire" : Livewire wire:navigate            (fires livewire:navigated)
    |   - "inertia"  : Inertia.js (Vue & React)          (fires inertia:navigate)
    |   - "generic"  : any client router via History pushState + popstate
    | An adapter records a page view only when the URL actually CHANGES: these events
    | are not proof of a navigation on their own (Livewire emits livewire:navigated
    | once on every hard load, too), and firing on them counted such a load twice.
    | A window.matomoTrackPageView() helper is always exposed for manual triggers;
    | it is not guarded, so it still records at an unchanged URL.
    | Only applies to the direct matomo.js tracker (Tag Manager handles SPA itself).
    */

    'spa' => [
        'enabled' => env('MATOMO_SPA', false),
        'adapters' => ['livewire', 'inertia'],

        // Soft (client-side) navigations produce no new browser Navigation Timing, so Matomo
        // records zero page-performance for them. When true, the SPA track() closure forwards
        // an optional app-provided `window.__matomoPerf` object
        // ({net,srv,tfr,dm1,dm2,onl} in ms) via setPagePerformanceTiming before each virtual
        // page view, then clears it. Harmless no-op until your app sets that object (needs
        // Matomo 4.5+). Never re-emits the hard-load timings.
        'performance' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Core Web Vitals (opt-in)
    |--------------------------------------------------------------------------
    |
    | When enabled, @matomoWebVitals beacons LCP/CLS/INP (etc.) to the ingest
    | route, which records each as a Matomo event (category below) through the
    | normal gate. The @matomoWebVitals directive expects Google's `web-vitals`
    | library on window.webVitals; bundle it yourself, or set `library` to a
    | (self-hosted) script URL. No third-party CDN is loaded by default.
    |
    | The throttle counts per client address, and an IPv6 address per /64, the
    | range a single connection is given.
    */

    'web_vitals' => [
        'enabled' => false,
        'path' => 'matomo-analytics/web-vitals',
        'category' => 'Web Vitals',
        'metrics' => ['LCP', 'CLS', 'INP', 'FCP', 'TTFB'],
        'throttle' => env('MATOMO_WEB_VITALS_THROTTLE', '60,1'), // "requests,minutes" or a count a minute; null or "off" to disable
        'middleware' => [],   // extra route middleware; see the note below
        'library' => null,    // optional <script src> for web-vitals; null = app provides it
    ],

    /*
    |--------------------------------------------------------------------------
    | Prefetched page views (opt-in)
    |--------------------------------------------------------------------------
    |
    | `middleware.skip_prefetch` keeps a speculation-rules prefetch from counting as a
    | page view — the pointer resting on a link is not a visit. But when the reader then
    | DOES click, the browser serves the page out of that prefetch and the server never
    | hears about it, so the view would be missing instead of doubled.
    |
    | When enabled, @matomoPrefetchPageView closes that half: the page reports itself,
    | once, and only when the browser says it was delivered from a prefetch. The beacon
    | goes through the normal gate, and a URL from another origin is refused. Its throttle
    | counts like the Web Vitals one: per client address, and an IPv6 address per /64.
    */

    'prefetch_beacon' => [
        'enabled' => false,
        'path' => 'matomo-analytics/page-view',
        'throttle' => env('MATOMO_PREFETCH_BEACON_THROTTLE', '60,1'), // "requests,minutes" or a count a minute; null or "off" to disable
        'middleware' => [],   // extra route middleware; see the note below
    ],

    /*
    |--------------------------------------------------------------------------
    | Hits from the browser without matomo.js (opt-in)
    |--------------------------------------------------------------------------
    |
    | A page that loads no `matomo.js` still has things only the browser sees: an
    | event, a click on an outlink or a download, a search run in the page, a
    | heartbeat. When enabled, @matomoHitBeacon defines `window.matomoHit(type, data)`,
    | which beacons them to this route, and the route records each through the
    | normal gate. A request from another origin is refused, text is bounded, and
    | `event_categories`, when set, names the only categories a page may send. The
    | throttle counts per client address, and an IPv6 address per /64.
    */

    'hit_beacon' => [
        'enabled' => false,
        'path' => 'matomo-analytics/hit',
        'throttle' => env('MATOMO_HIT_BEACON_THROTTLE', '60,1'), // "requests,minutes" or a count a minute; null or "off" to disable
        'middleware' => [],        // extra route middleware; see the note below
        'event_categories' => [],  // the only event categories a page may send; empty allows every one
    ],

    /*
    | The web-vitals, prefetch-beacon and hit-beacon routes are registered outside every middleware
    | group, because the browser beacons them with sendBeacon() and that carries no CSRF token. The
    | consequence is that no session is started on these paths, so the gate's `track_authenticated`
    | and `except_abilities` rules see a guest there regardless of who is logged in. Name middleware
    | in the section's `middleware` if you need those rules to apply — `['web']` starts a session,
    | and you then owe that route a CSRF exemption on your side.
    */

    /*
    |--------------------------------------------------------------------------
    | Release annotations (opt-in)
    |--------------------------------------------------------------------------
    | Post notes to Matomo's free Annotations plugin — most usefully a deploy
    | marker on your reports timeline. Requires a token_auth for a non-anonymous
    | user. `matomo:annotate --release` is a no-op unless `release` is true, so it
    | is safe to drop unconditionally into a deploy pipeline. The release note is
    | "<release_prefix> <version>", where version defaults to config('app.version').
    */

    'annotations' => [
        'release' => env('MATOMO_ANNOTATE_RELEASES', false), // enable `matomo:annotate --release`
        'starred' => false,                                  // star release annotations
        'release_prefix' => 'Deployed',                      // release note = "<prefix> <version>"
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel events
    |--------------------------------------------------------------------------
    */

    'events' => true,

];
