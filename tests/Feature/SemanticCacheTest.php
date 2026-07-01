<?php

use Carbon\CarbonImmutable;
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
| §5.1 Behavioural acceptance criteria
|--------------------------------------------------------------------------
| These assert BEHAVIOUR, not implementation, and MUST pass identically on
| both the `array` (cosine in PHP) and `pgvector` (cosine in SQL) drivers.
| The suite is parameterized over both via the `stores` dataset.
*/

dataset('stores', ['array', 'pgvector']);

// 16-d unit vectors chosen for controlled cosine relationships:
//   cos(HOURS, OPEN)     = 0.98  (>= 0.95 threshold  -> HIT)
//   cos(HOURS, UNRELATED)= 0.00  (<  0.95 threshold  -> MISS)
const VEC_HOURS = [1.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
const VEC_OPEN = [0.98, 0.199, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
const VEC_UNRELATED = [0, 1.0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

beforeEach(function () {
    $fake = (new FakeEmbeddingProvider(16))
        ->set('what are your opening hours?', VEC_HOURS)
        ->set('when do you open?', VEC_OPEN)
        ->set('where is the nearest station?', VEC_UNRELATED);

    app()->instance(EmbeddingProvider::class, $fake);

    Event::fake([CacheHitEvent::class, CacheMissEvent::class]);
});

/**
 * Point the container at $store and rebuild the manager so it picks up both
 * the store and the faked embedding provider. Skips pgvector unless a Postgres
 * test backend is explicitly enabled.
 */
function useStore(string $store): void
{
    if ($store === 'pgvector' && env('LLM_CACHE_TEST_PGVECTOR') !== '1') {
        test()->markTestSkipped('pgvector driver requires a Postgres + vector test backend (LLM_CACHE_TEST_PGVECTOR=1).');
    }

    config()->set('llm-cache.store', $store);
    config()->set('llm-cache.dimension', 16);
    config()->set('llm-cache.threshold', 0.95);

    app()->forgetInstance(VectorStore::class);
    app()->forgetInstance(SemanticCacheManager::class);
    app()->forgetInstance('llm-cache');
}

it('runs the callback and stores a fresh response on a cold miss', function (string $store) {
    useStore($store);

    $calls = 0;
    $result = SemanticCache::remember('what are your opening hours?', function () use (&$calls) {
        $calls++;

        return 'We are open 9-5, Mon-Fri.';
    });

    expect($result)->toBe('We are open 9-5, Mon-Fri.')
        ->and($calls)->toBe(1);

    Event::assertDispatched(CacheMissEvent::class);
    Event::assertNotDispatched(CacheHitEvent::class);
})->with('stores');

it('serves a semantically-equivalent prompt from cache without calling back', function (string $store) {
    useStore($store);

    SemanticCache::remember('what are your opening hours?', fn () => 'We are open 9-5, Mon-Fri.');

    $calls = 0;
    $result = SemanticCache::remember('when do you open?', function () use (&$calls) {
        $calls++;

        return 'FRESH (should not run)';
    });

    expect($result)->toBe('We are open 9-5, Mon-Fri.')
        ->and($calls)->toBe(0);

    Event::assertDispatched(CacheHitEvent::class, fn (CacheHitEvent $e) => $e->similarity >= 0.95);
})->with('stores');

it('increments the hit counter on the matched entry', function (string $store) {
    useStore($store);

    SemanticCache::remember('what are your opening hours?', fn () => 'We are open 9-5, Mon-Fri.');
    SemanticCache::remember('when do you open?', fn () => 'unused');

    expect(firstEntryHits())->toBe(1);
})->with('stores');

it('treats an unrelated prompt below threshold as a miss', function (string $store) {
    useStore($store);

    SemanticCache::remember('what are your opening hours?', fn () => 'We are open 9-5, Mon-Fri.');

    $calls = 0;
    $result = SemanticCache::remember('where is the nearest station?', function () use (&$calls) {
        $calls++;

        return 'Two blocks north.';
    });

    expect($result)->toBe('Two blocks north.')
        ->and($calls)->toBe(1);

    Event::assertDispatched(CacheMissEvent::class);
})->with('stores');

it('honours a per-call threshold override', function (string $store) {
    useStore($store);

    SemanticCache::remember('what are your opening hours?', fn () => 'We are open 9-5, Mon-Fri.');

    // 0.98 similarity would hit at the 0.95 default, but not at a 0.99 override.
    $calls = 0;
    $result = SemanticCache::remember('when do you open?', function () use (&$calls) {
        $calls++;

        return 'Regenerated.';
    }, threshold: 0.99);

    expect($result)->toBe('Regenerated.')
        ->and($calls)->toBe(1);
})->with('stores');

it('isolates entries by scope with no cross-scope leakage', function (string $store) {
    useStore($store);

    SemanticCache::remember('what are your opening hours?', fn () => 'Store #1 hours.', scope: 'user:1');

    $calls = 0;
    $result = SemanticCache::remember('what are your opening hours?', function () use (&$calls) {
        $calls++;

        return 'Store #2 hours.';
    }, scope: 'user:2');

    expect($result)->toBe('Store #2 hours.')
        ->and($calls)->toBe(1);
})->with('stores');

it('treats an expired entry as a miss and refreshes it', function (string $store) {
    useStore($store);

    // Seed an entry that expired an hour ago.
    SemanticCache::remember(
        'what are your opening hours?',
        fn () => 'Stale hours.',
        ttl: \Carbon\CarbonInterval::hour()->invert(),
    );

    $calls = 0;
    $result = SemanticCache::remember('what are your opening hours?', function () use (&$calls) {
        $calls++;

        return 'Fresh hours.';
    });

    expect($result)->toBe('Fresh hours.')
        ->and($calls)->toBe(1);
})->with('stores');

it('forgets only the targeted scope and returns the removed count', function (string $store) {
    useStore($store);

    SemanticCache::remember('what are your opening hours?', fn () => 'A', scope: 'user:1');
    SemanticCache::remember('what are your opening hours?', fn () => 'B', scope: 'user:2');

    $removed = SemanticCache::forget('user:1');
    expect($removed)->toBe(1);

    // user:2 survives -> still a hit (callback not run).
    $calls = 0;
    SemanticCache::remember('what are your opening hours?', function () use (&$calls) {
        $calls++;

        return 'C';
    }, scope: 'user:2');

    expect($calls)->toBe(0);
})->with('stores');

/**
 * Read the `hits` counter of the first stored entry. Requires a per-driver
 * test affordance: ArrayStore::entries() and, for pgvector, the DB row.
 */
function firstEntryHits(): int
{
    if (config('llm-cache.store') === 'pgvector') {
        return (int) \Illuminate\Support\Facades\DB::table('llm_cache_entries')->orderBy('id')->value('hits');
    }

    /** @var object|array $entry */
    $entry = app(VectorStore::class)->entries()[0] ?? null;

    return (int) (is_array($entry) ? ($entry['hits'] ?? 0) : ($entry->hits ?? 0));
}
