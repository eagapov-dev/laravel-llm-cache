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
    }
}
