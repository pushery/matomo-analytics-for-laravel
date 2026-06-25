<?php

declare(strict_types=1);

namespace MatomoAnalytics;

use Closure;
use Illuminate\Support\Facades\Bus;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Contracts\TrackingGate;
use MatomoAnalytics\Events\TrackingQueued;
use MatomoAnalytics\Events\TrackingSent;
use MatomoAnalytics\Events\VisitorExcluded;
use MatomoAnalytics\Exceptions\TrackingSendException;
use MatomoAnalytics\Jobs\SendHitsJob;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use MatomoAnalytics\Tracking\Download;
use MatomoAnalytics\Tracking\Event;
use MatomoAnalytics\Tracking\Goal;
use MatomoAnalytics\Tracking\Hit;
use MatomoAnalytics\Tracking\Outlink;
use MatomoAnalytics\Tracking\PageView;
use MatomoAnalytics\Tracking\Ping;
use MatomoAnalytics\Tracking\SiteSearch;
use Throwable;

/**
 * Entry point for tracking. Bound as a per-request (scoped) service so its
 * collected-hit buffer never leaks across requests (Octane-safe). In sync mode a
 * hit is sent immediately; otherwise it is collected and flushed as one queued
 * Bulk request on terminate. Every call is wrapped so tracking can never throw
 * into the host application.
 */
final class TrackManager implements Tracker
{
    /**
     * @var list<array<string, scalar>>
     */
    private array $pending = [];

    public function __construct(
        private readonly PayloadBuilder $builder,
        private readonly TrackingGate $gate,
        private readonly Sender $sender,
        private readonly Reporter $reporter,
        private readonly HitBuffer $buffer,
    ) {}

    public function track(Hit $hit): static
    {
        $this->safe(function () use ($hit): void {
            $request = request();

            $decision = $this->gate->decide($request, $hit);
            if (! $decision->allowed) {
                if ($decision->reason !== null && Config::bool('matomo-analytics.events', true)) {
                    event(new VisitorExcluded($decision->reason));
                }

                return;
            }

            $payload = $this->builder->build($hit, $request);

            $mode = Config::string('matomo-analytics.mode', 'queue');

            if ($mode === 'sync') {
                $this->sendNow([$payload]);

                return;
            }

            if ($mode === 'batch') {
                $this->buffer->push($payload);

                if (Config::bool('matomo-analytics.events', true)) {
                    event(new TrackingQueued([$payload]));
                }

                return;
            }

            $this->pending[] = $payload;
        });

        return $this;
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $payloads = $this->pending;
        $this->pending = [];

        if (Config::bool('matomo-analytics.events', true)) {
            event(new TrackingQueued($payloads));
        }

        Bus::dispatch(new SendHitsJob($payloads));
    }

    public function pageView(string $title, ?string $url = null): static
    {
        return $this->track(new PageView($title, $url));
    }

    public function event(string $category, string $action, ?string $name = null, int|float|null $value = null): static
    {
        return $this->track(new Event($category, $action, $name, $value));
    }

    public function siteSearch(string $keyword, ?string $category = null, ?int $count = null): static
    {
        return $this->track(new SiteSearch($keyword, $category, $count));
    }

    public function goal(int $id, ?float $revenue = null): static
    {
        return $this->track(new Goal($id, $revenue));
    }

    public function download(string $url): static
    {
        return $this->track(new Download($url));
    }

    public function outlink(string $url): static
    {
        return $this->track(new Outlink($url));
    }

    public function ping(): static
    {
        return $this->track(new Ping);
    }

    /**
     * @param  list<array<string, scalar>>  $payloads
     */
    private function sendNow(array $payloads): void
    {
        $result = $this->sender->send($payloads);

        if ($result->failed()) {
            $this->reporter->report(TrackingSendException::status($result->status), ['stage' => 'sync']);

            return;
        }

        if (Config::bool('matomo-analytics.events', true)) {
            event(new TrackingSent(count($payloads), $result->status));
        }
    }

    private function safe(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (! Config::bool('matomo-analytics.resilience.never_throw', true)) {
                throw $e;
            }

            $this->reporter->report($e, ['stage' => 'dispatch']);
        }
    }
}
