<?php

declare(strict_types=1);

namespace MatomoAnalytics\View;

use Illuminate\Support\Facades\URL;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Support\Config;

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

        $glue = implode("\n", [
            '(function(){',
            '  var wv=window.webVitals; if(!wv){return;}',
            '  var send=function(m){try{navigator.sendBeacon('.$path.',new Blob([JSON.stringify({metric:m.name,value:m.value,rating:m.rating,navigationType:m.navigationType})],{type:"application/json"}));}catch(e){}};',
            '  '.$names.'.forEach(function(n){var f=wv["on"+n];if(f){f(send);}});',
            '})();',
        ]);

        $script = '<script'.$this->nonceAttribute($nonce).'>'."\n".$glue."\n".'</script>';

        $library = Config::nullableString('matomo-analytics.web_vitals.library');
        if ($library !== null) {
            return '<script'.$this->nonceAttribute($nonce).' src="'.e($library).'"></script>'."\n".$script;
        }

        return $script;
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
        return $this->active() ? $this->noscriptPixel() : '';
    }

    public function optOut(): string
    {
        if (! $this->connection->isConfigured()) {
            return '';
        }

        $url = $this->connection->host.'/index.php?module=CoreAdminHome&action=optOut&language=auto';

        return '<iframe title="Matomo opt-out" style="border:0;height:200px;width:100%;" src="'.e($url).'"></iframe>';
    }

    private function noscriptPixel(): string
    {
        $pixel = $this->connection->trackingUrl().'?idsite='.$this->connection->siteId.'&rec=1';

        return '<noscript><img referrerpolicy="no-referrer-when-downgrade" src="'.e($pixel).'" style="border:0" alt=""></noscript>';
    }

    private function active(): bool
    {
        return Config::bool('matomo-analytics.enabled', false)
            && Config::bool('matomo-analytics.js.enabled', true)
            && $this->connection->isConfigured();
    }

    private function tracker(?string $nonce): string
    {
        $commands = ['var _paq = window._paq = window._paq || [];'];

        if (Config::bool('matomo-analytics.privacy.cookieless', true)) {
            $commands[] = "_paq.push(['disableCookies']);";
        }

        $consent = Config::string('matomo-analytics.privacy.consent', 'none');
        if ($consent === 'full') {
            $commands[] = "_paq.push(['requireConsent']);";
        } elseif ($consent === 'cookie') {
            $commands[] = "_paq.push(['requireCookieConsent']);";
        }

        if (Config::bool('matomo-analytics.privacy.honor_dnt', true)) {
            $commands[] = "_paq.push(['setDoNotTrack', true]);";
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

        $lines[] = '    _paq.push(['.$this->js('enableLinkTracking').']);';
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

    private function wrap(string $javascript, ?string $nonce): string
    {
        $html = '<script'.$this->nonceAttribute($nonce).'>'."\n".$javascript."\n".'</script>';

        if (Config::bool('matomo-analytics.js.dns_prefetch', true)) {
            $html = '<link rel="dns-prefetch" href="'.e($this->connection->host).'">'."\n".$html;

            $jsHost = $this->jsHost();
            if ($jsHost !== $this->connection->host) {
                $html = '<link rel="dns-prefetch" href="'.e($jsHost).'">'."\n".$html;
            }
        }

        if (Config::bool('matomo-analytics.js.noscript', true)) {
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
