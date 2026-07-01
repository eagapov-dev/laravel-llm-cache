<?php

namespace Yegoragapov\LlmCache\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Summarise cache hit rate and estimated tokens saved from the event history
 * written by RecordCacheEvent (§8.2).
 */
class StatsCommand extends Command
{
    protected $signature = 'llm-cache:stats
        {--days= : Only count events within the last N days}
        {--json : Output machine-readable JSON}';

    protected $description = 'Summarise llm-cache hit rate and estimated tokens saved from the event history.';

    public function handle(ConfigRepository $config): int
    {
        $table = $this->stringConfig($config, 'llm-cache.stats.table', 'llm_cache_events');
        $connectionName = $config->get('llm-cache.stats.connection');
        $connectionName = is_string($connectionName) ? $connectionName : null;
        $recording = (bool) $config->get('llm-cache.stats.record');

        if (! $recording || ! Schema::connection($connectionName)->hasTable($table)) {
            return $this->guidance($recording, $table);
        }

        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : null;

        $query = DB::connection($connectionName)->table($table);

        if ($days !== null) {
            $query->where('created_at', '>=', Carbon::now()->subDays($days)->toDateTimeString());
        }

        $hits = (clone $query)->where('type', 'hit')->count();
        $misses = (clone $query)->where('type', 'miss')->count();
        $total = $hits + $misses;
        $hitRate = $total > 0 ? $hits / $total : 0.0;
        $avg = (int) $config->get('llm-cache.stats.avg_tokens_per_generation', 500);

        $data = [
            'days' => $days,
            'total' => $total,
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => round($hitRate, 4),
            'estimated_tokens_saved' => $hits * $avg,
            'avg_tokens_per_generation' => $avg,
            'recording' => true,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderTable($data);

        return self::SUCCESS;
    }

    /**
     * @param array{days: int|null, total: int, hits: int, misses: int, hit_rate: float, estimated_tokens_saved: int, avg_tokens_per_generation: int, recording: bool} $data
     */
    protected function renderTable(array $data): void
    {
        if ($data['days'] !== null) {
            $this->line("<info>Window:</info> last {$data['days']} day(s)");
        }

        $this->table(['Metric', 'Value'], [
            ['Total calls', (string) $data['total']],
            ['Hits', (string) $data['hits']],
            ['Misses', (string) $data['misses']],
            ['Hit rate', number_format($data['hit_rate'] * 100, 1).'%'],
            ['Est. tokens saved', number_format($data['estimated_tokens_saved'])." (≈{$data['avg_tokens_per_generation']}/gen)"],
        ]);
    }

    protected function guidance(bool $recording, string $table): int
    {
        $message = $recording
            ? "The events table [{$table}] does not exist yet."
            : 'Event recording is disabled.';

        $steps = [
            $message,
            'Enable it via config llm-cache.stats.record = true (env LLM_CACHE_STATS_RECORD=1),',
            'then publish and run the migration:',
            '  php artisan vendor:publish --tag=llm-cache-migrations',
            '  php artisan migrate',
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'recording' => $recording,
                'total' => 0,
                'hits' => 0,
                'misses' => 0,
                'hit_rate' => 0.0,
                'estimated_tokens_saved' => 0,
                'message' => implode(' ', $steps),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($steps as $line) {
            $this->warn($line);
        }

        return self::SUCCESS;
    }

    protected function stringConfig(ConfigRepository $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return is_string($value) ? $value : $default;
    }
}
