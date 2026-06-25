<?php

declare(strict_types=1);

namespace MatomoAnalytics\Buffer;

/**
 * Encodes and decodes buffered hit payloads. Decoding tolerates corrupt lines
 * (returns null) and keeps only scalar values, so the buffer never trusts stored
 * bytes blindly.
 */
final class Json
{
    /**
     * @param  array<string, scalar>  $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, scalar>|null
     */
    public static function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_scalar($value)) {
                $payload[(string) $key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, scalar>>
     */
    public static function decodeAll(array $lines): array
    {
        $payloads = [];
        foreach ($lines as $line) {
            $decoded = self::decode($line);
            if ($decoded !== null) {
                $payloads[] = $decoded;
            }
        }

        return $payloads;
    }
}
