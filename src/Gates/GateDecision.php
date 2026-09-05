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
     * THIS EXISTS BECAUSE THE INVARIANT WAS UNSTATEABLE AND THE CALLER PAID FOR IT. The
     * constructor is private, `deny()` takes a non-nullable string and `allow()` passes null,
     * so `allowed === false` and `reason !== null` are the same fact — but the type says
     * `?string`, so `TrackManager` guarded with `$decision->reason !== null` inside a branch
     * that had already established it. A condition no run can make false is a permanently
     * surviving mutant and, worse, a reader's invitation to believe a denial without a reason
     * is possible.
     *
     * The `reason` property stays: it is the shape every test and every reader already uses,
     * and narrowing it would be a breaking change for a caller that holds an `allow()`.
     */
    public function deniedReason(): string
    {
        return $this->reason ?? '';
    }
}
