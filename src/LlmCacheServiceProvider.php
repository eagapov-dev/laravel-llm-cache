<?php

namespace Yegoragapov\LlmCache;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Yegoragapov\LlmCache\Console\PruneCommand;
use Yegoragapov\LlmCache\Console\StatsCommand;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\Listeners\RecordCacheEvent;
use Yegoragapov\LlmCache\Providers\HttpProvider;
use Yegoragapov\LlmCache\Providers\NullProvider;
use Yegoragapov\LlmCache\Providers\OpenAiProvider;
use Yegoragapov\LlmCache\Providers\VoyageProvider;
use Yegoragapov\LlmCache\Stores\ArrayStore;
use Yegoragapov\LlmCache\Stores\PgvectorStore;
use Yegoragapov\LlmCache\Stores\RedisStore;

class LlmCacheServiceProvider extends ServiceProvider
{
    /**
     * Custom embedding-provider factories registered via extendProvider().
     *
     * @var array<string, callable(Application): EmbeddingProvider>
     */
    protected static array $providerCreators = [];

    /**
     * Custom vector-store factories registered via extendStore().
     *
     * @var array<string, callable(Application): VectorStore>
     */
    protected static array $storeCreators = [];

    /**
     * Register a third-party embedding provider under $name, resolvable via the
     * `llm-cache.provider` config. Call from another package's register().
     *
     * @param callable(Application): EmbeddingProvider $factory
     */
    public static function extendProvider(string $name, callable $factory): void
    {
        static::$providerCreators[$name] = $factory;
    }

    /**
     * Register a third-party vector store under $name, resolvable via the
     * `llm-cache.store` config.
     *
     * @param callable(Application): VectorStore $factory
     */
    public static function extendStore(string $name, callable $factory): void
    {
        static::$storeCreators[$name] = $factory;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/llm-cache.php', 'llm-cache');

        $this->app->singleton(EmbeddingProvider::class, function ($app): EmbeddingProvider {
            $name = (string) $app['config']->get('llm-cache.provider');
            $config = (array) $app['config']->get("llm-cache.providers.{$name}", []);
            $dimension = (int) $app['config']->get('llm-cache.dimension');

            if (isset(static::$providerCreators[$name])) {
                return (static::$providerCreators[$name])($app);
            }

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

            if (isset(static::$storeCreators[$name])) {
                return (static::$storeCreators[$name])($app);
            }

            return match ($name) {
                'array' => new ArrayStore($dimension, $providerName),
                'pgvector' => new PgvectorStore(
                    $app['db'],
                    (array) $app['config']->get('llm-cache.stores.pgvector', []),
                    $dimension,
                    $providerName,
                ),
                'redis' => new RedisStore(
                    $app['redis'],
                    (array) $app['config']->get('llm-cache.stores.redis', []),
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
                $app['cache'],
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

            $this->commands([StatsCommand::class, PruneCommand::class]);
        }

        $this->guardDimension();
        $this->registerEventRecording();
    }

    /**
     * Wire the optional event-recording subscriber when stats recording is on.
     */
    protected function registerEventRecording(): void
    {
        if (! $this->app->make(ConfigRepository::class)->get('llm-cache.stats.record')) {
            return;
        }

        $this->app->make('events')->subscribe(RecordCacheEvent::class);
    }

    /**
     * Boot-time guard: the configured provider's dimensions() must equal the
     * configured vector dimension (and thus the migration's vector(N)).
     *
     * Throws at boot — never at query time. dimensions() is guaranteed cheap
     * (no network), so resolving the provider here is safe.
     *
     * In console the mismatch is logged as a warning instead of thrown, so the
     * very commands needed to fix it (migrate, config:clear, vendor:publish)
     * still run rather than being bricked by the guard.
     */
    protected function guardDimension(): void
    {
        $provider = $this->app->make(EmbeddingProvider::class);
        $configured = (int) $this->app->make(ConfigRepository::class)->get('llm-cache.dimension');

        if ($provider->dimensions() === $configured) {
            return;
        }

        $exception = DimensionMismatchException::make(
            $provider->name(),
            $provider->dimensions(),
            $configured,
        );

        if ($this->app->runningInConsole()) {
            Log::warning('llm-cache: '.$exception->getMessage());

            return;
        }

        throw $exception;
    }
}
