<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

/**
 * Resolves a config-supplied extension point into a callable: a closure, an
 * already-callable value, or an invokable class-string resolved from the
 * container (the config-cache-safe form). Anything else yields null.
 */
final class CallableResolver
{
    public static function resolve(mixed $value): ?callable
    {
        if (is_callable($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && class_exists($value)) {
            $instance = app($value);

            return is_callable($instance) ? $instance : null;
        }

        return null;
    }
}
