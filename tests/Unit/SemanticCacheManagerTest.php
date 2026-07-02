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
| Unit tests for SemanticCacheManager in isolation.
|--------------------------------------------------------------------------
| The real ArrayStore is a stub in this worktree, so these tests drive the
| manager against a fully-controlled inline fake VectorStore instead.
*/

/**
 * Inline fake store: records interactions and returns a caller-primed hit.
 */
class FakeVectorStore implements VectorStore
{
    /** @var list<CacheEntry> */
    public array $puts = [];

    public int $putCalls = 0;

    public bool $throwOnSearch = false;

    public bool $throwOnPut = false;

    public function __construct(
        private ?CacheHit $hit = null,
    ) {
    }

    public function search(array $vector, string $scope, float $threshold): ?CacheHit
    {
        if ($this->throwOnSearch) {
            throw new RuntimeException('search boom');
        }

        return $this->hit;
    }

    public function put(CacheEntry $entry): void
    {
        $this->putCalls++;

        if ($this->throwOnPut) {
            throw new RuntimeException('put boom');
        }

        $this->puts[] = $entry;
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

/**
 * @param array<string, mixed> $config
 */
function makeManager(FakeEmbeddingProvider $provider, VectorStore $store, array $config = []): SemanticCacheManager
{
    $config = array_merge([
        'threshold' => 0.95,
        'ttl' => '1 day',
        'fail_mode' => 'open',
    ], $config);

    return new SemanticCacheManager($provider, $store, app('events'), $config);
}

beforeEach(function () {
    Event::fake([CacheHitEvent::class, CacheMissEvent::class]);
});

it('runs the callback, stores the entry once, and dispatches a miss on a cold miss', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store);

    $calls = 0;
    $result = $manager->remember('hi', function () use (&$calls) {
        $calls++;

        return 'fresh';
    }, meta: ['model' => 'gpt-x']);

    expect($result)->toBe('fresh')
        ->and($calls)->toBe(1)
        ->and($store->putCalls)->toBe(1)
        ->and($store->puts[0]->response)->toBe('fresh')
        ->and($store->puts[0]->vector)->toBe([1.0, 0, 0, 0])
        ->and($store->puts[0]->model)->toBe('gpt-x')
        ->and($store->puts[0]->promptPreview)->toBe('hi')
        ->and($store->puts[0]->expiresAt)->not->toBeNull();

    Event::assertDispatched(CacheMissEvent::class);
    Event::assertNotDispatched(CacheHitEvent::class);
});

it('returns the cached response and dispatches a hit without running the callback', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: new CacheHit('cached', 0.97, [], 42));
    $manager = makeManager($provider, $store);

    $calls = 0;
    $result = $manager->remember('hi', function () use (&$calls) {
        $calls++;

        return 'should not run';
    });

    expect($result)->toBe('cached')
        ->and($calls)->toBe(0)
        ->and($store->putCalls)->toBe(0);

    Event::assertDispatched(CacheHitEvent::class, fn (CacheHitEvent $e) => $e->similarity === 0.97 && $e->entryId === 42);
    Event::assertNotDispatched(CacheMissEvent::class);
});

it('skips the cache entirely for an empty prompt', function () {
    $provider = new FakeEmbeddingProvider(4);
    $store = new FakeVectorStore(hit: new CacheHit('cached', 0.99));
    $manager = makeManager($provider, $store);

    $calls = 0;
    $result = $manager->remember('   ', function () use (&$calls) {
        $calls++;

        return 'raw';
    });

    expect($result)->toBe('raw')
        ->and($calls)->toBe(1)
        ->and($store->putCalls)->toBe(0);

    Event::assertNotDispatched(CacheHitEvent::class);
    Event::assertNotDispatched(CacheMissEvent::class);
});

it('honours a per-call threshold override at search time', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new class(null) extends FakeVectorStore {
        public ?float $seenThreshold = null;

        public function search(array $vector, string $scope, float $threshold): ?CacheHit
        {
            $this->seenThreshold = $threshold;

            return null;
        }
    };
    $manager = makeManager($provider, $store);

    $manager->remember('hi', fn () => 'x', threshold: 0.99);

    expect($store->seenThreshold)->toBe(0.99);
});

