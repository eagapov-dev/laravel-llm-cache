<?php

namespace Yegoragapov\LlmCache\Calibration;

/**
 * Confusion metrics for one similarity threshold in the sweep (spec §3.2).
 *
 * precision/recall/f1 are null when undefined for this threshold (e.g. no
 * predicted hits, or no positives in the dataset) rather than NaN, so callers
 * can skip the undefined metric instead of printing garbage.
 */
final class ThresholdRow
{
    public function __construct(
        public readonly float $threshold,
        public readonly int $falseHits,      // reuse=false but similarity >= threshold
        public readonly int $falseMisses,    // reuse=true  but similarity <  threshold
        public readonly int $truePositives,  // reuse=true  and similarity >= threshold
        public readonly int $trueNegatives,  // reuse=false and similarity <  threshold
        public readonly ?float $precision,   // TP / (TP + falseHits)
        public readonly ?float $recall,      // TP / (TP + falseMisses)
        public readonly ?float $f1,
        public readonly float $cost,         // falseHitPenalty·falseHits + falseMisses
    ) {
    }
}
