<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Connection;
use MatomoAnalytics\Contracts\Sender;
use Throwable;

final class TestConnectionCommand extends Command
{
    protected $signature = 'matomo:test';

    protected $description = 'Send a test hit to Matomo and report connectivity.';

    public function handle(Connection $connection, Sender $sender): int
    {
        if (! $connection->isConfigured()) {
            $this->error('Matomo is not configured. Set MATOMO_HOST and MATOMO_SITE_ID.');

            return self::FAILURE;
        }

        try {
            $result = $sender->send([$this->probe($connection)]);
        } catch (Throwable $e) {
            $this->error(sprintf('Could not reach Matomo at %s: %s', $connection->trackingUrl(), $e->getMessage()));

            return self::FAILURE;
        }

        if ($result->failed()) {
            $this->error(sprintf('Matomo returned HTTP %d at %s.', $result->status, $connection->trackingUrl()));

            return self::FAILURE;
        }

        $this->info(sprintf('Matomo OK — test hit accepted at %s (HTTP %d).', $connection->trackingUrl(), $result->status));

        return self::SUCCESS;
    }

    /**
     * @return array<string, scalar>
     */
    private function probe(Connection $connection): array
    {
        return [
            'idsite' => $connection->siteId,
            'rec' => 1,
            'apiv' => 1,
            'send_image' => 0,
            'action_name' => 'Matomo Analytics connection test',
            'url' => $connection->host.'/matomo-analytics/connection-test',
            '_id' => '00000000000000aa',
        ];
    }
}
