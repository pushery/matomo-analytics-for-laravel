<?php

declare(strict_types=1);

namespace MatomoAnalytics\Contracts;

interface BotDetector
{
    public function isBot(string $userAgent): bool;

    public function isAiCrawler(string $userAgent): bool;
}
