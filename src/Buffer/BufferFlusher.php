<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

use Illuminate\Support\Facades\Event as EventFacade;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Events\HitsDeadLettered;
use MatomoAnalytics\Events\TrackingSent;
use MatomoAnalytics\Exceptions\TrackingSendException;
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

    public function flush(): int
    {
        $size = max(1, Config::int('matomo-analytics.batch.size', 50));
        $max = max(1, Config::int('matomo-analytics.batch.max_per_flush', 2000));
        $processed = 0;
        $delivered = 0;

        while ($processed < $max) {
            $batch = $this->buffer->claim(min($size, $max - $processed));
            if ($batch->isEmpty()) {
                break;
            }

            $outcome = $this->deliver($batch);
            if ($outcome === self::STOP) {
                break;
            }

            $count = count($batch->payloads);
            $processed += $count;

            if ($outcome === self::DELIVERED) {
                $delivered += $count;
            }
        }

        return $delivered;
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
        $this->deadLetters->record($batch->payloads, $attempts, $e->getMessage());
        $this->buffer->ack($batch);

        if (Config::bool('matomo-analytics.events', true)) {
            EventFacade::dispatch(new HitsDeadLettered(count($batch->payloads), $attempts));
        }
    }
}
