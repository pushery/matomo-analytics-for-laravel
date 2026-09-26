<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Recognizes the request Laravel invents for a console process.
 *
 * Artisan commands, queue workers and the scheduler run without an HTTP request, and Laravel
 * binds one anyway: `SetRequestForConsole` builds it from `app.url` and the process's own
 * `$_SERVER`, and Symfony fills in what nobody sent, `Symfony` as the user agent and
 * `127.0.0.1` as the address. A hit built from it describes no visitor. Every job and command
 * would share one invented visitor on localhost, with a user agent no browser sends.
 *
 * Three marks together, so that a real request is never taken for it: the process runs in the
 * console, the server bag carries the console's `argv`, and the user agent is Symfony's
 * placeholder. Octane serves real requests from a console process, and those carry neither of
 * the other two.
 */
final class ConsoleRequest
{
    public static function isSynthetic(Request $request): bool
    {
        return App::runningInConsole()
            && $request->server->has('argv')
            && $request->headers->get('User-Agent') === 'Symfony';
    }
}
