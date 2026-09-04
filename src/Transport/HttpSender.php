<?php

declare(strict_types=1);

namespace MatomoAnalytics\Transport;

use Closure;
use GuzzleHttp\Handler\CurlHandler;
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
final class HttpSender implements Sender
{
    /**
     * The Guzzle handler every send goes through, built once.
     *
     * NOT `readonly` any more, and that is the whole change: a fresh `PendingRequest` per send
     * meant a fresh handler stack, a fresh curl handle and therefore a fresh TCP and TLS
     * handshake — up to forty of them against the same host in one flush run, where one
     * connection would have done.
     *
     * ⚠️ SHARED HANDLER, NOT A SHARED CLIENT, and the difference decides whether `Http::fake()`
     * still works. `PendingRequest::setClient()` bypasses `buildHandlerStack()` entirely, and
     * the fake IS a handler-stack middleware — so reusing a client would have silently turned
     * every faked request in the suite into a real one. `setHandler()` sits UNDER that
     * middleware instead: the stub is pushed on top of it and short-circuits before the
     * handler is ever reached, so a faked send never opens a socket and a real one reuses the
     * curl handle.
     */
    private ?Closure $handler;

    public function __construct(
        private readonly Connection $connection,
        ?callable $handler = null,
    ) {
        // Injectable so a test can watch it: the property that matters is that CONSECUTIVE
        // sends go through the SAME handler, and that is invisible from the outside unless
        // something can hold it.
        //
        // Stored as a Closure because PHP has no `callable` property type — an invokable
        // object becomes one through the first-class callable syntax, which is the same
        // object underneath.
        $this->handler = $handler === null ? null : $handler(...);
    }

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
            ->withOptions(['version' => 1.1])
            ->setHandler($this->handler());
    }

    /**
     * The one handler this sender reuses, created on first use.
     *
     * `CurlHandler` keeps a factory of curl handles, and curl keeps a connection alive on a
     * handle it is given again — which is the entire mechanism. Guzzle's default handler is
     * built fresh inside `HandlerStack::create(null)` on every request, so the default is a
     * new handle and a new connection each time.
     */
    private function handler(): Closure
    {
        return $this->handler ??= new CurlHandler()->__invoke(...);
    }
}
