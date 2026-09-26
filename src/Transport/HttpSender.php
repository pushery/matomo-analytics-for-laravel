<?php

declare(strict_types=1);

namespace MatomoAnalytics\Transport;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
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
     * How old a hit time may be for Matomo to take it from a request without a token, in seconds.
     *
     * Matomo accepts a custom timestamp from an unauthenticated request while it is younger than
     * `tracking_requests_require_authentication_when_custom_timestamp_newer_than`, a day by
     * default, and refuses the request otherwise. An hour less than that leaves room for the
     * clocks of the two hosts to differ.
     */
    private const int UNAUTHENTICATED_TIMESTAMP_WINDOW = 82800;

    /**
     * The Guzzle handler every send goes through, built once.
     *
     * NOT `readonly` any more, and that is the whole change: a fresh `PendingRequest` per send
     * meant a fresh handler stack, a fresh curl handle and therefore a fresh TCP and TLS
     * handshake — up to forty of them against the same host in one flush run, where one
     * connection would have done.
     *
     * SHARED HANDLER, NOT A SHARED CLIENT, and the difference decides whether `Http::fake()`
     * still works. `PendingRequest::setClient()` bypasses `buildHandlerStack()` entirely, and
     * the fake IS a handler-stack middleware — so reusing a client would have silently turned
     * every faked request in the suite into a real one. `setHandler()` sits UNDER that
     * middleware instead: the stub is pushed on top of it and short-circuits before the
     * handler is ever reached, so a faked send never opens a socket and a real one reuses the
     * curl handle.
     */
    private ?Closure $handler;

    /**
     * Whether PHP has curl, the only way to share a handle between sends.
     *
     * Guzzle runs without curl and only suggests the extension, and this package does not
     * require it. Where curl is missing, the handler is left to Guzzle, which falls back to PHP
     * streams: hits still go out, each on a connection of its own.
     */
    private readonly bool $curl;

    public function __construct(
        private readonly Connection $connection,
        ?callable $handler = null,
        ?bool $curl = null,
    ) {
        // Injectable so a test can watch it: the property that matters is that CONSECUTIVE
        // sends go through the SAME handler, and that is invisible from the outside unless
        // something can hold it.
        //
        // Stored as a Closure because PHP has no `callable` property type — an invokable
        // object becomes one through the first-class callable syntax, which is the same
        // object underneath.
        $this->handler = $handler === null ? null : $handler(...);

        // Injectable for the same reason: every machine that runs the suite has curl, so the
        // path without it would otherwise run nowhere before a consumer's.
        $this->curl = $curl ?? extension_loaded('curl');
    }

    public function send(array $payloads): SendResult
    {
        if ($payloads === []) {
            return SendResult::success();
        }

        if ($this->connection->token === null) {
            $payloads = array_map($this->withoutStaleTimestamp(...), $payloads);
        }

        $response = count($payloads) === 1
            ? $this->sendSingle($payloads[0])
            : $this->sendBulk($payloads);

        if (! $response->successful()) {
            return SendResult::failure($response->status());
        }

        return $this->readEnvelope($response);
    }

    /**
     * The payload without a hit time Matomo would refuse from a request that carries no token.
     *
     * A hit that waited longer than the window, in a long retry or a dead-letter replay, goes
     * out without `cdt` and is recorded when it arrives: a late hit rather than a refused one.
     * A time in any form other than the one PayloadBuilder writes is left for Matomo to judge.
     *
     * @param  array<string, scalar>  $payload
     * @return array<string, scalar>
     */
    private function withoutStaleTimestamp(array $payload): array
    {
        $cdt = $payload['cdt'] ?? null;
        $at = is_string($cdt) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cdt, new DateTimeZone('UTC')) : false;

        if ($at !== false && Date::now()->getTimestamp() - $at->getTimestamp() >= self::UNAUTHENTICATED_TIMESTAMP_WINDOW) {
            unset($payload['cdt']);
        }

        return $payload;
    }

    /**
     * What a 200 says about itself.
     *
     * THE BODY WAS NEVER READ, AND MATOMO ANSWERS 200 TO A BULK REQUEST IT PARTLY REFUSED.
     * `successful()` alone made every one of those a delivery: the batch was acked out of the
     * buffer, never dead-lettered, and the hits were gone with every signal green.
     *
     * Two things are acted on and no more, because the per-entry envelope shape was read
     * rather than measured against a live instance:
     *
     *   `status: error`   unambiguous under any reading — Matomo refusing inside a 200 is not
     *                     a delivery, so the batch takes the ordinary failure path.
     *   `invalid: N`      carried out as a number for the caller to report. It does not say
     *                     WHICH hits, so no accounting is built on it and the batch is still
     *                     a success: the ones that landed did land.
     *
     * Anything else — an empty body, an image, a non-JSON string, JSON without these keys —
     * behaves exactly as before. The tracker answers a single hit with an image or with
     * nothing, depending on `send_image`, so that path must stay untouched.
     */
    private function readEnvelope(Response $response): SendResult
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            return SendResult::success($response->status());
        }

        if (($body['status'] ?? null) === 'error') {
            return SendResult::failure($response->status());
        }

        $invalid = $body['invalid'] ?? 0;

        return SendResult::success($response->status(), is_int($invalid) ? max(0, $invalid) : 0);
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
        $request = Http::connectTimeout($this->connection->connectTimeout)
            ->timeout($this->connection->timeout)
            ->withOptions(['version' => 1.1])
            // A 307 OR 308 KEEPS THE METHOD AND THE BODY, AND THE TOKEN IS IN THE BODY.
            // Guzzle's `RedirectMiddleware` hands back an empty modifier set for any status
            // above 302, so the POST is replayed verbatim at whatever host the Location names.
            // Its cross-origin stripping covers `Authorization` and `Cookie` — headers — and
            // this token is a form field, so it traveled. Measured end to end: a 307 sent
            // `token_auth` to a foreign host, and `protocols` allowed the downgrade to http.
            //
            // The trigger is a RESPONSE FROM THE MATOMO HOST, which on Matomo Cloud is a third
            // party — and the same token is an admin token, because the GDPR deletion path
            // requires one. A redirect is not an expected state here, so it is refused rather
            // than sanitized.
            ->withoutRedirecting();

        $handler = $this->handler();

        return $handler instanceof Closure ? $request->setHandler($handler) : $request;
    }

    /**
     * The one handler this sender reuses, created on first use.
     *
     * `CurlHandler` keeps a factory of curl handles, and curl keeps a connection alive on a
     * handle it is given again — which is the entire mechanism. Guzzle's default handler is
     * built fresh inside `HandlerStack::create(null)` on every request, so the default is a
     * new handle and a new connection each time.
     *
     * Without curl there is no handle to share, and `CurlHandler` would fail on its first
     * `curl_exec()`. Null leaves the choice to Guzzle, and an injected handler still wins.
     */
    private function handler(): ?Closure
    {
        if (! $this->curl) {
            return $this->handler;
        }

        return $this->handler ??= new CurlHandler()->__invoke(...);
    }
}
