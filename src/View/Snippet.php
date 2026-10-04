<?php

declare(strict_types=1);

namespace MatomoAnalytics\View;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\TrackingGate;
use MatomoAnalytics\Http\Middleware\TrackPageViews;
use MatomoAnalytics\Privacy\ConsentMode;
use MatomoAnalytics\Privacy\UrlRedactor;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Tracking\PageView;

/**
 * Renders the client-side Matomo snippet (or a Tag Manager container) and the
 * opt-out iframe. Returns an empty string unless tracking and the JS layer are
 * enabled and the instance is configured, so it is safe to drop into any layout.
 * Embedded values are JSON-encoded (JS string literals) or HTML-escaped.
 */
final readonly class Snippet
{
    public function __construct(
        private Connection $connection,
        private TrackingGate $gate,
    ) {}

    public function script(?string $nonce = null): string
    {
        if (! $this->active()) {
            return '';
        }

        return Config::nullableString('matomo-analytics.js.tag_manager') !== null
            ? $this->tagManager($nonce)
            : $this->tracker($nonce);
    }

    public function webVitals(?string $nonce = null): string
    {
        if (! Config::bool('matomo-analytics.enabled', false) || ! Config::bool('matomo-analytics.web_vitals.enabled', false)) {
            return '';
        }

        $path = $this->js(URL::to(Config::string('matomo-analytics.web_vitals.path', 'matomo-analytics/web-vitals')));
        $names = '['.implode(',', array_map($this->js(...), Config::stringList('matomo-analytics.web_vitals.metrics'))).']';

        // THE GLUE WAITS INSTEAD OF ASSUMING. It read `window.webVitals` the instant it
        // parsed, which only works if the library above it blocked the parser — so the
        // measurement of Core Web Vitals was itself costing a render-blocking request, and
        // the numbers it reported were worse for its own presence. Deferring the library and
        // running the glue on DOMContentLoaded keeps the ordering (`defer` scripts execute in
        // order, before that event) while taking both off the critical path.
        //
        // `readyState` is checked first for the one case the event cannot cover: a consumer
        // who places the directive at the end of `<body>`, where the document may already be
        // interactive by the time this runs and DOMContentLoaded will never fire again.
        $glue = implode("\n", [
            '(function(){',
            '  var start=function(){',
            '    var wv=window.webVitals; if(!wv){return;}',
            '    var send=function(m){var body=JSON.stringify({metric:m.name,value:m.value,rating:m.rating,navigationType:m.navigationType,url:location.href});'
                .$this->beaconSend($path).'};',
            '    '.$names.'.forEach(function(n){var f=wv["on"+n];if(f){f(send);}});',
            '  };',
            '  if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start);}else{start();}',
            '})();',
        ]);

        $script = '<script'.$this->runOnceAttribute().$this->nonceAttribute($nonce).'>'."\n".$glue."\n".'</script>';

        $library = Config::nullableString('matomo-analytics.web_vitals.library');
        if ($library !== null) {
            return '<script'.$this->runOnceAttribute().$this->nonceAttribute($nonce).' defer src="'.e($library).'"></script>'."\n".$script;
        }

        return $script;
    }

    /**
     * A page that was delivered out of a speculation-rules prefetch reports itself, once.
     *
     * THE SERVER CANNOT SEE THIS PAGE VIEW AT ALL, AND THAT IS WHY THERE IS A SCRIPT HERE.
     * The prefetch request is skipped by {@see TrackPageViews}
     * on purpose — the pointer resting on a link is not a visit — and the navigation that
     * follows makes no request, because the browser already holds the bytes. Skipping alone
     * would therefore trade a page view too many for a page view missing.
     *
     * `deliveryType` is the browser's own answer to "where did this document come from", and
     * `navigational-prefetch` is the only value this beacons on: an ordinary load has already
     * been counted server-side, and beaconing it too would double every view on the site. An
     * engine that does not report `deliveryType` sends nothing, which is the behavior before
     * this existed.
     *
     * The beacon is for page views the server counts. Where matomo.js or a Tag Manager container
     * is on the page, `_paq` or `_mtm` is defined, and the script sends nothing: a prefetched
     * document runs its scripts when the reader opens it, not when it is fetched, so the client
     * tracker counts that view itself.
     *
     * Run on DOMContentLoaded for the same reason as the Web Vitals glue above it, plus one of
     * its own: `document.title` is read here, and in `<head>`, where a layout puts its tracking
     * directives, the title element may not be parsed yet. By then every inline script of the
     * document has run, so a tracker placed after this directive is seen as well.
     */
    public function prefetchPageView(?string $nonce = null): string
    {
        if (! Config::bool('matomo-analytics.enabled', false) || ! Config::bool('matomo-analytics.prefetch_beacon.enabled', false)) {
            return '';
        }

        $path = $this->js(URL::to(Config::string('matomo-analytics.prefetch_beacon.path', 'matomo-analytics/page-view')));

        $glue = implode("\n", [
            '(function(){',
            '  var start=function(){',
            '    if(window._paq||window._mtm||!performance.getEntriesByType){return;}',
            '    var nav=performance.getEntriesByType("navigation")[0];',
            '    if(!nav||nav.deliveryType!=="navigational-prefetch"){return;}',
            '    var body=JSON.stringify({url:location.href,title:document.title});'.$this->beaconSend($path),
            '  };',
            '  if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start);}else{start();}',
            '})();',
        ]);

        return '<script'.$this->runOnceAttribute().$this->nonceAttribute($nonce).'>'."\n".$glue."\n".'</script>';
    }

    /**
     * Sends the JSON in `body` to the beacon route at `$path`: `sendBeacon` first, because it
     * survives the page being left, and `fetch` with `keepalive` where it is missing, refuses
     * the payload or throws.
     *
     * Chromium refuses a Blob whose type is not a CORS-safelisted one, `application/json` among
     * them (crbug.com/490015), and the routes read JSON. Without the second call the measurement
     * was dropped in an empty catch. The hit beacon sends the same way.
     */
    private function beaconSend(string $path): string
    {
        return 'try{if(navigator.sendBeacon&&navigator.sendBeacon('.$path.',new Blob([body],{type:"application/json"}))){return;}}catch(e){}'
            .'try{fetch('.$path.',{method:"POST",body:body,headers:{"Content-Type":"application/json"},keepalive:true,credentials:"same-origin"});}catch(e){}';
    }

    /**
     * Defines `window.matomoHit(type, data)`, which sends a hit to the hit beacon route.
     *
     * For a page that loads no `matomo.js`. A call sends `{type, url, …data}` with the page's own
     * address as `url`, and the route records the hit through the normal gate. `sendBeacon` is
     * tried first because it survives the page being left, which is the moment an outlink or a
     * download is clicked; where it is missing or refuses the payload, `fetch` with `keepalive`
     * sends it instead. A second copy of the tag leaves the first definition in place.
     */
    public function hitBeacon(?string $nonce = null): string
    {
        if (! Config::bool('matomo-analytics.enabled', false) || ! Config::bool('matomo-analytics.hit_beacon.enabled', false)) {
            return '';
        }

        $path = $this->js(URL::to(Config::string('matomo-analytics.hit_beacon.path', 'matomo-analytics/hit')));

        $glue = implode("\n", [
            '(function(){',
            '  if(window.matomoHit){return;}',
            '  window.matomoHit=function(type,data){',
            '    var body=JSON.stringify(Object.assign({url:location.href},data||{},{type:type}));',
            '    try{if(navigator.sendBeacon&&navigator.sendBeacon('.$path.',new Blob([body],{type:"application/json"}))){return;}}catch(e){}',
            '    try{fetch('.$path.',{method:"POST",body:body,headers:{"Content-Type":"application/json"},keepalive:true,credentials:"same-origin"});}catch(e){}',
            '  };',
            '})();',
        ]);

        return '<script'.$this->runOnceAttribute().$this->nonceAttribute($nonce).'>'."\n".$glue."\n".'</script>';
    }

    /**
     * The `<noscript>` tracking pixel on its own, for placement inside `<body>`.
     *
     * `script()` already appends this pixel when `js.noscript` is on, so most integrations
     * need nothing here. This method exists for one specific and legitimate complaint: the
     * documented place for `script()` is `<head>`, and inside `<head>` the HTML spec allows
     * a `noscript` to contain ONLY `link`, `style` and `meta`. An `img` there is a parse
     * error that ends the head early.
     *
     * The measured impact is validator noise rather than breakage — a parser's "after head"
     * rules push the following head-ish elements back where they belong, so browsers
     * recover, and the case only arises with JavaScript disabled to begin with. That is why
     * the default is unchanged and this is an ADDITION: silently dropping the pixel from
     * `script()` would cost every consumer their no-JS tracking to fix validator output,
     * and most of them will never read the release note that explains it.
     *
     * So the validator-clean integration is opt-in and takes two steps:
     *
     *     'js' => ['noscript' => false],   // stop script() from emitting it in <head>
     *     …
     *     <body>… @matomoNoscript </body>  // and place it where an img is legal
     *
     * Returns '' when tracking is inactive, exactly like the other parts.
     */
    public function noscript(): string
    {
        return $this->active() && $this->pixelAllowed() ? $this->noscriptPixel() : '';
    }

    /**
     * Matomo's JavaScript opt-out: a container and the script that fills it.
     *
     * The script asks the tracker on the page to opt the visitor out, which writes the
     * first-party cookie `mtm_consent_removed`, or writes it directly where no tracker runs.
     * matomo.js writes that cookie with cookies disabled as well and stops tracking once it is
     * there. Matomo's older iframe set its cookie on the Matomo domain, a third-party cookie
     * wherever Matomo runs on another site, which browsers that block those never keep.
     */
    public function optOut(?string $nonce = null): string
    {
        if (! $this->connection->isConfigured()) {
            return '';
        }

        $url = $this->connection->host.'/index.php?'.http_build_query([
            'module' => 'CoreAdminHome',
            'action' => 'optOutJS',
            'divId' => 'matomo-opt-out',
            'language' => 'auto',
            'showIntro' => 1,
        ], '', '&', PHP_QUERY_RFC3986);

        return '<div id="matomo-opt-out"></div>'
            .'<script'.$this->nonceAttribute($nonce).' src="'.e($url).'"></script>';
    }

    /**
     * Whether the tracking gate lets a page view of the current request through.
     *
     * The pixel goes from the browser to Matomo directly and never passes the server, so the
     * rules the gate applies to server-side hits apply to it here or not at all: consent under
     * `privacy.consent => 'full'`, an opt-out, an excluded route or address, a bot. A visitor
     * without JavaScript cannot give the consent matomo.js remembers, so under `full` the
     * pixel stays out until the request carries it.
     *
     * A prefetched page is judged as the page that will be shown: its pixel is fetched only
     * when the reader opens it, so the headers that announce the prefetch do not decide it.
     */
    private function pixelAllowed(): bool
    {
        $request = RequestFacade::instance()->duplicate();
        $request->headers->remove('Sec-Purpose');
        $request->headers->remove('Purpose');

        return $this->gate->decide($request, new PageView(''))->allowed;
    }

    /**
     * The image a visitor without JavaScript requests.
     *
     * No tracker writes the page address into it, and without `url` Matomo takes the `Referer`
     * header, the full address with its query, as the page. So the address goes in as `url`,
     * redacted like a server-side hit, and the image sends no `Referer` at all.
     */
    private function noscriptPixel(): string
    {
        $pixel = $this->connection->trackingUrl().'?'.http_build_query([
            'idsite' => $this->connection->siteId,
            'rec' => 1,
            'url' => (new UrlRedactor)->redact(URL::full()),
        ], '', '&', PHP_QUERY_RFC3986);

        return '<noscript><img referrerpolicy="no-referrer" src="'.e($pixel).'" style="border:0" alt=""></noscript>';
    }

    private function active(): bool
    {
        return Config::bool('matomo-analytics.enabled', false)
            && Config::bool('matomo-analytics.js.enabled', true)
            && $this->connection->isConfigured()
            && ! $this->pageExcluded();
    }

    /**
     * Whether the tracking config excludes this page for every visitor: an environment outside
     * `tracking.environments`, or a path `tracking.except_routes` names.
     *
     * Those two describe the page rather than the visitor, so matomo.js follows them as the
     * server does, and a page served from a cache to anyone carries the same decision. Rules
     * about the visitor, an address, a login, an ability, are decided per hit on the server and
     * for the `<noscript>` pixel; rendering the tracker by them would let one cached page decide
     * for everybody.
     */
    private function pageExcluded(): bool
    {
        $environments = Config::stringList('matomo-analytics.tracking.environments');

        if ($environments !== [] && ! App::environment($environments)) {
            return true;
        }

        $routes = Config::stringList('matomo-analytics.tracking.except_routes');

        return $routes !== [] && Str::is($routes, RequestFacade::instance()->decodedPath());
    }

    private function tracker(?string $nonce): string
    {
        $commands = ['var _paq = window._paq = window._paq || [];'];

        if (Config::bool('matomo-analytics.privacy.cookieless', true)) {
            $commands[] = "_paq.push(['disableCookies']);";
        }

        $consent = ConsentMode::resolve();
        if ($consent === ConsentMode::FULL) {
            $commands[] = "_paq.push(['requireConsent']);";
        } elseif ($consent === ConsentMode::COOKIE) {
            $commands[] = "_paq.push(['requireCookieConsent']);";
        }

        if (Config::bool('matomo-analytics.privacy.honor_dnt', true)) {
            $commands[] = "_paq.push(['setDoNotTrack', true]);";
        }

        $redaction = $this->redactionCommand();
        if ($redaction !== null) {
            $commands[] = $redaction;
        }

        // Statically-configured Custom Dimensions, set before the page view so
        // action-scoped dimensions attach to it.
        foreach ($this->customDimensionCommands() as $command) {
            $commands[] = $command;
        }

        // Native page-performance tracking is on by default in matomo.js; there is no enable
        // command, only a disable. Push it before trackPageView so nothing is collected.
        if (! Config::bool('matomo-analytics.js.performance', true)) {
            $commands[] = "_paq.push(['disablePerformanceTracking']);";
        }

        $commands[] = "_paq.push(['trackPageView']);";

        // Automatic Content Tracking impressions (all / visible), after the page view.
        $content = $this->contentTrackingCommand();
        if ($content !== null) {
            $commands[] = $content;
        }

        if (Config::bool('matomo-analytics.js.enable_link_tracking', true)) {
            $commands[] = "_paq.push(['enableLinkTracking']);";
        }

        $heartbeat = Config::int('matomo-analytics.js.heartbeat', 15);
        if ($heartbeat > 0) {
            $commands[] = "_paq.push(['enableHeartBeatTimer', {$heartbeat}]);";
        }

        $commands[] = '_paq.push(['.$this->js('setTrackerUrl').', '.$this->js($this->connection->trackingUrl()).']);';
        $commands[] = '_paq.push(['.$this->js('setSiteId').', '.$this->js((string) $this->connection->siteId).']);';
        $commands[] = "var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];";
        $commands[] = 'g.async=true;g.src='.$this->js($this->jsUrl()).';s.parentNode.insertBefore(g,s);';

        $spa = $this->spaListeners();
        if ($spa !== '') {
            $commands[] = $spa;
        }

        return $this->wrap(implode("\n", $commands), $nonce);
    }

    /**
     * The line that keeps a virtual page view off a path `tracking.except_routes` names, as the
     * server keeps the page view of a full load off it.
     *
     * The path is read the way `Request::decodedPath()` reads it, without the slashes at its ends
     * and `/` for the home page, and each pattern is matched the way `Str::is()` matches it: `*`
     * stands for anything, everything else for itself, and the whole path has to match. The
     * referrer chain is left at the last page that was tracked.
     *
     * @return list<string>
     */
    private function excludedRouteCheck(): array
    {
        $routes = array_values(array_filter(Config::stringList('matomo-analytics.tracking.except_routes'), static fn (string $route): bool => $route !== ''));

        if ($routes === []) {
            return [];
        }

        $source = '^(?:'.implode('|', array_map(static fn (string $route): string => str_replace('\\*', '.*', preg_quote($route, '/')), $routes)).')$';

        return [
            '    var path=window.location.pathname.replace(/^\\/+|\\/+$/g,"");try{path=decodeURIComponent(path);}catch(e){}',
            '    if(new RegExp('.$this->js($source).').test(path||"/")){return;}',
        ];
    }

    /**
     * Records a virtual page view on each client-side (soft) navigation that actually
     * changes the URL. Returns an empty string unless spa.enabled. Always exposes
     * window.matomoTrackPageView(), which tracks unconditionally.
     */
    private function spaListeners(): string
    {
        if (! Config::bool('matomo-analytics.spa.enabled', false)) {
            return '';
        }

        $adapters = Config::stringList('matomo-analytics.spa.adapters');

        $lines = [
            '(function(){',
            // Seed the referrer with the hard-load URL. It is what the first soft navigation
            // navigated away FROM, and leaving it empty made that first virtual page view look
            // like a direct entry — breaking exactly the flow reports the referrer chain exists
            // for. It also gives the listener guard below a value to compare against.
            '  window.__matomoSpaRef=window.location.href;',
            '  var track=function(){',
            '    if(!window._paq){return;}',
            ...$this->excludedRouteCheck(),
            '    _paq.push(['.$this->js('setReferrerUrl').', window.__matomoSpaRef||'.$this->js('').']);',
            '    _paq.push(['.$this->js('setCustomUrl').', window.location.href]);',
            '    _paq.push(['.$this->js('setDocumentTitle').', document.title]);',
        ];

        // Re-apply the configured Custom Dimensions on each virtual page view so
        // action-scoped dimensions attach to it too.
        foreach ($this->customDimensionCommands() as $command) {
            $lines[] = '    '.$command;
        }

        // A soft navigation has no native Navigation Timing, so forward an app-measured
        // window.__matomoPerf via setPagePerformanceTiming (then clear it) before this virtual
        // page view. No-op until the app populates it; never re-emits the hard-load timings.
        if (Config::bool('matomo-analytics.spa.performance', true)) {
            $lines[] = '    var p=window.__matomoPerf; if(p){_paq.push(['.$this->js('setPagePerformanceTiming').', p.net,p.srv,p.tfr,p.dm1,p.dm2,p.onl]); window.__matomoPerf=undefined;}';
        }

        $lines[] = '    _paq.push(['.$this->js('trackPageView').']);';

        // Re-scan for Content Tracking impressions surfaced by the soft navigation.
        $content = $this->contentTrackingCommand();
        if ($content !== null) {
            $lines[] = '    '.$content;
        }

        // Link tracking is re-armed for the links the navigation rendered, and only where it is
        // on: pushed regardless, it would switch on with the first navigation of a site that
        // turned it off.
        if (Config::bool('matomo-analytics.js.enable_link_tracking', true)) {
            $lines[] = '    _paq.push(['.$this->js('enableLinkTracking').']);';
        }

        $lines[] = '    window.__matomoSpaRef=window.location.href;';
        $lines[] = '  };';
        $lines[] = '  window.matomoTrackPageView=track;';

        // A framework navigation event is not by itself proof that a navigation happened.
        // Livewire's navigate plugin ends with an unconditional `setTimeout(() =>
        // fireEventForOtherLibrariesToHookInto('alpine:navigated'))`, and Livewire forwards
        // that to `livewire:navigated` — so every hard load of a Livewire app emits the event
        // once, at the URL the page already tracked. Firing on it counted every hard load
        // twice. The URL is the only honest signal that a soft navigation occurred, so the
        // adapters track on a CHANGE of it; window.matomoTrackPageView() stays unguarded for
        // the screens a URL cannot express.
        $lines[] = '  var onNav=function(){if(window.location.href===window.__matomoSpaRef){return;}track();};';

        if (in_array('livewire', $adapters, true)) {
            $lines[] = '  document.addEventListener('.$this->js('livewire:navigated').', onNav);';
        }

        if (in_array('inertia', $adapters, true)) {
            $lines[] = '  document.addEventListener('.$this->js('inertia:navigate').', onNav);';
        }

        if (in_array('generic', $adapters, true)) {
            $lines[] = '  var _p=history.pushState;history.pushState=function(){_p.apply(this,arguments);setTimeout(onNav,0);};';
            $lines[] = '  window.addEventListener('.$this->js('popstate').', function(){setTimeout(onNav,0);});';
        }

        $lines[] = '})();';

        return implode("\n", $lines);
    }

    private function tagManager(?string $nonce): string
    {
        $container = Config::string('matomo-analytics.js.tag_manager');

        $commands = [
            'var _mtm = window._mtm = window._mtm || [];',
            "_mtm.push({'mtm.startTime':(new Date().getTime()),'event':'mtm.Start'});",
            "var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];",
            'g.async=true;g.src='.$this->js($container).';s.parentNode.insertBefore(g,s);',
        ];

        return $this->wrap(implode("\n", $commands), $nonce);
    }

    private function nonceAttribute(?string $nonce): string
    {
        return $nonce !== null && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '';
    }

    /**
     * Keeps a tag from being re-executed on every client-side navigation.
     *
     * Livewire's navigate plugin re-runs every <script> in the body it swaps in, unless the
     * tag carries this attribute AND the plugin has already seen that tag's hash. Both
     * snippets here register listeners on `document`, which survives the swap — so a re-run
     * never replaces the old registration, it adds another one beside it.
     *
     * For the tracker that means a second bootstrap and another matomo.js insert per hop; for
     * web vitals it means every metric is reported once per hop the session has made, which
     * scales an app's published Core Web Vitals with its navigation depth. Neither errors.
     *
     * The hash ignores `data-csrf`, `nonce` and `aria-hidden`, so a per-request CSP nonce does
     * not defeat it. Everything else these snippets emit comes from configuration and is
     * therefore identical between two pages of one app. Where it is NOT — a per-page site id,
     * say — the hash differs, Livewire re-runs the tag, and the new configuration applies:
     * the attribute degrades toward today's behavior rather than into a stale tracker.
     *
     * Outside Livewire it is an inert data attribute.
     */
    private function runOnceAttribute(): string
    {
        return ' data-navigate-once';
    }

    private function wrap(string $javascript, ?string $nonce): string
    {
        $html = '<script'.$this->runOnceAttribute().$this->nonceAttribute($nonce).'>'."\n".$javascript."\n".'</script>';

        if (Config::bool('matomo-analytics.js.dns_prefetch', true)) {
            $html = '<link rel="dns-prefetch" href="'.e($this->connection->host).'">'."\n".$html;

            $jsHost = $this->jsHost();
            if ($jsHost !== $this->connection->host) {
                $html = '<link rel="dns-prefetch" href="'.e($jsHost).'">'."\n".$html;
            }
        }

        if (Config::bool('matomo-analytics.js.noscript', true) && $this->pixelAllowed()) {
            $html .= "\n".$this->noscriptPixel();
        }

        return $html;
    }

    private function jsUrl(): string
    {
        return $this->jsHost().'/'.ltrim(Config::string('matomo-analytics.js_path', 'matomo.js'), '/');
    }

    /**
     * Where matomo.js is loaded from — the tracker host by default, or a separate
     * asset host (e.g. a Matomo Cloud CDN) when js.host is set. Tracking itself
     * always stays on the tracker host.
     */
    private function jsHost(): string
    {
        $host = Config::nullableString('matomo-analytics.js.host');

        return $host !== null ? rtrim($host, '/') : $this->connection->host;
    }

    /**
     * setCustomDimension pushes for the configured js.custom_dimensions map
     * (dimension id => value). Non-positive or non-integer ids are skipped.
     *
     * @return list<string>
     */
    private function customDimensionCommands(): array
    {
        $commands = [];

        foreach (Config::scalarMap('matomo-analytics.js.custom_dimensions') as $id => $value) {
            if (is_int($id) && $id > 0) {
                $commands[] = '_paq.push(['.$this->js('setCustomDimension').', '.$id.', '.$this->js((string) $value).']);';
            }
        }

        return $commands;
    }

    /**
     * The Content Tracking impression push for js.content_tracking ('all' scans
     * every content block, 'visible' only those in the viewport), or null when off.
     */
    private function contentTrackingCommand(): ?string
    {
        return match (Config::nullableString('matomo-analytics.js.content_tracking')) {
            'all' => '_paq.push(['.$this->js('trackAllContentImpressions').']);',
            'visible' => '_paq.push(['.$this->js('trackVisibleContentImpressions').']);',
            default => null,
        };
    }

    /**
     * The URL redaction of {@see UrlRedactor} for the requests matomo.js sends from the browser,
     * or null when redaction is off or names nothing.
     *
     * Those requests never pass the server, so `setCustomRequestProcessing` takes each one's
     * query string after the tracker built it and before it is sent. The function decodes the
     * parameters named in `privacy.redact.keys`, replaces the value of every name in
     * `privacy.redact.query_params` with the server's grammar, and encodes them again.
     * `privacy.redact.patterns` are PCRE and apply on the server only.
     */
    private function redactionCommand(): ?string
    {
        if (! Config::bool('matomo-analytics.privacy.redact.enabled', true)) {
            return null;
        }

        $redactor = new UrlRedactor;
        $names = array_values(array_filter(Config::stringList('matomo-analytics.privacy.redact.query_params'), static fn (string $name): bool => $name !== ''));
        $keys = $redactor->keys();
        $paths = $redactor->pathPatterns();

        if (($names === [] && $paths === []) || $keys === []) {
            return null;
        }

        $process = <<<'JS'
            _paq.push(['setCustomRequestProcessing', (function(names, keys, forms, replacement, paths){
              var rx = names.length ? new RegExp('([?&#](?:' + names.map(function(n){ return n.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&'); }).join('|') + ')' + forms + '=)[^&#]*', 'gi') : null;
              var px = paths.map(function(p){ return new RegExp(p); });
              var redact = function(url){
                px.forEach(function(p){ url = url.replace(p, function(match, prefix){ return prefix + replacement; }); });
                var start = url.search(/[?#]/);
                return start < 0 || !rx ? url : url.slice(0, start) + url.slice(start).replace(rx, function(match, key){ return key + replacement; });
              };
              return function(request){
                return request.split('&').map(function(pair){
                  var at = pair.indexOf('=');
                  if (at < 0 || keys.indexOf(pair.slice(0, at)) < 0) { return pair; }
                  try { return pair.slice(0, at + 1) + encodeURIComponent(redact(decodeURIComponent(pair.slice(at + 1)))); } catch (e) { return pair; }
                }).join('&');
              };
            })(__NAMES__, __KEYS__, __FORMS__, __REPLACEMENT__, __PATHS__)]);
            JS;

        return strtr($process, [
            '__NAMES__' => $this->jsList($names),
            '__KEYS__' => $this->jsList($keys),
            '__FORMS__' => $this->js(UrlRedactor::ARRAY_FORMS),
            '__REPLACEMENT__' => $this->js(rawurlencode(Config::string('matomo-analytics.privacy.redact.replacement', 'REDACTED'))),
            '__PATHS__' => $this->jsList($paths),
        ]);
    }

    /**
     * @param  list<string>  $values
     */
    private function jsList(array $values): string
    {
        return '['.implode(',', array_map($this->js(...), $values)).']';
    }

    private function js(string $value): string
    {
        // Mirror Laravel's Js::from() flag set: JSON_HEX_TAG escapes "<" and ">" to
        // their \u00XX form so an embedded value can never close the <script> block,
        // and HEX_AMP/APOS/QUOT keep it safe in an HTML-attribute context too.
        // JSON_UNESCAPED_SLASHES only keeps URLs readable; it is HEX_TAG, not
        // slash-escaping, that closes the "</script>" breakout.
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT,
        );
    }
}
