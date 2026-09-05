<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatomoAnalytics\Support\Config;

// Backs the database driver of the cross-request batch buffer. It is only read
// and written when the package runs in batch mode with the database driver;
// apps that stay on queue mode can opt out via Provider::ignoreMigrations().
return new class extends Migration
{
    public function up(): void
    {
        $table = $this->table();

        $this->assertIdentifiersFit([$table, $table.'_claimed_at_id_index']);

        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->json('payload');
            $table->string('claimed_by')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['claimed_at', 'id']);
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
        return Config::string('matomo-analytics.batch.table', 'matomo_tracking_buffer');
    }
};
