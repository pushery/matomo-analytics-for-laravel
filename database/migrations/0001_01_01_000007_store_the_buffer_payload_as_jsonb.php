<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// PostgreSQL's `json` keeps the text it was handed, and this column has no use for that.
//
// The payload is a map of Matomo tracking parameters, `array<string, scalar>`. The array cast
// writes it and reads it back, and the flush rebuilds a query string out of the array. Nothing
// reads its bytes, compares two encodings of the same map, or depends on key order — and that
// is the one thing `json` can do that `jsonb` cannot. `jsonb` parses once on write instead of
// on every read, stores smaller, and is the type a containment or key lookup could use later.
//
// SQLens reports the `json` type of this column as `PG.L6.JSON_NOT_JSONB` in every consumer's
// audit, and a finding nobody can act on would stay in every report.
//
// The other two findings of that audit are decisions, and they stay.
//
// `claimed_at`, `created_at` and `failed_at` are `timestamp without time zone` on purpose. The
// migration two files up moved them there FROM `timestamp` because MySQL's `TIMESTAMP` shifts
// with the session time zone and ends in 2038, and `timestamptz` is not the way back: MySQL
// has no such type, so a shared definition would hand MySQL that defect again. On PostgreSQL
// alone it would only be correct when the connection's session zone matches `app.timezone`,
// which a package cannot promise for a host it does not configure — Laravel writes a naive
// `Y-m-d H:i:s` string, and the session zone decides what instant that becomes.
//
// `id` is `serial` because it is `$table->id()`, Laravel's own. An identity column is the
// better default in PostgreSQL, and rewriting it here would make this package's tables differ
// from every other table in the application for a property nothing in the claim path reads.
//
// PostgreSQL only. MySQL's `json` is already binary and has no second type; SQLite stores text
// either way. A migration that ran this unconditionally would fail every non-Postgres install.
return new class extends Migration
{
    public function up(): void
    {
        $this->retype('jsonb');
    }

    public function down(): void
    {
        $this->retype('json');
    }

    private function retype(string $type): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');

        // The name is validated before the existence check, and the order is the whole point.
        // The other way round the refusal below is unreachable: `Schema::hasTable()` answers
        // false for any name that is not a real table, so a hostile one would take the quiet
        // exit and the guard would never run — a check that cannot fire, which reads in a
        // coverage report exactly as it did here, as a line no test covers.
        $quoted = $this->quoted($table);

        // Guarded per column rather than per table: `ignoreMigrations()` and a renamed table
        // both leave a tree where one exists and the other does not.
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'payload')) {
            return;
        }

        // The cast is explicit because PostgreSQL has no assignment cast between the two: an
        // `ALTER COLUMN … TYPE` without `USING` is refused outright rather than guessed at.
        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN payload TYPE %s USING payload::%s',
            $quoted,
            $type,
            $type,
        ));
    }

    /**
     * The table name as an identifier, refusing a configured name that is not a bare one.
     *
     * The name is configuration rather than input, but it reaches DDL either way, and "it
     * cannot be hostile" is an assumption rather than a guard. `Schema::hasTable()` and
     * `hasColumn()` add the connection's table prefix themselves and a raw statement does not,
     * so the prefix is added here, quoted the way the autovacuum migration quotes it.
     */
    private function quoted(string $table): string
    {
        if (preg_match('/^\w+$/', $table) !== 1) {
            throw new RuntimeException("refusing to retype the payload column of an unexpected table name: {$table}");
        }

        return '"'.str_replace('"', '""', Schema::getConnection()->getTablePrefix().$table).'"';
    }
};
