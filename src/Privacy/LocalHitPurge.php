<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Buffer\Json;
use MatomoAnalytics\Contracts\ErasableHitBuffer;
use MatomoAnalytics\Contracts\HitBuffer;
use MatomoAnalytics\Reporting\SegmentReader;
use MatomoAnalytics\Support\Config;

/**
 * Erases a data subject's hits from the tables this application holds.
 *
 * Matomo's erasure does not reach them, and they hold whole hits: `matomo_tracking_buffer` one
 * built payload per row, `matomo_dead_letters` whole batches for as long as
 * `batch.dead_letter.retention_days` keeps them (30 days by default, without limit at 0, and
 * only while the scheduler runs), with `cip`, `ua`, `url`, `urlref`, `_id` and `uid`, in the
 * application's own database.
 *
 * Two segment forms are acted on, each as the only condition: `userId==<value>` against the
 * payload's `uid`, and `visitIp==<value>` against its `cip`. Any other segment is an expression
 * Matomo evaluates against its own schema, and evaluating it here against stored payloads could
 * erase somebody else's data, so it is reported as not purged rather than as nothing to do.
 *
 * The buffer searched is the one the application writes to: the `HitBuffer` binding, which is
 * the configured driver unless the application bound a store of its own. Every driver this
 * package ships can erase; a store that cannot is reported as not searched. Neither is the
 * queue of the `queue` mode, where a request's hits wait as a queued job until a worker sends
 * them and a job that runs out of attempts stays in `failed_jobs`.
 */
final readonly class LocalHitPurge
{
    /**
     * Erase the subject's hits from the buffer and the dead letters.
     *
     * `matched` is false when the segment is not one of the two forms this can act on;
     * `buffer_searched` is false when the application's buffer cannot erase; and
     * `queue_searched` is false in the `queue` mode, where hits waiting in the queue cannot be
     * searched from here.
     *
     * @return array{buffer: int, dead_letters: int, matched: bool, buffer_searched: bool, queue_searched: bool}
     */
    public function forget(string $segment): array
    {
        $subject = $this->subject($segment);

        if (! $subject instanceof DataSubject) {
            return ['buffer' => 0, 'dead_letters' => 0, 'matched' => false, 'buffer_searched' => false, 'queue_searched' => false];
        }

        $buffer = $this->buffer();
        $erasable = $buffer instanceof ErasableHitBuffer;

        return [
            'buffer' => $erasable ? $buffer->erase($subject) : 0,
            'dead_letters' => $this->purgeDeadLetters($subject),
            'matched' => true,
            'buffer_searched' => $erasable,
            'queue_searched' => Config::string('matomo-analytics.mode', 'queue') !== 'queue',
        ];
    }

    /**
     * The buffer the application writes to, typed as the contract: an application can bind a
     * store of its own in place of the configured driver.
     */
    private function buffer(): HitBuffer
    {
        return App::make(HitBuffer::class);
    }

    /**
     * The data subject a segment names, or null when the segment is not one of the two forms.
     *
     * The segment is read with Matomo's own steps, so both systems erase the same person:
     * `userId==John+Doe` names `John Doe` in both, and `alice+news@example.com` is named by
     * `userId==alice%25252Bnews%252540example.com`, a value encoded once for each of the three
     * times Matomo decodes it.
     */
    private function subject(string $segment): ?DataSubject
    {
        $groups = SegmentReader::read($segment);

        // One condition, compared with `==`. A negation, a comparison or a boolean expression
        // describes a set, and deleting by a set this class inferred could erase somebody else.
        if ($groups === null || count($groups) !== 1 || count($groups[0]) !== 1 || $groups[0][0]['operator'] !== '==') {
            return null;
        }

        return match ($groups[0][0]['dimension']) {
            'userId' => new DataSubject('uid', $groups[0][0]['value']),
            'visitIp' => new DataSubject('cip', $groups[0][0]['value'], address: true),
            default => null,
        };
    }

    private function purgeDeadLetters(DataSubject $subject): int
    {
        $table = Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $removed = 0;

        // A dead letter holds a BATCH, so the subject's hits are removed from it and the row
        // is rewritten; a row left empty is deleted. Dropping the whole row would erase other
        // people's undelivered hits to satisfy one person's request.
        foreach (DB::table($table)->select(['id', 'payloads'])->lazyById() as $row) {
            if (! is_string($row->payloads ?? null)) {
                continue;
            }

            // JSONL, one encoded hit per line — the shape `DeadLetterStore::record()` writes.
            // Reading it as a JSON array would decode to null and silently purge nothing,
            // which is the failure direction that reports success over untouched data.
            $kept = [];
            $dropped = 0;

            foreach (explode("\n", $row->payloads) as $line) {
                if ($line === '') {
                    continue;
                }

                if ($subject->owns(Json::decode($line))) {
                    $dropped++;

                    continue;
                }

                $kept[] = $line;
            }

            if ($dropped === 0) {
                continue;
            }

            $removed += $dropped;

            if ($kept === []) {
                DB::table($table)->where('id', $row->id)->delete();

                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'payloads' => implode("\n", $kept),
                'hits' => count($kept),
            ]);
        }

        return $removed;
    }
}
