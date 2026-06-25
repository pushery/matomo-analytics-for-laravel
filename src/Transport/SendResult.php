<?php

declare(strict_types=1);

namespace MatomoAnalytics\Transport;

final readonly class SendResult
{
    private function __construct(
        public bool $ok,
        public int $status,
    ) {}

    public static function success(int $status = 200): self
    {
        return new self(true, $status);
    }

    public static function failure(int $status): self
    {
        return new self(false, $status);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }
}
