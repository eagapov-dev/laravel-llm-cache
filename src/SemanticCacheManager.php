<?php

namespace Yegoragapov\LlmCache;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\Events\CacheHitEvent;
use Yegoragapov\LlmCache\Events\CacheMissEvent;

/**
 * Public entry point behind the SemanticCache facade.
 *
 * Implements the embed → search → hit/miss flow with fail-open handling and
 * event dispatch, per docs/specs/llm-cache.md §4.3, §6 and §7.
 */
class SemanticCacheManager
{
    /**
     * @param array<string, mixed> $config The resolved `llm-cache` config array.
     */
    public function __construct(
        protected EmbeddingProvider $embeddings,
        protected VectorStore $store,
        protected Dispatcher $events,
        protected array $config,
    ) {
    }

    /**
     * Serve a cached response for a semantically-equivalent prompt, or run the
     * callback and cache its result.
     *
     * @param  Closure(): string     $callback The real LLM call.
     * @param  array<string, mixed>  $meta     Arbitrary caller metadata; `model` is lifted to a typed column.
     */
    public function remember(
        string $prompt,
        Closure $callback,
        ?float $threshold = null,
        ?CarbonInterval $ttl = null,
        string $scope = 'global',
        array $meta = [],
    ): string {
        // §6: an empty prompt bypasses the cache entirely — no store, no events.
        if (trim($prompt) === '') {
            return $callback();
        }

        $threshold ??= (float) $this->config['threshold'];

        // §7: embed + search are the cache-layer read path. Any failure here is
        // handled by the configured fail mode (open by default).
        try {
            $vector = $this->embeddings->embed($prompt);
            $hit = $this->store->search($vector, $scope, $threshold);
        } catch (Throwable $e) {
            if ($this->failMode() === 'closed') {
                throw $e;
            }

            Log::warning('llm-cache: cache read failed, failing open', [
                'scope' => $scope,
                'exception' => $e->getMessage(),
            ]);

            return $callback();
        }

        // Hit: the store already incremented `hits` inside search().
        if ($hit !== null) {
            $this->events->dispatch(new CacheHitEvent($prompt, $hit->similarity, $scope, $hit->entryId));

            return $hit->response;
        }

        // Miss: generate, then persist for next time.
        $response = $callback();

        $ttl ??= $this->resolveConfigTtl();
        $expiresAt = $ttl !== null ? now()->add($ttl) : null;

        $entry = new CacheEntry(
            vector: $vector,
            response: $response,
            scope: $scope,
            expiresAt: $expiresAt,
            model: (isset($meta['model']) && is_string($meta['model'])) ? $meta['model'] : null,
            promptPreview: mb_substr($prompt, 0, 255),
            meta: $meta,
        );

        // §6: a `put` failure is always logged and swallowed — the fresh
        // response is still returned regardless of fail mode.
        try {
            $this->store->put($entry);
        } catch (Throwable $e) {
            Log::warning('llm-cache: cache write failed, response served uncached', [
                'scope' => $scope,
                'exception' => $e->getMessage(),
            ]);
        }

        $this->events->dispatch(new CacheMissEvent($prompt, $scope));

        return $response;
    }

    public function forget(string $scope): int
    {
        return $this->store->forget($scope);
    }

    public function flush(): int
    {
        return $this->store->flush();
    }

    /**
     * Resolve the config `ttl` duration string into a CarbonInterval, or null
     * when no default expiry is configured.
     */
    protected function resolveConfigTtl(): ?CarbonInterval
    {
        $ttl = $this->config['ttl'] ?? null;

        if ($ttl instanceof CarbonInterval) {
            return $ttl;
        }

        if ($ttl === null || $ttl === '') {
            return null;
        }

        return CarbonInterval::fromString((string) $ttl);
    }

    protected function failMode(): string
    {
        $mode = $this->config['fail_mode'] ?? 'open';

        return is_string($mode) ? $mode : 'open';
    }
}
