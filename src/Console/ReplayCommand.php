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
        {--prune : Discard the dead-letter queue without replaying}
        {--prune-older-than= : Delete dead letters that failed more than N days ago}';

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

        $olderThan = $this->option('prune-older-than');
        if ($olderThan !== null) {
            // Rejected rather than coerced. `(int) 'soon'` is 0, and 0 days means "delete
            // the entire queue" — the widest possible action arrived at by silently
            // misreading a typo. A retention window is the one option here where a wrong
            // value is unrecoverable.
            if (! is_string($olderThan) || ! ctype_digit($olderThan) || (int) $olderThan < 1) {
                $this->error('--prune-older-than needs a whole number of days, 1 or greater.');

                return self::FAILURE;
            }

            $days = (int) $olderThan;
            $deleted = $store->pruneOlderThan($days);
            $this->info(sprintf(
                'Deleted %d dead-letter %s older than %d %s.',
                $deleted,
                $this->plural($deleted),
                $days,
                $days === 1 ? 'day' : 'days',
            ));

            return self::SUCCESS;
        }

        $entries = $store->take($this->limit());
        if ($entries === []) {
            $this->info('The dead-letter queue is empty.');

            return self::SUCCESS;
        }

        $replayed = 0;
        $hits = 0;
        foreach ($entries as $entry) {
            foreach ($entry['payloads'] as $payload) {
                $buffer->push($payload);
                $hits++;
            }
            // Delete each entry as soon as its payloads are buffered, not all at the end:
            // a crash mid-run then leaves the already-replayed entries removed, so a
            // re-run never double-pushes them into the buffer.
            $store->delete([$entry['id']]);
            $replayed++;
        }

        $this->info(sprintf(
            'Replayed %d %s from %d dead-letter %s back into the buffer.',
            $hits,
            $hits === 1 ? 'hit' : 'hits',
            $replayed,
            $this->plural($replayed),
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
