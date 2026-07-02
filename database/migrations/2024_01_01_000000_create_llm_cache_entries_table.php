<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `llm_cache_entries` table for the pgvector store (§3).
 *
 * pgvector types (`vector(N)`) and the ANN cosine index cannot be expressed by
 * the schema builder, so they are added with raw statements. The migration is a
 * no-op on non-pgsql drivers: pgvector is Postgres-only and the package's own
 * suite runs on the in-memory `array` store.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = $this->connectionName();
        $db = DB::connection($connection);

        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $table = $this->table();
        $dimension = (int) config('llm-cache.dimension');
        // Default to hnsw: it builds incrementally (correct on an empty table)
        // and has higher recall than ivfflat, whose k-means partitions are
        // degenerate until rebuilt on representative data.
        $method = config('llm-cache.stores.pgvector.index') === 'ivfflat' ? 'ivfflat' : 'hnsw';

        // Enable the pgvector extension (idempotent).
        $db->statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::connection($connection)->create($table, function (Blueprint $blueprint): void {
            $blueprint->bigIncrements('id');
            $blueprint->string('scope')->index();
            // `embedding vector(N)` is added below via a raw statement.
            $blueprint->text('response');
            $blueprint->string('prompt_preview')->nullable();
            $blueprint->string('provider');
            $blueprint->string('model')->nullable();
            $blueprint->jsonb('meta')->nullable();
            $blueprint->integer('hits')->default(0);
            // timestamptz (not plain timestamp): expires_at is compared against
            // SQL now() in every search/purge. A timezone-naive column would be
            // read in the DB session's timezone, so an app/DB timezone mismatch
            // would shift expiry by whole hours (entries expiring early or late).
            $blueprint->timestampTz('expires_at')->nullable()->index();
            $blueprint->timestampsTz();
        });

        // Vector column — no schema-builder equivalent.
        $db->statement(sprintf('ALTER TABLE %s ADD COLUMN embedding vector(%d)', $table, $dimension));

        // ANN cosine index for SQL-side threshold filtering (§5.2 / §7).
        if ($method === 'hnsw') {
            $m = (int) config('llm-cache.stores.pgvector.hnsw.m', 16);
            $efConstruction = (int) config('llm-cache.stores.pgvector.hnsw.ef_construction', 64);
            $with = sprintf('WITH (m = %d, ef_construction = %d)', $m, $efConstruction);
        } else {
            $lists = (int) config('llm-cache.stores.pgvector.ivfflat.lists', 100);
            $with = sprintf('WITH (lists = %d)', $lists);
        }

        $db->statement(sprintf(
            'CREATE INDEX %s_embedding_%s_idx ON %s USING %s (embedding vector_cosine_ops) %s',
            $table,
            $method,
            $table,
            $method,
            $with,
        ));
    }

    public function down(): void
    {
        $connection = $this->connectionName();

        if (DB::connection($connection)->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::connection($connection)->dropIfExists($this->table());
    }

    private function connectionName(): ?string
    {
        $name = config('llm-cache.stores.pgvector.connection');

        return is_string($name) ? $name : null;
    }

    private function table(): string
    {
        $table = config('llm-cache.stores.pgvector.table');
        $table = is_string($table) ? $table : 'llm_cache_entries';

        // This name is interpolated into raw ALTER/CREATE INDEX statements below
        // (identifiers can't be bound), so constrain it to a safe identifier
        // shape — matching the guard in PgvectorStore::table().
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException("Invalid llm-cache table name [{$table}].");
        }

        return $table;
    }
};
