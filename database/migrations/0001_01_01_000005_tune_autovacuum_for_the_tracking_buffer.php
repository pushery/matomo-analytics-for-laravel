<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// A QUEUE TABLE IS THE WORST SHAPE FOR POSTGRESQL'S DEFAULT AUTOVACUUM, and this one is a
// queue: every row is inserted, claimed (an UPDATE, which writes a new tuple) and deleted.
// The default trigger is 20% of the table plus fifty rows, so on a one-million-row backlog
// autovacuum waits for 200,050 dead tuples before it runs at all.
//
// Measured on PostgreSQL 18, blocks read to claim fifty ids:
//
//     0 dead tuples          5 blocks
//     50,000             1,748 blocks
//     200,000            5,373 blocks
//     after VACUUM           5 blocks
//
// End-to-end per drain the effect is much smaller (heap +11%, index +121%) because only the
// first claim of a run pays it. It is still a graveyard sitting exactly where the claim walks,
// and the table that creates it set no per-table tuning.
//
// POSTGRESQL ONLY, AND SILENT EVERYWHERE ELSE. `ALTER TABLE … SET (autovacuum_…)` is
// Postgres syntax; MySQL and SQLite have no equivalent and need none — InnoDB's purge thread
// and SQLite's free-list do this work without a knob. A migration that ran it unconditionally
// would fail every MySQL install.
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $table = $this->table();

        if (! Schema::hasTable($table)) {
            return;
        }

        // 2% plus a thousand rows: often enough that the head of the queue stays clean,
        // rarely enough that a small buffer is not vacuumed on every handful of hits.
        DB::statement(sprintf(
            'ALTER TABLE %s SET (autovacuum_vacuum_scale_factor = 0.02, autovacuum_vacuum_threshold = 1000, autovacuum_analyze_scale_factor = 0.05)',
            $this->quoted($table),
        ));
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $table = $this->table();

        if (! Schema::hasTable($table)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold, autovacuum_analyze_scale_factor)',
            $this->quoted($table),
        ));
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
    }

    /**
     * The table name as an identifier, prefix included.
     *
     * `DB::statement()` takes raw SQL, so the name is quoted here rather than interpolated:
     * `batch.table` is consumer configuration, and a table prefix or a reserved word would
     * otherwise produce a statement that is either broken or worse.
     */
    private function quoted(string $table): string
    {
        $connection = Schema::getConnection();

        return '"'.str_replace('"', '""', $connection->getTablePrefix().$table).'"';
    }
};
