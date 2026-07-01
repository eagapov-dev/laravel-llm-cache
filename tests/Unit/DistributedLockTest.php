<?php

use Illuminate\Support\Facades\Event;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;
use Yegoragapov\LlmCache\Events\CacheHitEvent;
use Yegoragapov\LlmCache\Events\CacheMissEvent;
use Yegoragapov\LlmCache\SemanticCacheManager;
use Yegoragapov\LlmCache\Tests\Support\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| §9 Concurrent-miss deduplication (optional lock)
|--------------------------------------------------------------------------
*/

/**
 * @param array<string, mixed> $lock
 */
function lockManager(VectorStore $store, array $lock): SemanticCacheManager
{
    $provider = (new FakeEmbeddingProvider(16))->set('q', [1.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);

    $config = [
        'threshold' => 0.95,
        'ttl' => null,
        'fail_mode' => 'open',
        'lock' => array_merge(['enabled' => true, 'store' => 'array', 'ttl' => 10, 'wait' => 10], $lock),
    ];

    return new SemanticCacheManager($provider, $store, app('events'), $config, app('cache'));
}

it('behaves like a normal miss with the lock enabled and no contention', function () {
    Event::fake([CacheHitEvent::class, CacheMissEvent::class]);

    $store = new \Yegoragapov\LlmCache\Stores\ArrayStore(16, 'fake');
    $manager = lockManager($store, []);

    $calls = 0;
    $result = $manager->remember('q', function () use (&$calls) {
        $calls++;

        return 'generated';
    });

    expect($result)->toBe('generated')->and($calls)->toBe(1);
    expect($store->entries())->toHaveCount(1);
    Event::assertDispatched(CacheMissEvent::class);
});

it('re-reads under the lock and reuses a leader-populated entry without generating', function () {
    Event::fake([CacheHitEvent::class, CacheMissEvent::class]);

    // Store that misses on the first search (the initial miss check) and hits on
    // the second (the post-lock re-check) — modelling a concurrent leader that
    // populated the cache while this request waited for the lock.
    $store = new class implements VectorStore
    {
        private int $searches = 0;

        public function search(array $vector, string $scope, float $threshold): ?CacheHit
        {
            $this->searches++;

            return $this->searches >= 2
                ? new CacheHit('from-leader', 0.99, [], 7)
                : null;
        }

        public function put(CacheEntry $entry): void {}

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
    };

    $manager = lockManager($store, []);

    $calls = 0;
    $result = $manager->remember('q', function () use (&$calls) {
        $calls++;

        return 'should not run';
    });

    expect($result)->toBe('from-leader')->and($calls)->toBe(0);
    Event::assertDispatched(CacheHitEvent::class);
    Event::assertNotDispatched(CacheMissEvent::class);
});

it('fails open and generates when the configured lock store is unavailable', function () {
    Event::fake([CacheHitEvent::class, CacheMissEvent::class]);

    $store = new \Yegoragapov\LlmCache\Stores\ArrayStore(16, 'fake');
    $manager = lockManager($store, ['store' => 'no-such-cache-store']);

    $calls = 0;
    $result = $manager->remember('q', function () use (&$calls) {
        $calls++;

        return 'generated anyway';
    });

    expect($result)->toBe('generated anyway')->and($calls)->toBe(1);
    Event::assertDispatched(CacheMissEvent::class);
});
