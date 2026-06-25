<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

use MatomoAnalytics\Transport\SendResult;

interface Sender
{
    /**
     * Send one or many already-built hit payloads to Matomo. A single payload
     * goes as one form POST; many go as one Bulk Tracking request.
     *
     * @param  list<array<string, scalar>>  $payloads
     */
    public function send(array $payloads): SendResult;
}
