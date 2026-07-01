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

        $connection->update(
            "UPDATE {$table} SET hits = hits + 1, updated_at = ? WHERE id = ?",
            [Carbon::now()->toDateTimeString(), $data['id']],
        );

        return new CacheHit(
            response: (string) $data['response'],
            similarity: (float) $data['similarity'],
            meta: $this->decodeMeta($data['meta'] ?? null),
            entryId: (int) $data['id'],
        );
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

        return is_string($table) ? $table : 'llm_cache_entries';
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
