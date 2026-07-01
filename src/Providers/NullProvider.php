<?php

namespace Yegoragapov\LlmCache\Providers;

use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Deterministic, network-free fake for tests.
 *
 * Produces a pseudo-embedding derived entirely from a hash of the input text:
 * the same text always yields the identical vector, distinct texts almost
 * always yield distinct vectors. The vector is normalized to unit length so it
 * behaves like a real cosine-space embedding. No network, no randomness.
 */
class NullProvider implements EmbeddingProvider
{
    public function __construct(
        protected int $dimensions,
    ) {
    }

    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $dimensions = max(1, $this->dimensions);

        $vector = [];
        $sumSquares = 0.0;

        // Derive each component deterministically from a per-index hash of the
        // text. sha256 gives us plenty of entropy; we map each hash to a float
        // in [-1, 1] so the resulting vector spreads across the space.
        for ($i = 0; $i < $dimensions; $i++) {
            $hash = hash('sha256', $i . ':' . $text);

            // Use the first 8 hex chars (32 bits) as an unsigned integer.
            $intValue = (int) hexdec(substr($hash, 0, 8));

            // Scale into [-1, 1].
            $component = ($intValue / 0xFFFFFFFF) * 2.0 - 1.0;

            $vector[] = $component;
            $sumSquares += $component * $component;
        }

        // Normalize to unit length. Guard the pathological all-zero case
        // (astronomically unlikely) so we never divide by zero.
        $norm = sqrt($sumSquares);

        if ($norm > 0.0) {
            foreach ($vector as $index => $component) {
                $vector[$index] = $component / $norm;
            }
        }

        return $vector;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function name(): string
    {
        return 'null';
    }
}
