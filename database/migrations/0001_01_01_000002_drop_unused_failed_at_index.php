<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// The dead-letter table shipped with an index on failed_at that no query ever used:
// the recent list orders by id, the replay walks by id, the cleanup deletes by id.
// Removing it from the CREATE only helps installations made after this release —
// everyone already running keeps paying for it on every insert — so it is dropped
// here as well.
//
// Reversible on purpose. down() puts the index back rather than doing nothing, so
// rolling this migration back leaves the schema exactly as the previous release had
// it; a down() that silently keeps the new shape is how a rollback stops meaning
// what it says.
return new class extends Migration
{
    public function up(): void
    {
        // Conditional in BOTH directions, and not as belt-and-braces. On a fresh database
        // the create migration above no longer adds the index at all, so this would drop
        // something that never existed and take `migrate` down with it — a migration has to
        // run from zero as cleanly as it runs on an installation that has the old shape.
        // Matched by COLUMNS, not by name. hasIndex() compares against both, and the
        // column form is the only one that is independent of how the name was derived.
        if (! Schema::hasTable($this->table()) || ! Schema::hasIndex($this->table(), ['failed_at'])) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            // The COLUMN ARRAY, deliberately — not a hand-built name.
            //
            // This used to pass an explicit name, reasoning that an installation which
            // RENAMED the table carries a name built from its own table. That reasoning was
            // right about one variable and blind to a second: Laravel's createIndexName()
            // also prepends the connection's TABLE PREFIX whenever `prefix_indexes` is set,
            // which is the shipped default for mysql and pgsql. The index was created by an
            // unnamed `$table->index('failed_at')`, so it carries the prefixed name — and a
            // hand-built, prefix-blind name never matches it. up() then dropped nothing
            // while recording itself as run, and down() added a SECOND index on the same
            // column.
            //
            // The array form goes through createIndexName() itself, so it reproduces
            // whatever Laravel produced at create time — renamed table and prefix alike.
            $table->dropIndex(['failed_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table()) || Schema::hasIndex($this->table(), ['failed_at'])) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            // Unnamed, exactly as the create migration wrote it before this release, so a
            // rollback restores the name the previous shape actually had — prefix included.
            $table->index('failed_at');
        });
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');
    }
};
