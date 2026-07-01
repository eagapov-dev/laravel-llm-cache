<?php

namespace Yegoragapov\LlmCache\Contracts;

interface EmbeddingProvider
{
    /**
     * Turn a piece of text into an embedding vector.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * The fixed dimensionality of vectors this provider produces.
     */
    public function dimensions(): int;

    /**
     * Stable driver name, stamped on cache rows for audit.
     */
    public function name(): string;
}
