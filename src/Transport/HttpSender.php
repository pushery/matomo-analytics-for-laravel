<?php

declare(strict_types=1);

namespace MatomoAnalytics\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\Sender;

/**
 * Sends hits over Laravel's HTTP client. One payload is a single form POST; many
 * become a single Bulk Tracking request. token_auth is attached here (body only),
 * never stored in the per-hit payload. HTTP/1.1 is forced because Matomo's Bulk
 * endpoint rejects HTTP/1.0 with 426 Upgrade Required.
 */
final readonly class HttpSender implements Sender
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function send(array $payloads): SendResult
    {
        if ($payloads === []) {
            return SendResult::success();
        }

        $response = count($payloads) === 1
            ? $this->sendSingle($payloads[0])
            : $this->sendBulk($payloads);

        return $response->successful()
            ? SendResult::success($response->status())
            : SendResult::failure($response->status());
    }

    /**
     * @param  array<string, scalar>  $payload
     */
    private function sendSingle(array $payload): Response
    {
        if ($this->connection->token !== null) {
            $payload['token_auth'] = $this->connection->token;
        }

        return $this->request()->asForm()->post($this->connection->trackingUrl(), $payload);
    }

    /**
     * @param  list<array<string, scalar>>  $payloads
     */
    private function sendBulk(array $payloads): Response
    {
        $body = [
            'requests' => array_map(
                static fn (array $payload): string => '?'.http_build_query($payload),
                $payloads,
            ),
        ];

        if ($this->connection->token !== null) {
            $body['token_auth'] = $this->connection->token;
        }

        return $this->request()->post($this->connection->trackingUrl(), $body);
    }

    private function request(): PendingRequest
    {
        return Http::connectTimeout($this->connection->connectTimeout)
            ->timeout($this->connection->timeout)
            ->withOptions(['version' => 1.1]);
    }
}
