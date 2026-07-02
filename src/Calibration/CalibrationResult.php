<?php

namespace Yegoragapov\LlmCache\Calibration;

/**
 * The full calibration report (spec §3.2): a confusion row per swept threshold,
 * the recommended threshold under the chosen objective, and the offending
 * false-hit pairs per threshold so the developer can see which semantics
 * collapse — not just the count.
 */
final class CalibrationResult
{
    /**
     * @param list<ThresholdRow>                                                                        $thresholds
     * @param array<array-key, array<int, array{a: string, b: string, similarity: float, note: string|null}>> $falseHitPairs
     */
    public function __construct(
        public readonly array $thresholds,
        public readonly float $recommended,
        public readonly string $recommendedObjective,
        public readonly float $falseHitPenalty,
        public readonly int $datasetSize,
        public readonly int $positives,
        public readonly int $negatives,
        public readonly array $falseHitPairs,
    ) {
    }
}
