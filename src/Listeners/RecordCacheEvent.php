<?php

namespace Yegoragapov\LlmCache\Listeners;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Yegoragapov\LlmCache\Events\CacheHitEvent;
use Yegoragapov\LlmCache\Events\CacheMissEvent;

/**
 * Optional subscriber that persists Hit/Miss events to the `llm_cache_events`
 * table for `llm-cache:stats` (§8.1). Registered only when
 * `llm-cache.stats.record` is true.
 */
class RecordCacheEvent
{
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ConfigRepository $config,
    ) {
    }

    public function handleHit(CacheHitEvent $event): void
    {
        $this->record('hit', $event->scope, $event->similarity);
    }

    public function handleMiss(CacheMissEvent $event): void
    {
        $this->record('miss', $event->scope, null);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            CacheHitEvent::class => 'handleHit',
            CacheMissEvent::class => 'handleMiss',
        ];
    }

    protected function record(string $type, string $scope, ?float $similarity): void
    {
        try {
            $this->connection()->table($this->table())->insert([
                'type' => $type,
                'scope' => $scope,
                'similarity' => $similarity,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            // §8.1 / §7: recording must never break the request path.
            Log::warning('llm-cache: failed to record cache event', [
                'type' => $type,
                'scope' => $scope,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function connection(): ConnectionInterface
    {
        $name = $this->config->get('llm-cache.stats.connection');

        return $this->db->connection(is_string($name) ? $name : null);
    }

    protected function table(): string
    {
        $table = $this->config->get('llm-cache.stats.table');

        return is_string($table) ? $table : 'llm_cache_events';
    }
}
