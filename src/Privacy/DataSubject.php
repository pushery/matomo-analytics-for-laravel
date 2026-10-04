<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

/**
 * One data subject, as the local stores can recognize it: a payload key and the value that key
 * holds for this person.
 *
 * A payload belongs to the subject when the key holds that value exactly. A value that merely
 * contains it, such as a URL mentioning the address, belongs to somebody else. Scalars compare
 * as strings, so a user id stored as the integer 42 is the same person as "42".
 *
 * An address compares by the address it names, the way Matomo's `visitIp` segment compares, so
 * `2001:DB8::1` and `2001:0db8:0:0:0:0:0:1` are the same person as `2001:db8::1`. A value that is
 * not an address keeps the exact comparison.
 */
final readonly class DataSubject
{
    public function __construct(
        public string $key,
        public string $value,
        public bool $address = false,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    public function owns(?array $payload): bool
    {
        $held = $payload[$this->key] ?? null;

        if (! is_scalar($held)) {
            return false;
        }

        $packed = $this->address ? $this->packed($this->value) : null;

        if ($packed !== null) {
            return $this->packed((string) $held) === $packed;
        }

        return (string) $held === $this->value;
    }

    private function packed(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);

        return $packed === false ? null : $packed;
    }
}
