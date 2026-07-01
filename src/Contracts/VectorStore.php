<?php

namespace Yegoragapov\LlmCache\Contracts;

use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;

interface VectorStore
{
    /**
     * Find the nearest cached entry within $scope whose cosine similarity to
     * $vector is >= $threshold. Expired entries are never returned.
     *
     * @param array<int, float> $vector
     */
    public function search(array $vector, string $scope, float $threshold): ?CacheHit;

    /**
     * Persist a cache entry. Stamps the store's own provider name.
     */
    public function put(CacheEntry $entry): void;

    /**
     * Remove all entries in a scope. Returns the number of rows removed.
     */
    public function forget(string $scope): int;

    /**
     * Remove every entry. Returns the number of rows removed.
     */
    public function flush(): int;
}
