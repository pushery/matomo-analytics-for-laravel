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
        Schema::create($this->table(), function (Blueprint $table): void {
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
            $table->timestamp('failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('matomo-analytics.batch.dead_letter.table', 'matomo_dead_letters');
    }
};
