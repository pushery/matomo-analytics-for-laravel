<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\Event as EventFacade;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Events\HitsDeadLettered;
use MatomoAnalytics\Events\TrackingSent;
use MatomoAnalytics\Exceptions\TrackingSendException;
use MatomoAnalytics\Exceptions\UnreadableBatchException;
use MatomoAnalytics\Support\Config;
use MatomoAnalytics\Support\Reporter;
use Throwable;

/**
 * Drains the buffer in batches via the Bulk API. Each batch is acked only on a
 * confirmed 200. On a permanent failure (HTTP 4xx) the batch is poison — it is
 * dead-lettered immediately so it never blocks the queue. On a transient failure
 * (timeout / 5xx) the batch is released back and the run stops (backing off so a
 * struggling Matomo is not hammered); once such failures persist past
 * batch.max_attempts the stuck batch is dead-lettered too. Returns the number of
 * hits actually delivered.
 */
final readonly class BufferFlusher
{
    private const int DELIVERED = 0;

    private const int DEAD_LETTERED = 1;

    private const int STOP = 2;

    public function __construct(
        private HitBuffer $buffer,
        private Sender $sender,
        private Reporter $reporter,
        private DeadLetterStore $deadLetters,
        private ConsecutiveFailures $failures,
    ) {}

    /**
     * Drain the buffer and report the hits delivered.
     *
     * The count nearly every caller wants. A caller that has to tell a quiet run apart from
     * a losing one — both end at zero delivered — asks `drain()` instead.
     */
    public function flush(): int
    {
        return $this->drain()->delivered;
    }

    public function drain(): FlushOutcome
    {
        $size = max(1, Config::int('matomo-analytics.batch.size', 50));
        $max = max(1, Config::int('matomo-analytics.batch.max_per_flush', 2000));
        $processed = 0;
        $delivered = 0;
        $deadLettered = 0;

        while ($processed < $max) {
            $batch = $this->buffer->claim(min($size, $max - $processed));
            if ($batch->claimedNothing()) {
                break;
            }

            // CLAIMED ROWS, NO READABLE PAYLOADS — a poison pill of a different kind, and the
            // loop used to stop dead on it. `isEmpty()` was the break condition, so a batch
            // whose payloads no longer decode read as "the buffer is drained": the rows kept
            // their claim, went stale, were reclaimed, failed to decode again, and every hit
            // behind them waited forever. No error, no dead letter, no failing flush.
            //
            // There is nothing to deliver and nothing to replay — a payload that will not
            // decode cannot be sent to Matomo by anyone. So it is reported once and acked
            // away, which is the only disposal that lets the rest of the buffer move.
            if ($batch->isEmpty()) {
                $this->reporter->report(
                    new UnreadableBatchException('A buffered batch held no decodable payload and was discarded.'),
                    ['stage' => 'flush'],
                );
                $this->buffer->ack($batch);
                $processed += $size;

                continue;
            }

            $outcome = $this->deliver($batch);
            if ($outcome === self::STOP) {
                break;
            }

            $count = count($batch->payloads);
            $processed += $count;

            if ($outcome === self::DELIVERED) {
                $delivered += $count;
            } else {
                $deadLettered++;
            }
        }

        return new FlushOutcome($delivered, $deadLettered);
    }

    private function deliver(BufferBatch $batch): int
    {
        try {
            $result = $this->sender->send($batch->payloads);
        } catch (Throwable $e) {
            return $this->onFailure($batch, $e, permanent: false);
        }

        if ($result->failed()) {
            // A 4xx is a permanent poison EXCEPT the back-pressure/timeout statuses
            // (408 Request Timeout, 423 Locked, 425 Too Early, 429 Too Many Requests):
            // those are transient and must be retried with back-off, not dead-lettered
            // on the first hit, so a rate-limited instance does not drain the backlog
            // into the dead-letter queue.
            $permanent = $result->status >= 400 && $result->status < 500
                && ! in_array($result->status, [408, 423, 425, 429], true);

            return $this->onFailure($batch, TrackingSendException::status($result->status), $permanent);
        }

        $this->buffer->ack($batch);
        $this->failures->reset();

        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new TrackingSent(count($batch->payloads), $result->status));
        }

        return self::DELIVERED;
    }

    private function onFailure(BufferBatch $batch, Throwable $e, bool $permanent): int
    {
        // Without a dead-letter safety net, or on a permanent poison being dead-lettered
        // now, the failure is terminal for the batch — surface it immediately.
        if (! Config::bool('matomo-analytics.batch.dead_letter.enabled', true)) {
            $this->reporter->report($e, ['stage' => 'flush']);
            $this->buffer->release($batch);

            return self::STOP;
        }

        if ($permanent) {
            $this->reporter->report($e, ['stage' => 'flush']);
            $this->deadLetter($batch, 1, $e);

            return self::DEAD_LETTERED;
        }

        $attempts = $this->failures->increment();

        if ($attempts >= max(1, Config::int('matomo-analytics.batch.max_attempts', 25))) {
            // Terminal escalation: a dead-letter always surfaces, like the poison path
            // and the queue path's failed() — regardless of report_after_attempts.
            // Otherwise a sustained transient outage sheds every batch to the dead-letter
            // queue silently whenever report_after_attempts is set above max_attempts.
            $this->reporter->report($e, ['stage' => 'flush']);
            $this->failures->reset();
            $this->deadLetter($batch, $attempts, $e);

            return self::DEAD_LETTERED;
        }

        // Pre-escalation transient failure: honor report_after_attempts (like the queue
        // path) so a brief Matomo blip does not page monitoring on the first failed flush.
        if ($this->reporter->shouldReport($attempts)) {
            $this->reporter->report($e, ['stage' => 'flush']);
        }

        $this->buffer->release($batch);

        return self::STOP;
    }

    private function deadLetter(BufferBatch $batch, int $attempts, Throwable $e): void
    {
        // Record first, then ack: if recording throws, the batch stays claimed and
        // is reclaimed as stale later, so a dead-letter write failure never loses hits.
        //
        // A write that could not happen gets the same treatment as one that threw. `record()`
        // now answers false when there is no table to write to, and acking on that answer
        // would drop the batch to make room for a row that was never written. Releasing it
        // instead puts the hits back at the head of the buffer, where the next flush finds
        // them — the outage is still an outage, but it is not also a loss.
        if (! $this->deadLetters->record($batch->payloads, $attempts, $e->getMessage())) {
            $this->buffer->release($batch);

            return;
        }

        $this->buffer->ack($batch);

        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new HitsDeadLettered(count($batch->payloads), $attempts));
        }
    }
}
