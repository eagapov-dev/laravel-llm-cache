<?php

use Yegoragapov\LlmCache\Stores\RedisStore;

/*
|--------------------------------------------------------------------------
| RedisStore — server-free unit coverage (vector serialization)
|--------------------------------------------------------------------------
| Behavioural coverage runs through the §5.1 dataset against a live Redis Stack
| (LLM_CACHE_TEST_REDIS=1). These assert the FLOAT32 blob encoding without a
| server.
*/

it('serializes a vector to a float32 little-endian blob', function () {
    $store = new RedisStore(app('redis'), ['index' => 'x', 'prefix' => 'y:'], 3, 'fake');

    $blob = $store->vectorBlob([1.0, 0.5, 0.0]);

    expect(strlen($blob))->toBe(12) // 3 × 4 bytes
        ->and(array_values((array) unpack('g*', $blob)))->toBe([1.0, 0.5, 0.0]);
});

it('rejects a vector whose length does not match the configured dimension', function () {
    $store = new RedisStore(app('redis'), [], 3, 'fake');

    expect(fn () => $store->vectorBlob([1.0, 2.0]))->toThrow(RuntimeException::class);
});
