<?php

declare(strict_types=1);

namespace MatomoAnalytics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use MatomoAnalytics\Buffer\DeadLetterStore;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Contracts\Sender;
use MatomoAnalytics\Jobs\SendHitsJob;
use MatomoAnalytics\Support\Config;

final class ReplayCommand extends Command
{
    protected $signature = 'matomo:replay
        {--list : Show the dead-letter queue without replaying}
        {--limit=0 : Maximum entries to replay (0 = all)}
        {--prune : Discard the dead-letter queue without replaying}
        {--prune-older-than= : Delete dead letters that failed more than N days ago}';

    protected $description = 'Replay dead-lettered Matomo hits back into delivery.';

    /**
     * REPLAY GOES WHERE THE CONFIGURED MODE ACTUALLY DELIVERS, which used to be the buffer
     * in every mode — and the buffer is only ever drained in `batch` mode, because
     * `registerScheduledFlush()` returns early for anything else. So on the shipped default
     * (`queue`) the command deleted the dead-letter rows, pushed their hits into a store
     * nothing reads, and reported success. It announced a recovery while losing the data.
     *
     * The three modes therefore get the three channels they really use:
     *
     *   batch  the buffer, drained by the scheduled `matomo:flush`
     *   queue  a `SendHitsJob` per entry, exactly as the live path dispatches it
     *   sync   the sender, right here — and the row is kept when the send is refused
     */
    public function handle(DeadLetterStore $store, HitBuffer $buffer, Sender $sender): int
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

        $mode = Config::string('matomo-analytics.mode', 'queue');

        $replayed = 0;
        $hits = 0;
        $refused = 0;
        foreach ($store->take($this->limit()) as $entry) {
            if (! $this->deliver($mode, $entry['payloads'], $buffer, $sender)) {
                // Kept, not deleted. A refused send is the one case where dropping the row
                // would turn a recoverable backlog into a loss, so the entry stays exactly
                // where it was and the next run tries again.
                $refused++;

                continue;
            }

            $hits += count($entry['payloads']);
            // Delete each entry as soon as its payloads are handed off, not all at the end:
            // a crash mid-run then leaves the already-replayed entries removed, so a
            // re-run never double-delivers them.
            $store->delete([$entry['id']]);
            $replayed++;
        }

        if ($replayed === 0 && $refused === 0) {
            $this->info('The dead-letter queue is empty.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Replayed %d %s from %d dead-letter %s back into %s.',
            $hits,
            $hits === 1 ? 'hit' : 'hits',
            $replayed,
            $this->plural($replayed),
            $this->destination($mode),
        ));

        if ($refused > 0) {
            $this->error(sprintf(
                '%d dead-letter %s could not be delivered and %s kept for the next run.',
                $refused,
                $this->plural($refused),
                $refused === 1 ? 'was' : 'were',
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, scalar>>  $payloads
     * @return bool whether the batch was handed off; false keeps the dead-letter row
     */
    private function deliver(string $mode, array $payloads, HitBuffer $buffer, Sender $sender): bool
    {
        if ($payloads === []) {
            // Nothing to deliver, and nothing worth keeping either — an entry whose payloads
            // no longer decode would otherwise be retried forever.
            return true;
        }

        if ($mode === 'batch') {
            foreach ($payloads as $payload) {
                $buffer->push($payload);
            }

            return true;
        }

        if ($mode === 'sync') {
            return ! $sender->send($payloads)->failed();
        }

        Bus::dispatch(new SendHitsJob($payloads));

        return true;
    }

    private function destination(string $mode): string
    {
        return match ($mode) {
            'batch' => 'the buffer',
            'sync' => 'Matomo',
            default => 'the queue',
        };
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
