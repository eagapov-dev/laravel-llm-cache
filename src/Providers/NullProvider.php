<?php

namespace Yegoragapov\LlmCache\Providers;

use LogicException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Deterministic, network-free fake for tests (hashed pseudo-vector).
 *
 * STUB (Phase 0). Real deterministic embedding implemented on `feat/providers`
 * against docs/specs/llm-cache.md §4.1. Constructor is intentionally functional
 * so the container boots; dimensions()/name() are real, embed() is pending.
 */
class NullProvider implements EmbeddingProvider
{
    public function __construct(
        protected int $dimensions,
    ) {
    }

    public function embed(string $text): array
    {
        throw new LogicException('NullProvider::embed() not implemented (Phase 1: feat/providers).');
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
