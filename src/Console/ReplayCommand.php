<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Contracts\HitBuffer;

final class ReplayCommand extends Command
{
    protected $signature = 'matomo:replay
        {--list : Show the dead-letter queue without replaying}
        {--limit=0 : Maximum entries to replay (0 = all)}
        {--prune : Discard the dead-letter queue without replaying}';

    protected $description = 'Replay dead-lettered Matomo hits back into the buffer.';

    public function handle(DeadLetterStore $store, HitBuffer $buffer): int
    {
        if ($this->option('list') === true) {
            return $this->showList($store);
        }

        if ($this->option('prune') === true) {
            $purged = $store->purge();
            $this->info(sprintf('Discarded %d dead-letter %s.', $purged, $this->plural($purged)));

            return self::SUCCESS;
        }

        $entries = $store->take($this->limit());
        if ($entries === []) {
            $this->info('The dead-letter queue is empty.');

            return self::SUCCESS;
        }

        $ids = [];
        $hits = 0;
        foreach ($entries as $entry) {
            foreach ($entry['payloads'] as $payload) {
                $buffer->push($payload);
                $hits++;
            }
            $ids[] = $entry['id'];
        }

        $store->delete($ids);

        $this->info(sprintf(
            'Replayed %d %s from %d dead-letter %s back into the buffer.',
            $hits,
            $hits === 1 ? 'hit' : 'hits',
            count($ids),
            $this->plural(count($ids)),
        ));

        return self::SUCCESS;
    }

    private function showList(DeadLetterStore $store): int
    {
        $count = $store->count();
        if ($count === 0) {
            $this->info('The dead-letter queue is empty.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d dead-letter %s:', $count, $this->plural($count)));

        $rows = array_map(static fn (array $entry): array => [
            $entry['id'],
            $entry['hits'],
            $entry['attempts'],
            Str::limit($entry['error'], 50),
            $entry['failed_at'],
        ], $store->recent(20));

        $this->table(['ID', 'Hits', 'Attempts', 'Error', 'Failed at'], $rows);

        return self::SUCCESS;
    }

    private function limit(): ?int
    {
        $value = $this->option('limit');
        $limit = is_numeric($value) ? (int) $value : 0;

        return $limit > 0 ? $limit : null;
    }

    private function plural(int $count): string
    {
        return $count === 1 ? 'entry' : 'entries';
    }
}
