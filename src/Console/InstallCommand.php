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

        // MATOMO_ENABLED is named FIRST and on its own line: the package ships
        // dormant, so host and site id alone track nothing. Listing only those two
        // (as this command used to) sends the reader off to configure a package
        // that then stays silent, with nothing telling them why.
        $this->info('Set MATOMO_ENABLED=true in your .env — the package ships dormant and tracks nobody until you do.');
        $this->info('Then set MATOMO_HOST and MATOMO_SITE_ID (add MATOMO_TOKEN for the real client IP, exact hit time, and batch delivery).');

        return self::SUCCESS;
    }
}
