<?php

use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;
use Yegoragapov\LlmCache\LlmCacheServiceProvider;
use Yegoragapov\LlmCache\Stores\ArrayStore;

/*
|--------------------------------------------------------------------------
| Driver extension points on the service provider
|--------------------------------------------------------------------------
| A custom vector store can be wired either by registering a factory via
| extendStore(), or by pointing the `store` config at a resolvable class-string.
*/

/** Minimal store with a no-arg constructor so the container can autowire it. */
class StubRegistryStore implements VectorStore
{
    public function search(array $vector, string $scope, float $threshold): ?CacheHit
    {
        return null;
    }

    public function put(CacheEntry $entry): void
    {
    }

    public function forget(string $scope): int
    {
        return 0;
    }

    public function flush(): int
    {
        return 0;
    }

    public function purgeExpired(): int
    {
        return 0;
    }
}

afterEach(function () {
    LlmCacheServiceProvider::flushExtensions();
});

it('resolves a custom store registered via extendStore()', function () {
    LlmCacheServiceProvider::extendStore('memory-test', fn ($app) => new ArrayStore(16, 'test'));

    config()->set('llm-cache.store', 'memory-test');
    app()->forgetInstance(VectorStore::class);

    expect(app(VectorStore::class))->toBeInstanceOf(ArrayStore::class);
});

it('resolves a custom store given as a resolvable class-string', function () {
    config()->set('llm-cache.store', StubRegistryStore::class);
    app()->forgetInstance(VectorStore::class);

    expect(app(VectorStore::class))->toBeInstanceOf(StubRegistryStore::class);
});

it('throws for an unknown store that is neither built-in, registered, nor a class', function () {
    config()->set('llm-cache.store', 'no-such-store');
    app()->forgetInstance(VectorStore::class);

    expect(fn () => app(VectorStore::class))->toThrow(InvalidArgumentException::class);
});
