<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// MYSQL'S `TIMESTAMP` IS NOT AN ABSOLUTE MOMENT, AND THIS SCHEMA TREATED IT AS ONE.
//
// Two measured consequences, both on real servers:
//
//  1. It shifts with the SESSION time zone. Written `12:00:00`, read back after
//     `SET time_zone = '+05:00'`: MySQL answers `16:00:00`, PostgreSQL answers `12:00:00`.
//     The session zone is `SYSTEM` by default and follows the operating system's, DST
//     included. The stale-claim arithmetic is built on these columns, so after a DST change
//     every open claim on MySQL is an hour older -- a batch still being sent is reclaimed and
//     Matomo counts it twice -- or an hour younger, and a dead worker's batch is never
//     released. Laravel's own `jobs` table stores epoch integers for exactly this reason.
//
//  2. It ends in 2038. `failed_at = '2039-01-01'` is rejected outright with SQLSTATE[22007]
//     1292 on MySQL; PostgreSQL takes it. An expiry date in the schema.
//
// `datetime` has neither property: MySQL stores it verbatim and its range runs to 9999.
// PostgreSQL maps both to `timestamp without time zone`, so nothing changes there, and SQLite
// stores text either way -- which is also why the suite could not see this.
return new class extends Migration
{
    public function up(): void
    {
        $this->convert('dateTime');
    }

    public function down(): void
    {
        $this->convert('timestamp');
    }

    /**
     * Rewrite the three columns to the given type, skipping what is absent.
     *
     * Guarded per column rather than per table, because `ignoreMigrations()` and a renamed
     * table both leave a tree where one of these exists and the other does not.
     */
    private function convert(string $type): void
    {
        $buffer = Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
        $deadLetters = Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');

        $this->rewrite($buffer, ['claimed_at', 'created_at'], $type);
        $this->rewrite($deadLetters, ['failed_at'], $type);
    }

    /**
     * @param  list<string>  $columns
     */
    private function rewrite(string $table, array $columns, string $type): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($present === []) {
            return;
        }

        $toDateTime = $type === 'dateTime';

        Schema::table($table, function (Blueprint $table) use ($present, $toDateTime): void {
            foreach ($present as $column) {
                // Named rather than variable-dispatched: `$table->{$type}(...)` hands the
                // analyzer a `mixed` it cannot follow, and a typo in the type string would
                // then be a runtime error in a migration.
                $definition = $toDateTime ? $table->dateTime($column) : $table->timestamp($column);

                $definition->nullable()->change();
            }
        });
    }
};
