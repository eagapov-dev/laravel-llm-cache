<?php

use Carbon\CarbonImmutable;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\Stores\ArrayStore;

/**
 * Build a 16-dimensional unit-ish vector: value `$v` on axis `$axis`, zeros
 * elsewhere. Two vectors on the same axis are cosine-identical (similarity 1);
 * orthogonal axes give similarity 0.
 *
 * @return array<int, float>
 */
function vec(int $axis, float $v = 1.0): array
{
    $out = array_fill(0, 16, 0.0);
    $out[$axis] = $v;

    return $out;
}

function newStore(): ArrayStore
{
    return new ArrayStore(16, 'fake');
}

it('stores an entry and returns a hit above threshold with correct fields', function () {
    $store = newStore();

    $store->put(new CacheEntry(
        vector: vec(0),
        response: 'opening hours are 9-5',
        scope: 'global',
        meta: ['k' => 'v'],
    ));

    $hit = $store->search(vec(0), 'global', 0.95);

    expect($hit)->toBeInstanceOf(CacheHit::class);
    expect($hit->response)->toBe('opening hours are 9-5');
    expect($hit->similarity)->toBeGreaterThanOrEqual(0.95);
    expect($hit->similarity)->toEqualWithDelta(1.0, 1e-9);
    expect($hit->meta)->toBe(['k' => 'v']);
    expect($hit->entryId)->toBe(1);
});

it('matches a near-but-not-identical vector above threshold', function () {
    $store = newStore();

    // Two vectors mostly aligned on axis 0 with a small axis-1 component:
    // cosine similarity ~0.9994, comfortably above 0.95.
    $a = vec(0);
    $a[1] = 0.03;

    $store->put(new CacheEntry(vector: $a, response: 'hi', scope: 'global'));

    $hit = $store->search(vec(0), 'global', 0.95);

    expect($hit)->not->toBeNull();
    expect($hit->similarity)->toBeGreaterThanOrEqual(0.95);
});

it('misses when similarity is below threshold', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'x', scope: 'global'));

    // Orthogonal vector → similarity 0 → below any positive threshold.
    expect($store->search(vec(5), 'global', 0.95))->toBeNull();
});

it('isolates entries by scope', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'u1', scope: 'user:1'));

    expect($store->search(vec(0), 'user:1', 0.95))->not->toBeNull();
    expect($store->search(vec(0), 'user:2', 0.95))->toBeNull();
});

it('excludes an entry whose expiresAt is in the past', function () {
    $store = newStore();

    $store->put(new CacheEntry(
        vector: vec(0),
        response: 'stale',
        scope: 'global',
        expiresAt: CarbonImmutable::now()->subMinute(),
    ));

    expect($store->search(vec(0), 'global', 0.95))->toBeNull();
});

it('keeps an entry whose expiresAt is in the future', function () {
    $store = newStore();

    $store->put(new CacheEntry(
        vector: vec(0),
        response: 'fresh',
        scope: 'global',
        expiresAt: CarbonImmutable::now()->addMinute(),
    ));

    expect($store->search(vec(0), 'global', 0.95))->not->toBeNull();
});

it('increments the hit counter on each successful search', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'x', scope: 'global'));

    expect($store->entries()[0]->hits)->toBe(0);

    $store->search(vec(0), 'global', 0.95);
    expect($store->entries()[0]->hits)->toBe(1);

    $store->search(vec(0), 'global', 0.95);
    expect($store->entries()[0]->hits)->toBe(2);
});

it('does not increment hits on a miss', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'x', scope: 'global'));

    $store->search(vec(5), 'global', 0.95);

    expect($store->entries()[0]->hits)->toBe(0);
});

it('picks the best match among several candidates', function () {
    $store = newStore();

    $near = vec(0);
    $near[1] = 0.1; // slightly off axis 0

    $store->put(new CacheEntry(vector: vec(3), response: 'far', scope: 'global'));
    $store->put(new CacheEntry(vector: vec(0), response: 'exact', scope: 'global'));
    $store->put(new CacheEntry(vector: $near, response: 'near', scope: 'global'));

    $hit = $store->search(vec(0), 'global', 0.95);

    expect($hit->response)->toBe('exact');
});

it('forget removes only the targeted scope and returns the count', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'a', scope: 'user:1'));
    $store->put(new CacheEntry(vector: vec(1), response: 'b', scope: 'user:1'));
    $store->put(new CacheEntry(vector: vec(2), response: 'c', scope: 'user:2'));

    $removed = $store->forget('user:1');

    expect($removed)->toBe(2);
    expect($store->entries())->toHaveCount(1);
    expect($store->entries()[0]->scope)->toBe('user:2');
});

it('forget also removes context-derived child scopes of the target', function () {
    $store = newStore();

    // Base scope plus two context-derived children (see contextScope()).
    $store->put(new CacheEntry(vector: vec(0), response: 'base', scope: 'user:1'));
    $store->put(new CacheEntry(vector: vec(1), response: 'ctx-a', scope: 'user:1#ctx:aaa'));
    $store->put(new CacheEntry(vector: vec(2), response: 'ctx-b', scope: 'user:1#ctx:bbb'));
    // A different user whose scope shares the "user:1" text as a prefix but is
    // NOT a context child of user:1 — must survive.
    $store->put(new CacheEntry(vector: vec(3), response: 'other', scope: 'user:12'));

    $removed = $store->forget('user:1');

    expect($removed)->toBe(3);
    expect($store->entries())->toHaveCount(1);
    expect($store->entries()[0]->scope)->toBe('user:12');
});

it('flush empties the store and returns the count', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'a', scope: 'global'));
    $store->put(new CacheEntry(vector: vec(1), response: 'b', scope: 'global'));

    expect($store->flush())->toBe(2);
    expect($store->entries())->toBe([]);
    expect($store->flush())->toBe(0);
});

it('throws on a dimension mismatch', function () {
    $store = newStore();

    $store->search(array_fill(0, 8, 0.1), 'global', 0.95);
})->throws(DimensionMismatchException::class);

it('purgeExpired physically removes only past-expiry entries and returns the count', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'stale', scope: 'global', expiresAt: CarbonImmutable::now()->subMinute()));
    $store->put(new CacheEntry(vector: vec(1), response: 'fresh', scope: 'global', expiresAt: CarbonImmutable::now()->addMinute()));
    $store->put(new CacheEntry(vector: vec(2), response: 'no-expiry', scope: 'global'));

    $removed = $store->purgeExpired();

    expect($removed)->toBe(1);
    expect($store->entries())->toHaveCount(2);
    expect(array_map(fn ($e) => $e->response, $store->entries()))->toBe(['fresh', 'no-expiry']);
});

it('purgeExpired returns zero when nothing has expired', function () {
    $store = newStore();

    $store->put(new CacheEntry(vector: vec(0), response: 'fresh', scope: 'global', expiresAt: CarbonImmutable::now()->addMinute()));

    expect($store->purgeExpired())->toBe(0);
    expect($store->entries())->toHaveCount(1);
});
