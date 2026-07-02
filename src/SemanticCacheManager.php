<?php

namespace Yegoragapov\LlmCache;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
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
        protected ?CacheFactory $cache = null,
    ) {
    }

    /**
     * Serve a cached response for a semantically-equivalent prompt, or run the
     * callback and cache its result.
     *
     * SECURITY: $scope is the only isolation boundary. The cache returns a
     * response generated for a *similar* prompt within the same scope, so the
     * default 'global' scope shares cached answers across all callers — never
     * cache anything user-specific or containing PII under 'global'. In
     * multi-tenant / per-user contexts always pass a trusted, server-derived
     * scope (e.g. "user:".auth()->id()), never a raw request value.
     *
     * @param  Closure(): string        $callback The real LLM call.
     * @param  array<string, mixed>     $meta     Arbitrary caller metadata; `model` is lifted to a typed column.
     * @param  string|array<mixed>|null $context  Conversation context to isolate on (§10); folded into the scope.
     */
    public function remember(
        string $prompt,
        Closure $callback,
        ?float $threshold = null,
        ?CarbonInterval $ttl = null,
        string $scope = 'global',
        array $meta = [],
        string|array|null $context = null,
    ): string {
        // §6: an empty prompt bypasses the cache entirely — no store, no events.
        if (trim($prompt) === '') {
            return $callback();
        }

        // §10: fold any conversation context into the scope so context-dependent
        // follow-ups don't collide across conversations.
        $scope = $this->contextScope($scope, $context);

        $threshold ??= (float) ($this->config['threshold'] ?? 0.95);

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

            // Emit a miss so stats stay truthful during a provider/store outage —
            // otherwise the hit rate flatlines exactly when you need to see it.
            $this->events->dispatch(new CacheMissEvent($prompt, $scope));

            return $callback();
        }

        // Hit: the store already incremented `hits` inside search().
        if ($hit !== null) {
            $this->events->dispatch(new CacheHitEvent($prompt, $hit->similarity, $scope, $hit->entryId));

            return $hit->response;
        }

        // Miss: generate (deduplicated by the optional lock), then persist.
        return $this->lockEnabled()
            ? $this->generateWithLock($prompt, $callback, $vector, $threshold, $scope, $ttl, $meta)
            : $this->generateAndStore($prompt, $callback, $vector, $scope, $ttl, $meta);
    }

    /**
     * Run the callback, persist the result, and dispatch the miss event.
     *
     * @param  Closure(): string     $callback
     * @param  array<int, float>     $vector
     * @param  array<string, mixed>  $meta
     */
    protected function generateAndStore(
        string $prompt,
        Closure $callback,
        array $vector,
        string $scope,
        ?CarbonInterval $ttl,
        array $meta,
    ): string {
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

    /**
     * §9: dedupe concurrent identical misses. The leader generates while waiters
     * block, then re-read the cache and reuse the leader's result. Any lock
     * failure fails open to a plain generation.
     *
     * @param  Closure(): string     $callback
     * @param  array<int, float>     $vector
     * @param  array<string, mixed>  $meta
     */
    protected function generateWithLock(
        string $prompt,
        Closure $callback,
        array $vector,
        float $threshold,
        string $scope,
        ?CarbonInterval $ttl,
        array $meta,
    ): string {
        $lock = $this->resolveLock($scope, $prompt);

        if ($lock === null) {
            return $this->generateAndStore($prompt, $callback, $vector, $scope, $ttl, $meta);
        }

        $wait = (int) ($this->config['lock']['wait'] ?? 10);

        try {
            $lock->block($wait);
        } catch (Throwable $e) {
            // Timed out waiting, or the lock backend errored — fail open.
            Log::warning('llm-cache: lock unavailable, generating without dedup', [
                'scope' => $scope,
                'exception' => $e->getMessage(),
            ]);

            return $this->generateAndStore($prompt, $callback, $vector, $scope, $ttl, $meta);
        }

        try {
            // A concurrent leader may have populated the cache while we waited.
            try {
                $hit = $this->store->search($vector, $scope, $threshold);
            } catch (Throwable $e) {
                $hit = null;
            }

            if ($hit !== null) {
                $this->events->dispatch(new CacheHitEvent($prompt, $hit->similarity, $scope, $hit->entryId));

                return $hit->response;
            }

            return $this->generateAndStore($prompt, $callback, $vector, $scope, $ttl, $meta);
        } finally {
            $lock->release();
        }
    }

    protected function lockEnabled(): bool
    {
        return $this->cache !== null && (bool) ($this->config['lock']['enabled'] ?? false);
    }

    /**
     * Resolve an atomic lock for this scope+prompt, or null when the configured
     * store is unavailable or lacks lock support (caller then fails open).
     */
    protected function resolveLock(string $scope, string $prompt): ?Lock
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            $store = $this->cache->store($this->lockStoreName())->getStore();
        } catch (Throwable $e) {
            Log::warning('llm-cache: lock store unavailable, generating without dedup', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $store instanceof LockProvider) {
            return null;
        }

        $key = 'llm-cache:lock:'.sha1($scope.'|'.$prompt);
        $ttl = (int) ($this->config['lock']['ttl'] ?? 10);

        return $store->lock($key, $ttl);
    }

    protected function lockStoreName(): ?string
    {
        $name = $this->config['lock']['store'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function forget(string $scope): int
    {
        return $this->store->forget($scope);
    }

    public function flush(): int
    {
        return $this->store->flush();
    }

    public function purgeExpired(): int
    {
        return $this->store->purgeExpired();
    }

    /**
     * Fold a conversation-context digest into a scope (§10). Null/empty context
     * returns the scope unchanged. Exposed so callers can target one
     * conversation's entries with forget().
     *
     * @param string|array<mixed>|null $context
     */
    public function contextScope(string $scope, string|array|null $context = null): string
    {
        if ($context === null || $context === '' || $context === []) {
            return $scope;
        }

        if (is_array($context)) {
            // Must never throw: contextScope() runs before the fail-open try in
            // remember(), so an exception here would break the app's LLM path.
            // But it must also never silently drop the digest (that collapses
            // distinct conversations into one scope and leaks answers across
            // them). Substitute invalid UTF-8, and fall back to serialize() for
            // anything json can't encode — always a stable, isolating digest.
            $raw = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE);

            if ($raw === false) {
                // serialize() itself throws on closures/resources nested in the
                // context array. Guard it so a non-serializable context degrades
                // to a stable digest instead of breaking the LLM path.
                try {
                    $raw = serialize($context);
                } catch (Throwable $e) {
                    $raw = 'llm-cache:unserializable-context:'.print_r($context, true);
                }
            }
        } else {
            $raw = $context;
        }

        return $scope.'#ctx:'.hash('sha256', $raw);
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

        // A malformed ttl (e.g. bare "3600" with no unit) must not throw here:
        // the callback has already run and the response is in hand. Degrade to
        // "no expiry" and warn rather than discarding a paid-for generation.
        try {
            $interval = CarbonInterval::fromString((string) $ttl);
        } catch (Throwable $e) {
            $interval = null;
        }

        // CarbonInterval::fromString() does NOT throw on every bad input: "0",
        // "abc" and similar yield a zero-length interval instead. A zero ttl
        // would set expires_at ≈ now(), so the entry is already expired by the
        // next search() — the cache writes but never hits (0% hit rate at full
        // generation cost, silently). Treat both cases as "no expiry".
        if ($interval === null || $interval->isEmpty()) {
            Log::warning('llm-cache: invalid or zero ttl config, storing without expiry', [
                'ttl' => is_scalar($ttl) ? (string) $ttl : gettype($ttl),
            ]);

            return null;
        }

        return $interval;
    }

    /**
     * The configured fail mode, normalized. Governs the read path (embed +
     * search) only; put/lock always fail open by design (§6, §9.3). Any
     * unrecognized value falls back to "open".
     */
    protected function failMode(): string
    {
        $mode = $this->config['fail_mode'] ?? 'open';

        return $mode === 'closed' ? 'closed' : 'open';
    }
}
