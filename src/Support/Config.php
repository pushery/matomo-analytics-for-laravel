<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

/**
 * Typed accessors over the package config. Keeps call sites free of `mixed`
 * juggling so the rest of the package stays Larastan-clean, and reads through
 * the live container each call (Octane-safe — no cached repository).
 */
final class Config
{
    public static function string(string $key, string $default = ''): string
    {
        $value = config($key);

        return is_string($value) ? $value : $default;
    }

    public static function nullableString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = config($key);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function stringList(string $key): array
    {
        $value = config($key);

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
     * A map of scalar values keyed as configured. Non-scalar entries are dropped
     * so call sites stay typed (used for e.g. the custom-dimension id => value map).
     *
     * @return array<int|string, scalar>
     */
    public static function scalarMap(string $key): array
    {
        $value = config($key);

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
