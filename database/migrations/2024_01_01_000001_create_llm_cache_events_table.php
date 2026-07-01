<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only event history for the `llm-cache:stats` command (§8.1).
 *
 * Driver-agnostic (no vector column), so it works on any database — the events
 * table need not live on the same connection as the pgvector store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connectionName())->create($this->table(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('type')->index();        // 'hit' | 'miss'
            $table->string('scope')->index();
            $table->double('similarity')->nullable(); // hits only
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->table());
    }

    private function connectionName(): ?string
    {
        $name = config('llm-cache.stats.connection');

        return is_string($name) ? $name : null;
    }

    private function table(): string
    {
        $table = config('llm-cache.stats.table');

        return is_string($table) ? $table : 'llm_cache_events';
    }
};
