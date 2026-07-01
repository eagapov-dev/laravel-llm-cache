<?php

use Illuminate\Support\Facades\Log;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\LlmCacheServiceProvider;
use Yegoragapov\LlmCache\Stores\PgvectorStore;
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

    // The test runner is not a remediation command, so the guard fails loud —
    // the same as a web request, queue worker, or octane process would.
    expect(fn () => $provider->boot())->toThrow(DimensionMismatchException::class);
});

it('downgrades the dimension guard to a warning for remediation commands', function () {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(16));
    config()->set('llm-cache.dimension', 32);

    // Simulate `php artisan migrate` so the operator can still fix the config
    // that's wrong rather than being bricked by the guard.
    $argv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', 'migrate'];

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'produces 16-dimensional vectors'));

    try {
        $provider = new LlmCacheServiceProvider(app());
        expect(fn () => $provider->boot())->not->toThrow(DimensionMismatchException::class);
    } finally {
        if ($argv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $argv;
        }
    }
});

it('applies the cosine-distance threshold in SQL via the ANN index, not in PHP', function () {
    if (env('LLM_CACHE_TEST_PGVECTOR') !== '1') {
        test()->markTestSkipped('Requires a Postgres + vector test backend (LLM_CACHE_TEST_PGVECTOR=1).');
    }

    $dimension = 3;
    config()->set('llm-cache.dimension', $dimension);
    config()->set('llm-cache.store', 'pgvector');

    // Bring up the pgvector schema on the configured Postgres connection.
    $connectionName = config('llm-cache.stores.pgvector.connection');
    $migration = require __DIR__.'/../../database/migrations/2024_01_01_000000_create_llm_cache_entries_table.php';
    $migration->down();
    $migration->up();

    $store = new PgvectorStore(
        app('db'),
        (array) config('llm-cache.stores.pgvector'),
        $dimension,
        'fake',
    );

    // Seed a near vector (cosine sim 1.0 to the query) and a far, orthogonal one.
    $store->put(new CacheEntry(vector: [1.0, 0.0, 0.0], response: 'near', scope: 'global'));
    $store->put(new CacheEntry(vector: [0.0, 1.0, 0.0], response: 'far', scope: 'global'));

    $connection = app('db')->connection(is_string($connectionName) ? $connectionName : null);
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $hit = $store->search([1.0, 0.0, 0.0], 'global', 0.95);

    $queries = collect($connection->getQueryLog())->pluck('query')->implode("\n");

    // Threshold filtering happens in SQL via the cosine operator — not in PHP.
    expect($queries)->toContain('<=>');
    expect($hit)->not->toBeNull();
    expect($hit->response)->toBe('near');
    expect($hit->similarity)->toBeGreaterThanOrEqual(0.95);

    $migration->down();
});
