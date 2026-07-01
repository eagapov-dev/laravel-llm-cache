<?php

namespace Yegoragapov\LlmCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string remember(string $prompt, \Closure $callback, ?float $threshold = null, ?\Carbon\CarbonInterval $ttl = null, string $scope = 'global', array $meta = [], string|array|null $context = null)
 * @method static int forget(string $scope)
 * @method static int flush()
 * @method static int purgeExpired()
 * @method static string contextScope(string $scope, string|array|null $context = null)
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
