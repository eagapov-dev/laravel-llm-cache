<?php

namespace Yegoragapov\LlmCache;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Yegoragapov\LlmCache\Console\CalibrateCommand;
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

    /**
     * Clear all registered custom factories. Intended for test isolation, since
     * the registries are static and otherwise persist across the process.
     */
    public static function flushExtensions(): void
    {
        static::$providerCreators = [];
        static::$storeCreators = [];
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
                // Also accept a resolvable class-string implementing the contract,
                // so `'provider' => My\Provider::class` works with no registration.
                default => $this->resolveCustom($app, $name, EmbeddingProvider::class, 'embedding provider'),
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
                default => $this->resolveCustom($app, $name, VectorStore::class, 'vector store'),
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

    /**
     * Resolve a driver given as a class-string implementing $contract, so a
     * consumer can point config straight at their own class without registering
     * a factory. Throws if the name is neither a built-in nor such a class.
     *
     * @template T of object
     * @param  class-string<T> $contract
     * @return T
     */
    protected function resolveCustom(Application $app, string $name, string $contract, string $label): object
    {
        if (class_exists($name) && is_a($name, $contract, true)) {
            /** @var T $instance */
            $instance = $app->make($name);

            return $instance;
        }

        throw new InvalidArgumentException("Unknown {$label} [{$name}].");
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

            $this->commands([StatsCommand::class, PruneCommand::class, CalibrateCommand::class]);
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
     * For a remediation/bootstrap command (migrate, config:*, vendor:publish, …)
     * the mismatch is logged as a warning instead of thrown, so the very commands
     * needed to fix it still run. Everything else — web requests, queue:work,
     * octane:start, schedule:run — fails loud at boot, since those are the paths
     * where a silent mismatch would regenerate on every call (a cost explosion).
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

        if ($this->isRemediationCommand()) {
            Log::warning('llm-cache: '.$exception->getMessage());

            return;
        }

        throw $exception;
    }

    /**
     * True only for the bootstrap/remediation console commands that an operator
     * needs in order to fix a bad dimension config. Deliberately excludes
     * long-lived, request-serving console processes (queue:work, octane:start,
     * schedule:run) so a real misconfig there still fails loud.
     */
    protected function isRemediationCommand(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? '';

        if (! is_string($command) || $command === '') {
            return false;
        }

        foreach (['migrate', 'config:', 'vendor:publish', 'package:discover', 'optimize', 'cache:', 'key:generate'] as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
