<?php

use Illuminate\Support\Facades\Event;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\Events\CacheHitEvent;
use Yegoragapov\LlmCache\Events\CacheMissEvent;
use Yegoragapov\LlmCache\Facades\SemanticCache;
use Yegoragapov\LlmCache\SemanticCacheManager;
use Yegoragapov\LlmCache\Tests\Support\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| §10 Multi-turn context hashing
|--------------------------------------------------------------------------
| Manager-level behaviour (folds a context digest into the scope), so the
| in-memory array store is sufficient — the prompt vector is identical across
| contexts; only the scope differs.
*/

beforeEach(function () {
    config()->set('llm-cache.store', 'array');
    config()->set('llm-cache.dimension', 16);

    $fake = (new FakeEmbeddingProvider(16))
        ->set('and what about refunds?', [1.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);

    app()->instance(EmbeddingProvider::class, $fake);
    app()->forgetInstance(VectorStore::class);
    app()->forgetInstance(SemanticCacheManager::class);
    app()->forgetInstance('llm-cache');

    Event::fake([CacheHitEvent::class, CacheMissEvent::class]);
});

it('hits when the same prompt is asked under an identical context', function () {
    SemanticCache::remember('and what about refunds?', fn () => 'Refunds within 30 days.', scope: 'user:1', context: 'conversation-1');

    $calls = 0;
    $result = SemanticCache::remember('and what about refunds?', function () use (&$calls) {
        $calls++;

        return 'REGENERATED';
    }, scope: 'user:1', context: 'conversation-1');

    expect($result)->toBe('Refunds within 30 days.')->and($calls)->toBe(0);
    Event::assertDispatched(CacheHitEvent::class);
});

it('misses when the same prompt is asked under a different context', function () {
    SemanticCache::remember('and what about refunds?', fn () => 'Answer for conv 1.', scope: 'user:1', context: 'conversation-1');

    $calls = 0;
    $result = SemanticCache::remember('and what about refunds?', function () use (&$calls) {
        $calls++;

        return 'Answer for conv 2.';
    }, scope: 'user:1', context: 'conversation-2');

    expect($result)->toBe('Answer for conv 2.')->and($calls)->toBe(1);
});

it('derives a deterministic digest from an array of prior turns', function () {
    $context = [
        ['role' => 'user', 'content' => 'do you ship internationally?'],
        ['role' => 'assistant', 'content' => 'Yes, worldwide.'],
    ];

    SemanticCache::remember('and what about refunds?', fn () => 'Stored.', context: $context);

    $calls = 0;
    $result = SemanticCache::remember('and what about refunds?', function () use (&$calls) {
        $calls++;

        return 'REGENERATED';
    }, context: $context);

    expect($result)->toBe('Stored.')->and($calls)->toBe(0);
});

it('is unchanged when no context is passed', function () {
    SemanticCache::remember('and what about refunds?', fn () => 'Plain.');

    $calls = 0;
    $result = SemanticCache::remember('and what about refunds?', function () use (&$calls) {
        $calls++;

        return 'REGENERATED';
    });

    expect($result)->toBe('Plain.')->and($calls)->toBe(0);
});

it('exposes contextScope() so a caller can forget one conversation', function () {
    $scope = SemanticCache::contextScope('user:1', 'conversation-1');

    expect($scope)->toStartWith('user:1#ctx:');

    SemanticCache::remember('and what about refunds?', fn () => 'A', scope: 'user:1', context: 'conversation-1');

    expect(SemanticCache::forget($scope))->toBe(1);
});
