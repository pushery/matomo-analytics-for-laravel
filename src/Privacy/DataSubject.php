<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

/**
 * One data subject, as the local stores can recognize it: a payload key and the exact value
 * that key holds for this person.
 *
 * A payload belongs to the subject when the key holds that value exactly. A value that merely
 * contains it, such as a URL mentioning the address, belongs to somebody else. Scalars compare
 * as strings, so a user id stored as the integer 42 is the same person as "42".
 */
final readonly class DataSubject
{
    public function __construct(
        public string $key,
        public string $value,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    public function owns(?array $payload): bool
    {
        $held = $payload[$this->key] ?? null;

        return is_scalar($held) && (string) $held === $this->value;
    }
}
