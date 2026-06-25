<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Tracking is additionally a no-op whenever `host` or `site_id` is missing.
    */

    'enabled' => env('MATOMO_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Connection (Matomo Cloud or self-hosted — same code path)
    |--------------------------------------------------------------------------
    | `host` is the base URL, e.g. https://analytics.example.com or
    | https://your-instance.matomo.cloud. MATOMO_URL is accepted as an alias.
    | `token` (token_auth) is server-side only; it is required for the real
    | client IP (cip), an accurate hit time (cdt) and bulk authorisation.
    */

    'host' => env('MATOMO_HOST', env('MATOMO_URL')),
    'site_id' => env('MATOMO_SITE_ID'),
    'token' => env('MATOMO_TOKEN'),
    'tracker_path' => env('MATOMO_TRACKER_PATH', 'matomo.php'),
    'js_path' => env('MATOMO_JS_PATH', 'matomo.js'),
    'timeout' => env('MATOMO_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Dispatch mode
    |--------------------------------------------------------------------------
    | 'sync'  — send immediately (CLI/tests/low volume).
    | 'queue' — collect a request's hits and flush them as one Bulk request via
    |           a queued job on terminate (default; never blocks the response).
    | 'batch' — cross-request buffer flushed in large Bulk batches (arrives in a
    |           later release; currently behaves as 'queue').
    */

    'mode' => env('MATOMO_MODE', 'queue'),

    'queue' => [
        'connection' => env('MATOMO_QUEUE_CONNECTION'),
        'queue' => env('MATOMO_QUEUE', 'matomo'),
        'tries' => 5,
        'backoff' => [30, 120, 300, 900],
        'retry_until_minutes' => 1440,
    ],

    'batch' => [
        'driver' => env('MATOMO_BATCH_DRIVER', 'database'), // database|redis|file|array
        'size' => env('MATOMO_BATCH_SIZE', 50),
        'flush_interval' => env('MATOMO_BATCH_INTERVAL', 60),
        'max_per_flush' => 2000,
        'stale_after_minutes' => 15,
        'redis_connection' => env('MATOMO_BATCH_REDIS', 'default'),
        'table' => 'matomo_tracking_buffer',
        'path' => env('MATOMO_BATCH_PATH'),

        // After this many consecutive failed flushes a stuck batch is moved to the
        // dead-letter queue (transient failures keep retrying until then; a poison
        // HTTP 4xx is dead-lettered at once). Nothing is lost — replay re-queues it.
        'max_attempts' => env('MATOMO_BATCH_MAX_ATTEMPTS', 25),
        'dead_letter' => [
            'enabled' => true,
            'table' => 'matomo_dead_letters',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fail-safe / resilience
    |--------------------------------------------------------------------------
    | The app is never blocked and tracking errors never bubble up. Alerts are
    | raised only after `report_after_attempts` failures, via `channel`
    | ('report' routes to Flare/Nightwatch/Sentry, 'log', or 'silent'), and are
    | throttled per error signature so a sustained outage cannot flood monitoring.
    */

    'resilience' => [
        'never_throw' => true,
        'connect_timeout' => 2,
        'durability' => env('MATOMO_DURABILITY', 'durable'), // durable|best_effort
        'reporting' => [
            'report_after_attempts' => 3,
            'channel' => env('MATOMO_REPORT_CHANNEL', 'report'),
            'level' => 'warning',
            'transient_level' => null,
            'throttle_minutes' => 15,
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
        'timeout' => env('MATOMO_REPORTING_TIMEOUT', 10),
        'default_period' => env('MATOMO_REPORTING_PERIOD', 'day'),
        'default_date' => env('MATOMO_REPORTING_DATE', 'today'),
        'cache' => [
            'enabled' => true,
            'store' => env('MATOMO_REPORTING_CACHE_STORE'), // null = default cache store
            'prefix' => 'matomo-analytics:report',
            'ttl' => [
                'live' => 60,         // Live.* realtime counters
                'today' => 300,       // periods covering today (not yet archived)
                'recent' => 900,      // yesterday / lastN / previous ranges
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
        'user_id' => 'auth', // 'auth' to attach the authenticated user id, or null
    ],

    'anonymize_ip' => false,
    'ip_header' => env('MATOMO_IP_HEADER'), // e.g. CF-Connecting-IP behind Cloudflare

    /*
    |--------------------------------------------------------------------------
    | Tracking gates — which visitors are tracked
    |--------------------------------------------------------------------------
    */

    'tracking' => [
        'environments' => null,          // null = all; or ['production']
        'track_authenticated' => true,   // include logged-in users
        'except_abilities' => [],        // skip users passing any of these Gate abilities, e.g. ['admin']
        'except_ips' => [],              // skip these client IPs / CIDR ranges
        'except_routes' => ['horizon*', 'telescope*', 'nova*', 'up', 'health*', 'livewire/*'],
        'gate' => null,                  // invokable class-string (config-cache safe) or closure: fn(Request, $hit): ?bool
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy / consent
    |--------------------------------------------------------------------------
    */

    'privacy' => [
        'honor_dnt' => true,   // skip on DNT:1 / Sec-GPC:1
        'cookieless' => true,  // JS: disableCookies before trackPageView
        'consent' => 'none',   // none|cookie|full

        // Server-side opt-out: the gate skips tracking when this first-party cookie
        // is present. Set/clear it with MatomoAnalytics\Privacy\OptOut::enable()/disable().
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
            'query_params' => [
                'token', 'api_key', 'apikey', 'api-key', 'access_token', 'auth',
                'auth_token', 'password', 'passwd', 'pwd', 'secret', 'client_secret',
                'signature', 'sig', '_token', 'session', 'session_id', 'sessionid',
            ],
            'patterns' => [], // e.g. ['/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/'] to scrub emails
            'keys' => ['url', 'urlref', 'link', 'download'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bots / AI crawlers (detector arrives in a later phase)
    |--------------------------------------------------------------------------
    */

    'bots' => [
        'track' => false,               // track bots/crawlers at all?
        'detect_ai_crawlers' => true,   // built-in AI/LLM crawler token list
        'detect_generic' => true,       // built-in generic crawler signals
        'allow' => [],                  // UA tokens always treated as human
        'deny' => [],                   // UA tokens always treated as bots
        'detector' => null,             // extra invokable class-string/closure: fn(string $ua): bool (e.g. a device-detector wrapper)
        'record_ai_dimension' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Page-view middleware (opt-in)
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'auto' => false,            // auto-register on the 'web' group
        'only_get' => true,         // only GET requests
        'only_successful' => true,  // only 2xx responses
        'skip_livewire' => true,    // skip Livewire update requests
        'strip_query' => false,     // drop the query string from the tracked URL
    ],

    /*
    |--------------------------------------------------------------------------
    | Client-side JS snippet
    |--------------------------------------------------------------------------
    */

    'js' => [
        'enabled' => true,
        'tag_manager' => null,        // full MTM container URL; when set, mtm.js renders instead of matomo.js
        'enable_link_tracking' => true,
        'heartbeat' => 15,            // enableHeartBeatTimer seconds; 0 to disable
        'noscript' => true,           // render a <noscript> tracking pixel
        'dns_prefetch' => true,       // emit a dns-prefetch link for the Matomo origin
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
    */

    'web_vitals' => [
        'enabled' => false,
        'path' => 'matomo-analytics/web-vitals',
        'category' => 'Web Vitals',
        'metrics' => ['LCP', 'CLS', 'INP', 'FCP', 'TTFB'],
        'throttle' => '60,1', // route throttle "requests,minutes"; null to disable
        'library' => null,    // optional <script src> for web-vitals; null = app provides it
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel events
    |--------------------------------------------------------------------------
    */

    'events' => true,

];
