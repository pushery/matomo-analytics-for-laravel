<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// The index on failed_at comes back, and this time it is used.
//
// It shipped originally, was never read by any query, and was dropped one migration ago
// because it cost an insert on every dead-lettered batch and returned nothing. Both
// migrations were right for their moment: the create migration's own comment said the
// column "earns one the day something filters on age (a retention window)", and the
// retention window is now here. batch.dead_letter.retention_days makes the daily prune
// filter on exactly this column, on a table whose reason for existing is that it grows.
//
// Deliberately a SEPARATE migration rather than an edit to either of the two before it.
// Editing the create migration would leave every existing installation without the index
// while recording the schema as current, and editing the drop would make a migration mean
// something different after it had already run.
return new class extends Migration
{
    public function up(): void
    {
        // Conditional in BOTH directions, for the same reason the drop before it is: this
        // has to run cleanly from zero AND on an installation carrying either earlier
        // shape. Matched by COLUMNS, never by a hand-built name — Laravel's
        // createIndexName() prepends the connection's table prefix when `prefix_indexes`
        // is set (the shipped default for mysql and pgsql), so a name assembled here would
        // miss the real index and this migration would add a second one beside it.
        if (! Schema::hasTable($this->table()) || Schema::hasIndex($this->table(), ['failed_at'])) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->index('failed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table()) || ! Schema::hasIndex($this->table(), ['failed_at'])) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropIndex($this->indexNameFor('failed_at') ?? 'failed_at');
        });
    }

    /**
     * The name of the index over exactly these columns, or null when there is none.
     *
     * DERIVED FROM THE CONNECTION, NOT FROM THE COLUMN NAMES. `hasIndex()` matches on columns
     * and `dropIndex([...])` builds `<table>_<columns>_index`, so the two agree only when
     * Laravel created the index. A DBA who created it by hand -- likely, since the package ran
     * without it for eight releases -- got a clean guard and a drop that died with "index does
     * not exist" on PostgreSQL or 1091 on MySQL, ending the whole rollback.
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
        return Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');
    }
};
