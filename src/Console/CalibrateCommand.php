<?php

namespace Yegoragapov\LlmCache\Console;

use Illuminate\Console\Command;
use Throwable;
use Yegoragapov\LlmCache\Calibration\Calibrator;
use Yegoragapov\LlmCache\Calibration\CalibrationResult;
use Yegoragapov\LlmCache\Calibration\DatasetLoader;
use Yegoragapov\LlmCache\Calibration\ThresholdRow;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Offline threshold calibration (spec docs/specs/llm-cache-calibrate-spec.md).
 *
 * Answers what `stats` cannot: is the similarity threshold right on MY data, and
 * what does a wrong choice cost? Embeds a labeled dataset of prompt pairs, sweeps
 * the threshold, and reports false-hit / false-miss trade-offs. No generation, no
 * cache writes — read-only except stdout.
 */
class CalibrateCommand extends Command
{
    protected $signature = 'llm-cache:calibrate
        {dataset : Path to the JSONL dataset of labeled prompt pairs}
        {--min=0.80 : Sweep start (inclusive)}
        {--max=0.99 : Sweep end (inclusive)}
        {--step=0.01 : Sweep increment}
        {--provider= : Override the configured embedding provider by name}
        {--objective=cost_weighted : cost_weighted | max_precision | min_false_hits | max_f1}
        {--false-hit-penalty=5 : Weight of a false hit vs a false miss (cost_weighted)}
        {--strict=true : Abort on a malformed line; false = skip it with a warning}
        {--json : Emit JSON instead of a table}';

    protected $description = 'Calibrate the similarity threshold against a labeled dataset (offline, no LLM calls).';

    public function handle(): int
    {
        try {
            $provider = $this->resolveProvider();

            $dataset = $this->argument('dataset');

            $loaded = (new DatasetLoader())->load(
                is_string($dataset) ? $dataset : '',
                $this->boolOption('strict', true),
            );

            $result = (new Calibrator($provider))->run(
                $loaded['pairs'],
                $this->floatOption('min'),
                $this->floatOption('max'),
                $this->floatOption('step'),
                $this->stringOption('objective', 'cost_weighted'),
                $this->floatOption('false-hit-penalty'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($this->toJson($result, $provider->name(), $loaded['skipped']));

            return self::SUCCESS;
        }

        $this->renderTable($result, $provider, $loaded['skipped'], $loaded['warnings']);

        return self::SUCCESS;
    }

    private function resolveProvider(): EmbeddingProvider
    {
        $name = $this->option('provider');

        if (is_string($name) && $name !== '') {
            // Reuse the container factory (handles built-ins + custom class-strings)
            // by pointing config at the override and rebuilding the singleton.
            config(['llm-cache.provider' => $name]);

            $container = $this->laravel;

            if ($container instanceof \Illuminate\Container\Container) {
                $container->forgetInstance(EmbeddingProvider::class);
            }
        }

        return $this->laravel->make(EmbeddingProvider::class);
    }

    private function boolOption(string $key, bool $default): bool
    {
        $value = $this->option($key);

        if (! is_string($value) && ! is_bool($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function floatOption(string $key): float
    {
        $value = $this->option($key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function stringOption(string $key, string $default): string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function toJson(CalibrationResult $result, string $provider, int $skipped): string
    {
        $data = [
            'dataset_size' => $result->datasetSize,
            'positives' => $result->positives,
            'negatives' => $result->negatives,
            'provider' => $provider,
            'skipped' => $skipped,
            'recommended' => $result->recommended,
            'objective' => $result->recommendedObjective,
            'false_hit_penalty' => $result->falseHitPenalty,
            'thresholds' => array_map(static fn (ThresholdRow $r): array => [
                'threshold' => $r->threshold,
                'false_hits' => $r->falseHits,
                'false_misses' => $r->falseMisses,
                'precision' => $r->precision === null ? null : round($r->precision, 4),
                'recall' => $r->recall === null ? null : round($r->recall, 4),
                'f1' => $r->f1 === null ? null : round($r->f1, 4),
                'cost' => round($r->cost, 4),
            ], $result->thresholds),
            'false_hit_pairs' => $result->falseHitPairs,
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<string> $warnings
     */
    private function renderTable(
        CalibrationResult $result,
        EmbeddingProvider $provider,
        int $skipped,
        array $warnings,
    ): void {
        $this->line(sprintf(
            'Dataset: %d pairs (%d reuse, %d no-reuse)',
            $result->datasetSize,
            $result->positives,
            $result->negatives,
        ));
        $this->line(sprintf('Provider: %s (dim %d)', $provider->name(), $provider->dimensions()));

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} malformed line(s) (--strict=false).");

            foreach ($warnings as $w) {
                $this->line("  {$w}");
            }
        }

        $this->newLine();

        $rows = array_map(static function (ThresholdRow $r): array {
            $fmt = static fn (?float $v): string => $v === null ? '—' : number_format($v, 3);

            return [
                number_format($r->threshold, 3),
                (string) $r->falseHits,
                (string) $r->falseMisses,
                $fmt($r->precision),
                $fmt($r->recall),
                $fmt($r->f1),
                number_format($r->cost, 2),
            ];
        }, $result->thresholds);

        $this->table(
            ['threshold', 'false_hits', 'false_misses', 'precision', 'recall', 'f1', 'cost'],
            $rows,
        );

        $recRow = $this->rowAtThreshold($result, $result->recommended);
        $this->newLine();
        $this->line(sprintf(
            '<info>Recommended</info> (%s, penalty=%s): %s',
            $result->recommendedObjective,
            rtrim(rtrim(number_format($result->falseHitPenalty, 2), '0'), '.'),
            number_format($result->recommended, 3),
        ));

        if ($recRow !== null) {
            $this->line("  false_hits: {$recRow->falseHits}   false_misses: {$recRow->falseMisses}   cost: ".number_format($recRow->cost, 2));
        }

        // Honest caveats (spec §7): pairwise is an optimistic bound, and the
        // dataset only measures the semantics you thought to include.
        $this->newLine();
        $this->warn('Pairwise numbers are an optimistic bound — production 1-NN over a full cache will not do better.');
        $this->warn('Calibrate with the SAME embedding provider/model you run in production, or the thresholds do not transfer.');
        $this->line('False-hit pairs per threshold are in --json (false_hit_pairs) — review which semantics collapse.');
    }

    private function rowAtThreshold(CalibrationResult $result, float $threshold): ?ThresholdRow
    {
        foreach ($result->thresholds as $row) {
            if (abs($row->threshold - $threshold) < 1e-9) {
                return $row;
            }
        }

        return null;
    }
}
