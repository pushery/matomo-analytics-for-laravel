<?php

declare(strict_types=1);

namespace MatomoAnalytics\Transport;

/**
 * What one send to Matomo actually did.
 *
 * `invalid` EXISTS BECAUSE A 200 IS NOT AN ANSWER ON ITS OWN. The bulk endpoint replies
 * 200 with an envelope saying how the batch went, and the sender used to ask `successful()`
 * and nothing else — so hits Matomo rejected INSIDE a success counted as delivered, left the
 * buffer on the ack, and never reached the dead-letter queue.
 *
 * Deliberately narrow. The per-entry envelope shape was read rather than measured against a
 * live Matomo, so nothing here decides WHICH hits a partial rejection covers and no delivery
 * accounting hangs on it. `invalid` carries the count Matomo states, for a caller to report;
 * a body that does not state one leaves it at zero and every verdict unchanged.
 */
final readonly class SendResult
{
    private function __construct(
        public bool $ok,
        public int $status,
        public int $invalid = 0,
    ) {}

    public static function success(int $status = 200, int $invalid = 0): self
    {
        return new self(true, $status, $invalid);
    }

    public static function failure(int $status): self
    {
        return new self(false, $status);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }
}