it('fails open when the embedding provider throws', function () {
    // Unprimed prompt -> FakeEmbeddingProvider::embed() throws.
    $provider = new FakeEmbeddingProvider(4);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store, ['fail_mode' => 'open']);

    $result = $manager->remember('unprimed', fn () => 'served anyway');

    expect($result)->toBe('served anyway')
        ->and($store->putCalls)->toBe(0);
});

it('fails open when the store search throws', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $store->throwOnSearch = true;
    $manager = makeManager($provider, $store, ['fail_mode' => 'open']);

    $result = $manager->remember('hi', fn () => 'served anyway');

    expect($result)->toBe('served anyway')
        ->and($store->putCalls)->toBe(0);
});

it('fails closed by rethrowing when configured', function () {
    $provider = new FakeEmbeddingProvider(4);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store, ['fail_mode' => 'closed']);

    expect(fn () => $manager->remember('unprimed', fn () => 'x'))
        ->toThrow(RuntimeException::class);
});

it('swallows a put failure and still returns the fresh response', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $store->throwOnPut = true;
    $manager = makeManager($provider, $store, ['fail_mode' => 'open']);

    $result = $manager->remember('hi', fn () => 'fresh');

    expect($result)->toBe('fresh')
        ->and($store->putCalls)->toBe(1);

    // A put failure is not a read failure: the miss event still fires.
    Event::assertDispatched(CacheMissEvent::class);
});

it('resolves no expiry when config ttl is null', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store, ['ttl' => null]);

    $manager->remember('hi', fn () => 'fresh');

    expect($store->puts[0]->expiresAt)->toBeNull();
});

it('stores without expiry for a zero or malformed ttl instead of expiring instantly', function (string $ttl) {
    // CarbonInterval::fromString("0"/"abc") yields a zero-length interval rather
    // than throwing; a zero ttl would set expires_at ≈ now(), so the entry would
    // be expired by the next search — writing at full cost but never hitting.
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store, ['ttl' => $ttl]);

    $manager->remember('hi', fn () => 'fresh');

    expect($store->puts[0]->expiresAt)->toBeNull();
})->with(['0', 'abc', '']);

it('resolves a valid ttl string to a future expiry', function () {
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store, ['ttl' => '1 hour']);

    $manager->remember('hi', fn () => 'fresh');

    expect($store->puts[0]->expiresAt)->not->toBeNull()
        ->and($store->puts[0]->expiresAt->isFuture())->toBeTrue();
});

it('dispatches a miss event when the read path fails open', function () {
    // A provider/store outage must still emit a miss so the hit rate stays
    // truthful during the outage instead of flatlining.
    $provider = (new FakeEmbeddingProvider(4))->set('hi', [1.0, 0, 0, 0]);
    $store = new FakeVectorStore(hit: null);
    $store->throwOnSearch = true;
    $manager = makeManager($provider, $store, ['fail_mode' => 'open']);

    $manager->remember('hi', fn () => 'served anyway');

    Event::assertDispatched(CacheMissEvent::class);
});

it('never throws from contextScope() on a non-serializable context', function () {
    // contextScope() runs before the fail-open try in remember(), so it must not
    // throw even when json_encode fails and serialize() would choke on a closure.
    $provider = new FakeEmbeddingProvider(4);
    $store = new FakeVectorStore(hit: null);
    $manager = makeManager($provider, $store);

    $context = ['cb' => fn () => 'x'];

    $scope = $manager->contextScope('user:1', $context);

    expect($scope)->toStartWith('user:1#ctx:')
        // Deterministic: the same non-serializable context yields the same digest.
        ->and($manager->contextScope('user:1', $context))->toBe($scope);
});

it('delegates forget and flush to the store', function () {
    $provider = new FakeEmbeddingProvider(4);
    $store = new class(null) extends FakeVectorStore {
        public function forget(string $scope): int
        {
            return 3;
        }

        public function flush(): int
        {
            return 7;
        }
    };
    $manager = makeManager($provider, $store);

    expect($manager->forget('user:1'))->toBe(3)
        ->and($manager->flush())->toBe(7);
});
