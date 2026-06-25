<?php

declare(strict_types=1);

namespace MatomoAnalytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Events\TrackingFailed;
use MatomoAnalytics\Events\TrackingSent;
use MatomoAnalytics\Exceptions\TrackingSendException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * Durably delivers a batch of hits via the Sender. Failures are retried with
 * escalating backoff up to a deadline; only after the configured attempt
 * threshold is a (throttled) report raised, and an exhausted job lands in
 * failed_jobs so nothing is silently lost.
 */
final class SendHitsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array<string, scalar>>  $payloads
     */
    public function __construct(
        public array $payloads,
    ) {
        $this->onConnection(Config::nullableString('matomo-analytics.queue.connection'));
        $this->onQueue(Config::string('matomo-analytics.queue.queue', 'matomo'));
    }

    public function tries(): int
    {
        return Config::int('matomo-analytics.queue.tries', 5);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $configured = config('matomo-analytics.queue.backoff');
        if (! is_array($configured)) {
            return [30, 120, 300, 900];
        }

        $backoff = [];
        foreach ($configured as $seconds) {
            if (is_int($seconds)) {
                $backoff[] = $seconds;
            } elseif (is_numeric($seconds)) {
                $backoff[] = (int) $seconds;
            }
        }

        return $backoff === [] ? [30] : $backoff;
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(Config::int('matomo-analytics.queue.retry_until_minutes', 1440));
    }

    public function handle(Sender $sender, Reporter $reporter): void
    {
        try {
            $result = $sender->send($this->payloads);
        } catch (Throwable $e) {
            $this->escalate($reporter, $e);

            throw $e;
        }

        if ($result->failed()) {
            $exception = TrackingSendException::status($result->status);
            $this->escalate($reporter, $exception);

            throw $exception;
        }

        if (Config::bool('matomo-analytics.events', true)) {
            event(new TrackingSent(count($this->payloads), $result->status));
        }
    }

    public function failed(Throwable $exception): void
    {
        if (Config::bool('matomo-analytics.events', true)) {
            event(new TrackingFailed($exception));
        }

        app(Reporter::class)->report($exception, ['final' => 1]);
    }

    private function escalate(Reporter $reporter, Throwable $e): void
    {
        if ($reporter->shouldReport($this->attempts())) {
            $reporter->report($e, ['attempt' => $this->attempts()]);

            return;
        }

        $reporter->recordTransient($e);
    }
}
