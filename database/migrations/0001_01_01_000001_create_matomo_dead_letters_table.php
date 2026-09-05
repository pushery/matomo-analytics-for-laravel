<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// Holds batches that exhausted delivery (a poison payload Matomo permanently
// rejects, or transient failures past batch.max_attempts). Nothing is lost: the
// hits sit here for inspection and can be re-queued with `matomo:replay`.
//
// Opt out with batch.dead_letter.enabled=false. What that means depends on the delivery
// MODE, and the two are not the same sentence: in `batch` mode the failed batch stays in
// the buffer; in `queue` mode there is no buffer to stay in, so the job fails the ordinary
// way and the batch lands in `failed_jobs`. Both are visible, neither loses the hits.
//
// That distinction is written out because the single-clause version was true for batch
// users and misleading for queue users, and both read the same line.
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        $this->assertIdentifiersFit([$table]);

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->longText('payloads'); // one JSON hit per line (JSONL)
            $table->unsignedInteger('hits');
            $table->unsignedInteger('attempts');
            $table->text('error')->nullable();
            // Deliberately UNINDEXED. Every read of this table goes by id — the recent
            // list orders by id, the replay walks by id, the cleanup deletes by id — so an
            // index here would be paid for on every insert and used by nothing. It earns
            // one the day something filters on age (a retention window); until then it is
            // overhead with a plausible-looking name.
            $table->dateTime('failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    /**
     * Refuse BEFORE any DDL when the prefixed identifiers would not fit.
     *
     * `Schema::create()` sends more than one statement, and MySQL reports
     * `supportsSchemaTransactions: false` -- so a failure halfway leaves the table created,
     * the migration unrecorded, `migrate:rollback` doing nothing, and every later `migrate`
     * dying on "1050 Table already exists". Neither forward nor back, until somebody drops it
     * by hand. Measured: with a table prefix of 22 characters it runs, at 23 it falls, and a
     * `tenant_<uuid>_` prefix is about 44.
     *
     * PostgreSQL is not safe either, it is merely recoverable: past 63 characters it TRUNCATES
     * silently, which collided two index names at a prefix of 32, and its transactional DDL
     * then rolled the whole thing back.
     *
     * So the check runs first and names the identifier and the prefix. A migration that
     * refuses is a problem somebody can fix; a half-created schema is not.
     *
     * @param  list<string>  $identifiers  unprefixed table and index names this migration creates
     */
    private function assertIdentifiersFit(array $identifiers): void
    {
        $prefix = Schema::getConnection()->getTablePrefix();
        $limit = Schema::getConnection()->getDriverName() === 'pgsql' ? 63 : 64;

        foreach ($identifiers as $identifier) {
            $full = $prefix.$identifier;

            if (strlen($full) <= $limit) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'The Matomo migration cannot create "%s": %d characters with the table prefix "%s", and this database allows %d. Shorten the prefix, or rename the table through matomo-analytics.batch.table.',
                $full,
                strlen($full),
                $prefix,
                $limit,
            ));
        }
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');
    }
};
