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

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropIndex(['claimed_by']);
        });
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
    }
};
