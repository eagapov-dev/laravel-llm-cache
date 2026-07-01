<?php

namespace Yegoragapov\LlmCache;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Contracts\VectorStore;

/**
 * Public entry point behind the SemanticCache facade.
 *
 * STUB (Phase 0 foundation). The real behaviour — embed → search → hit/miss,
 * fail-open handling, event dispatch — is implemented on branch `feat/core`
 * against the acceptance tests in docs/specs/llm-cache.md §5.1.
 *
 * @param array<string, mixed> $config The resolved `llm-cache` config array.
 */
class SemanticCacheManager
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected EmbeddingProvider $embeddings,
        protected VectorStore $store,
        protected Dispatcher $events,
        protected array $config,
    ) {
    }

    public function remember(
        string $prompt,
        Closure $callback,
        ?float $threshold = null,
        ?CarbonInterval $ttl = null,
        string $scope = 'global',
        array $meta = [],
    ): string {
        throw new LogicException('SemanticCacheManager::remember() not implemented (Phase 1: feat/core).');
    }

    public function forget(string $scope): int
    {
        throw new LogicException('SemanticCacheManager::forget() not implemented (Phase 1: feat/core).');
    }

    public function flush(): int
    {
        throw new LogicException('SemanticCacheManager::flush() not implemented (Phase 1: feat/core).');
    }
}
