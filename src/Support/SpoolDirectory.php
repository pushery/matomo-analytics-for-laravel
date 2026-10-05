<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

/**
 * Makes sure a directory exists while another process may be creating it at the same moment.
 *
 * Two requests that find a fresh spool missing both call mkdir(), and the second one fails with
 * "File exists" although the directory it asked for is there. A failed mkdir() therefore only
 * counts when the directory is still missing afterwards.
 */
final class SpoolDirectory
{
    public static function ensure(string $path, int $mode): bool
    {
        return is_dir($path) || self::created($path, $mode);
    }

    /**
     * Whether mkdir() created the directory, or another process created it in the meantime.
     */
    private static function created(string $path, int $mode): bool
    {
        return @mkdir($path, $mode, true) || is_dir($path);
    }
}
