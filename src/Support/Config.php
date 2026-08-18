<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Support\Facades\Config as ConfigFacade;

/**
 * Typed accessors over the package config. Keeps call sites free of `mixed`
 * juggling so the rest of the package stays Larastan-clean, and reads through
 * the live container each call (Octane-safe — no cached repository).
 */
final class Config
{
    /** The package's own config namespace, i.e. the published file's basename. */
    private const string NAMESPACE = 'matomo-analytics';

    /**
     * The shipped config file, parsed once. Null until the first fallback needs it, so a
     * healthy installation never touches the disk for this.
     *
     * @var array<array-key, mixed>|null
     */
    private static ?array $shipped = null;

    public static function string(string $key, string $default = ''): string
    {
        $value = ConfigFacade::get($key);

        return is_string($value) ? $value : $default;
    }

    public static function nullableString(string $key): ?string
    {
        $value = ConfigFacade::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = ConfigFacade::get($key);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = ConfigFacade::get($key);

        return is_bool($value) ? $value : $default;
    }

    /**
     * A list-of-strings option, falling back to what the package SHIPS for that key.
     *
     * The fallback matters in exactly one situation, and it is a bad one: a `config:cache`
     * built before this package's recursive merge existed and never rebuilt. The merge runs
     * as the cache is built, so a fresh cache carries the shipped values — a stale one does
     * not, and then a missing key answers with an empty list.
     *
     * Empty is the wrong answer for five of the thirteen call sites, and two of those are
     * privacy: `privacy.redact.query_params` ships eighteen entries, and "redaction is
     * running" and "redaction does nothing" look identical in production.
     *
     * The shipped file is read rather than the lists being repeated here. Repeating them
     * would put an eighteen-entry list in two places and let them drift silently, which is a
     * worse failure than the one being fixed. The read costs nothing on the healthy path: it
     * happens only when the key is missing, which is precisely the broken-cache case, and the
     * file is parsed once per process.
     *
     * @return list<string>
     */
    public static function stringList(string $key): array
    {
        $value = ConfigFacade::get($key);

        if (! is_array($value)) {
            $value = self::shipped($key);
        }

        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) || is_int($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * The value the package's own config file declares for a key, or null when it declares
     * none. Parsed once per process — the file is a plain array literal with no side effects.
     *
     * The key arrives fully qualified (`matomo-analytics.privacy.redact.keys`); the leading
     * namespace is this package's own and is stripped to index into the file.
     */
    private static function shipped(string $key): mixed
    {
        self::$shipped ??= (array) require __DIR__.'/../../config/matomo-analytics.php';

        $path = str_starts_with($key, self::NAMESPACE.'.')
            ? substr($key, strlen(self::NAMESPACE) + 1)
            : $key;

        return data_get(self::$shipped, $path);
    }

    /**
     * A map of scalar values keyed as configured. Non-scalar entries are dropped
     * so call sites stay typed (used for e.g. the custom-dimension id => value map).
     *
     * @return array<int|string, scalar>
     */
    public static function scalarMap(string $key): array
    {
        $value = ConfigFacade::get($key);

        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $mapKey => $item) {
            if (is_scalar($item)) {
                $map[$mapKey] = $item;
            }
        }

        return $map;
    }
}
