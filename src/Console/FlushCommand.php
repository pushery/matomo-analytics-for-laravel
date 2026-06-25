<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use MatomoAnalytics\Buffer\BufferFlusher;

final class FlushCommand extends Command
{
    protected $signature = 'matomo:flush';

    protected $description = 'Flush buffered Matomo hits to the tracking endpoint.';

    public function handle(BufferFlusher $flusher): int
    {
        $this->info(sprintf('Flushed %d Matomo hit(s).', $flusher->flush()));

        return self::SUCCESS;
    }
}
