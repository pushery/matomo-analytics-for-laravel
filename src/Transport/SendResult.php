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

    /**
     * Whether sending the same batch again would get the same answer.
     *
     * A 4xx says the request itself is wrong: a token Matomo refuses, a site id it does not
     * know, a URL that is not its tracking endpoint. Waiting changes none of that, so both
     * delivery modes park such a batch in the dead-letter store on the first attempt, where
     * `matomo:replay` sends it again once the configuration is corrected.
     *
     * Four 4xx statuses describe the server's state rather than the request's and stay
     * transient: 408 Request Timeout, 423 Locked, 425 Too Early and 429 Too Many Requests.
     * Parking a 429 on sight would move a whole backlog into the dead-letter store at the
     * moment a rate limit is reached, which is when the data is still good. A server error,
     * a redirect and a refusal inside a 200 are transient as well.
     */
    public function permanent(): bool
    {
        return ! $this->ok
            && $this->status >= 400
            && $this->status < 500
            && ! in_array($this->status, [408, 423, 425, 429], true);
    }
}
