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
use MatomoAnalytics\Events\HitsDeadLettered;
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
     *
     * NULLABLE, BECAUSE THE FRAMEWORK'S CONTRACT IS. `CallQueuedHandler::failed()` passes
     * `?Throwable`, and it really can be null: a job killed by `queue:work --timeout`, or one
     * failed through `Queue::failing()` without an exception, arrives here with nothing to
     * report. A non-nullable parameter turns that into a TypeError inside the worker's own
     * failure handling — the one place an error has nowhere left to go.
     */
    public function failed(?Throwable $exception): void
    {
        // Nothing to say and nothing to report. The batch is already in `failed_jobs`; a
        // TrackingFailed carrying a manufactured exception would be a worse answer than none.
        if (! $exception instanceof Throwable) {
            return;
        }

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
        // THE OPT-OUT, honored on the queue path too. The dead-letter migration has always
        // documented `batch.dead_letter.enabled = false`, and `BufferFlusher` has always
        // respected it — but only for BATCH mode. Queue mode reached `record()`
        // unconditionally, so an installation that switched the store off still had rows
        // written to it, and an installation that suppressed the package migrations got
        // "Undefined table" in place of its actual delivery error.
        //
        // The migration's wording, "then failed batches stay in the buffer", describes batch
        // mode; queue mode has no buffer to stay in. Its honest equivalent is the job failing
        // the ordinary way, which is what `fail()` does: the batch lands in `failed_jobs`,
        // visible and re-runnable.
        //
        // `fail()` and NOT `throw`, deliberately. Rethrowing would hand the exception to the
        // application's own handler — exactly the behavior 0.20.0 removed, and it would make
        // `resilience.never_throw` untrue again for the mode most installations run.
        if (! Config::bool('matomo-analytics.batch.dead_letter.enabled', true)) {
            $this->fail($e);

            return;
        }

        // Record before deleting: if the dead-letter write throws, the job is neither
        // deleted nor released, so the worker's own handling still owns the batch and
        // the hits are not lost between the two steps.
        //
        // AND A WRITE THAT COULD NOT HAPPEN IS NOT A WRITE. `record()` answers false when
        // there is no table — the state an installation reaches by calling
        // `ignoreMigrations()` while leaving the store switched on, which the installation
        // guide suggests and nothing here used to reconcile. Parking was impossible, so the
        // batch takes the same honest route as a switched-off store: `failed_jobs`, where
        // it is visible and re-runnable, rather than a `delete()` that would drop it.
        if (! $deadLetters->record($this->payloads, $this->attempts(), $e->getMessage())) {
            $this->fail($e);

            return;
        }

        if (Config::bool('matomo-analytics.events', true)) {
            // BOTH events, and neither stands in for the other. `TrackingFailed` says this
            // batch will not be attempted again; `HitsDeadLettered` says where it went. The
            // queue path fired only the first, so the listener the documentation recommends
            // for exactly this alarm — "a HitsDeadLettered event fires whenever a batch is
            // dead-lettered" — never fired in the shipped default mode.
            //
            // THIS SENTENCE USED TO END "the batch path has dispatched both all along",
            // AND THE BATCH PATH HAD NEVER DISPATCHED `TrackingFailed` AT ALL. A comment
            // asserting a neighbour's behavior is a claim nothing checks, and this one sent
            // three readers past the defect: the config file, the provider and the 0.24.0
            // changelog all repeated it. `BufferFlusher::deadLetter()` dispatches both now.
            EventFacade::dispatch(new TrackingFailed($e));
            EventFacade::dispatch(new HitsDeadLettered(count($this->payloads), $this->attempts()));
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
