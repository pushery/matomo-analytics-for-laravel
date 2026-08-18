<?php

declare(strict_types=1);

namespace MatomoAnalytics\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config as ConfigFacade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event as EventFacade;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Events\TrackingFailed;
use MatomoAnalytics\Events\TrackingSent;
use MatomoAnalytics\Exceptions\TrackingSendException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * Durably delivers a batch of hits via the Sender. Failures are retried with
 * escalating backoff up to the configured attempt budget; only after the
 * configured attempt threshold is a (throttled) report raised, and a batch that
 * exhausts the budget is dead-lettered so nothing is silently lost.
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
        $configured = ConfigFacade::get('matomo-analytics.queue.backoff');
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

    /**
     * \DateTimeInterface, not a concrete Carbon: a host app may run
     * Date::use(CarbonImmutable::class), and Date::now() then returns
     * CarbonImmutable — a concrete Illuminate\Support\Carbon return type
     * fatals the queue worker with a TypeError.
     */
    public function retryUntil(): DateTimeInterface
    {
        return Date::now()->addMinutes(Config::int('matomo-analytics.queue.retry_until_minutes', 1440));
    }

    public function handle(Sender $sender, Reporter $reporter, DeadLetterStore $deadLetters): void
    {
        try {
            $result = $sender->send($this->payloads);
        } catch (Throwable $e) {
            $this->absorb($reporter, $deadLetters, $e);

            return;
        }

        if ($result->failed()) {
            $this->absorb($reporter, $deadLetters, TrackingSendException::status($result->status));

            return;
        }

        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new TrackingSent(count($this->payloads), $result->status));
        }
    }

    /**
     * Only reachable when `resilience.never_throw` is off, because that is the one
     * configuration in which this job still lets an exception escape.
     */
    public function failed(Throwable $exception): void
    {
        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new TrackingFailed($exception));
        }

        App::make(Reporter::class)->report($exception, ['final' => 1]);
    }

    /**
     * A delivery failure is ABSORBED rather than rethrown.
     *
     * Rethrowing is what reached the host application, and no setting in this package
     * could stop it: `Worker::runJob` catches whatever a job throws and hands it to the
     * app's OWN ExceptionHandler, so every failed attempt became one entry in the app's
     * error dashboard — past this package's `report_after_attempts` gate, past its
     * per-signature throttle, and past `channel => 'silent'`. It also made
     * `resilience.never_throw` untrue for the queued sender while it held for the
     * synchronous one, which is the harder half to notice.
     *
     * Absorbing keeps the retry rather than replacing it: the job is released with the
     * same backoff the worker would have applied, so pacing and escalation behave as
     * before. What changes is that the retry loop is now bounded by `queue.tries` — see
     * the note on that bound below.
     */
    private function absorb(Reporter $reporter, DeadLetterStore $deadLetters, Throwable $e): void
    {
        $this->escalate($reporter, $e);

        if (! Config::bool('matomo-analytics.resilience.never_throw', true)) {
            throw $e;
        }

        if ($this->attempts() < $this->tries()) {
            $this->release($this->backoffFor($this->attempts()));

            return;
        }

        $this->exhaust($deadLetters, $e);
    }

    /**
     * The batch exhausted its attempt budget: park it in the dead-letter store, where
     * `matomo:replay` can put it back, and delete the job so the queue does not also
     * record it as failed. This mirrors what the buffered sender already does — the two
     * delivery modes had different failure semantics for no stated reason.
     */
    private function exhaust(DeadLetterStore $deadLetters, Throwable $e): void
    {
        // Record before deleting: if the dead-letter write throws, the job is neither
        // deleted nor released, so the worker's own handling still owns the batch and
        // the hits are not lost between the two steps.
        $deadLetters->record($this->payloads, $this->attempts(), $e->getMessage());

        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new TrackingFailed($e));
        }

        App::make(Reporter::class)->report($e, ['final' => 1]);

        $this->delete();
    }

    /**
     * The worker's own rule, mirrored so released attempts keep their pacing:
     * index the backoff list by attempt, and hold at the last entry once past its end.
     */
    private function backoffFor(int $attempt): int
    {
        $backoff = $this->backoff();

        return $backoff[$attempt - 1] ?? $backoff[count($backoff) - 1];
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
