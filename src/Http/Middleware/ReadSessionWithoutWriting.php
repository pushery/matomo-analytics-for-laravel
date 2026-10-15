<?php

declare(strict_types=1);

namespace MatomoAnalytics\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads the session the request's cookie names, for reading only, on a beacon route.
 *
 * The beacon routes stand outside every middleware group, so a heartbeat does not write the
 * session each time it arrives. The tracking gate still asks `$request->user()` for
 * `track_authenticated` and `except_abilities`, and this lets it answer: the session is loaded and
 * left unsaved, and the response carries no session cookie.
 *
 * The remember-me cookie is taken off the request first. A user resolved from it migrates the
 * session, and the migration deletes the stored one, so a visitor whose session has expired is a
 * guest to the beacons until the next page view signs them back in.
 *
 * It reads the session cookie as `EncryptCookies` leaves it, so it is named after that middleware.
 */
final readonly class ReadSessionWithoutWriting
{
    public function __construct(private SessionManager $sessions, private AuthFactory $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard();

        if ($guard instanceof SessionGuard) {
            $request->cookies->remove($guard->getRecallerName());
        }

        $cookie = $this->sessions->getSessionConfig()['cookie'] ?? null;
        $id = is_string($cookie) ? $request->cookies->get($cookie) : null;
        $session = is_string($id) && $id !== '' ? $this->sessions->driver() : null;

        if ($session instanceof Session) {
            $session->setId($id);
            $session->start();
            $request->setLaravelSession($session);
        }

        return $next($request);
    }
}
