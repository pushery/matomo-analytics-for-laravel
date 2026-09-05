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
use MatomoAnalytics\Support\CallableResolver;
use MatomoAnalytics\Support\ClientIp;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Tracking\Hit;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The single tracking predicate consulted before every dispatch. First false
 * wins; every rule is config-driven.
 */
final readonly class DefaultTrackingGate implements TrackingGate
{
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

        if (! Config::bool('matomo-analytics.bots.track', false) && $this->botDetector->isBot($request->userAgent() ?? '')) {
            return GateDecision::deny('bot');
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

        $cookie = Config::string('matomo-analytics.privacy.opt_out.cookie', 'matomo_opt_out');
        if ($cookie === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $value = $request->cookie($cookie);

        return is_string($value) && $value !== '';
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
     * `except_routes` USED TO MEASURE THE REQUEST AND NOTHING ELSE, so a Web Vitals beacon
     * was tested against `/matomo-analytics/web-vitals` — a path no exclusion list ever names.
     * Measured with `except_routes => ['admin/*']`: a page view on `/admin/customers` was
     * denied with reason `route`, and a beacon measured ON that page was allowed. Both
     * `web-vitals.md` ("an excluded route produces no event") and `tracking-gate.md` ("every
     * hit passes through one gate") describe the first case and not the second.
     *
     * A hit that carries its own `url` is telling us where it happened; anything else is
     * about the request it arrived on, which is the ordinary case and unchanged.
     */
    private function trackedPath(Request $request, Hit $hit): string
    {
        $url = $hit->toParams()['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return $request->decodedPath();
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? trim(rawurldecode($path), '/') : $request->decodedPath();
    }

    private function excludedByIp(Request $request): bool
    {
        $ips = Config::stringList('matomo-analytics.tracking.except_ips');
        if ($ips === []) {
            return false; // @pest-mutate-ignore: RemoveEarlyReturn
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
