<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

/**
 * The Redis instance backing the buffer is allowed to delete it.
 *
 * Its own class because the disposal is unusual: nothing has failed yet, and nothing will
 * fail when it does. An eviction takes the whole list at once and leaves no error behind —
 * measured under `allkeys-lru` at 194,642 of 200,000 hits gone, `evicted_keys=1`, `push()`
 * silent throughout. This is the only notice there will be.
 */
final class BufferEvictableException extends RuntimeException {}
