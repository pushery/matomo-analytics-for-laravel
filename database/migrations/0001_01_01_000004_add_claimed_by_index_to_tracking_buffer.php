<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// The claim path reads and writes by claimed_by, and until now nothing indexed it.
//
// The create migration added one secondary index, ['claimed_at', 'id'], which is the one
// the claim itself uses. What it does not cover is everything that happens AFTER a claim,
// and all three of those filter on claimed_by: reading the payloads back
// (DatabaseHitBuffer::claim), ack() deleting the batch, and release() handing it back. So
// a delivered batch cost two full scans of the buffer table and a released one cost two
// more.
//
// That is invisible while the buffer is small, which is most of the time — and it stops
// being invisible in exactly the situation the buffer exists for. A flush run walks up to
// max_per_flush / batch.size batches (2000/50 = 40 on the shipped defaults), so a backlog
// turns into up to eighty full scans a minute, at the moment the table is at its largest.
//
// Deliberately a SEPARATE migration rather than an edit to the create migration, for the
// reason the retention index one migration up gives: editing that one would leave every
// existing installation without the index while recording the schema as current.
return new class extends Migration
{
    public function up(): void
    {
        // Conditional in BOTH directions, and matched by COLUMNS rather than by a
        // hand-built name — Laravel's createIndexName() prepends the connection's table
        // prefix when `prefix_indexes` is set (the shipped default for mysql and pgsql),
        // so a name assembled here would miss the real index and add a second one beside it.
        if (! Schema::hasTable($this->table()) || Schema::hasIndex($this->table(), ['claimed_by'])) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->index('claimed_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table()) || ! Schema::hasIndex($this->table(), ['claimed_by'])) {
            return;
        }

        // BY THE NAME THAT EXISTS, NOT BY THE NAME LARAVEL WOULD DERIVE. The guard above
        // matches on COLUMNS -- `hasIndex($table, ['claimed_by'])` is true for any index over
        // exactly that column, whatever it is called -- while `dropIndex(['claimed_by'])`
        // builds `<table>_claimed_by_index` and drops that. A DBA who created the index by
        // hand (likely: the package ran eight releases without it) therefore got a clean
        // `up()` and a rollback that died:
        //
        //   PostgreSQL  SQLSTATE[42704] index "..._claimed_by_index" does not exist
        //   MySQL       SQLSTATE[42000] 1091 Can't DROP '...'
        //
        // And this is the NEWEST migration, so it rolls back first -- the whole rollback ends
        // there. Eighteen arms across the three index migrations covered prefixes, repeats,
        // a missing table and an absent index, and none covered "same column, another name".
        $name = $this->indexNameFor('claimed_by');

        if ($name === null) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    /**
     * The name of the index over exactly these columns, or null when there is none.
     *
     * Read from the connection rather than derived, for the reason `down()` explains: the
     * two are the same string only when Laravel created the index.
     *
     * @param  string|list<string>  $columns
     */
    private function indexNameFor(string|array $columns): ?string
    {
        $wanted = array_map(strtolower(...), (array) $columns);
        sort($wanted);

        foreach (Schema::getIndexes($this->table()) as $index) {
            if (! is_array($index)) {
                continue;
            }

            $name = $index['name'] ?? null;
            $on = $index['columns'] ?? [];

            if (! is_string($name) || ! is_array($on)) {
                continue;
            }

            $have = array_map(
                static fn (mixed $column): string => strtolower(is_string($column) ? $column : ''),
                array_values($on),
            );
            sort($have);

            if ($have === $wanted) {
                return $name;
            }
        }

        return null;
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
    }
};
