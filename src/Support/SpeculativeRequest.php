<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Http\Request;

/**
 * Recognizes a request the browser made on speculation rather than for a reader.
 *
 * A speculation-rules prefetch fetches a page because the pointer rests on a link, and nobody
 * may ever see it. Browsers announce it in `Sec-Purpose`, older ones in `Purpose`. Both headers
 * are read as a list of tokens, because `Sec-Purpose` is specified as one: `prefetch;prerender`
 * is a prerender, and it is speculative for the same reason.
 */
final class SpeculativeRequest
{
    public static function is(Request $request): bool
    {
        return array_any(
            ['Sec-Purpose', 'Purpose'],
            static fn (string $header): bool => str_contains(strtolower((string) $request->headers->get($header, '')), 'prefetch'),
        );
    }
}
