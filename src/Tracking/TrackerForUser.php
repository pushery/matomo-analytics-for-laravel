<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use MatomoAnalytics\Contracts\Tracker;
use MatomoAnalytics\Tracking\Concerns\TracksCommonHits;
use Throwable;

/**
 * The tracker Matomo::forUser() returns: it names one user as the Matomo User ID (`uid`) on
 * every hit sent through it, and hands the hit to the tracker it was made from.
 *
 * `visitor.user_id => 'auth'` reads the user from the request, so a hit sent before that user is
 * logged in names nobody: the registration that has just created the account, the callback of a
 * social login, a queued job. This is the explicit form of that setting and does not depend on
 * it, the same as a `uid` set through CustomParameters. The user named here wins over the one
 * logged in, and a hit that carries a `uid` of its own keeps it. An empty id names nobody.
 */
final readonly class TrackerForUser implements Tracker
{
    use TracksCommonHits;

    private ?string $userId;

    public function __construct(private Tracker $tracker, Authenticatable|int|string $user)
    {
        $id = $user instanceof Authenticatable ? $user->getAuthIdentifier() : $user;

        $this->userId = is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    public function track(Hit $hit): static
    {
        $this->tracker->track($this->named($hit));

        return $this;
    }

    /** Bot telemetry opens no visit, so it names no user either. */
    public function aiChatbot(?Request $request = null): static
    {
        $this->tracker->aiChatbot($request);

        return $this;
    }

    /** The other user, on the tracker this one was made from. */
    public function forUser(Authenticatable|int|string $user): Tracker
    {
        return $this->tracker->forUser($user);
    }

    public function flush(): void
    {
        $this->tracker->flush();
    }

    private function named(Hit $hit): Hit
    {
        if ($this->userId === null) {
            return $hit;
        }

        try {
            $own = array_key_exists('uid', $hit->toParams());
        } catch (Throwable) {
            // The tracker reports a hit that cannot build its parameters, so it gets the hit as
            // it came rather than an error from here.
            return $hit;
        }

        return $own ? $hit : CustomParameters::for($hit)->param('uid', $this->userId);
    }
}
