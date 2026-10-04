<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The package's own lines, with the language of a regional locale between that locale and the
 * fallback locale.
 *
 * Laravel looks a line up in the current locale and then in the fallback locale, and nowhere in
 * between, so `pt_BR`, `de_AT` or `fr-CA` never reach the `pt`, `de` or `fr` this package ships,
 * and a regional site would render its privacy paragraph in the fallback locale. A line published
 * for the region itself is still read first.
 */
final class Translation
{
    public static function line(string $key): string
    {
        $locale = Lang::getLocale();
        $language = strtolower(explode('_', str_replace('-', '_', $locale), 2)[0]);

        if ($language !== $locale && ! Lang::has($key, $locale, false) && Lang::has($key, $language, false)) {
            $locale = $language;
        }

        $line = Lang::get($key, [], $locale);

        // A key that names a group of lines rather than one answers with an array.
        return is_string($line) ? $line : $key;
    }
}
