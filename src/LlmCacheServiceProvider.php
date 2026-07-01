<?php

namespace Yegoragapov\LlmCache;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\Providers\HttpProvider;
use Yegoragapov\LlmCache\Providers\NullProvider;
use Yegoragapov\LlmCache\Providers\OpenAiProvider;
use Yegoragapov\LlmCache\Providers\VoyageProvider;
use Yegoragapov\LlmCache\Stores\ArrayStore;
use Yegoragapov\LlmCache\Stores\PgvectorStore;

class LlmCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/llm-cache.php', 'llm-cache');

        $this->app->singleton(EmbeddingProvider::class, function ($app): EmbeddingProvider {
            $name = (string) $app['config']->get('llm-cache.provider');
            $config = (array) $app['config']->get("llm-cache.providers.{$name}", []);
            $dimension = (int) $app['config']->get('llm-cache.dimension');

            return match ($name) {
                'openai' => new OpenAiProvider($config),
                'voyage' => new VoyageProvider($config),
                'http' => new HttpProvider($config),
                'null' => new NullProvider($dimension),
                default => throw new InvalidArgumentException("Unknown embedding provider [{$name}]."),
            };
        });

        $this->app->singleton(VectorStore::class, function ($app): VectorStore {
            $name = (string) $app['config']->get('llm-cache.store');
            $dimension = (int) $app['config']->get('llm-cache.dimension');
            $providerName = (string) $app['config']->get('llm-cache.provider');

            return match ($name) {
                'array' => new ArrayStore($dimension, $providerName),
                'pgvector' => new PgvectorStore(
                    $app['db'],
                    (array) $app['config']->get('llm-cache.stores.pgvector', []),
                    $dimension,
                    $providerName,
                ),
                default => throw new InvalidArgumentException("Unknown vector store [{$name}]."),
            };
        });

        $this->app->singleton('llm-cache', function ($app): SemanticCacheManager {
            return new SemanticCacheManager(
                $app->make(EmbeddingProvider::class),
                $app->make(VectorStore::class),
                $app['events'],
                (array) $app['config']->get('llm-cache'),
            );
        });

        $this->app->alias('llm-cache', SemanticCacheManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/llm-cache.php' => config_path('llm-cache.php'),
            ], 'llm-cache-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'llm-cache-migrations');
        }

        $this->guardDimension();
    }

    /**
     * Boot-time guard: the configured provider's dimensions() must equal the
     * configured vector dimension (and thus the migration's vector(N)).
     *
     * Throws at boot — never at query time. dimensions() is guaranteed cheap
     * (no network), so resolving the provider here is safe.
     */
    protected function guardDimension(): void
    {
        $provider = $this->app->make(EmbeddingProvider::class);
        $configured = (int) $this->app->make(ConfigRepository::class)->get('llm-cache.dimension');

        if ($provider->dimensions() !== $configured) {
            throw DimensionMismatchException::make(
                $provider->name(),
                $provider->dimensions(),
                $configured,
            );
        }
    }
}
