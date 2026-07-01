<?php

namespace Yegoragapov\LlmCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Yegoragapov\LlmCache\LlmCacheServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LlmCacheServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Deterministic, network-free defaults for the suite: the null
        // embedding provider (hashed pseudo-vector) and the in-memory store.
        $config->set('llm-cache.provider', 'null');
        $config->set('llm-cache.store', 'array');
        $config->set('llm-cache.dimension', 16);
        $config->set('llm-cache.threshold', 0.95);
        $config->set('llm-cache.ttl', '1 day');

        // Postgres + pgvector backend for the driver-parameterized tests. Only
        // exercised when LLM_CACHE_TEST_PGVECTOR=1; harmless otherwise.
        $config->set('database.connections.pgvector', [
            'driver' => 'pgsql',
            'host' => env('LLM_CACHE_PG_HOST', '127.0.0.1'),
            'port' => env('LLM_CACHE_PG_PORT', '55432'),
            'database' => env('LLM_CACHE_PG_DB', 'llmcache_test'),
            'username' => env('LLM_CACHE_PG_USER', 'llmcache'),
            'password' => env('LLM_CACHE_PG_PASS', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
        $config->set('llm-cache.stores.pgvector.connection', 'pgvector');
    }
}
