<?php

declare(strict_types=1);

namespace MatomoAnalytics\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The package's own lines, with the language of a regional locale between that locale and the
 * fallback locale.
 *
 * The provider's loader answers a regional locale with its language whenever the translator loads
 * this package's lines from their files. A line added at run time, with `Lang::addLines()`, passes
 * no loader, and for it Laravel looks in the current locale and then in the fallback locale,
 * nowhere in between, so `pt_BR`, `de_AT` or `fr-CA` would not reach the `pt`, `de` or `fr` this
 * package ships. This reads the language for such a line as well. A line published or added for
 * the region itself is still read first.
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
