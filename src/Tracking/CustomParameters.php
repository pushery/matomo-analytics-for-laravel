<?php

declare(strict_types=1);

namespace MatomoAnalytics\Tracking;

/**
 * Decorates any Hit with Custom Dimensions (dimension{N}) and raw Tracking-API
 * parameters — the setCustomParameter escape hatch for anything the typed hits
 * do not model (campaign _rcn/_rck, gt_ms, …). Immutable and fluent: every
 * builder call returns a new instance, so a decorated hit is safe to share.
 *
 *   Matomo::track(
 *       CustomParameters::for(new PageView('Dashboard'))
 *           ->dimension(1, 'plan:pro')
 *           ->param('_rcn', 'newsletter'),
 *   );
 */
final readonly class CustomParameters implements Hit
{
    /**
     * @param  array<string, scalar>  $parameters
     */
    public function __construct(
        public Hit $hit,
        public array $parameters = [],
    ) {}

    /**
     * Start decorating a hit. Passing an already-decorated hit returns it
     * unchanged so custom parameters never nest.
     */
    public static function for(Hit $hit): self
    {
        return $hit instanceof self ? $hit : new self($hit);
    }

    /**
     * Set a Custom Dimension value. The scope (Action or Visit) is defined by the
     * dimension id in Matomo, not here.
     */
    public function dimension(int $id, string|int|float $value): self
    {
        return $this->param('dimension'.$id, $value);
    }

    /**
     * Set a raw Tracking-API parameter. A custom value overrides the wrapped
     * hit's own parameter of the same name.
     */
    public function param(string $key, string|int|float|bool $value): self
    {
        return new self($this->hit, [...$this->parameters, $key => $value]);
    }

    public function toParams(): array
    {
        return array_merge($this->hit->toParams(), $this->parameters);
    }
}
