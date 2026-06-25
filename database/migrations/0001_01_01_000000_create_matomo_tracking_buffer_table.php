<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs the database driver of the cross-request batch buffer. It is only read
// and written when the package runs in batch mode with the database driver;
// apps that stay on queue mode can opt out via Provider::ignoreMigrations().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->json('payload');
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['claimed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('matomo-analytics.batch.table', 'matomo_tracking_buffer');

        return is_string($table) ? $table : 'matomo_tracking_buffer';
    }
};
