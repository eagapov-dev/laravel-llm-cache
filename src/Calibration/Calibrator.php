<?php

namespace Yegoragapov\LlmCache\Calibration;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\DataObjects\PromptPair;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\Support\Vectors;

/**
 * Offline threshold calibrator (spec §4.2). Embeds each distinct prompt once,
 * computes each pair's cosine similarity once, then sweeps the threshold and
 * reports confusion metrics per step. No LLM generation, no cache mutation.
 *
 * Comparison semantics MUST match the runtime store: the same Support/Vectors
 * cosine and the same inclusive `>=` boundary (spec §7).
 */
final class Calibrator
{
    public function __construct(
        private EmbeddingProvider $provider,
    ) {
    }

    /**
     * @param iterable<PromptPair> $pairs
     */
    public function run(
        iterable $pairs,
        float $min,
        float $max,
        float $step,
        string $objective = 'cost_weighted',
        float $falseHitPenalty = 5.0,
    ): CalibrationResult {
        // Validate the sweep BEFORE touching the provider (spec §5.2).
        if ($min > $max) {
            throw new InvalidArgumentException("Sweep min ({$min}) must not exceed max ({$max}).");
        }

        if ($step <= 0.0) {
            throw new InvalidArgumentException("Sweep step must be positive, got {$step}.");
        }

        /** @var list<PromptPair> $pairs */
        $pairs = is_array($pairs) ? array_values($pairs) : iterator_to_array($pairs, false);

        if ($pairs === []) {
            throw new InvalidArgumentException('no pairs to calibrate: the dataset is empty.');
        }

        // Embed each distinct prompt exactly once (memoized), then similarity per
        // pair exactly once. Embedding is the only cost; the sweep is arithmetic.
        $vectors = [];
        $similarities = [];

        foreach ($pairs as $pair) {
            $sim = Vectors::cosineSimilarity(
                $this->vectorFor($pair->a, $vectors),
                $this->vectorFor($pair->b, $vectors),
            );
            $similarities[] = $sim;
        }

        $decimals = $this->stepDecimals($step);
        $rows = [];
        $falseHitPairs = [];

        foreach ($this->sweep($min, $max, $step) as $threshold) {
            $tp = $fh = $fm = $tn = 0;
            $offenders = [];

            foreach ($pairs as $i => $pair) {
                $hit = $similarities[$i] >= $threshold; // `>=` matches the store

                if ($pair->reuse) {
                    $hit ? $tp++ : $fm++;
                } elseif ($hit) {
                    $fh++;
                    $offenders[] = [
                        'a' => $pair->a,
                        'b' => $pair->b,
                        'similarity' => $similarities[$i],
                        'note' => $pair->note,
                    ];
                } else {
                    $tn++;
                }
            }

            $precision = ($tp + $fh) > 0 ? $tp / ($tp + $fh) : null;
            $recall = ($tp + $fm) > 0 ? $tp / ($tp + $fm) : null;
            $f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0.0)
                ? 2 * $precision * $recall / ($precision + $recall)
                : null;

            $rows[] = new ThresholdRow(
                threshold: $threshold,
                falseHits: $fh,
                falseMisses: $fm,
                truePositives: $tp,
                trueNegatives: $tn,
                precision: $precision,
                recall: $recall,
                f1: $f1,
                cost: $falseHitPenalty * $fh + $fm,
            );

            if ($offenders !== []) {
                $falseHitPairs[number_format($threshold, $decimals, '.', '')] = $offenders;
            }
        }

        $positives = count(array_filter($pairs, static fn (PromptPair $p): bool => $p->reuse));

        return new CalibrationResult(
            thresholds: $rows,
            recommended: self::recommend($rows, $objective, $falseHitPenalty),
            recommendedObjective: $objective,
            falseHitPenalty: $falseHitPenalty,
            datasetSize: count($pairs),
            positives: $positives,
            negatives: count($pairs) - $positives,
            falseHitPairs: $falseHitPairs,
        );
    }

    /**
     * Choose the recommended threshold under $objective. Pure over the swept
     * rows so it is unit-testable with synthetic data (spec §5.1).
     *
     * @param list<ThresholdRow> $rows
     */
    public static function recommend(array $rows, string $objective, float $falseHitPenalty): float
    {
        if ($rows === []) {
            throw new InvalidArgumentException('Cannot recommend a threshold from an empty sweep.');
        }

        // Each objective maps a row to a tuple where SMALLER is better; the row
        // with the lexicographically smallest tuple wins. Trailing threshold term
        // makes every tie-break deterministic (lower threshold = more reuse).
        $key = static function (ThresholdRow $r) use ($objective, $falseHitPenalty): array {
            $higherIsBetter = static fn (?float $m): float => $m === null ? INF : -$m;

            return match ($objective) {
                'min_false_hits' => [$r->falseHits, $r->threshold],
                'max_precision' => [$higherIsBetter($r->precision), $higherIsBetter($r->recall), $r->threshold],
                'max_f1' => [$higherIsBetter($r->f1), $higherIsBetter($r->recall), $r->threshold],
                // cost_weighted (default): recompute from the passed penalty so the
                // recommendation tracks the penalty independently of row->cost.
                default => [$falseHitPenalty * $r->falseHits + $r->falseMisses, $r->threshold],
            };
        };

        $best = $rows[0];
        $bestKey = $key($best);

        foreach ($rows as $row) {
            $rowKey = $key($row);

            if ($rowKey < $bestKey) {
                $best = $row;
                $bestKey = $rowKey;
            }
        }

        return $best->threshold;
    }

    /**
     * Embed $text once and memoize. Wraps a provider failure with the offending
     * prompt (fail-closed, spec §6) and enforces the provider's own dimension.
     *
     * @param array<string, array<int, float>> $cache
     * @return array<int, float>
     */
    private function vectorFor(string $text, array &$cache): array
    {
        if (array_key_exists($text, $cache)) {
            return $cache[$text];
        }

        try {
            $vector = $this->provider->embed($text);
        } catch (DimensionMismatchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException(
                "llm-cache:calibrate failed to embed prompt [{$text}]: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $expected = $this->provider->dimensions();

        if (count($vector) !== $expected) {
            throw DimensionMismatchException::make($this->provider->name(), count($vector), $expected);
        }

        return $cache[$text] = $vector;
    }

    /**
     * Inclusive threshold sweep, rounded to kill float drift so keys are stable.
     *
     * @return list<float>
     */
    private function sweep(float $min, float $max, float $step): array
    {
        $steps = (int) round(($max - $min) / $step);
        $out = [];

        for ($i = 0; $i <= $steps; $i++) {
            $t = round($min + $i * $step, 10);

            if ($t > $max + 1e-9) {
                break;
            }

            $out[] = $t;
        }

        return $out;
    }

    /** Number of significant decimal places in the step, for stable key formatting. */
    private function stepDecimals(float $step): int
    {
        $s = rtrim(number_format($step, 10, '.', ''), '0');
        $dot = strpos($s, '.');

        return $dot === false ? 0 : strlen($s) - $dot - 1;
    }
}
