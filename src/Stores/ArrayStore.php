<?php

namespace Yegoragapov\LlmCache\Stores;

use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\Support\StoredEntry;
use Yegoragapov\LlmCache\Support\Vectors;

/**
 * In-memory vector store. Computes cosine similarity in PHP.
 *
 * Satisfies the SAME behavioural contract as the pgvector driver (§5.1):
 * scope isolation, expiry exclusion, threshold-gated best-match search, and a
 * `hits` counter incremented on every hit. Intended for tests and small,
 * process-local caches — state does not survive the request/worker lifetime.
 */
class ArrayStore implements VectorStore
{
    /**
     * @var array<int, StoredEntry>
     */
    protected array $entries = [];

    protected int $nextId = 1;

    public function __construct(
        protected int $dimensions,
        protected string $providerName,
    ) {
    }

    public function search(array $vector, string $scope, float $threshold): ?CacheHit
    {
        $this->assertDimensions($vector);

        $best = null;
        $bestSimilarity = -INF;

        foreach ($this->entries as $entry) {
            if ($entry->scope !== $scope || $entry->isExpired()) {
                continue;
            }

            $similarity = Vectors::cosineSimilarity($vector, $entry->vector);

            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $best = $entry;
            }
        }

        if ($best === null || $bestSimilarity < $threshold) {
            return null;
        }

        // Side effect the acceptance suite observes via entries()[0]->hits.
        $best->hits++;

        return new CacheHit(
            response: $best->response,
            similarity: $bestSimilarity,
            meta: $best->meta,
            entryId: $best->id,
        );
    }

    public function put(CacheEntry $entry): void
    {
        $this->assertDimensions($entry->vector);

        $this->entries[] = new StoredEntry(
            id: $this->nextId++,
            vector: $entry->vector,
            response: $entry->response,
            scope: $entry->scope,
            expiresAt: $entry->expiresAt,
            model: $entry->model,
            promptPreview: $entry->promptPreview,
            meta: $entry->meta,
            provider: $this->providerName,
            hits: 0,
        );
    }

    public function forget(string $scope): int
    {
        $remaining = [];
        $removed = 0;

        foreach ($this->entries as $entry) {
            if ($entry->scope === $scope) {
                $removed++;

                continue;
            }

            $remaining[] = $entry;
        }

        $this->entries = $remaining;

        return $removed;
    }

    public function flush(): int
    {
        $removed = count($this->entries);

        $this->entries = [];

        return $removed;
    }

    /**
     * Test-support accessor. Returns stored entries (re-indexed) so callers can
     * assert on the `hits` counter and other row fields.
     *
     * @return array<int, StoredEntry>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    /**
     * Guard against a provider that silently changes embedding dimension
     * mid-flight (§6): reject vectors whose length differs from the configured
     * dimension before they can poison the store or a search.
     *
     * @param array<int, float> $vector
     */
    protected function assertDimensions(array $vector): void
    {
        if (count($vector) !== $this->dimensions) {
            throw DimensionMismatchException::make(
                $this->providerName,
                count($vector),
                $this->dimensions,
            );
        }
    }
}
