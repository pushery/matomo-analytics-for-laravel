<?php

declare(strict_types=1);

namespace MatomoAnalytics\Reporting;

use InvalidArgumentException;
use Stringable;

/**
 * Fluent builder for a Matomo segment definition string, e.g.
 * `deviceType==smartphone;visitCount>1`. Expressions are joined with `;` (AND)
 * or `,` (OR); values are left verbatim for the HTTP transport to URL-encode.
 * Immutable: each call returns a new instance.
 *
 *   Segment::where('deviceType', '==', 'smartphone')->andWhere('visitCount', '>', 1);
 */
final readonly class Segment implements Stringable
{
    /** @var list<string> */
    private const array OPERATORS = ['==', '!=', '<=', '>=', '<', '>', '=@', '!@', '=^', '=$'];

    /**
     * @param  list<array{glue: string, expression: string}>  $parts
     */
    private function __construct(private array $parts) {}

    public static function where(string $dimension, string $operator, string|int|float $value): self
    {
        return new self([['glue' => '', 'expression' => self::express($dimension, $operator, $value)]]);
    }

    public function andWhere(string $dimension, string $operator, string|int|float $value): self
    {
        return new self([...$this->parts, ['glue' => ';', 'expression' => self::express($dimension, $operator, $value)]]);
    }

    public function orWhere(string $dimension, string $operator, string|int|float $value): self
    {
        return new self([...$this->parts, ['glue' => ',', 'expression' => self::express($dimension, $operator, $value)]]);
    }

    public function definition(): string
    {
        $out = '';

        foreach ($this->parts as $part) {
            $out .= $part['glue'].$part['expression'];
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->definition();
    }

    private static function express(string $dimension, string $operator, string|int|float $value): string
    {
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported Matomo segment operator [{$operator}].");
        }

        return $dimension.$operator.$value;
    }
}
