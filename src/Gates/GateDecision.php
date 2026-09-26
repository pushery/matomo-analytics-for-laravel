<?php

declare(strict_types=1);

namespace MatomoAnalytics\Gates;

final readonly class GateDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * Why tracking was refused, for a decision that refused it.
     *
     * This exists because the property cannot state the invariant, and a caller paid for it.
     * The constructor is private, `deny()` takes a non-nullable string and `allow()` passes
     * null, so `allowed === false` and `reason !== null` are the same fact, but the type says
     * `?string`. `TrackManager` therefore guarded with `$decision->reason !== null` inside a
     * branch that had already established it: a condition no input can make false, and a
     * reader's invitation to believe a denial without a reason is possible.
     *
     * The `reason` property stays: it is the shape every test and every reader already uses,
     * and narrowing it would be a breaking change for a caller that holds an `allow()`.
     */
    public function deniedReason(): string
    {
        return $this->reason ?? '';
    }
}
