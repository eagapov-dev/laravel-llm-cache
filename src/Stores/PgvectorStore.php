<?php

namespace Yegoragapov\LlmCache\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;

/**
 * Flagship Postgres + pgvector store. The threshold is applied as a
 * cosine-distance filter in SQL via an ANN index (ivfflat/hnsw) — similarity is
 * NOT computed in PHP (see §5.2). The cosine distance operator `<=>` returns
 * `1 - cosine_similarity`, so similarity = `1 - (embedding <=> :vec)`.
 */
class PgvectorStore implements VectorStore
{
    /**
     * @param array<string, mixed> $config The `llm-cache.stores.pgvector` config.
     */
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected array $config,
        protected int $dimensions,
        protected string $providerName,
    ) {
    }

    public function search(array $vector, string $scope, float $threshold): ?CacheHit
    {
        $connection = $this->connection();
        $table = $this->table();
        $literal = self::vectorLiteral($vector);

        // Wrap the whole read in one transaction so the recall-tuning SET LOCAL
        // and the SELECT are guaranteed to run on the same backend — under
        // transaction pooling (PgBouncer / Supabase pooler) a bare SET would
        // otherwise land on a different connection and silently no-op.
        return $connection->transaction(function () use ($connection, $table, $literal, $scope, $threshold): ?CacheHit {
            // Raise ANN recall for this query. With a scope + expiry post-filter
            // and LIMIT 1, the default probes/ef_search often return only
            // candidates from other scopes, yielding a false miss.
            $this->applyRecallTuning($connection);

            // Single SELECT: same scope, not expired, cosine-similarity threshold
            // filtered in SQL, ordered by ANN cosine distance, top 1.
            $row = $connection->selectOne(
                "SELECT id, response, meta, 1 - (embedding <=> ?::vector) AS similarity
                 FROM {$table}
                 WHERE scope = ?
                   AND (expires_at IS NULL OR expires_at > now())
                   AND 1 - (embedding <=> ?::vector) >= ?
                 ORDER BY embedding <=> ?::vector ASC
                 LIMIT 1",
                [$literal, $scope, $literal, $threshold, $literal],
            );

            if ($row === null) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = (array) $row;

            // Best-effort hit counter: a failure here (transient blip) must never
            // demote a valid hit to a miss and trigger a needless regeneration.
            try {
                $connection->update(
                    "UPDATE {$table} SET hits = hits + 1, updated_at = ? WHERE id = ?",
                    [Carbon::now()->toDateTimeString(), $data['id']],
                );
            } catch (\Throwable $e) {
                // non-essential; swallow
            }

            return new CacheHit(
                response: (string) $data['response'],
                similarity: (float) $data['similarity'],
                meta: $this->decodeMeta($data['meta'] ?? null),
                entryId: (int) $data['id'],
            );
        });
    }

    public function put(CacheEntry $entry): void
    {
        $connection = $this->connection();
        $table = $this->table();
        $now = Carbon::now()->toDateTimeString();

        $meta = $entry->meta === []
            ? null
            : json_encode($entry->meta, JSON_THROW_ON_ERROR);

        $connection->insert(
            "INSERT INTO {$table}
                (scope, embedding, response, prompt_preview, provider, model, meta, hits, expires_at, created_at, updated_at)
             VALUES (?, ?::vector, ?, ?, ?, ?, ?::jsonb, 0, ?, ?, ?)",
            [
                $entry->scope,
                self::vectorLiteral($entry->vector),
                $entry->response,
                $entry->promptPreview,
                $this->providerName,
                $entry->model,
                $meta,
                $entry->expiresAt?->toDateTimeString(),
                $now,
                $now,
            ],
        );
    }

    public function forget(string $scope): int
    {
        return $this->connection()->delete(
            "DELETE FROM {$this->table()} WHERE scope = ?",
            [$scope],
        );
    }

    public function flush(): int
    {
        return $this->connection()->delete("DELETE FROM {$this->table()}");
    }

    public function purgeExpired(): int
    {
        $table = $this->table();
        $connection = $this->connection();
        $removed = 0;

        // Delete in bounded batches (matching the Redis driver) so a large sweep
        // never becomes one long-running transaction that locks the table or
        // trips a statement timeout in the daily prune. Loops until dry.
        do {
            $batch = $connection->delete(
                "DELETE FROM {$table}
                 WHERE id IN (
                    SELECT id FROM {$table}
                    WHERE expires_at IS NOT NULL AND expires_at <= now()
                    LIMIT 1000
                 )",
            );
            $removed += $batch;
        } while ($batch > 0);

        return $removed;
    }

    /**
     * Apply the configured query-time ANN recall settings for the active index
     * method. Uses SET LOCAL (transaction-scoped), so it is correct under
     * transaction pooling and auto-reverts at commit; best-effort so an
     * unexpected pgvector build can't break lookups. Must run inside a
     * transaction (search() provides one).
     */
    protected function applyRecallTuning(ConnectionInterface $connection): void
    {
        $method = $this->config['index'] ?? 'hnsw';

        try {
            if ($method === 'ivfflat') {
                $probes = max(1, (int) ($this->config['probes'] ?? 8));
                $connection->statement("SET LOCAL ivfflat.probes = {$probes}");
            } else {
                $efSearch = max(1, (int) ($this->config['ef_search'] ?? 64));
                $connection->statement("SET LOCAL hnsw.ef_search = {$efSearch}");
            }

            // Optional (pgvector 0.8+): let the index keep fetching candidates
            // until the scope/expiry filter is satisfied — the real cure for
            // false misses on a highly selective scope. Off unless configured.
            $iterative = $this->config['iterative_scan'] ?? null;

            if (is_string($iterative) && in_array($iterative, ['strict_order', 'relaxed_order', 'on'], true)) {
                $guc = $method === 'ivfflat' ? 'ivfflat.iterative_scan' : 'hnsw.iterative_scan';
                $connection->statement("SET LOCAL {$guc} = '{$iterative}'");
            }
        } catch (\Throwable $e) {
            // recall tuning is an optimization; never let it break a search
        }
    }

    /**
     * Serialize a vector to a pgvector text literal: `[v1,v2,...]`.
     *
     * `(string) (float)` renders with a `.` decimal separator regardless of
     * locale and avoids trailing-zero noise, keeping the literal parseable by
     * pgvector's input function.
     *
     * @param array<int, float> $vector
     */
    public static function vectorLiteral(array $vector): string
    {
        $parts = array_map(
            static fn (int|float $value): string => (string) (float) $value,
            $vector,
        );

        return '['.implode(',', $parts).']';
    }

    protected function connection(): ConnectionInterface
    {
        $name = $this->config['connection'] ?? null;

        return $this->db->connection(is_string($name) ? $name : null);
    }

    protected function table(): string
    {
        $table = $this->config['table'] ?? 'llm_cache_entries';
        $table = is_string($table) ? $table : 'llm_cache_entries';

        // The name is interpolated into SQL (identifiers can't be bound), so
        // constrain it to a safe identifier shape as defense-in-depth against a
        // misconfigured/less-trusted config source.
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("Invalid llm-cache table name [{$table}].");
        }

        return $table;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeMeta(mixed $meta): array
    {
        if (! is_string($meta) || $meta === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        /** @var array<string, mixed> $result */
        $result = is_array($decoded) ? $decoded : [];

        return $result;
    }
}
