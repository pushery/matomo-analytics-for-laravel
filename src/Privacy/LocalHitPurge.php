<?php

declare(strict_types=1);

namespace MatomoAnalytics\Privacy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Buffer\Json;
use MatomoAnalytics\Support\Config;

/**
 * Erases a data subject's hits from the tables THIS application holds.
 *
 * GDPR ERASURE REACHED MATOMO AND NOTHING ELSE, and this package keeps whole hits of its
 * own. `matomo_tracking_buffer` holds one built payload per row and `matomo_dead_letters`
 * holds whole batches for up to thirty days — `cip`, `ua`, `url`, `urlref`, `_id`, `uid`, all
 * of it, in the CONSUMER's database. So an operator could run `MatomoGdpr::forget(...)`, be
 * handed deletion counts, report the request fulfilled, and leave the same person's IP and
 * user agent sitting in their own tables. The documentation says "erases every matching
 * visit"; `reference/database.md` did not mention that these tables hold personal data at all.
 *
 * IT MATCHES TWO SEGMENT FORMS AND REFUSES THE REST, ON PURPOSE. A Matomo segment is an
 * expression evaluated by Matomo against ITS schema; re-implementing that here against stored
 * payloads would be a guess, and a wrong guess deletes somebody else's data. So exactly the
 * two unambiguous forms are honored — `userId==<value>` against the payload's `uid`, and
 * `visitIp==<value>` against its `cip` — and anything else is reported as NOT purged rather
 * than silently treated as nothing to do.
 */
final readonly class LocalHitPurge
{
    /**
     * @return array{buffer: int, dead_letters: int, matched: bool} rows removed, and whether
     *                                                              the segment was one this can act on
     */
    public function forget(string $segment): array
    {
        $criterion = $this->criterion($segment);

        if ($criterion === null) {
            return ['buffer' => 0, 'dead_letters' => 0, 'matched' => false];
        }

        [$key, $value] = $criterion;

        return [
            'buffer' => $this->purgeBuffer($key, $value),
            'dead_letters' => $this->purgeDeadLetters($key, $value),
            'matched' => true,
        ];
    }

    /**
     * The payload key and value a segment names, or null when it names something this cannot
     * evaluate without guessing.
     *
     * @return array{0: string, 1: string}|null
     */
    private function criterion(string $segment): ?array
    {
        // `==` only. A negation, a comparison or a boolean expression describes a SET, and
        // deleting by a set this class inferred is exactly the mistake worth refusing.
        if (preg_match('/^\s*(userId|visitIp)\s*==\s*(.+?)\s*$/', $segment, $found) !== 1) {
            return null;
        }

        // A composite expression describes a SET, and deleting by a set this class inferred is
        // the mistake the whole method refuses. `preg_quote` is not enough there: the pattern
        // above would happily take everything after `==` as one value.
        if (str_contains($segment, ';') || str_contains($segment, ',')) {
            return null;
        }

        $value = rawurldecode($found[2]);

        return [$found[1] === 'userId' ? 'uid' : 'cip', $value];
    }

    private function purgeBuffer(string $key, string $value): int
    {
        $table = Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $removed = 0;

        // Read and compare the DECODED payload rather than matching the stored JSON as text.
        // A substring match would delete a row whose URL merely mentions the address, and a
        // JSON path is not portable across the engines this package supports.
        foreach (DB::table($table)->select(['id', 'payload'])->orderBy('id')->cursor() as $row) {
            $payload = is_string($row->payload ?? null) ? Json::decode($row->payload) : null;

            if ($payload !== null && ($payload[$key] ?? null) === $value) {
                $removed += DB::table($table)->where('id', $row->id)->delete();
            }
        }

        return $removed;
    }

    private function purgeDeadLetters(string $key, string $value): int
    {
        $table = Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $removed = 0;

        // A dead letter holds a BATCH, so the subject's hits are removed from it and the row
        // is rewritten; a row left empty is deleted. Dropping the whole row would erase other
        // people's undelivered hits to satisfy one person's request.
        foreach (DB::table($table)->select(['id', 'payloads'])->orderBy('id')->cursor() as $row) {
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

                $payload = Json::decode($line);

                if ($payload !== null && ($payload[$key] ?? null) === $value) {
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
