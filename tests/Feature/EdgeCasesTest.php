<?php

use Illuminate\Support\Facades\Event;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\Events\CacheMissEvent;
use Yegoragapov\LlmCache\Facades\SemanticCache;
use Yegoragapov\LlmCache\SemanticCacheManager;
use Yegoragapov\LlmCache\Tests\Support\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| §6 Edge cases (behavioural, array driver)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('llm-cache.store', 'array');
    config()->set('llm-cache.dimension', 16);
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(16));
    app()->forgetInstance(VectorStore::class);
    app()->forgetInstance(SemanticCacheManager::class);
    app()->forgetInstance('llm-cache');
});

it('skips the cache entirely for an empty prompt', function () {
    Event::fake();

    $calls = 0;
    $result = SemanticCache::remember('', function () use (&$calls) {
        $calls++;

        return 'raw';
    });

    expect($result)->toBe('raw')->and($calls)->toBe(1);

    // Not stored, no cache events emitted for an empty prompt.
    Event::assertNotDispatched(CacheMissEvent::class);
});

it('fails open: returns a fresh response when the embedding provider throws', function () {
    // The primed fake has no vector for this prompt, so embed() throws.
    config()->set('llm-cache.fail_mode', 'open');

    $calls = 0;
    $result = SemanticCache::remember('unprimed prompt that makes embed() throw', function () use (&$calls) {
        $calls++;

        return 'served despite embedding outage';
    });

    expect($result)->toBe('served despite embedding outage')->and($calls)->toBe(1);
});

it('fails closed: rethrows the embedding error when configured to', function () {
    config()->set('llm-cache.fail_mode', 'closed');

    expect(fn () => SemanticCache::remember('unprimed prompt that makes embed() throw', fn () => 'x'))
        ->toThrow(RuntimeException::class);
});
