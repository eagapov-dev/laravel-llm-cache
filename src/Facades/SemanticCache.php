<?php

namespace Yegoragapov\LlmCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string remember(string $prompt, \Closure $callback, ?float $threshold = null, ?\Carbon\CarbonInterval $ttl = null, string $scope = 'global', array $meta = [])
 * @method static int forget(string $scope)
 * @method static int flush()
 *
 * @see \Yegoragapov\LlmCache\SemanticCacheManager
 */
class SemanticCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'llm-cache';
    }
}
