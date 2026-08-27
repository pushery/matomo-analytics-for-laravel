<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

/**
 * A claimed batch held no payload that could be decoded.
 *
 * Its own class rather than a generic one, because the disposal is unusual enough to be
 * worth recognizing in an error dashboard: there is nothing to deliver and nothing to
 * replay — a payload that will not decode cannot be sent to Matomo by anyone — so the rows
 * are discarded rather than dead-lettered. That is data loss, and it should be loud, but it
 * is the only disposal that lets the rest of the buffer move: the alternative held every hit
 * behind the bad rows forever, silently.
 */
final class UnreadableBatchException extends RuntimeException {}
