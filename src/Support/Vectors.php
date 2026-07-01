<?php

namespace Yegoragapov\LlmCache\Support;

/**
 * Pure vector math helpers used by the in-memory ArrayStore.
 */
final class Vectors
{
    /**
     * Cosine similarity = dot(a, b) / (norm(a) * norm(b)).
     *
     * Returns 0.0 when either vector has zero magnitude (undefined direction),
     * which correctly registers as "no similarity" rather than a division error.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        // Iterate positionally so mismatched-length vectors still compare on
        // their shared prefix; caller is responsible for dimension validation.
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];

            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
