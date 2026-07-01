<?php

namespace Yegoragapov\LlmCache\Stores;

use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;

/**
 * Flagship Postgres + pgvector store. Threshold is applied as a cosine-distance
 * filter in SQL via an ANN index (ivfflat/hnsw) — similarity is NOT computed
 * in PHP (see §5.2).
 *
 * STUB (Phase 0). Implemented on `feat/store-pgvector` against §4.2 / §5.1 / §5.2,
 * along with the migration and the boot-time dimension guard.
 *
 * @param array<string, mixed> $config The `llm-cache.stores.pgvector` config.
 */
class PgvectorStore implements VectorStore
{
    /**
     * @param array<string, mixed> $config
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
        throw new LogicException('PgvectorStore::search() not implemented (Phase 1: feat/store-pgvector).');
    }

    public function put(CacheEntry $entry): void
    {
        throw new LogicException('PgvectorStore::put() not implemented (Phase 1: feat/store-pgvector).');
    }

    public function forget(string $scope): int
    {
        throw new LogicException('PgvectorStore::forget() not implemented (Phase 1: feat/store-pgvector).');
    }

    public function flush(): int
    {
        throw new LogicException('PgvectorStore::flush() not implemented (Phase 1: feat/store-pgvector).');
    }
}
