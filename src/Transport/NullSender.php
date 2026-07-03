<?php

declare(strict_types=1);

namespace MatomoAnalytics\Transport;

use MatomoAnalytics\Contracts\Sender;

/**
 * A sender that discards payloads and always reports success, counting the Bulk
 * POSTs and hits it would have made. The default sink for matomo:load-sim, so a
 * load simulation exercises the real buffer + flush pipeline (build, enqueue,
 * claim, bulk-coalesce, ack) without touching a Matomo instance.
 */
final class NullSender implements Sender
{
    public int $posts = 0;

    public int $hits = 0;

    public function send(array $payloads): SendResult
    {
        $this->posts++;
        $this->hits += count($payloads);

        return SendResult::success(204);
    }
}
