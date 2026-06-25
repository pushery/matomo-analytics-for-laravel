<?php

declare(strict_types=1);

namespace MatomoAnalytics\View;

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
        if (! Config::bool('matomo-analytics.enabled', true) || ! Config::bool('matomo-analytics.web_vitals.enabled', false)) {
            return '';
        }

        $path = $this->js(url(Config::string('matomo-analytics.web_vitals.path', 'matomo-analytics/web-vitals')));
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

    public function optOut(): string
    {
        if (! $this->connection->isConfigured()) {
            return '';
        }

        $url = $this->connection->host.'/index.php?module=CoreAdminHome&action=optOut&language=auto';

        return '<iframe title="Matomo opt-out" style="border:0;height:200px;width:100%;" src="'.e($url).'"></iframe>';
    }

    private function active(): bool
    {
        return Config::bool('matomo-analytics.enabled', true)
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

        $commands[] = "_paq.push(['trackPageView']);";

        if (Config::bool('matomo-analytics.js.enable_link_tracking', true)) {
            $commands[] = "_paq.push(['enableLinkTracking']);";
        }

        $heartbeat = Config::int('matomo-analytics.js.heartbeat', 0);
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
     * Records a virtual page view on each client-side (soft) navigation. Returns an
     * empty string unless spa.enabled. Always exposes window.matomoTrackPageView().
     */
    private function spaListeners(): string
    {
        if (! Config::bool('matomo-analytics.spa.enabled', false)) {
            return '';
        }

        $adapters = Config::stringList('matomo-analytics.spa.adapters');

        $lines = [
            '(function(){',
            '  var track=function(){',
            '    if(!window._paq){return;}',
            '    _paq.push(['.$this->js('setReferrerUrl').', window.__matomoSpaRef||'.$this->js('').']);',
            '    _paq.push(['.$this->js('setCustomUrl').', window.location.href]);',
            '    _paq.push(['.$this->js('setDocumentTitle').', document.title]);',
            '    _paq.push(['.$this->js('trackPageView').']);',
            '    _paq.push(['.$this->js('enableLinkTracking').']);',
            '    window.__matomoSpaRef=window.location.href;',
            '  };',
            '  window.matomoTrackPageView=track;',
        ];

        if (in_array('livewire', $adapters, true)) {
            $lines[] = '  document.addEventListener('.$this->js('livewire:navigated').', track);';
        }

        if (in_array('inertia', $adapters, true)) {
            $lines[] = '  document.addEventListener('.$this->js('inertia:navigate').', track);';
        }

        if (in_array('generic', $adapters, true)) {
            $lines[] = '  var _p=history.pushState;history.pushState=function(){_p.apply(this,arguments);setTimeout(track,0);};';
            $lines[] = '  window.addEventListener('.$this->js('popstate').', function(){setTimeout(track,0);});';
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
            $pixel = $this->connection->trackingUrl().'?idsite='.$this->connection->siteId.'&rec=1';
            $html .= "\n".'<noscript><img referrerpolicy="no-referrer-when-downgrade" src="'.e($pixel).'" style="border:0" alt=""></noscript>';
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

    private function js(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
