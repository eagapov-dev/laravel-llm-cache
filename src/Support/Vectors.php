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
     * Returns 0.0 when either vector has zero magnitude (undefined direction) or
     * when the vectors differ in length — both register as "no similarity"
     * rather than a division error or a plausible-but-wrong prefix score.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        // Mismatched dimensions can't be meaningfully compared; a shared-prefix
        // score would be silently wrong. Callers validate dimensions, so this is
        // a defensive guard rather than an expected path.
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $length = count($a);

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
