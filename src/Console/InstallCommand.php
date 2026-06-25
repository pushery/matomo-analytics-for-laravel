<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'matomo:install';

    protected $description = 'Publish the Matomo Analytics config and print setup hints.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'matomo-analytics-config']);

        $this->info('Set MATOMO_HOST and MATOMO_SITE_ID in your .env (add MATOMO_TOKEN for the real client IP, exact hit time, and batch delivery).');

        return self::SUCCESS;
    }
}
