<?php

use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\LlmCacheServiceProvider;
use Yegoragapov\LlmCache\Tests\Support\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| §5.2 Implementation criteria (pgvector-specific)
|--------------------------------------------------------------------------
*/

it('throws a clear DimensionMismatchException at boot, not at query time', function () {
    // Provider inherently produces 16-d vectors; configuration claims 32.
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(16));
    config()->set('llm-cache.dimension', 32);

    $provider = new LlmCacheServiceProvider(app());

    expect(fn () => $provider->boot())->toThrow(DimensionMismatchException::class);
});

it('applies the cosine-distance threshold in SQL via the ANN index, not in PHP', function () {
    if (env('LLM_CACHE_TEST_PGVECTOR') !== '1') {
        test()->markTestSkipped('Requires a Postgres + vector test backend (LLM_CACHE_TEST_PGVECTOR=1).');
    }

    // With the pgvector backend enabled, this seeds entries, runs a search, and
    // asserts (via the query log) that the SELECT uses the `<=>` cosine operator
    // with a distance predicate — i.e. filtering happens in SQL, not PHP.
    // Implemented on feat/store-pgvector.
    expect(true)->toBeTrue();
})->todo();
