<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

/**
 * Reads a segment definition with the steps Matomo 5 takes before it looks up a segment name.
 * `core/Segment.php` keeps either the definition as given or its URL-decoded form, and
 * `core/Segment/SegmentExpression.php` splits the kept form into conditions, then decodes each
 * condition and then its value. The decoded form is the one kept unless the other has more
 * conditions, so a value is usually decoded three times, and the definition is split after the
 * first. `Segment` encodes for exactly these steps.
 *
 * The package reads definitions itself where it acts beside Matomo, so that it reaches the visits
 * Matomo reaches. Segment names are not checked here: they live in Matomo's registry, and a caller
 * that acts on a reading accepts only the names it knows.
 *
 * @internal
 */
final class SegmentReader
{
    /** Matomo's `Segment::SEGMENT_TRUNCATE_LIMIT`: nothing past it is read. */
    private const int LIMIT = 8192;

    /** The first operator after at least one character ends the name, and `.` stops at a line break. */
    private const string CONDITION = '/^(.+?)(==|!=|>=|>|<=|<|=@|!@|=\^|=\$)(.*)/';

    /**
     * The groups are joined by `;` (AND) and hold the conditions joined by `,` (OR). Null when
     * Matomo refuses the definition.
     *
     * @return list<list<array{dimension: string, operator: string, value: string}>>|null
     */
    public static function read(string $definition): ?array
    {
        $definition = trim($definition);
        $decoded = urldecode($definition);
        $raw = self::groups($definition);

        // Matomo keeps the definition as given only when that reading has MORE conditions than the
        // decoded one. A tie, the usual case, goes to the decoded reading.
        $decodedCount = $decoded === $definition ? 0 : self::count(self::groups($decoded));

        return self::count($raw) > $decodedCount ? $raw : self::groups($decoded);
    }

    /**
     * @return list<list<array{dimension: string, operator: string, value: string}>>|null
     */
    private static function groups(string $definition): ?array
    {
        $groups = [];

        foreach (self::split(trim(substr($definition, 0, self::LIMIT)), ';') as $group) {
            // Matomo filters the groups by PHP truthiness, which removes a lone `0` along with an
            // empty group.
            if ($group === '' || $group === '0') {
                continue;
            }

            $conditions = [];

            foreach (self::split($group, ',') as $condition) {
                $read = self::condition(str_replace(['\\;', '\\,'], [';', ','], $condition));

                if ($read === null) {
                    return null;
                }

                $conditions[] = $read;
            }

            $groups[] = $conditions;
        }

        return $groups;
    }

    /**
     * @return array{dimension: string, operator: string, value: string}|null
     */
    private static function condition(string $condition): ?array
    {
        if (preg_match(self::CONDITION, urldecode($condition), $parts) !== 1) {
            return null;
        }

        $value = urldecode($parts[3]);

        // An empty value asks whether the dimension is empty, which only `==` and `!=` can ask.
        $operator = match (true) {
            $value !== '' => $parts[2],
            $parts[2] === '==' => '::NULL',
            $parts[2] === '!=' => '::NOT_NULL',
            default => null,
        };

        return $operator === null ? null : ['dimension' => $parts[1], 'operator' => $operator, 'value' => $value];
    }

    /**
     * Splits where Matomo's `parseTree()` splits: at every `$delimiter` without a backslash in
     * front of it, except at the end, which to PCRE's `$` includes the place before a final line
     * break.
     *
     * @return list<string>
     */
    private static function split(string $text, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $length = strlen($text);

        for ($at = 0; $at < $length; $at++) {
            $atEnd = $at + 1 === $length || ($at + 2 === $length && $text[$length - 1] === "\n");

            if ($text[$at] === $delimiter && ! $atEnd && ($at === 0 || $text[$at - 1] !== '\\')) {
                $parts[] = substr($text, $start, $at - $start);
                $start = $at + 1;
            }
        }

        $parts[] = substr($text, $start);

        return $parts;
    }

    /**
     * @param  list<list<array{dimension: string, operator: string, value: string}>>|null  $groups
     */
    private static function count(?array $groups): int
    {
        $count = 0;

        foreach ($groups ?? [] as $group) {
            foreach ($group as $condition) {
                // Matomo counts the conditions whose name passes `empty()`, which a name `0` does not.
                $count += $condition['dimension'] === '0' ? 0 : 1;
            }
        }

        return $count;
    }
}
