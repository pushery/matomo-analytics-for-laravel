<?php

declare(strict_types=1);

namespace MatomoAnalytics\Gates;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config as ConfigFacade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\BotDetector;
use MatomoAnalytics\Contracts\TrackingGate;
use MatomoAnalytics\Privacy\ConsentMode;
use MatomoAnalytics\Support\CallableResolver;
use MatomoAnalytics\Support\ClientIp;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\ConsoleRequest;
use MatomoAnalytics\Support\LivewireUpdate;
use MatomoAnalytics\Support\SpeculativeRequest;
use MatomoAnalytics\Tracking\Hit;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The single tracking predicate consulted before every dispatch. First false
 * wins; every rule is config-driven.
 */
final readonly class DefaultTrackingGate implements TrackingGate
{
    /**
     * The first-party cookie Matomo's JavaScript opt-out writes, which `@matomoOptOut` renders.
     * matomo.js stops tracking once it is there; reading it here stops the server side as well.
     */
    public const string MATOMO_OPT_OUT_COOKIE = 'mtm_consent_removed';

    /** The first-party cookie in which matomo.js remembers a consent given with `rememberConsentGiven`. */
    public const string MATOMO_CONSENT_COOKIE = 'mtm_consent';

    public function __construct(
        private Connection $connection,
        private BotDetector $botDetector,
    ) {}

    public function decide(Request $request, Hit $hit): GateDecision
    {
        if (! Config::bool('matomo-analytics.enabled', false)) {
            return GateDecision::deny('disabled');
        }

        if (! $this->connection->isConfigured()) {
            return GateDecision::deny('not_configured');
        }

        $environments = Config::stringList('matomo-analytics.tracking.environments');
        if ($environments !== [] && ! App::environment($environments)) {
            return GateDecision::deny('environment');
        }

        if (Config::bool('matomo-analytics.privacy.honor_dnt', true) && $this->doesNotTrack($request)) {
            return GateDecision::deny('dnt');
        }

        if ($this->optedOut($request)) {
            return GateDecision::deny('opted_out');
        }

        if ($this->lacksConsent($request)) {
            return GateDecision::deny('no_consent');
        }

        if (! Config::bool('matomo-analytics.bots.track', false) && $this->botDetector->isBot($request->userAgent() ?? '')) {
            return GateDecision::deny('bot');
        }

        // Every hit of a speculative request, not only the page view the middleware builds: an
        // event or a page view the application sends while rendering a prefetched 404 describes
        // a page no reader has seen.
        if (Config::bool('matomo-analytics.tracking.skip_prefetch', true) && SpeculativeRequest::is($request)) {
            return GateDecision::deny('prefetch');
        }

        if (! Config::bool('matomo-analytics.tracking.track_authenticated', true) && $request->user() !== null) {
            return GateDecision::deny('authenticated');
        }

        if ($this->excludedByAbility($request)) {
            return GateDecision::deny('ability');
        }

        if ($this->excludedByIp($request)) {
            return GateDecision::deny('ip');
        }

        $routes = Config::stringList('matomo-analytics.tracking.except_routes');
        if ($routes !== [] && Str::is($routes, $this->trackedPath($request, $hit))) {
            return GateDecision::deny('route');
        }

        if ($this->deniedByCustomGate($request, $hit)) {
            return GateDecision::deny('gate');
        }

        return GateDecision::allow();
    }

    private function doesNotTrack(Request $request): bool
    {
        if ($request->headers->get('DNT') === '1') {
            return true;
        }

        return $request->headers->get('Sec-GPC') === '1';
    }

    private function optedOut(Request $request): bool
    {
        if (! Config::bool('matomo-analytics.privacy.opt_out.respect', true)) {
            return false;
        }

        // A visitor who opted out through `@matomoOptOut` opted out of this site's tracking, not
        // only of the half that runs in the browser. JavaScript writes the cookie in plain text,
        // so the provider exempts it from `EncryptCookies`, which would otherwise drop it.
        $matomo = $request->cookie(self::MATOMO_OPT_OUT_COOKIE);
        if (is_string($matomo) && $matomo !== '') {
            return true;
        }

        $cookie = Config::string('matomo-analytics.privacy.opt_out.cookie', 'matomo_opt_out');
        if ($cookie === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $value = $request->cookie($cookie);

        return is_string($value) && $value !== '';
    }

    /**
     * Under `privacy.consent => 'full'` a hit waits for the consent matomo.js remembers in the
     * first-party cookie `mtm_consent`, as matomo.js waits for it in the browser. A job or a
     * command has no visitor and no cookie, so there the application sends only what it may.
     */
    private function lacksConsent(Request $request): bool
    {
        if (ConsentMode::resolve() !== ConsentMode::FULL || ConsoleRequest::isSynthetic($request)) {
            return false;
        }

        $consent = $request->cookie(self::MATOMO_CONSENT_COOKIE);

        return ! is_string($consent) || $consent === '';
    }

    private function excludedByAbility(Request $request): bool
    {
        $abilities = Config::stringList('matomo-analytics.tracking.except_abilities');
        if ($abilities === []) {
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $user = $request->user();

        return $user !== null && Gate::forUser($user)->any($abilities);
    }

    /**
     * The path this hit is ABOUT, which is not always the path it arrived on.
     *
     * A beacon arrives on the package's own route, such as `/matomo-analytics/web-vitals`, a
     * path no exclusion list names, while what it measured happened on the page in its `url`.
     * Tested against that page, `except_routes => ['admin/*']` denies a page view on
     * `/admin/customers` and a beacon measured on it alike, as the documentation promises.
     *
     * A hit that carries its own `url` is telling us where it happened. A hit from a Livewire
     * component action arrives on Livewire's update endpoint and happened on the component's
     * page, so it is judged by that page, which `livewire/*` does not name. Anything else is
     * about the request it arrived on, which is the ordinary case. Either way the home page is
     * `/`, as `Request::path()` names it, so one pattern matches its page view and its beacons.
     *
     * A URL without a path names the home page too: `url('/')` and `route()` write it as
     * `https://example.com`, with no slash. Only a URL that cannot be parsed names no page, and
     * such a hit is judged by the request it arrived on.
     */
    private function trackedPath(Request $request, Hit $hit): string
    {
        $url = $hit->toParams()['url'] ?? null;

        if (! is_string($url) || $url === '') {
            $url = LivewireUpdate::pageUrl($request);
        }

        if ($url === null) {
            return $request->decodedPath();
        }

        $path = parse_url($url, PHP_URL_PATH);

        if ($path === false) {
            return $request->decodedPath();
        }

        $path = trim(rawurldecode($path ?? ''), '/');

        return $path === '' ? '/' : $path;
    }

    private function excludedByIp(Request $request): bool
    {
        $ips = Config::stringList('matomo-analytics.tracking.except_ips');
        if ($ips === []) {
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // The 127.0.0.1 of the request Laravel invents for a console process is nobody's
        // address, and matching it against the list would drop every hit a job or command sends.
        if (ConsoleRequest::isSynthetic($request)) {
            return false;
        }

        $ip = ClientIp::resolve($request);

        return $ip !== null && IpUtils::checkIp($ip, $ips);
    }

    private function deniedByCustomGate(Request $request, Hit $hit): bool
    {
        $callable = CallableResolver::resolve(ConfigFacade::get('matomo-analytics.tracking.gate'));

        return $callable !== null && $callable($request, $hit) === false;
    }
}
