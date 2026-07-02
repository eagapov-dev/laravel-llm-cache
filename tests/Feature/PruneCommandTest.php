<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\Stores\ArrayStore;

/*
|--------------------------------------------------------------------------
| llm-cache:prune — reclaims expired entries from the active store
|--------------------------------------------------------------------------
| Runs against the default in-memory array store. VectorStore is a container
| singleton, so the instance we seed is the one the command resolves.
*/

/**
 * @return array<int, float>
 */
function dim16(int $axis): array
{
    $v = array_fill(0, 16, 0.0);
    $v[$axis] = 1.0;

    return $v;
}

it('purges expired entries and reports a pluralised count', function () {
    /** @var ArrayStore $store */
    $store = app(VectorStore::class);

    $store->put(new CacheEntry(vector: dim16(0), response: 'a', scope: 'global', expiresAt: CarbonImmutable::now()->subMinute()));
    $store->put(new CacheEntry(vector: dim16(1), response: 'b', scope: 'global', expiresAt: CarbonImmutable::now()->subMinute()));
    $store->put(new CacheEntry(vector: dim16(2), response: 'c', scope: 'global', expiresAt: CarbonImmutable::now()->addMinute()));

    $code = Artisan::call('llm-cache:prune');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('purged 2 expired entries')
        ->and($store->entries())->toHaveCount(1);
});

it('uses the singular "entry" for a single expired row', function () {
    /** @var ArrayStore $store */
    $store = app(VectorStore::class);

    $store->put(new CacheEntry(vector: dim16(0), response: 'a', scope: 'global', expiresAt: CarbonImmutable::now()->subMinute()));

    Artisan::call('llm-cache:prune');

    expect(Artisan::output())->toContain('purged 1 expired entry.');
});

it('reports zero purged on an empty store', function () {
    $code = Artisan::call('llm-cache:prune');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('purged 0 expired entries');
});
