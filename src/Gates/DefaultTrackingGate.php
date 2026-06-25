<?php

declare(strict_types=1);

namespace MatomoAnalytics\Gates;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        if (! Config::bool('matomo-analytics.enabled', true)) {
            return GateDecision::deny('disabled');
        }

        if (! $this->connection->isConfigured()) {
            return GateDecision::deny('not_configured');
        }

        $environments = Config::stringList('matomo-analytics.tracking.environments');
        if ($environments !== [] && ! app()->environment($environments)) {
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
        if ($routes !== [] && $request->is(...$routes)) {
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
        if ($cookie === '') {
            return false;
        }

        $value = $request->cookie($cookie);

        return is_string($value) && $value !== '';
    }

    private function excludedByAbility(Request $request): bool
    {
        $abilities = Config::stringList('matomo-analytics.tracking.except_abilities');
        if ($abilities === []) {
            return false;
        }

        $user = $request->user();

        return $user !== null && Gate::forUser($user)->any($abilities);
    }

    private function excludedByIp(Request $request): bool
    {
        $ips = Config::stringList('matomo-analytics.tracking.except_ips');
        if ($ips === []) {
            return false;
        }

        $ip = ClientIp::resolve($request);

        return $ip !== null && IpUtils::checkIp($ip, $ips);
    }

    private function deniedByCustomGate(Request $request, Hit $hit): bool
    {
        $callable = CallableResolver::resolve(config('matomo-analytics.tracking.gate'));

        return $callable !== null && $callable($request, $hit) === false;
    }
}
