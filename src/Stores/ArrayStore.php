<?php

namespace Yegoragapov\LlmCache\Stores;

use LogicException;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;

/**
 * In-memory vector store for tests. Computes cosine similarity in PHP.
 *
 * STUB (Phase 0). Implemented on `feat/store-array` against §4.2 / §5.1.
 * Must satisfy the SAME behavioural contract as the pgvector driver.
 *
 * NOTE for the implementer: expose a test-support accessor (e.g. entries())
 * so acceptance tests can assert the `hits` counter increments.
 */
class ArrayStore implements VectorStore
{
    public function __construct(
        protected int $dimensions,
        protected string $providerName,
    ) {
    }

    public function search(array $vector, string $scope, float $threshold): ?CacheHit
    {
        throw new LogicException('ArrayStore::search() not implemented (Phase 1: feat/store-array).');
    }

    public function put(CacheEntry $entry): void
    {
        throw new LogicException('ArrayStore::put() not implemented (Phase 1: feat/store-array).');
    }

    public function forget(string $scope): int
    {
        throw new LogicException('ArrayStore::forget() not implemented (Phase 1: feat/store-array).');
    }

    public function flush(): int
    {
        throw new LogicException('ArrayStore::flush() not implemented (Phase 1: feat/store-array).');
    }
}
