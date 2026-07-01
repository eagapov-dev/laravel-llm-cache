<?php

namespace Yegoragapov\LlmCache\Providers;

use LogicException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Voyage AI embeddings (recommended for Anthropic-centric stacks).
 *
 * STUB (Phase 0). Implemented on `feat/providers` against §4.1.
 */
class VoyageProvider implements EmbeddingProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
    ) {
    }

    public function embed(string $text): array
    {
        throw new LogicException('VoyageProvider::embed() not implemented (Phase 1: feat/providers).');
    }

    public function dimensions(): int
    {
        throw new LogicException('VoyageProvider::dimensions() not implemented (Phase 1: feat/providers).');
    }

    public function name(): string
    {
        return 'voyage';
    }
}
