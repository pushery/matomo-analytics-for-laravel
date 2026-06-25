<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

use DeviceDetector\DeviceDetector;

/**
 * Optional bot detector backed by matomo/device-detector — the same exhaustively
 * maintained catalogue Matomo uses server-side, covering every category (search
 * engines, social, SEO/marketing, monitoring, libraries, AI, …) and refreshed via
 * `composer update`. Opt in without bloating the core: install the package and
 * point the detector hook at this class.
 *
 *   composer require matomo/device-detector
 *
 *   // config/matomo-analytics.php
 *   'bots' => [
 *       'detector' => \MatomoAnalytics\Bots\DeviceDetectorBotDetector::class,
 *   ],
 *
 * The built-in token lists still run first; this is consulted last as the
 * comprehensive backstop (see DefaultBotDetector).
 */
final class DeviceDetectorBotDetector
{
    public function __invoke(string $userAgent): bool
    {
        $detector = new DeviceDetector($userAgent);
        $detector->discardBotInformation(); // detect only — we just need the boolean
        $detector->parse();

        return $detector->isBot();
    }
}
