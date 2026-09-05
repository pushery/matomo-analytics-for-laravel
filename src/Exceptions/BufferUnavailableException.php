<?php

declare(strict_types=1);

namespace MatomoAnalytics\Exceptions;

use RuntimeException;

/**
 * The buffer holds work but could not be claimed from.
 *
 * ITS OWN CLASS BECAUSE THE ALTERNATIVE READS AS "DRAINED", WHICH IS THE OPPOSITE. A
 * driver that cannot claim has nothing to hand back but an empty batch, and an empty batch
 * is how the flusher learns the buffer is empty — so a spool nobody can write to produced
 * `Flushed 0 Matomo hit(s).` and exit zero, every minute, forever, while the hits sat in the
 * file. Measured with the spool directory at `0555`: delivered 0, dead-lettered 0,
 * `isStuck()` false, no log, no event.
 *
 * Thrown rather than returned, because there is no value an empty batch could carry that the
 * "buffer is drained" reading would not swallow. `BufferFlusher::drain()` catches it, reports
 * it, and ends the run marked unavailable.
 */
final class BufferUnavailableException extends RuntimeException {}
